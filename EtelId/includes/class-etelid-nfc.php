<?php
/**
 * EtelID NFC Card module.
 *
 * Adds a new table (etelid_nfc_cards), a public route at /m/{token} that
 * resolves to a member's public profile, and AJAX endpoints for the admin
 * "Write NFC Card" tool to issue/revoke tokens.
 *
 * ASSUMPTIONS (confirm against actual plugin code before deploying):
 *  - Members table name: $wpdb->prefix . 'etelid_members'
 *  - Member columns used: id, etelid_number, full_name, photo_url, tier, status
 *  - Admin capability used elsewhere in the portal: 'manage_options'
 *    (swap ETELID_NFC_ADMIN_CAP below if the portal uses a custom capability)
 *
 * If any of the above do not match the real schema, only the three spots
 * marked "ADJUST HERE" need to change - the rest of the module does not
 * care about exact column names.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ETELID_NFC_ADMIN_CAP')) {
    define('ETELID_NFC_ADMIN_CAP', 'manage_options'); // ADJUST HERE if needed
}

class Etelid_Nfc {

    const TABLE = 'etelid_nfc_cards';
    const QUERY_VAR = 'etelid_nfc_token';
    const TOKEN_BYTES = 18; // -> 24 char base64url token

    public static function init() {
        self::maybe_install();

        add_action('init', array(__CLASS__, 'register_rewrite'));
        add_filter('query_vars', array(__CLASS__, 'register_query_var'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_profile'));

        add_action('wp_ajax_etelid_nfc_issue', array(__CLASS__, 'ajax_issue'));
        add_action('wp_ajax_etelid_nfc_revoke', array(__CLASS__, 'ajax_revoke'));
        add_action('wp_ajax_etelid_nfc_lookup_member', array(__CLASS__, 'ajax_lookup_member'));

        /* Public flow on the /m/{token} page - the scanning person
           (service giver or customer) is never a logged-in WP user, so
           both priv and nopriv hooks point at the same handlers, same
           pattern as class-etelid-ajax.php's public apply endpoint. */
        add_action('wp_ajax_etelid_nfc_start',   array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_nopriv_etelid_nfc_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_etelid_nfc_confirm', array(__CLASS__, 'ajax_confirm'));
        add_action('wp_ajax_nopriv_etelid_nfc_confirm', array(__CLASS__, 'ajax_confirm'));

        /* EtelPay tap-to-pay, cardholder pays: separate confirm step
           from ajax_confirm() above (that one is order_id-specific for
           pickup/delivery), since this one takes the cardholder's own
           password instead of an order id. */
        add_action('wp_ajax_etelid_nfc_cardholder_pay_confirm', array(__CLASS__, 'ajax_cardholder_pay_confirm'));
        add_action('wp_ajax_nopriv_etelid_nfc_cardholder_pay_confirm', array(__CLASS__, 'ajax_cardholder_pay_confirm'));

        /* Etel Madeya "Register in Queue", admin path only: separate
           confirm step for when the signed-in person is an admin/
           manager (not a station controller) and has to pick which
           station's queue to register the tapped driver into. */
        add_action('wp_ajax_etelid_nfc_madeya_station_confirm', array(__CLASS__, 'ajax_madeya_station_confirm'));
        add_action('wp_ajax_nopriv_etelid_nfc_madeya_station_confirm', array(__CLASS__, 'ajax_madeya_station_confirm'));
    }

    /* Idempotent runtime install - the plugin's own activation hook only
       fires on activation, which this already-active plugin will not go
       through again just because this file is now wired in. Cheap
       option check keeps this from running dbDelta() on every request. */
    public static function maybe_install() {
        if ('1.0' !== get_option('etelid_nfc_db_version')) {
            self::install();
        }
    }

    /* ---------------------------------------------------------------
     * Install / migration
     * ------------------------------------------------------------- */

    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(40) NOT NULL,
            card_uid VARCHAR(64) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            issued_at DATETIME NOT NULL,
            last_tapped_at DATETIME NULL DEFAULT NULL,
            tap_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY member_id (member_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('etelid_nfc_db_version', '1.0');
    }

    /* ---------------------------------------------------------------
     * Token helpers
     * ------------------------------------------------------------- */

    protected static function generate_token() {
        $raw = random_bytes(self::TOKEN_BYTES);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return $token;
    }

    public static function issue_card($member_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $member_id = (int) $member_id;

        if ($member_id <= 0) {
            return new WP_Error('bad_member', 'Invalid member id.');
        }

        $token = self::generate_token();
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table,
            array(
                'member_id'  => $member_id,
                'token'      => $token,
                'status'     => 'active',
                'issued_at'  => $now,
            ),
            array('%d', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            return new WP_Error('db_error', 'Could not issue card token.');
        }

        return $token;
    }

    public static function revoke_card($token) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->update(
            $table,
            array('status' => 'revoked'),
            array('token' => sanitize_text_field($token)),
            array('%s'),
            array('%s')
        );
    }

    protected static function get_card_row($token) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $token = sanitize_text_field($token);

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token)
        );

        return $row;
    }

    protected static function get_member($member_id) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'etelid_members';

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, etel_id, full_name, phone, photo_url, tier, status, pin_hash
                 FROM {$members_table} WHERE id = %d",
                (int) $member_id
            )
        );

        return $member;
    }

    /* ---------------------------------------------------------------
     * Public route: /m/{token}
     * ------------------------------------------------------------- */

    public static function register_rewrite() {
        add_rewrite_rule('^m/([A-Za-z0-9_-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    public static function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function maybe_render_profile() {
        $token = get_query_var(self::QUERY_VAR);

        if (empty($token)) {
            return;
        }

        $card = self::get_card_row($token);

        if (!$card || 'active' !== $card->status) {
            status_header(404);
            $member = null;
        } else {
            $member = self::get_member($card->member_id);

            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET tap_count = tap_count + 1, last_tapped_at = %s WHERE id = %d",
                    current_time('mysql'),
                    $card->id
                )
            );
        }

        $template = plugin_dir_path(__FILE__) . '../templates/nfc-profile.php';

        if (file_exists($template)) {
            include $template;
        } else {
            wp_die('Profile template not found.');
        }

        exit;
    }

    /* ---------------------------------------------------------------
     * AJAX: admin "Write NFC Card" tool
     * ------------------------------------------------------------- */

    public static function ajax_issue() {
        // The live "Write NFC Card" tool is built into templates/portal.php,
        // which uses the same 'etelid_auth' nonce as every other in-portal
        // admin AJAX call (_P.nonce) - not a separate 'etelid_nfc_admin'
        // nonce, which nothing on that page ever generates.
        check_ajax_referer('etelid_auth', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        $token = self::issue_card($member_id);

        if (is_wp_error($token)) {
            wp_send_json_error(array('message' => $token->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'token' => $token,
            'url'   => home_url('/m/' . $token),
        ));
    }

    public static function ajax_revoke() {
        check_ajax_referer('etelid_auth', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        self::revoke_card($token);

        wp_send_json_success();
    }

    public static function ajax_lookup_member() {
        check_ajax_referer('etelid_auth', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        global $wpdb;
        $members_table = $wpdb->prefix . 'etelid_members';
        $search = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
        $like = '%' . $wpdb->esc_like($search) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, etel_id, full_name FROM {$members_table}
                 WHERE etel_id LIKE %s OR full_name LIKE %s
                 LIMIT 10",
                $like,
                $like
            )
        );

        wp_send_json_success($rows);
    }

    /* ---------------------------------------------------------------
     * Public flow: /m/{token} page - "confirm pickup" / "confirm delivery"
     * ------------------------------------------------------------- */

    public static function resolve_token($token) {
        $card = self::get_card_row($token);
        if (!$card || 'active' !== $card->status) return null;
        $member = self::get_member($card->member_id);
        if (!$member) return null;
        return array('card' => $card, 'member' => $member);
    }

    /* Outbound call to the Delivery system's shared-service-key API -
       the reverse direction of Delivery's own etelid_bridge.php (which
       calls INTO EtelID). Same base-URL/option pattern already used by
       class-etelid-admin-panels.php's Members Controller. */
    protected static function delivery_service_call($action, $payload) {
        $key = defined('ETELID_SERVICE_KEY') ? ETELID_SERVICE_KEY : get_option('etelid_service_key', '');
        if (!$key) return new WP_Error('no_key', 'etelid_service_key is not configured.');

        $home_parts = wp_parse_url(home_url());
        $site_root  = (isset($home_parts['scheme']) ? $home_parts['scheme'] : 'https') . '://'
                    . (isset($home_parts['host']) ? $home_parts['host'] : $_SERVER['HTTP_HOST']);
        $base = get_option('etelid_delivery_api_base', $site_root . '/delivery');
        $url  = trailingslashit($base) . 'api/api.php';

        $payload['action'] = $action;
        $resp = wp_remote_post($url, array(
            'timeout' => 12,
            'headers' => array('X-Etel-Service-Key' => $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return new WP_Error('bad_response', 'Delivery returned an unreadable response.');
        if ($code < 200 || $code >= 300 || empty($body['success'])) {
            $msg = !empty($body['message']) ? $body['message'] : ('Delivery request failed (HTTP ' . $code . ').');
            return new WP_Error('delivery_error', $msg);
        }
        return isset($body['data']) ? $body['data'] : array();
    }

    /* Outbound call to EtelPay's shared-service-key API, same shape as
       delivery_service_call() above but a different base URL/pairing -
       reuses the same ETELID_SERVICE_KEY, since EtelPay's own service
       endpoints (etelid_member_detail.php etc.) already authenticate
       incoming calls against that same shared EtelGroup key. */
    protected static function etelpay_service_call($path, $payload) {
        $key = defined('ETELID_SERVICE_KEY') ? ETELID_SERVICE_KEY : get_option('etelid_service_key', '');
        if (!$key) return new WP_Error('no_key', 'etelid_service_key is not configured.');

        $home_parts = wp_parse_url(home_url());
        $site_root  = (isset($home_parts['scheme']) ? $home_parts['scheme'] : 'https') . '://'
                    . (isset($home_parts['host']) ? $home_parts['host'] : $_SERVER['HTTP_HOST']);
        $base = get_option('etelid_etelpay_api_base', $site_root . '/Etel.Pay/wallet');
        $url  = trailingslashit($base) . 'api/' . $path;

        $resp = wp_remote_post($url, array(
            'timeout' => 12,
            'headers' => array('X-Etel-Service-Key' => $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return new WP_Error('bad_response', 'EtelPay returned an unreadable response.');
        if ($code < 200 || $code >= 300 || empty($body['success'])) {
            $msg = !empty($body['message']) ? $body['message'] : ('EtelPay request failed (HTTP ' . $code . ').');
            return new WP_Error('etelpay_error', $msg);
        }
        return isset($body['data']) ? $body['data'] : array();
    }

    /* Outbound call to Etel Madeya's shared-service-key API, same shape
       as etelpay_service_call() above - reuses the same
       ETELID_SERVICE_KEY, since Etel Madeya's own etelid_bridge.php
       already authenticates outgoing calls to EtelID against that same
       shared EtelGroup key. */
    protected static function madeya_service_call($path, $payload) {
        $key = defined('ETELID_SERVICE_KEY') ? ETELID_SERVICE_KEY : get_option('etelid_service_key', '');
        if (!$key) return new WP_Error('no_key', 'etelid_service_key is not configured.');

        $home_parts = wp_parse_url(home_url());
        $site_root  = (isset($home_parts['scheme']) ? $home_parts['scheme'] : 'https') . '://'
                    . (isset($home_parts['host']) ? $home_parts['host'] : $_SERVER['HTTP_HOST']);
        $base = get_option('etelid_madeya_api_base', $site_root . '/Etel-Madeya');
        $url  = trailingslashit($base) . 'api/' . $path;

        $resp = wp_remote_post($url, array(
            'timeout' => 12,
            'headers' => array('X-Etel-Service-Key' => $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return new WP_Error('bad_response', 'Etel Madeya returned an unreadable response.');
        if ($code < 200 || $code >= 300 || empty($body['success'])) {
            $msg = !empty($body['message']) ? $body['message'] : ('Etel Madeya request failed (HTTP ' . $code . ').');
            return new WP_Error('madeya_error', $msg);
        }
        return isset($body['data']) ? $body['data'] : array();
    }

    /* Whether $phone currently holds $role_name at $company_slug in Etel
       Group's own Employees panel data - queried DIRECTLY here (this
       data lives in EtelID's own WordPress DB, the same tables
       EtelID_Service_Api::check_role() reads), never over HTTP.
       Delivery's nfc_driver_status previously re-derived this by
       calling BACK into EtelID's own REST API mid-request (Delivery's
       etelid_bridge_check_role) while EtelID was itself in the middle
       of calling Delivery for this exact request - a self-referential
       round trip that can deadlock on shared hosting with a small
       PHP-FPM worker pool (every worker ends up blocked waiting on
       another blocked worker). Checking locally here removes that
       outbound hop entirely instead of just tuning a timeout. */
    protected static function has_company_role($phone, $company_slug, $role_name) {
        global $wpdb;
        $companies_t = EtelID_DB::t_companies();
        $members_t   = EtelID_DB::t_members();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.role_at
               FROM {$companies_t} c
               JOIN {$members_t} m ON m.id = c.member_id
              WHERE c.company_slug = %s AND m.phone = %s AND c.active = 1
              ORDER BY c.linked_at DESC LIMIT 1",
            $company_slug, $phone
        ));
        return $row && isset($row->role_at) && 0 === strcasecmp($row->role_at, $role_name);
    }

    /* Step 1: the scanning person picks "Confirm Pickup" / "Confirm
       Delivery Complete" and enters THEIR OWN EtelID phone/Etel-ID +
       password. On success, returns the courier's active orders for
       that stage plus a short-lived single-use action token (so step 2
       does not need to resend the password). */
    public static function ajax_start() {
        if (!check_ajax_referer('etelid_public', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired, please reload the page.'));
        }

        $token  = isset($_POST['token'])  ? sanitize_text_field(wp_unslash($_POST['token']))  : '';
        $stage  = isset($_POST['stage'])  ? sanitize_key($_POST['stage'])                     : '';
        $ident  = isset($_POST['identifier']) ? sanitize_text_field(wp_unslash($_POST['identifier'])) : '';
        $pass   = isset($_POST['password']) ? (string) $_POST['password'] : '';

        $allowedStages = array('pickup', 'delivery', 'ride_start', 'driver_status', 'etelpay_payout', 'etelpay_cardholder_pay', 'etelmadeya_register');
        if (!$token || !in_array($stage, $allowedStages, true) || !$ident || !$pass) {
            wp_send_json_error(array('message' => 'Missing required fields.'));
        }

        $resolved = self::resolve_token($token);
        if (!$resolved) wp_send_json_error(array('message' => 'This card is not active.'));
        $courier = $resolved['member']; // whoever's card was physically tapped

        $ip = class_exists('EtelID_Security') ? EtelID_Security::get_ip() : '';
        if (class_exists('EtelID_Security') && !EtelID_Security::rate_limit('nfc_confirm_' . md5($ip . $token), 15, 900)) {
            wp_send_json_error(array('message' => 'Too many attempts. Please wait a few minutes.'));
        }

        $confirmer = EtelID_DB::get_by_etel_id(strtoupper($ident));
        if (!$confirmer) $confirmer = EtelID_DB::find_by_phone($ident);
        if (!$confirmer) wp_send_json_error(array('message' => 'No EtelID account found for that phone/Etel-ID.'));

        if (empty($confirmer->pin_hash) || !EtelID_Auth::verify_password($pass, $confirmer->pin_hash)) {
            wp_send_json_error(array('message' => 'Incorrect password.'));
        }
        if ('suspended' === $confirmer->status || 'revoked' === $confirmer->status) {
            wp_send_json_error(array('message' => 'This EtelID account is not active.'));
        }

        /* "Start Trip" (street hail): the driver taps the RIDER's card and
           enters their own driver password. Unlike pickup/delivery there
           is nothing to list or choose between - one tap either starts
           the trip or fails - so this is a single round trip with no
           second "confirm" step and no action_token. */
        if ('ride_start' === $stage) {
            $result = self::delivery_service_call('nfc_start_ride', array(
                'driver_etel_id'   => $confirmer->etel_id,
                'driver_phone'     => $confirmer->phone,
                'customer_etel_id' => $courier->etel_id,
                'customer_phone'   => $courier->phone,
                'customer_name'    => $courier->full_name,
            ));
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_ride_start', 'NFC card scan - trip started with ' . $courier->full_name);
            wp_send_json_success(array(
                'ride_started' => true,
                'message'      => isset($result['message']) ? $result['message'] : 'Trip started.',
            ));
        }

        /* "Driver Info" (traffic-officer compliance check): gated behind
           the scanning person's own EtelID login (same as every other
           stage here) AND, on the Delivery side, behind actually holding
           the "Traffic Officer" staff role there - unlike the page's
           name/photo, license and compliance info is not shown to a bare
           EtelID login alone. Delivery resolves and checks the officer's
           role itself (officer_etel_id/officer_phone below); EtelID has
           no visibility into Delivery's staff_role/role_permissions data
           to pre-check it here. Read-only either way, so this is a
           single round trip with no second confirm step. */
        if ('driver_status' === $stage) {
            if (!self::has_company_role($confirmer->phone, 'etel-delivery', 'Traffic Officer')) {
                wp_send_json_error(array('message' => 'Traffic Officer access required to view driver compliance info.'));
            }
            $result = self::delivery_service_call('nfc_driver_status', array(
                'etel_id'         => $courier->etel_id,
                'phone'           => $courier->phone,
                'officer_etel_id' => $confirmer->etel_id,
                'officer_phone'   => $confirmer->phone,
            ));
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_driver_status', 'NFC card scan - driver info checked for ' . $courier->full_name);
            wp_send_json_success(array('driver_status' => $result));
        }

        /* "Register in Queue" (Etel Madeya fuel station): a controller
           taps a DRIVER's card to auto-register their plate into that
           station's queue, using whichever plate/vehicle type is
           already on file for that phone number in Etel Madeya's own
           driver_vehicles registry (built the first time a controller
           manually registers that driver with their phone number).
           The controller must type their own phone/Etel-ID + password
           here, same as every other stage - a shared station device
           can be used by different controllers through the day, so
           there is no reliable "who is currently signed in" ambient
           session to read; only an explicit login on THIS tap
           correctly identifies who is doing it. Whether that person is
           actually a real Etel Madeya controller is checked
           server-side on Etel Madeya's own end (against its
           controllers table), not duplicated here. Single round trip,
           no second confirm step. */
        if ('etelmadeya_register' === $stage) {
            $result = self::madeya_service_call('service_nfc_register.php', array(
                'controller_phone' => $confirmer->phone,
                'driver_phone'     => $courier->phone,
                'driver_etel_id'   => $courier->etel_id,
            ));
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            /* Signed-in person isn't a station controller but IS an
               Etel Madeya admin/manager - they have no single station
               of their own, so Etel Madeya asks which one to register
               into. Bridge the same way ajax_charge_confirm() does:
               short-lived single-use action_token, second request
               supplies the chosen station_id. */
            if (!empty($result['needs_station'])) {
                $action_token = wp_generate_password(24, false, false);
                set_transient('etelid_nfc_action_' . $action_token, array(
                    'stage'             => $stage,
                    'courier_etel_id'   => $courier->etel_id,
                    'courier_phone'     => $courier->phone,
                    'confirmer_phone'   => $confirmer->phone,
                ), 5 * MINUTE_IN_SECONDS);

                wp_send_json_success(array(
                    'station_pending' => true,
                    'action_token'    => $action_token,
                    'stations'        => isset($result['stations']) ? $result['stations'] : array(),
                    'driver_name'     => $courier->full_name,
                ));
            }

            EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_etelmadeya_register', 'NFC card scan - registered ' . $courier->full_name . ' into Etel Madeya queue');
            wp_send_json_success(array(
                'madeya_register_done' => true,
                'message' => isset($result['message']) ? $result['message'] : 'Registered in queue.',
            ));
        }

        /* EtelPay tap-to-pay, CARDHOLDER PAYS (classic tap-to-pay: the
           customer taps their own card, the merchant's device collects):
           the tapped card is the PAYER, and the person filling in this
           form (the scanner/merchant) is the RECEIVER. Since it's the
           tapped card owner's money leaving, the scanner's own login
           here only identifies who is collecting - it does NOT move
           any money yet. Money only moves once the cardholder also
           proves their own EtelID password, in
           ajax_cardholder_pay_confirm() below - same "authorize
           whoever's money is leaving" rule as every other flow on this
           card, just with 'etelpay_payout's roles swapped back. */
        if ('etelpay_cardholder_pay' === $stage) {
            $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
            $note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
            if ($amount <= 0) wp_send_json_error(array('message' => 'Enter an amount greater than zero.'));

            $action_token = wp_generate_password(24, false, false);
            set_transient('etelid_nfc_action_' . $action_token, array(
                'stage'             => $stage,
                'amount'            => $amount,
                'note'              => $note,
                'courier_etel_id'   => $courier->etel_id,
                'courier_phone'     => $courier->phone,
                'confirmer_id'      => $confirmer->id,
                'confirmer_etel_id' => $confirmer->etel_id,
                'confirmer_phone'   => $confirmer->phone,
                'confirmer_name'    => $confirmer->full_name,
            ), 5 * MINUTE_IN_SECONDS);

            EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_etelpay_cardholder_pay_start', 'NFC tap-to-pay started against ' . $courier->full_name . ' for ' . $amount . ' ETB');

            wp_send_json_success(array(
                'cardholder_pay_pending' => true,
                'action_token'           => $action_token,
                'payer_name'             => $courier->full_name,
                'amount'                 => $amount,
            ));
        }

        /* EtelPay tap-to-pay PAYOUT (pay OUT, e.g. a driver's earnings,
           or collecting payment when the tapped card belongs to the
           collector): whoever logs in here pays, whoever's card was
           tapped receives - their own login both identifies whose
           wallet pays AND authorizes the debit, same "authorize
           whoever's money is leaving" rule as every login on this
           page. No fixed business/till account, no second confirm
           step - money moves immediately once they log in. */
        if ('etelpay_payout' === $stage) {
            $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
            $note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
            if ($amount <= 0) wp_send_json_error(array('message' => 'Enter an amount greater than zero.'));

            $result = self::etelpay_service_call('service_nfc_charge.php', array(
                'receiver_phone' => $courier->phone,    // cardholder RECEIVES
                'payer_phone'    => $confirmer->phone,  // operator's OWN wallet PAYS
                'amount'         => $amount,
                'note'           => $note,
            ));
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_etelpay_payout', 'NFC tap-to-pay: paid out ' . $amount . ' ETB to ' . $courier->full_name . ', tx ' . (isset($result['tx_ref']) ? $result['tx_ref'] : '?'));

            wp_send_json_success(array(
                'payout_done' => true,
                'message'     => 'Paid ' . $amount . ' ETB to ' . $courier->full_name . '.',
                'tx_ref'      => isset($result['tx_ref']) ? $result['tx_ref'] : null,
            ));
        }

        $orders = self::delivery_service_call('nfc_list_courier_orders', array(
            'stage'              => $stage,
            'courier_etel_id'    => $courier->etel_id,
            'courier_phone'      => $courier->phone,
            'confirmer_etel_id'  => $confirmer->etel_id,
            'confirmer_phone'    => $confirmer->phone,
            'confirmer_name'     => $confirmer->full_name,
        ));
        if (is_wp_error($orders)) {
            wp_send_json_error(array('message' => $orders->get_error_message()));
        }

        $action_token = wp_generate_password(24, false, false);
        set_transient('etelid_nfc_action_' . $action_token, array(
            'stage'             => $stage,
            'courier_etel_id'   => $courier->etel_id,
            'courier_phone'     => $courier->phone,
            'confirmer_etel_id' => $confirmer->etel_id,
            'confirmer_phone'   => $confirmer->phone,
            'confirmer_name'    => $confirmer->full_name,
        ), 5 * MINUTE_IN_SECONDS);

        EtelID_DB::log($confirmer->id, $confirmer->etel_id, 'nfc_' . $stage . '_start', 'NFC card scan - ' . $courier->full_name);

        wp_send_json_success(array(
            'action_token'  => $action_token,
            'courier_name'  => $courier->full_name,
            'orders'        => isset($orders['orders']) ? $orders['orders'] : array(),
        ));
    }

    /* Step 2: confirm one specific order using the action token from
       step 1 - no password resend needed, single-use, 5-minute expiry. */
    public static function ajax_confirm() {
        if (!check_ajax_referer('etelid_public', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired, please reload the page.'));
        }

        $action_token = isset($_POST['action_token']) ? sanitize_text_field(wp_unslash($_POST['action_token'])) : '';
        $order_id     = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$action_token || !$order_id) wp_send_json_error(array('message' => 'Missing required fields.'));

        $key  = 'etelid_nfc_action_' . $action_token;
        $data = get_transient($key);
        if (false === $data) wp_send_json_error(array('message' => 'This confirmation has expired. Please start again.'));
        delete_transient($key); // single-use

        $delivery_action = 'pickup' === $data['stage'] ? 'nfc_confirm_pickup' : 'nfc_confirm_delivery';

        $result = self::delivery_service_call($delivery_action, array(
            'order_id'           => $order_id,
            'courier_etel_id'    => $data['courier_etel_id'],
            'courier_phone'      => $data['courier_phone'],
            'confirmer_etel_id'  => $data['confirmer_etel_id'],
            'confirmer_phone'    => $data['confirmer_phone'],
            'confirmer_name'     => $data['confirmer_name'],
        ));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => isset($result['message']) ? $result['message'] : 'Confirmed.'));
    }

    /* Step 2 of the cardholder-pays flow: the PAYER (whoever's card was
       tapped) types their own phone/Etel-ID + password into this same
       device - verified LOCALLY against their real EtelID pin_hash,
       same as every other login on this page. Only once this passes
       does service_nfc_charge.php get called, so a bare tap alone still
       can never move money. Single-use action_token, same pattern as
       ajax_confirm() above. */
    public static function ajax_cardholder_pay_confirm() {
        if (!check_ajax_referer('etelid_public', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired, please reload the page.'));
        }

        $action_token = isset($_POST['action_token']) ? sanitize_text_field(wp_unslash($_POST['action_token'])) : '';
        $identifier   = isset($_POST['payer_identifier']) ? sanitize_text_field(wp_unslash($_POST['payer_identifier'])) : '';
        $password     = isset($_POST['payer_password']) ? (string) $_POST['payer_password'] : '';
        if (!$action_token || !$identifier || !$password) wp_send_json_error(array('message' => 'Missing required fields.'));

        $key  = 'etelid_nfc_action_' . $action_token;
        $data = get_transient($key);
        if (false === $data || 'etelpay_cardholder_pay' !== $data['stage']) {
            wp_send_json_error(array('message' => 'This payment has expired. Please start again.'));
        }
        delete_transient($key); // single-use - a resubmit needs a fresh tap+login+amount

        $payer = EtelID_DB::get_by_etel_id(strtoupper($identifier));
        if (!$payer) $payer = EtelID_DB::find_by_phone($identifier);
        if (!$payer) wp_send_json_error(array('message' => 'No EtelID account found for that phone/Etel-ID.'));

        /* The identifier typed here must belong to the SAME person whose
           card was tapped - otherwise this field would let anyone
           approve someone else's payment with their own unrelated
           account, defeating the whole point of asking. */
        if (0 !== strcasecmp($payer->etel_id, $data['courier_etel_id'])) {
            wp_send_json_error(array('message' => 'That is not the cardholder\'s account. Enter the tapped card owner\'s own phone/Etel-ID.'));
        }

        if (empty($payer->pin_hash) || !EtelID_Auth::verify_password($password, $payer->pin_hash)) {
            wp_send_json_error(array('message' => 'Incorrect password.'));
        }
        if ('suspended' === $payer->status || 'revoked' === $payer->status) {
            wp_send_json_error(array('message' => 'This EtelID account is not active.'));
        }

        $result = self::etelpay_service_call('service_nfc_charge.php', array(
            'receiver_phone' => $data['confirmer_phone'],
            'payer_phone'    => $data['courier_phone'],
            'amount'         => $data['amount'],
            'note'           => $data['note'],
        ));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        EtelID_DB::log($payer->id, $payer->etel_id, 'nfc_etelpay_cardholder_pay', 'NFC tap-to-pay: paid ' . $data['confirmer_phone'] . ' ' . $data['amount'] . ' ETB, tx ' . (isset($result['tx_ref']) ? $result['tx_ref'] : '?'));

        wp_send_json_success(array(
            'message' => 'Payment of ' . $data['amount'] . ' ETB sent.',
            'tx_ref'  => isset($result['tx_ref']) ? $result['tx_ref'] : null,
            'amount'  => $data['amount'],
        ));
    }

    /* Step 2 of the Etel Madeya admin-registers-a-vehicle flow: the
       admin/manager picked which station to register into. Re-sends
       the same request to Etel Madeya, this time with station_id -
       single-use action_token, same pattern as every other confirm
       step here. */
    public static function ajax_madeya_station_confirm() {
        if (!check_ajax_referer('etelid_public', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired, please reload the page.'));
        }

        $action_token = isset($_POST['action_token']) ? sanitize_text_field(wp_unslash($_POST['action_token'])) : '';
        $stationId    = isset($_POST['station_id']) ? (int) $_POST['station_id'] : 0;
        if (!$action_token || !$stationId) wp_send_json_error(array('message' => 'Missing required fields.'));

        $key  = 'etelid_nfc_action_' . $action_token;
        $data = get_transient($key);
        if (false === $data || 'etelmadeya_register' !== $data['stage']) {
            wp_send_json_error(array('message' => 'This request has expired. Please start again.'));
        }
        delete_transient($key); // single-use - a resubmit needs a fresh tap+login

        $result = self::madeya_service_call('service_nfc_register.php', array(
            'controller_phone' => $data['confirmer_phone'],
            'driver_phone'     => $data['courier_phone'],
            'driver_etel_id'   => $data['courier_etel_id'],
            'station_id'       => $stationId,
        ));
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? $result['message'] : 'Registered in queue.',
        ));
    }

}
