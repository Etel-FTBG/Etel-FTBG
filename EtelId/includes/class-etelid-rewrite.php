<?php
/**
 * Etel-ID Premium — Rewrite Rules v3.8.0
 *
 * URL map:
 *   /apply/          → apply form
 *   /login/          → login
 *   /signup/         → signup
 *   /portal/         → member portal
 *   /profile/        → edit profile
 *   /logout/         → logout
 *   /verify/{ID}     → public verify page
 *   /print/{ID}      → print page
 *
 * IMPORTANT: verify and print use [^/]+ to support Ethiopic characters
 * in the ID prefix (e.g. ኤ.E-2026-00002). DO NOT use [A-Z0-9\-]+.
 *
 * PHP 5.6 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Rewrite {

    public static function init() {
        add_action( 'init',                   array( __CLASS__, 'add_rules'       ) );
        add_filter( 'query_vars',             array( __CLASS__, 'add_vars'        ) );
        add_action( 'template_include',       array( __CLASS__, 'route'           ), 999 );
        add_action( 'template_redirect',      array( __CLASS__, 'redirect_wp_pages' ) );
        add_action( 'template_redirect',      array( __CLASS__, 'redirect_long_to_short' ), 1 );
        add_action( 'wp_loaded',              array( __CLASS__, 'maybe_flush'     ) );
    }

    /* ── Add rewrite rules ── */
    public static function add_rules() {
        /* Static pages */
        add_rewrite_rule( '^etel-id/apply/?$',   'index.php?etelid_page=apply',   'top' );
        add_rewrite_rule( '^etel-id/login/?$',   'index.php?etelid_page=login',   'top' );
        add_rewrite_rule( '^etel-id/signup/?$',  'index.php?etelid_page=signup',  'top' );
        add_rewrite_rule( '^etel-id/portal/?$',  'index.php?etelid_page=portal',  'top' );
        add_rewrite_rule( '^etel-id/profile/?$', 'index.php?etelid_page=profile', 'top' );
        add_rewrite_rule( '^etel-id/logout/?$',  'index.php?etelid_page=logout',  'top' );
        add_rewrite_rule( '^etel-id/verify-pass2/?$', 'index.php?etelid_page=verify-pass2', 'top' );
        add_rewrite_rule( '^etel-id/reset-password/?$', 'index.php?etelid_page=reset-password', 'top' );

        /* Same static pages, shorter path without the redundant "etel-id/"
           segment - this whole plugin already lives under the /EtelId/
           folder, so /EtelId/login/ reads just as clearly as
           /EtelId/etel-id/login/ without repeating the name twice. Added
           alongside the originals (not replacing them) so nothing that
           still links to the longer form breaks. This is now the
           canonical form every internal link in this plugin uses. */
        add_rewrite_rule( '^apply/?$',   'index.php?etelid_page=apply',   'top' );
        add_rewrite_rule( '^login/?$',   'index.php?etelid_page=login',   'top' );
        add_rewrite_rule( '^signup/?$',  'index.php?etelid_page=signup',  'top' );
        add_rewrite_rule( '^portal/?$',  'index.php?etelid_page=portal',  'top' );
        add_rewrite_rule( '^profile/?$', 'index.php?etelid_page=profile', 'top' );
        add_rewrite_rule( '^logout/?$',  'index.php?etelid_page=logout',  'top' );
        add_rewrite_rule( '^verify-pass2/?$', 'index.php?etelid_page=verify-pass2', 'top' );
        add_rewrite_rule( '^reset-password/?$', 'index.php?etelid_page=reset-password', 'top' );

        /* Dynamic pages — [^/]+ supports Ethiopic characters in ID prefix */
        add_rewrite_rule( '^etel-id/verify/([^/]+)/?$', 'index.php?etelid_verify=$matches[1]', 'top' );
        add_rewrite_rule( '^etel-id/print/([^/]+)/?$',  'index.php?etelid_print=$matches[1]',  'top' );
        add_rewrite_rule( '^verify/([^/]+)/?$', 'index.php?etelid_verify=$matches[1]', 'top' );
        add_rewrite_rule( '^print/([^/]+)/?$',  'index.php?etelid_print=$matches[1]',  'top' );
    }

    /* ── Register query vars ── */
    public static function add_vars( $vars ) {
        $vars[] = 'etelid_page';
        $vars[] = 'etelid_verify';
        $vars[] = 'etelid_print';
        return $vars;
    }

    /* ── Route requests to templates ── */
    public static function route( $template ) {
        $page   = get_query_var( 'etelid_page' );
        $verify = get_query_var( 'etelid_verify' );
        $print  = get_query_var( 'etelid_print' );

        /* ── Verify page ── */
        if ( $verify ) {
            $tpl = ETELID_PATH . 'templates/verify.php';
            return file_exists( $tpl ) ? $tpl : $template;
        }

        /* ── Print page ── */
        if ( $print ) {
            $etel_id = sanitize_text_field( urldecode( $print ) );
            $member  = EtelID_DB::get_by_etel_id( $etel_id );
            if ( ! $member ) {
                /* Try URL-decoded version */
                $member = EtelID_DB::get_by_etel_id( rawurldecode( $print ) );
            }
            if ( $member ) {
                /* Make member available to template */
                $GLOBALS['etelid_print_member'] = $member;
                $tpl = ETELID_PATH . 'templates/print-page.php';
                return file_exists( $tpl ) ? $tpl : $template;
            }
            return $template;
        }

        /* ── Named pages ── */
        if ( ! $page ) return $template;

        switch ( $page ) {
            case 'apply':
                $tpl = ETELID_PATH . 'templates/apply-form.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'login':
            case 'signup':
                $tpl = ETELID_PATH . 'templates/auth-page.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'reset-password':
                $tpl = ETELID_PATH . 'templates/reset-password.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'portal':
                if ( ! EtelID_Auth::is_logged_in() ) {
                    wp_redirect( home_url( '/login/' ) );
                    exit;
                }
                $tpl = ETELID_PATH . 'templates/portal.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'profile':
                if ( ! EtelID_Auth::is_logged_in() ) {
                    wp_redirect( home_url( '/login/' ) );
                    exit;
                }
                $tpl = ETELID_PATH . 'templates/profile-update.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'verify-pass2':
                if ( ! EtelID_Auth::is_logged_in() ) {
                    wp_redirect( home_url( '/login/' ) );
                    exit;
                }
                $tpl = ETELID_PATH . 'templates/verify-pass2.php';
                return file_exists( $tpl ) ? $tpl : $template;

            case 'logout':
                EtelID_Auth::logout();
                wp_redirect( home_url( '/login/' ) );
                exit;
        }

        return $template;
    }

    /* ── Redirect the old /etel-id/xxx/ URLs to the shorter canonical
       /xxx/ form, so the address bar always ends up showing the short
       version regardless of which link/bookmark someone used to get
       here. Runs before add_rules' own routing, so both old and new
       links still reach the right page - this just cleans up the URL
       shown afterward. ── */
    public static function redirect_long_to_short() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( preg_match( '#^(.*?)/etel-id/(apply|login|signup|portal|profile|logout|verify-pass2|reset-password)/?(\?.*)?$#i', $uri, $m ) ) {
            wp_redirect( home_url( '/' . $m[2] . '/' ) . ( isset( $m[3] ) ? $m[3] : '' ), 301 );
            exit;
        }
        if ( preg_match( '#^(.*?)/etel-id/(verify|print)/([^/?]+)/?(\?.*)?$#i', $uri, $m ) ) {
            wp_redirect( home_url( '/' . $m[2] . '/' . $m[3] . '/' ) . ( isset( $m[4] ) ? $m[4] : '' ), 301 );
            exit;
        }
    }

    /* ── Redirect WP pages with matching slugs ── */
    public static function redirect_wp_pages() {
        if ( ! is_page() && ! is_singular() ) return;

        global $post;
        if ( ! $post ) return;

        /* CRITICAL: Never intercept real Etel-ID routes - both the
           original long form and the shorter canonical form */
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( preg_match( '#/(etel-id/)?(profile|portal|login|signup|apply|logout|verify|print|verify-pass2|reset-password)(/|$|\?)#i', $uri ) ) {
            return;
        }

        $slug = strtolower( $post->post_name );

        $map = array(
            'etelid'         => '/apply/',
            'etel-id-apply'  => '/apply/',
            'apply-for-id'   => '/apply/',
            'etelid-login'   => '/login/',
            'etel-id-signin' => '/login/',
            'member-login'   => '/login/',
            'etelid-portal'  => '/portal/',
            'member-portal'  => '/portal/',
        );

        if ( isset( $map[ $slug ] ) ) {
            wp_redirect( home_url( $map[ $slug ] ), 301 );
            exit;
        }
    }

    /* ── Flush rewrite rules once after plugin update ── */
    public static function maybe_flush() {
        if ( get_option( 'etelid_flush_needed' ) ) {
            flush_rewrite_rules();
            delete_option( 'etelid_flush_needed' );
        }
    }

    /* ── Called from admin to force flush ── */
    public static function flush() {
        flush_rewrite_rules();
    }
}
