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

    public static function register_routes() {
        register_rest_route( 'etelid/v1', '/service/resolve', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'resolve' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/authenticate', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'authenticate' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/company_admin', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'company_admin' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/check_role', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'check_role' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/list_company_roles', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'list_company_roles' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/set_company_role', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'set_company_role' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
        register_rest_route( 'etelid/v1', '/service/remove_company_role', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'remove_company_role' ),
            'permission_callback' => array( __CLASS__, 'check_service_key' ),
        ));
    }

    /* ---- Shared-secret check (fail closed) ---------------------- */
    public static function check_service_key( $request ) {
    $expected = defined('ETELID_SERVICE_KEY') 
        ? ETELID_SERVICE_KEY 
        : get_option('etelid_service_key', '');

    if (!$expected) {
        return new WP_Error('no_key', 
            'Service API key not configured.', 
            array('status' => 503));
    }

    $given = $request->get_header('x-etel-service-key');
    if (!$given) {
        return new WP_Error('forbidden', 
            'Missing X-Etel-Service-Key header.', 
            array('status' => 403));
    }

    // Primary key check
    if (hash_equals($expected, $given)) return true;

    // Grace period: also accept old key during rotation
    $old_key = defined('ETELID_SERVICE_KEY_OLD') ? ETELID_SERVICE_KEY_OLD : '';
    if ($old_key && hash_equals($old_key, $given)) return true;

    return new WP_Error('forbidden', 
        'Invalid service key.', 
        array('status' => 403));
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
    private static function member_payload( $m ) {
        return array(
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

        EtelID_DB::log( $m->id, $m->etel_id, 'service_resolve', 'Service API lookup' );
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
    public static function authenticate( $request ) {
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
        if ( ! EtelID_Security::rate_limit( 'svc_auth_ident_' . md5( strtolower( $ident ) ), 8, 900 ) ) {
            EtelID_DB::log( 0, '', 'login_rate_limited', 'Service API SSO blocked - too many attempts for identifier in the last 15 minutes' );
            return new WP_Error( 'rate_limited', 'Too many attempts for this account. Please wait a few minutes, or reset the password.', array( 'status' => 429 ) );
        }

        $m = self::find_member( $ident );
        if ( ! $m ) return new WP_Error( 'not_found', 'No EtelID account found for that identifier.', array( 'status' => 404 ) );

        if ( $m->status === 'suspended' ) return new WP_Error( 'suspended', 'This EtelID is suspended. Contact security@etelgroup.com.', array( 'status' => 403 ) );
        if ( $m->status === 'revoked' )   return new WP_Error( 'revoked', 'This EtelID has been revoked.', array( 'status' => 403 ) );
        if ( empty( $m->pin_hash ) )      return new WP_Error( 'no_password', 'No password set on this EtelID. Reset it at the EtelID portal first.', array( 'status' => 403 ) );

        if ( ! EtelID_Auth::verify_password( $pass, $m->pin_hash ) ) {
            EtelID_DB::log( $m->id, $m->etel_id, 'login_failed', 'Service API SSO - wrong password' );
            return new WP_Error( 'wrong_password', 'Incorrect EtelID password.', array( 'status' => 401 ) );
        }

        EtelID_DB::log( $m->id, $m->etel_id, 'login', 'Service API SSO (' . substr( $ip, 0, 45 ) . ')' );

        $payload = array( 'ok' => true, 'member' => self::member_payload( $m ) );

        $wantSession = filter_var( $request->get_param( 'create_session' ), FILTER_VALIDATE_BOOLEAN );
        if ( $wantSession ) {
            /* Same table, same token format as the portal's own login -
               this IS the shared cross-app session, not a lookalike. */
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
        EtelID_DB::log( $member->id, $member->etel_id, 'company_role_set', "Set role '{$role}' for company '{$slug}' via EtelGroup admin" );

        return rest_ensure_response( array(
            'ok' => true,
            'message' => $member->full_name . ' is now ' . $role . ' for ' . $company_name . '.',
            'full_name' => $member->full_name, 'etel_id' => $member->etel_id, 'phone' => $member->phone,
        ) );
    }

    /* ---- POST /service/remove_company_role --------------------------- */
    public static function remove_company_role( $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! $id ) return new WP_Error( 'bad_request', 'id is required.', array( 'status' => 400 ) );
        EtelID_DB::remove_company_link( $id );
        return rest_ensure_response( array( 'ok' => true, 'message' => 'Role removed.' ) );
    }
}

EtelID_Service_API::init();
