<?php
/**
 * Etel-ID Premium — Login Interceptor v4.0.0
 *
 * WHO CAN ACCESS WP-ADMIN:
 *   ✅ The member whose row ID = etelid_admin_member_id (set in Settings)
 *   ✅ Existing WP users already logged in via normal WP session
 *   ❌ ALL other Etel-ID members — redirected to their portal
 *   ❌ Anyone not logged in at all — redirected to Etel-ID login
 *
 * FLOW for admin member:
 *   Visit /wp-admin/ → Etel-ID login → detected as admin → WP session created → /wp-admin/
 *
 * FLOW for regular members:
 *   Visit /wp-admin/ → Etel-ID login → NOT admin → redirected to /portal/
 *
 * EMERGENCY BACKDOOR (if locked out):
 *   Visit /wp-login.php?etelid_admin_access=1
 *   Sets a 30-min bypass cookie → shows normal WP login form
 *
 * PHP 5.6 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Login_Interceptor {

    const BYPASS_COOKIE = 'etelid_admin_bypass';
    const BYPASS_SECRET = 'etelid_bypass_v4';
    const INTENT_COOKIE = 'etelid_wants_wpadmin';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'intercept' ), 1 );
        add_filter( 'login_url',  array( __CLASS__, 'filter_login_url'  ), 999, 3 );
        add_filter( 'logout_url', array( __CLASS__, 'filter_logout_url' ), 999, 2 );
    }

    /* ══════════════════════════════════════════
       MAIN INTERCEPT — runs on every request
    ══════════════════════════════════════════ */
    public static function intercept() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

        /* ── Block wp-login.php ── */
        if ( strpos( $uri, 'wp-login.php' ) !== false ) {
            self::handle_wp_login();
            return;
        }

        /* ── Block /wp-admin/ ── */
        if ( is_admin() && ! self::is_safe_admin_request() ) {
            self::handle_wp_admin();
            return;
        }

        /* ── After Etel-ID login: maybe auto-login to WP ── */
        if ( ! is_admin() ) {
            self::maybe_bridge_to_wp_admin();
        }
    }

    /* ══════════════════════════════════════════
       HANDLE wp-login.php
    ══════════════════════════════════════════ */
    private static function handle_wp_login() {
        /* Emergency backdoor */
        if ( isset( $_GET['etelid_admin_access'] ) ) {
            setcookie( self::BYPASS_COOKIE, self::BYPASS_SECRET,
                time() + 1800, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            return;
        }

        /* Active bypass cookie */
        if ( self::has_bypass() ) return;

        /* Safe WP actions (logout, password reset) */
        $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
        $safe   = array( 'logout', 'rp', 'resetpass', 'lostpassword', 'postpass' );
        if ( in_array( $action, $safe, true ) ) return;

        /* Everything else → Etel-ID login */
        wp_redirect( home_url( '/login/' ), 302 );
        exit;
    }

    /* ══════════════════════════════════════════
       HANDLE /wp-admin/
    ══════════════════════════════════════════ */
    private static function handle_wp_admin() {
        /* Bypass cookie active → allow normal WP access */
        if ( self::has_bypass() ) return;

        /* Already logged into WordPress with manage_options → allow */
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return;

        /* Check if Etel-ID member is logged in AND is the admin */
        if ( class_exists( 'EtelID_Auth' ) ) {
            $member = EtelID_Auth::get_current_member();
            if ( $member && EtelID_Auth::is_site_admin( $member ) ) {
                /* Auto-login this member to WordPress */
                $wp_user = self::get_wp_admin_user( $member );
                if ( $wp_user ) {
                    wp_set_current_user( $wp_user->ID );
                    wp_set_auth_cookie( $wp_user->ID, true );
                    return; /* Let them through to wp-admin */
                }

                /* FIX: recognized as site admin, but no WordPress user
                 * account matches their email or the site admin_email.
                 * Previously this fell through to the "not admin" branch
                 * below and silently bounced back to /portal/ —
                 * which looked like every admin tool (Fayda Register
                 * included) was just "re-opening the dashboard". Show a
                 * clear, actionable message instead. */
                wp_die(
                    'Etel-ID recognizes you as the site admin, but no WordPress user account matches your member email (' .
                    esc_html( $member->email ? $member->email : '— none on file —' ) .
                    ') or the site\'s Admin Email (' . esc_html( get_option( 'admin_email' ) ) . ').<br><br>' .
                    'Fix: go to <strong>Settings → General</strong> in wp-admin and set the Admin Email to match your Etel-ID email — or create/edit a WordPress user with that email and the Administrator role.<br><br>' .
                    'Locked out of Settings too? Use the emergency backdoor: <code>/wp-login.php?etelid_admin_access=1</code> (valid for 30 minutes).',
                    'Etel-ID — admin account not linked',
                    array( 'response' => 200, 'back_link' => true )
                );
            }

            /* Member is logged in but NOT admin → send to portal */
            if ( $member ) {
                wp_redirect( home_url( '/portal/' ), 302 );
                exit;
            }
        }

        /* Not logged in at all → Etel-ID login with intent cookie */
        self::set_intent_cookie();
        wp_redirect( home_url( '/login/?redirect=wpadmin' ), 302 );
        exit;
    }

    /* ══════════════════════════════════════════
       AFTER ETEL-ID LOGIN: bridge admin to WP
    ══════════════════════════════════════════ */
    private static function maybe_bridge_to_wp_admin() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( ! class_exists( 'EtelID_Auth' ) ) return;

        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) return;
        if ( ! EtelID_Auth::is_site_admin( $member ) ) return;

        /* Check intent cookie or redirect param */
        $has_intent   = isset( $_COOKIE[ self::INTENT_COOKIE ] ) &&
                        $_COOKIE[ self::INTENT_COOKIE ] === '1';
        $has_redirect = isset( $_GET['redirect'] ) && $_GET['redirect'] === 'wpadmin';

        if ( ! $has_intent && ! $has_redirect ) return;

        /* Clear intent */
        setcookie( self::INTENT_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );

        /* Already logged into WP → just redirect */
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            wp_redirect( admin_url(), 302 );
            exit;
        }

        /* Log admin into WordPress */
        $wp_user = self::get_wp_admin_user( $member );
        if ( $wp_user ) {
            wp_set_current_user( $wp_user->ID );
            wp_set_auth_cookie( $wp_user->ID, true );
            wp_redirect( admin_url(), 302 );
            exit;
        }
    }

    /* ══════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════ */
    private static function is_safe_admin_request() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return true;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return true;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return true;
        return false;
    }

    private static function has_bypass() {
        return isset( $_COOKIE[ self::BYPASS_COOKIE ] ) &&
               $_COOKIE[ self::BYPASS_COOKIE ] === self::BYPASS_SECRET;
    }

    private static function set_intent_cookie() {
        setcookie( self::INTENT_COOKIE, '1', time() + 600,
            COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }

    /* Find the WP admin user account for an Etel-ID admin member */
    private static function get_wp_admin_user( $member ) {
        /* Try by email */
        if ( $member->email ) {
            $user = get_user_by( 'email', $member->email );
            if ( $user && user_can( $user, 'manage_options' ) ) return $user;
        }
        /* Fall back to site admin email */
        $admin_email = get_option( 'admin_email' );
        if ( $admin_email ) {
            $user = get_user_by( 'email', $admin_email );
            if ( $user ) return $user;
        }
        return null;
    }

    /* Filters */
    public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
        if ( $redirect && strpos( $redirect, 'wp-admin' ) !== false ) return $login_url;
        return home_url( '/login/' );
    }

    public static function filter_logout_url( $logout_url, $redirect ) {
        if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) return $logout_url;
        return home_url( '/logout/' );
    }
}
