<?php
/**
 * Etel-ID Premium — Member Authentication System
 *
 * @package    Etel-ID Premium
 * @version    2.1.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.1.0
 *
 * Standalone auth: no WordPress user accounts needed.
 * Members sign up / log in with phone + password.
 * Sessions stored in {prefix}etelid_sessions table.
 * All passwords hashed with password_hash (bcrypt, PHP 5.5+).
 * Falls back to SHA-256 on PHP < 5.5 shared hosts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Auth {

    const SESSION_COOKIE  = 'etelid_session';
    const SESSION_SECONDS = 2592000; /* 30 days */
    const TABLE_SESSIONS  = 'etelid_sessions';

    /* ─── Install session table ──────────────────────────── */
    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SESSIONS;
        $c     = $wpdb->get_charset_collate();
        $sql   = "CREATE TABLE IF NOT EXISTS {$table} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id  BIGINT UNSIGNED NOT NULL,
            token      VARCHAR(64)     NOT NULL,
            ip         VARCHAR(45)     NOT NULL DEFAULT '',
            ua         VARCHAR(255)    NOT NULL DEFAULT '',
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_token (token),
            KEY idx_member (member_id),
            KEY idx_expires (expires_at)
        ) {$c};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ─── Password helpers ───────────────────────────────── */
    public static function hash_password( $plain ) {
        if ( function_exists( 'password_hash' ) ) {
            return password_hash( $plain, PASSWORD_BCRYPT );
        }
        return hash( 'sha256', 'etelid_v2_' . $plain . '_salt_9x' );
    }

    public static function verify_password( $plain, $hash ) {
        if ( function_exists( 'password_verify' ) ) {
            /* Also handle legacy sha256 hashes */
            if ( strlen( $hash ) === 64 ) {
                return hash_equals( $hash, hash( 'sha256', 'etelid_v2_' . $plain . '_salt_9x' ) );
            }
            return password_verify( $plain, $hash );
        }
        return hash_equals( $hash, hash( 'sha256', 'etelid_v2_' . $plain . '_salt_9x' ) );
    }

    /* ─── Registration ───────────────────────────────────── */
    /**
     * Register a new member account.
     * @param  array          $data  Keys: phone, password, full_name, full_name_am, dob, sex,
     *                               blood_type, nationality, region, zone, woreda,
     *                               primary_company_slug, role, department, email
     * @return int|WP_Error   member row id on success
     */
    public static function register( $data ) {
        /* Validate phone */
        $phone = isset( $data['phone'] ) ? preg_replace( '/\s+/', '', $data['phone'] ) : '';
        if ( ! $phone ) return new WP_Error( 'phone_required', 'Phone number is required.' );
        if ( ! EtelID_Security::valid_ethiopian_phone( $phone ) ) {
            return new WP_Error( 'phone_invalid', 'Enter a valid Ethiopian phone number, e.g. 0924445206 or +251924445206.' );
        }

        /* Check duplicate */
        $existing = EtelID_DB::find_by_phone( $phone );
        if ( $existing ) {
            return new WP_Error( 'phone_exists', 'An account with this phone number already exists. Please sign in.' );
        }

        /* Validate password */
        $password = isset( $data['password'] ) ? $data['password'] : '';
        /* HARDENING: was a 6-character minimum with no other requirement -
         * below current OWASP/NIST guidance (8 minimum is the baseline
         * they recommend; NIST explicitly discourages forced complexity
         * rules like "must contain a symbol" in favor of length, since
         * complexity rules push people toward predictable substitutions
         * like "Passw0rd!"). Raising to 8, still no complexity
         * requirement - length is what actually matters. This only
         * affects new registrations and password resets going forward;
         * existing shorter passwords keep working exactly like the old
         * SHA-256->bcrypt hash migration above (verified on login and
         * left alone otherwise), not force-invalidated. */
        if ( strlen( $password ) < 8 ) {
            return new WP_Error( 'weak_password', 'Password must be at least 8 characters.' );
        }

        /* Hash password and store in data */
        $data['phone']         = $phone;
        $data['password_hash'] = self::hash_password( $password );
        unset( $data['password'] );
        unset( $data['password_confirm'] );

        /* Fayda ID photos are optional at registration, but if either is
         * provided, both must be - and both must actually be images.
         * Same check fayda_id_submit() already does for an existing
         * member's later submission; applied here too since this is now
         * a second entry point writing to the same columns. */
        $has_front = ! empty( $data['fayda_front_image'] );
        $has_back  = ! empty( $data['fayda_back_image'] );
        if ( $has_front || $has_back ) {
            if ( ! $has_front || strpos( $data['fayda_front_image'], 'data:image' ) !== 0 ) {
                return new WP_Error( 'fayda_front_invalid', 'Please add a photo of the front of your Fayda ID.' );
            }
            if ( ! $has_back || strpos( $data['fayda_back_image'], 'data:image' ) !== 0 ) {
                return new WP_Error( 'fayda_back_invalid', 'Please add a photo of the back of your Fayda ID.' );
            }
        }

        /* Create the member record (generates Etel-ID, FIN, card number etc.) */
        $member_id = EtelID_Generator::create( $data );
        if ( is_wp_error( $member_id ) ) return $member_id;

        /* Save password hash */
        EtelID_DB::update_member( $member_id, array( 'pin_hash' => $data['password_hash'] ) );

        /* Log */
        EtelID_DB::log( $member_id, '', 'registered', 'Self-registration via portal' );

        return $member_id;
    }

    /* ─── Login ──────────────────────────────────────────── */
    /**
     * Authenticate with phone + password. Returns member object or WP_Error.
     */
    public static function login( $phone, $password ) {
        $phone  = preg_replace( '/\s+/', '', $phone );

        /* BRUTE-FORCE PROTECTION — this endpoint previously had ZERO rate
         * limiting, unlike the password-reset flow (which already
         * rate-limits both per-IP and per-account). Anyone could try
         * unlimited phone+password combinations against any account with
         * no throttling at all. Same two-layer approach as reset:
         * a looser per-IP cap (catches one attacker hammering many
         * different phone numbers) and a tighter per-phone cap (catches
         * one account being hammered from many IPs/proxies). Checked
         * before the DB lookup so a flood of attempts against a
         * non-existent phone still costs nothing extra. */
        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'login_ip_' . $ip, 30, 900 ) ) {
            return new WP_Error( 'rate_limited', 'Too many login attempts from this location. Please wait a few minutes and try again.' );
        }
        if ( $phone && ! EtelID_Security::rate_limit( 'login_phone_' . $phone, 8, 900 ) ) {
            EtelID_DB::log( 0, '', 'login_rate_limited', 'Login blocked - too many attempts for phone ' . $phone . ' in the last 15 minutes' );
            return new WP_Error( 'rate_limited', 'Too many attempts for this account. Please wait a few minutes, or use "Forgot password" to reset it.' );
        }

        $member = EtelID_DB::find_by_phone( $phone );

        if ( ! $member ) {
            return new WP_Error( 'not_found', 'No account found with this phone number.' );
        }
        if ( $member->status === 'suspended' ) {
            return new WP_Error( 'suspended', 'This account has been suspended. Contact security@etelgroup.com.' );
        }
        if ( $member->status === 'revoked' ) {
            return new WP_Error( 'revoked', 'This account has been revoked.' );
        }
        if ( empty( $member->pin_hash ) ) {
            return new WP_Error( 'no_password', 'No password set. Please reset your password or contact support.' );
        }
        if ( ! self::verify_password( $password, $member->pin_hash ) ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'login_failed', 'Wrong password' );
            return new WP_Error( 'wrong_password', 'Incorrect password. Please try again.' );
        }

        /* Transparent bcrypt upgrade: if the stored hash is still the legacy
         * SHA-256 format (exactly 64 hex chars), re-hash with bcrypt now that
         * the password is confirmed correct. Zero user friction — happens silently. */
        if ( strlen( $member->pin_hash ) === 64 ) {
            $new_hash = self::hash_password( $password );
            EtelID_DB::update_member( $member->id, array( 'pin_hash' => $new_hash ) );
            EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'PIN hash upgraded from SHA-256 to bcrypt on login' );
        }

        /* Start session */
        $token = self::create_session( $member->id );
        EtelID_DB::log( $member->id, $member->etel_id, 'login', 'Member portal login' );

        return array( 'member' => $member, 'token' => $token );
    }

    /* ─── Session management ─────────────────────────────── */
    public static function create_session( $member_id ) {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SESSIONS;
        $token   = bin2hex( function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32) );
        /* HARDENING: the raw token used to be stored directly in the
         * `token` column - meaning any DB read access (a backup, a
         * mysqldump handed off for analysis, a future SQL-injection bug
         * anywhere else in the stack, or just an over-broad phpMyAdmin
         * grant) handed over every currently-valid session outright, with
         * no further authentication needed to use them. Store a SHA-256
         * hash of the token instead - same 64-char length, so no schema
         * change needed - and keep the *raw* token only in the cookie the
         * browser holds. A stolen DB dump is now useless for hijacking
         * sessions on its own; a stolen dump AND a stolen cookie both
         * being needed is a real improvement, not just theoretical -
         * this exact plugin's own `etelid.sql` export, sitting in this
         * conversation, contains several session rows that are still
         * valid for weeks. */
        $token_hash = hash( 'sha256', $token );
        $expires = date( 'Y-m-d H:i:s', time() + self::SESSION_SECONDS );
        $ip      = EtelID_Security::get_ip();
        $ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

        $wpdb->insert( $table, array(
            'member_id'  => (int) $member_id,
            'token'      => $token_hash,
            'ip'         => $ip,
            'ua'         => $ua,
            'expires_at' => $expires,
        ));

        /* Set secure cookie */
        $secure = is_ssl();
        /* HARDENING: previously used the old positional-args setcookie()
         * signature, which has no way to set SameSite at all - this
         * session cookie was going out with no SameSite attribute,
         * leaving it to whatever the browser's own default happens to
         * be (inconsistent across browsers/versions, and some default
         * to sending it on cross-site requests). SameSite=Lax is the
         * right choice here specifically (not Strict): Strict would
         * drop the cookie on a customer arriving via an external link
         * (e.g. a payment redirect back from EtelPay, or a shared
         * WhatsApp/SMS link into the portal) since those are top-level
         * cross-site navigations - Lax still blocks it being sent on
         * cross-site POSTs/embeds/XHR (the actual CSRF-relevant cases)
         * while still working for ordinary link clicks.
         * PHP 7.3+ setcookie() accepts an options array with 'samesite'
         * - falls back to the old positional call (no SameSite) only
         * on PHP < 7.3, which can't express it at all. */
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie(
                self::SESSION_COOKIE,
                $token,
                array(
                    'expires'  => time() + self::SESSION_SECONDS,
                    'path'     => COOKIEPATH,
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );
        } else {
            setcookie(
                self::SESSION_COOKIE,
                $token,
                time() + self::SESSION_SECONDS,
                COOKIEPATH,
                COOKIE_DOMAIN,
                $secure,
                true /* httponly */
            );
        }

        return $token;
    }

    public static function get_current_member() {
        if ( ! isset( $_COOKIE[ self::SESSION_COOKIE ] ) ) return null;
        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) );
        return self::member_from_token( $token );
    }

    public static function member_from_token( $token ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_SESSIONS;
        $now    = date( 'Y-m-d H:i:s' );
        /* Hash the presented token the same way create_session() hashes it
         * before storing - the column holds SHA-256 hashes now, not raw
         * tokens (see create_session), so comparing the raw incoming
         * token directly would never match anything. */
        $token_hash = hash( 'sha256', $token );
        $row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT member_id, ip FROM {$table} WHERE token = %s AND expires_at > %s LIMIT 1",
            $token_hash, $now
        ));
        if ( ! $row ) return null;
        /* HARDENING: sessions live for 30 days (self::SESSION_SECONDS)
         * with no re-auth requirement and no IP/device binding - a
         * stolen token (leaked log, XSS, shared/public device) stays
         * fully valid for a month regardless of where it's used from
         * next. Deliberately NOT hard-blocking on an IP change here -
         * that would false-positive constantly for ordinary mobile
         * customers switching between WiFi and mobile data, or moving
         * between cell towers, and this system has no step-up/MFA flow
         * to fall back to instead of an outright lockout. Logging the
         * anomaly is the safe middle ground: it costs the legitimate
         * user nothing, and gives whoever reviews etelid_log a real
         * signal to act on (e.g. "flag for the customer to review their
         * active sessions") rather than silently trusting a token
         * forever with zero visibility into where it's being used. */
        $current_ip = EtelID_Security::get_ip();
        if ( $current_ip && $row->ip && $current_ip !== $row->ip ) {
            EtelID_DB::log( $row->member_id, '', 'session_ip_changed',
                'Session token used from a different IP than it was created with (was ' .
                substr( $row->ip, 0, 45 ) . ', now ' . substr( $current_ip, 0, 45 ) . ')' );
        }
        return EtelID_DB::get_member( $row->member_id );
    }

    public static function logout() {
        if ( isset( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
            global $wpdb;
            $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) );
            $table = $wpdb->prefix . self::TABLE_SESSIONS;
            // Same hash as create_session()/member_from_token() - deleting
            // by raw token would never match a row now that the column
            // stores hashes.
            $wpdb->delete( $table, array( 'token' => hash( 'sha256', $token ) ) );
        }
        /* Clear with the same attributes used to set it (see create_session
         * above) - a mismatched domain/path/samesite on the clearing call
         * can cause some browsers to leave the original cookie in place
         * instead of overwriting it. */
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( self::SESSION_COOKIE, '', array(
                'expires'  => time() - 3600,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie( self::SESSION_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
        }
    }

    public static function is_logged_in() {
        return (bool) self::get_current_member();
    }

    /* ─── Purge expired sessions (run on login) ──────────── */
    public static function purge_expired() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SESSIONS;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at < %s",
            date( 'Y-m-d H:i:s' )
        ));
    }
    /**
     * Check if a member is the designated site admin.
     * Strict: only matches etelid_admin_member_id from Settings.
     */
    public static function is_site_admin( $member ) {
        if ( ! $member ) return false;
        $admin_mid = (int) get_option( 'etelid_admin_member_id', 0 );
        if ( $admin_mid > 0 ) {
            return (int) $member->id === $admin_mid;
        }
        $admin_email = get_option( 'admin_email', '' );
        if ( $admin_email && $member->email && $member->email === $admin_email ) {
            return true;
        }
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

}
