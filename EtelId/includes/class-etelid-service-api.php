<?php
/**
 * EtelID Service API - server-to-server endpoints for Etel Group apps
 * (ETEL Delivery, Madeya, Laundry, Market, Transport, Pay, ...)
 *
 * Routes (namespace etelid/v1):
 *   POST /service/resolve         { identifier }            -> member info
 *   POST /service/authenticate    { identifier, password }  -> SSO login check
 *   POST /service/company_admin   { company_slug }          -> phone of the
 *     admin/owner/creator for that company (etelid_companies.role_at).
 *     Added so sibling projects (Delivery, Etel Pay, EtelGebeya, ...) can
 *     resolve their merchant/payout phone via one clean, secured endpoint
 *     instead of each parsing this site's wp-config.php for raw DB
 *     credentials and connecting directly -- same shared-key auth as the
 *     routes above.
 *
 * Security:
 *   - Every request must send header  X-Etel-Service-Key
 *     matching the ETELID_SERVICE_KEY constant (define it in wp-config.php)
 *     or the 'etelid_service_key' option.
 *   - Fails CLOSED: if no key is configured, the API refuses all requests.
 *   - pin_hash NEVER leaves this server; password checks happen here.
 *   - Per-IP rate limit on authenticate (30/min) against brute force.
 *
 * @package EtelID
 * @since   3.9.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Service_API {

    const RATE_LIMIT  = 30;   /* authenticate attempts per IP per minute */

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /* route => method name. Each route is also a SCOPE name: a per-app key
     * only works on the routes listed in its "scopes". */
    private static $routes = array(
        'resolve'             => 'resolve',
        'authenticate'        => 'authenticate',
        'company_admin'       => 'company_admin',
        'check_role'          => 'check_role',
        'list_company_roles'  => 'list_company_roles',
        'set_company_role'    => 'set_company_role',
        'remove_company_role' => 'remove_company_role',
        'member_count'        => 'member_count',
        'list_companies'      => 'list_companies',
    );

    public static function register_routes() {
        foreach ( self::$routes as $scope => $method ) {
            register_rest_route( 'etelid/v1', '/service/' . $scope, array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, $method ),
                'permission_callback' => self::permission_for( $scope ),
            ));
        }
    }

    private static function permission_for( $scope ) {
        return function ( $request ) use ( $scope ) {
            return EtelID_Service_API::check_service_key( $request, $scope );
        };
    }

    /* ---- Service-key check (fail closed) -------------------------------
     * Two kinds of key:
     *   - the legacy MASTER key (ETELID_SERVICE_KEY): works on every route,
     *     exactly as before, so nothing breaks while apps are migrated;
     *   - PER-APP keys (ETELID_SERVICE_KEYS in wp-config.php, JSON), each
     *     limited to the routes ("scopes") that app really needs and
     *     optionally to its server's IPs, e.g.
     *     define('ETELID_SERVICE_KEYS', '[
     *       {"app":"delivery","key":"<random>","scopes":["resolve","authenticate","check_role","company_admin"]},
     *       {"app":"etelgroup","key":"<random>","scopes":["list_company_roles","set_company_role","remove_company_role","member_count","list_companies"]}
     *     ]');
     * One compromised sibling app then exposes only its own scopes, and the
     * audit log shows WHICH app made each call. Guessing keys is throttled
     * per IP (20 misses / 15 min). To retire the master key once every app
     * has its own key: define('ETELID_DISABLE_MASTER_KEY', true). */
    private static $caller = array( 'app' => '', 'scopes' => array() );

    public static function caller_label() {
        return self::$caller['app'] !== '' ? self::$caller['app'] : 'unknown';
    }

    private static function configured_keys() {
        $raw = defined( 'ETELID_SERVICE_KEYS' ) ? ETELID_SERVICE_KEYS : get_option( 'etelid_service_keys', '' );
        $list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        if ( ! is_array( $list ) ) return array();
        $out = array();
        foreach ( $list as $e ) {
            if ( ! is_array( $e ) || empty( $e['key'] ) || empty( $e['app'] ) ) continue;
            if ( strlen( (string) $e['key'] ) < 24 ) continue;   /* refuse weak keys */
            $out[] = array(
                'app'    => sanitize_key( (string) $e['app'] ),
                'key'    => (string) $e['key'],
                'scopes' => isset( $e['scopes'] ) && is_array( $e['scopes'] ) ? array_map( 'strval', $e['scopes'] ) : array(),
                'ips'    => isset( $e['ips'] ) && is_array( $e['ips'] ) ? array_map( 'strval', $e['ips'] ) : array(),
            );
        }
        return $out;
    }

    public static function check_service_key( $request, $scope = '' ) {
        $master = defined( 'ETELID_SERVICE_KEY' ) ? ETELID_SERVICE_KEY : get_option( 'etelid_service_key', '' );
        if ( defined( 'ETELID_DISABLE_MASTER_KEY' ) && ETELID_DISABLE_MASTER_KEY ) $master = '';
        $keys = self::configured_keys();

        if ( ! $master && ! $keys ) {
            return new WP_Error( 'no_key', 'Service API key not configured.', array( 'status' => 503 ) );
        }

        $ip = EtelID_Security::get_ip();
        if ( EtelID_Security::fail_blocked( 'svc_key_' . $ip, 20, 900 ) ) {
            return new WP_Error( 'rate_limited', 'Too many invalid service keys. Try again later.', array( 'status' => 429 ) );
        }

        $given = $request->get_header( 'x-etel-service-key' );
        if ( ! $given ) {
            return new WP_Error( 'forbidden', 'Missing X-Etel-Service-Key header.', array( 'status' => 403 ) );
        }

        /* Per-app keys first */
        foreach ( $keys as $k ) {
            if ( ! hash_equals( $k['key'], $given ) ) continue;
            if ( $k['ips'] ) {
                $ok_ip = false;
                foreach ( $k['ips'] as $allowed ) {
                    if ( strpos( $allowed, '/' ) !== false ? EtelID_Security::ip_in_cidr( $ip, $allowed ) : $allowed === $ip ) { $ok_ip = true; break; }
                }
                if ( ! $ok_ip ) {
                    EtelID_DB::log( 0, '', 'service_key_denied', 'Key for app ' . $k['app'] . ' used from non-allowed IP ' . substr( $ip, 0, 45 ) );
                    return new WP_Error( 'forbidden', 'This key is not valid from this address.', array( 'status' => 403 ) );
                }
            }
            if ( $scope !== '' && ! in_array( $scope, $k['scopes'], true ) && ! in_array( '*', $k['scopes'], true ) ) {
                EtelID_DB::log( 0, '', 'service_key_denied', 'App ' . $k['app'] . ' tried route outside its scope: ' . $scope );
                return new WP_Error( 'forbidden', 'This key is not allowed to use that endpoint.', array( 'status' => 403 ) );
            }
            self::$caller = array( 'app' => $k['app'], 'scopes' => $k['scopes'] );
            return true;
        }

        /* Legacy master key (full access) */
        if ( $master && hash_equals( $master, $given ) ) {
            self::$caller = array( 'app' => 'master-key', 'scopes' => array( '*' ) );
            return true;
        }

        /* Grace period: also accept old master key during rotation */
        $old_key = defined( 'ETELID_SERVICE_KEY_OLD' ) ? ETELID_SERVICE_KEY_OLD : '';
        if ( $old_key && hash_equals( $old_key, $given ) ) {
            self::$caller = array( 'app' => 'master-key-old', 'scopes' => array( '*' ) );
            return true;
        }

        EtelID_Security::fail_hit( 'svc_key_' . $ip, 900 );
        return new WP_Error( 'forbidden', 'Invalid service key.', array( 'status' => 403 ) );
    }


    /* ---- Identifier -> member ----------------------------------- */
    private static function find_member( $identifier ) {
        global $wpdb;
        $t   = EtelID_DB::t_members();
        $raw = trim( (string) $identifier );

        /* QR encodes a verify URL: .../verify/EG-2026-00003 */
        if ( stripos( $raw, '/verify/' ) !== false ) {
            $parts = explode( '/verify/', $raw );
            $raw   = urldecode( trim( end( $parts ), "/ \t" ) );
        }
        $upper  = strtoupper( $raw );
        $digits = preg_replace( '/[^0-9]/', '', $raw );

        /* 1) Etel-ID exact: EG-2026-00003 */
        if ( preg_match( '/^[A-Z]{1,4}-[0-9]{2,4}-[0-9]{3,8}$/', $upper ) ) {
            $m = EtelID_DB::get_by_etel_id( $upper );
            if ( $m ) return $m;
        }
        /* 2) FIN (12 digits) or card number (16 digits), any formatting */
        if ( strlen( $digits ) >= 12 ) {
            $m = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t}
                 WHERE REPLACE(REPLACE(fin,'-',''),' ','') = %s
                    OR REPLACE(REPLACE(card_number,'-',''),' ','') = %s
                 LIMIT 1", $digits, $digits ) );
            if ( $m ) return $m;
        }
        /* 3) Phone (9-13 digits, any formatting incl. +251 / 09 / dashes) */
        if ( strlen( $digits ) >= 9 && strlen( $digits ) <= 13 ) {
            $last9 = substr( $digits, -9 );
            $m = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t}
                 WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') = %s
                    OR RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''), 9) = %s
                 LIMIT 1", $digits, $last9 ) );
            if ( $m ) return $m;
        }
        return null;
    }

    /* ---- Public payload (no secrets) ----------------------------- */
    /* Apps a limited (Ride-only) account may still sign in to. Everything
     * else is blocked until the member adds a profile photo and both sides
     * of their Fayda ID - see EtelID_DB::refresh_limited(). */
    private static $limited_apps = array( 'ride' );

    private static function app_allowed_for( $m, $app ) {
        if ( ! EtelID_Security::member_is_limited( $m ) ) return true;
        $app = strtolower( trim( (string) $app ) );
        return in_array( $app, self::$limited_apps, true );
    }

    private static function member_payload( $m ) {
        $limited = EtelID_Security::member_is_limited( $m );
        return array(
            /* Limited (Ride-only) accounts: every consuming app must check
             * profile_limited and refuse anything that is not Ride. */
            'kyc_level'       => isset( $m->kyc_level ) ? (int) $m->kyc_level : 0,
            'profile_limited' => $limited,
            'allowed_apps'    => $limited ? self::$limited_apps : array( '*' ),
            'missing_items'   => $limited ? EtelID_Security::member_missing_items( $m ) : array(),
            /* phone_verified: the member proved they own this number. phone_trusted: what the other apps may rely on - the
             * number is verified, or verification is not required yet (see EtelID_Phone_Verify). */
            'phone_verified' => class_exists( 'EtelID_Phone_Verify' ) ? EtelID_Phone_Verify::is_verified( $m ) : true,
            'phone_trusted'  => class_exists( 'EtelID_Phone_Verify' ) ? EtelID_Phone_Verify::trusted( $m ) : true,
            'etel_id'    => $m->etel_id,
            'full_name'  => $m->full_name,
            'phone'      => $m->phone,
            'email'      => isset( $m->email )           ? $m->email           : '',
            'status'     => $m->status,
            'tier'       => isset( $m->tier )            ? $m->tier            : '',
            'company'    => isset( $m->primary_company ) ? $m->primary_company : '',
            'role'       => isset( $m->role )            ? $m->role            : ( isset( $m->primary_role ) ? $m->primary_role : '' ),
            'expiry'     => isset( $m->expiry_date )     ? $m->expiry_date     : '',
            /* Messaging channels — used by all Etel Group apps for free OTP delivery */
            'tg_chat_id' => isset( $m->tg_chat_id )     ? $m->tg_chat_id      : '',
            'wa_phone'   => isset( $m->wa_phone )        ? $m->wa_phone        : '',
        );
    }

    /* ---- POST /service/resolve ----------------------------------- */
    public static function resolve( $request ) {
        $ident = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
        if ( ! $ident ) return new WP_Error( 'bad_request', 'identifier is required.', array( 'status' => 400 ) );

        $m = self::find_member( $ident );
        if ( ! $m ) return new WP_Error( 'not_found', 'No EtelID member matches that identifier.', array( 'status' => 404 ) );

        EtelID_DB::log( $m->id, $m->etel_id, 'service_resolve', 'Service API lookup (app: ' . self::caller_label() . ')' );
        return rest_ensure_response( array( 'ok' => true, 'member' => self::member_payload( $m ) ) );
    }

    /* ---- POST /service/authenticate (SSO) ------------------------
       Pass "create_session": true to also get back a real cross-app
       session token (same Etelid_sessions table + mechanism the
       EtelID portal's own login already uses). Any Etel Group app
       (Delivery, Ride, EtelAI, Madeya, ...) can store that one token
       under one shared key and every other app recognizes it -
       without each app inventing its own separate, disconnected
       session system the way Delivery/Ride's etel_token and EtelAI's
       unused etelid_auth key currently do. */
    /* Crash guard: authenticate() never lets an unexpected error escape as a
     * WordPress "critical error" page. The real reason goes to the option
     * "etelid_last_fatal" (admin-readable only) and the caller gets a generic
     * message - internal paths and method names are not sent over the API. */
    private static $stage = 'start';

    public static function capture_fatal() {
        $e = error_get_last();
        if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
            update_option( 'etelid_last_fatal', date( 'c' ) . ' stage=' . self::$stage . ' ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'], false );
        }
    }

    private static function diag_error( $e ) {
        $msg = 'stage=' . self::$stage . ' ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
        update_option( 'etelid_last_fatal', date( 'c' ) . ' ' . $msg, false );
        return new WP_Error( 'sso_internal_error', 'EtelID could not complete the sign-in. Please try again; if it keeps happening contact support.', array( 'status' => 500 ) );
    }

    public static function authenticate( $request ) {
        register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );
        try {
            return self::authenticate_inner( $request );
        } catch ( Exception $e ) {
            return self::diag_error( $e );
        } catch ( Throwable $e ) {
            return self::diag_error( $e );
        }
    }

    private static function authenticate_inner( $request ) {
        /* Brute-force shield: per-IP sliding window */
        $ip  = EtelID_Security::get_ip();
        $key = 'etelid_svc_auth_' . md5( $ip );
        $n   = (int) get_transient( $key );
        if ( $n >= self::RATE_LIMIT ) {
            return new WP_Error( 'rate_limited', 'Too many attempts. Try again in a minute.', array( 'status' => 429 ) );
        }
        set_transient( $key, $n + 1, 60 );

        $ident = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
        $pass  = (string) $request->get_param( 'password' );
        if ( ! $ident || ! $pass ) return new WP_Error( 'bad_request', 'identifier and password are required.', array( 'status' => 400 ) );

        /* HARDENING: this endpoint - the actual SSO login path every
         * subsidiary app (Delivery, Pay, Gebeya, Taxi, Madeya) calls into -
         * previously rate-limited by IP only. EtelID_Auth::login() (the
         * direct portal login form) already rate-limits by BOTH IP and
         * account identifier, specifically because IP-only limiting is
         * trivially bypassed by a distributed attacker rotating through
         * many IPs/proxies to brute-force one specific customer's
         * password with no ceiling at all. Add the same per-identifier
         * cap here, checked before the DB lookup so a flood against a
         * non-existent identifier still costs nothing extra. */
        /* Per-account protection is the failed-password lockout further down
         * (8 wrong passwords -> 15 min). It replaced a limiter that counted
         * every call, successful or not, and so could lock out a person who
         * simply signed in to several Etel apps in a row. */

        self::$stage = 'find_member';
        $m = self::find_member( $ident );
        if ( ! $m ) return new WP_Error( 'not_found', 'No EtelID account found for that identifier.', array( 'status' => 404 ) );

        if ( $m->status === 'suspended' ) return new WP_Error( 'suspended', 'This EtelID is suspended. Contact security@etelgroup.com.', array( 'status' => 403 ) );
        if ( $m->status === 'revoked' )   return new WP_Error( 'revoked', 'This EtelID has been revoked.', array( 'status' => 403 ) );
        if ( empty( $m->pin_hash ) )      return new WP_Error( 'no_password', 'No password set on this EtelID. Reset it at the EtelID portal first.', array( 'status' => 403 ) );

        self::$stage = 'lockout';
        $lock_key = 'nfc_acct_' . strtolower( (string) $m->etel_id );   /* shared with the NFC steps */
        if ( EtelID_Security::fail_blocked( $lock_key, 8, 900 ) ) {
            return new WP_Error( 'rate_limited', 'Too many wrong passwords for this account. Please wait 15 minutes, or reset the password.', array( 'status' => 429 ) );
        }
        if ( ! EtelID_Auth::verify_password( $pass, $m->pin_hash ) ) {
            EtelID_Security::fail_hit( $lock_key, 900 );
            EtelID_DB::log( $m->id, $m->etel_id, 'login_failed', 'Service API SSO - wrong password' );
            return new WP_Error( 'wrong_password', 'Incorrect EtelID password.', array( 'status' => 401 ) );
        }

        /* Limited (Ride-only) account signing in to anything but Ride: refuse
         * with a clear reason. Checked only AFTER the password is verified so
         * this can never reveal an account's state to someone who does not
         * know its password. The calling app names itself with `app`. */
        self::$stage = 'password_ok';
        EtelID_Security::fail_clear( $lock_key );

        /* Two-step sign-in (members who switched it on): the calling app passes
         * `totp_code`. Without it the app is told to ask for one. This is after
         * the password check, so it never reveals anything to a stranger. */
        $twofa_on = false;
        if ( class_exists( 'EtelID_2FA' ) && EtelID_2FA::is_enabled( $m->id ) ) {
            $twofa_on = true;
            $g = EtelID_2FA::gate( $m, (string) $request->get_param( 'totp_code' ) );
            if ( is_wp_error( $g ) ) return $g;
        }

        /* A number nobody has proved they own cannot be used to sign in to the other Etel apps: they find the person's account
         * by that number. Checked after the password (and two-step code), so it never tells a stranger anything. The member
         * can verify the number in the EtelID portal, which they can still sign in to. */
        if ( class_exists( 'EtelID_Phone_Verify' ) && ! EtelID_Phone_Verify::trusted( $m ) ) {
            EtelID_DB::log( $m->id, $m->etel_id, 'phone_unverified_blocked', 'Sign-in to ' . substr( sanitize_key( (string) $request->get_param( 'app' ) ), 0, 30 ) . ' refused: phone number not verified' );
            return new WP_Error( 'phone_unverified',
                'Please verify your phone number first. Sign in to your Etel-ID portal and enter the code we send you, then try again.',
                array( 'status' => 403 ) );
        }

        $app = (string) $request->get_param( 'app' );
        if ( '' !== $app && ! self::app_allowed_for( $m, $app ) ) {
            EtelID_DB::log( $m->id, $m->etel_id, 'limited_blocked', 'Limited account refused by ' . substr( sanitize_key( $app ), 0, 30 ) );
            return new WP_Error( 'profile_incomplete',
                'Your Etel-ID is limited to Ride. Add your profile photo and both sides of your Fayda ID in the EtelID portal to unlock all Etel services.',
                array( 'status' => 403 ) );
        }

        self::$stage = 'log_login';
        EtelID_DB::log( $m->id, $m->etel_id, 'login', 'Service API SSO (' . substr( $ip, 0, 45 ) . ', app: ' . self::caller_label() . ')' );

        self::$stage = 'member_payload';
        $payload = array( 'ok' => true, 'member' => self::member_payload( $m ), 'twofa_verified' => $twofa_on );

        $wantSession = filter_var( $request->get_param( 'create_session' ), FILTER_VALIDATE_BOOLEAN );
        if ( $wantSession ) {
            /* Same table, same token format as the portal's own login -
               this IS the shared cross-app session, not a lookalike. */
        self::$stage = 'create_session';
            $payload['session_token'] = EtelID_Auth::create_session( $m->id );
        }

        return rest_ensure_response( $payload );
    }

    /* ---- POST /service/company_admin ------------------------------
       Whoever holds admin/owner/creator for a given company_slug is
       the receiving account for that project's payments/payouts. */
    public static function company_admin( $request ) {
        global $wpdb;
        $slug = sanitize_text_field( (string) $request->get_param( 'company_slug' ) );
        if ( ! $slug ) return new WP_Error( 'bad_request', 'company_slug is required.', array( 'status' => 400 ) );

        $companies_t = EtelID_DB::t_companies();
        $members_t   = EtelID_DB::t_members();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.phone, m.full_name, m.etel_id, c.role_at
               FROM {$companies_t} c
               JOIN {$members_t} m ON m.id = c.member_id
              WHERE c.company_slug = %s AND c.active = 1
                AND LOWER(c.role_at) IN ('admin','owner','creator')
              ORDER BY c.linked_at ASC LIMIT 1",
            $slug
        ) );

        if ( ! $row ) return new WP_Error( 'not_found', 'No admin/owner linked for that company_slug yet.', array( 'status' => 404 ) );

        return rest_ensure_response( array(
            'ok'    => true,
            'phone' => $row->phone,
            'full_name' => $row->full_name,
            'etel_id'   => $row->etel_id,
            'role_at'   => $row->role_at,
        ) );
    }

    /* ---- POST /service/check_role -----------------------------------
       Any active role (not just admin/owner/creator) - lets a sibling
       system (Delivery, EtelTaxi, ...) check "does this phone number
       have an employee role here, and what is it" as part of its own
       "Sign in with EtelID" login option. */
    public static function check_role( $request ) {
        global $wpdb;
        $slug  = sanitize_text_field( (string) $request->get_param( 'company_slug' ) );
        $phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
        if ( ! $slug || ! $phone ) {
            return new WP_Error( 'bad_request', 'company_slug and phone are required.', array( 'status' => 400 ) );
        }
        $companies_t = EtelID_DB::t_companies();
        $members_t   = EtelID_DB::t_members();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.phone, m.full_name, m.etel_id, c.role_at
               FROM {$companies_t} c
               JOIN {$members_t} m ON m.id = c.member_id
              WHERE c.company_slug = %s AND m.phone = %s AND c.active = 1
              ORDER BY c.linked_at DESC LIMIT 1",
            $slug, $phone
        ) );
        if ( ! $row ) return new WP_Error( 'not_found', 'No active role for that phone at this company.', array( 'status' => 404 ) );
        return rest_ensure_response( array(
            'ok' => true, 'phone' => $row->phone, 'full_name' => $row->full_name,
            'etel_id' => $row->etel_id, 'role_at' => $row->role_at,
        ) );
    }

    /* ---- POST /service/list_company_roles ---------------------------
       All active employee role links for one company_slug - powers the
       central "Employees" panel in EtelGroup admin, so the group owner
       never has to open this site's wp-admin just to see who has
       access to what. */
    public static function list_company_roles( $request ) {
        global $wpdb;
        $slug = sanitize_text_field( (string) $request->get_param( 'company_slug' ) );
        if ( ! $slug ) return new WP_Error( 'bad_request', 'company_slug is required.', array( 'status' => 400 ) );
        $companies_t = EtelID_DB::t_companies();
        $members_t   = EtelID_DB::t_members();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, m.full_name, m.etel_id, m.phone, c.role_at, c.linked_at
               FROM {$companies_t} c
               JOIN {$members_t} m ON m.id = c.member_id
              WHERE c.company_slug = %s AND c.active = 1
              ORDER BY c.linked_at DESC",
            $slug
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'id' => (int) $r->id, 'full_name' => $r->full_name, 'etel_id' => $r->etel_id,
                'phone' => $r->phone, 'role_at' => $r->role_at, 'linked_at' => $r->linked_at,
            );
        }
        return rest_ensure_response( array( 'ok' => true, 'roles' => $out ) );
    }

    /* ---- POST /service/set_company_role ------------------------------
       Create or update a staff role at a company - same effect
       as using this site's own "Company Roles" admin tool, exposed as
       a secured REST call so EtelGroup admin can manage it remotely
       without needing wp-admin access. role_at is free text (e.g.
       "Dispatcher", "Support", "Manager", "Traffic Officer") - not
       restricted to admin/owner/creator, those are just conventionally
       reserved for the company_admin payout-recipient check above. */
    public static function set_company_role( $request ) {
        $identifier = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
        $slug       = sanitize_key( (string) $request->get_param( 'company_slug' ) );
        $role       = sanitize_text_field( (string) $request->get_param( 'role_at' ) );
        if ( ! $identifier ) return new WP_Error( 'bad_request', 'identifier (phone or Etel-ID) is required.', array( 'status' => 400 ) );
        if ( ! $slug )       return new WP_Error( 'bad_request', 'company_slug is required.', array( 'status' => 400 ) );
        if ( ! $role )       return new WP_Error( 'bad_request', 'role_at is required.', array( 'status' => 400 ) );

        $member = EtelID_DB::get_by_etel_id( strtoupper( $identifier ) );
        if ( ! $member ) $member = EtelID_DB::find_by_phone( $identifier );
        if ( ! $member ) return new WP_Error( 'not_found', 'No member found for "' . $identifier . '". They need an EtelID account first.', array( 'status' => 404 ) );

        $canonical    = EtelID_Generator::companies();
        $company_name = isset( $canonical[ $slug ] ) ? $canonical[ $slug ]['name'] : ucfirst( str_replace( '-', ' ', $slug ) );

        EtelID_DB::set_company_role( $member->id, $slug, $company_name, $role );
        EtelID_DB::log( $member->id, $member->etel_id, 'company_role_set', "Set role '{$role}' for company '{$slug}' via EtelGroup admin (app: " . self::caller_label() . ')' );

        return rest_ensure_response( array(
            'ok' => true,
            'message' => $member->full_name . ' is now ' . $role . ' for ' . $company_name . '.',
            'full_name' => $member->full_name, 'etel_id' => $member->etel_id, 'phone' => $member->phone,
        ) );
    }

    /* ---- POST /service/remove_company_role ---------------------------
       Requires company_slug alongside id, and verifies the link row
       actually belongs to that company, before removing it - previously
       any caller holding the shared service key could deactivate ANY
       company's role link by guessing/incrementing id, since nothing
       scoped the removal to the company the caller claimed to manage.
       Also logs the removal now, matching set_company_role's existing
       audit trail (this route was the one exception before). */
    public static function remove_company_role( $request ) {
        $id   = (int) $request->get_param( 'id' );
        $slug = sanitize_key( (string) $request->get_param( 'company_slug' ) );
        if ( ! $id )   return new WP_Error( 'bad_request', 'id is required.', array( 'status' => 400 ) );
        if ( ! $slug ) return new WP_Error( 'bad_request', 'company_slug is required.', array( 'status' => 400 ) );

        $link = EtelID_DB::get_company_link( $id );
        if ( ! $link ) return new WP_Error( 'not_found', 'No such role link.', array( 'status' => 404 ) );
        if ( $link->company_slug !== $slug ) {
            return new WP_Error( 'forbidden', 'That role link does not belong to company_slug "' . $slug . '".', array( 'status' => 403 ) );
        }

        EtelID_DB::remove_company_link( $id );

        $member = EtelID_DB::get_member( $link->member_id );
        if ( $member ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'company_role_removed',
                "Removed role '{$link->role_at}' for company '{$slug}' via EtelGroup admin (app: " . self::caller_label() . ')' );
        }

        return rest_ensure_response( array( 'ok' => true, 'message' => 'Role removed.' ) );
    }

    /* ---- GET /service/member_count ------------------------------------
       Real registered-member count (revoked accounts excluded) - so
       EtelGroup's public homepage can show a true number instead of a
       client-side fake. No PII in the response, just a count, but still
       gated behind the same service key as every other route here rather
       than opening a new unauthenticated surface for one extra field. */
    public static function member_count( $request ) {
        global $wpdb;
        $t = EtelID_DB::t_members();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status != 'revoked'" );
        return rest_ensure_response( array( 'ok' => true, 'count' => $count ) );
    }

    /* ---- POST /service/list_companies -----------------------------
       The canonical slug/name/status registry from EtelID_Generator -
       the single source of truth every other role/auth route above
       matches company_slug against by exact string, with no error on a
       typo. Exposed here so sibling admin panels (EtelGroup's Companies
       CMS in particular) can populate their own slug picker from this
       list instead of letting an admin type an arbitrary slug that
       looks right but silently never matches a real role here. */
    public static function list_companies( $request ) {
        $out = array();
        foreach ( EtelID_Generator::companies() as $slug => $c ) {
            $out[] = array(
                'slug'   => $slug,
                'name'   => $c['name'],
                'status' => $c['status'],
            );
        }
        return rest_ensure_response( array( 'ok' => true, 'companies' => $out ) );
    }
}

EtelID_Service_API::init();
