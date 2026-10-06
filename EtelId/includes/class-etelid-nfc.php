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
        add_action('wp_ajax_etelid_nfc_revoke_member', array(__CLASS__, 'ajax_revoke_member'));
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

        /* Reveal gate: the tapped card's photo/name/Etel-ID/tier are no
           longer rendered into the page at all until the scanning
           person proves who THEY are with their own EtelID login -
           the card's PII is not shown to a bare tap. See ajax_reveal()
           below. */
        add_action('wp_ajax_etelid_nfc_reveal', array(__CLASS__, 'ajax_reveal'));
        add_action('wp_ajax_nopriv_etelid_nfc_reveal', array(__CLASS__, 'ajax_reveal'));
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

    public static function issue_card($member_id, $revoke_previous = true) {
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

        /* A new card replaces the old one: every EARLIER active card for
           this member is revoked, so a lost / replaced / test card stops
           working the moment a new one is issued (live data had 21 active
           cards for 4 people). Pass $revoke_previous=false only to issue
           an additional physical card on purpose. */
        if ($revoke_previous) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'revoked' WHERE member_id = %d AND status = 'active' AND token <> %s",
                $member_id,
                $token
            ));
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

    /* Full member row (every column - dob, sex, blood_type, nationality,
       fin, card_number, serial_number, issue/expiry dates, region, zone,
       primary_company, role, ...), not a narrow projection. The public
       NFC card renders templates/card-front.php and card-back.php -
       the exact same templates the member's own portal uses - and both
       read dozens of these fields directly off $member; a stripped-down
       row here previously left most of the printed card blank (visible
       as "-" for DOB/blood/etc.) even though the portal showed the real
       values from the same row via EtelID_DB::get_member(). Delegating
       to that one method keeps both surfaces reading identical data. */
    protected static function get_member($member_id) {
        return EtelID_DB::get_member($member_id);
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
        $keep_old = !empty($_POST['keep_old']);
        $token = self::issue_card($member_id, !$keep_old);

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

    /* Lost-card button: revoke every active card a member holds. */
    public static function revoke_all_for_member($member_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'revoked' WHERE member_id = %d AND status = 'active'",
            (int) $member_id
        ));
    }

    public static function ajax_revoke_member() {
        check_ajax_referer('etelid_auth', 'nonce');

        if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }

        $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
        if ($member_id <= 0) wp_send_json_error(array('message' => 'Invalid member.'), 400);
        $n = self::revoke_all_for_member($member_id);
        wp_send_json_success(array('revoked' => (int) $n));
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

    /* Password check for every NFC step, with a per-ACCOUNT lockout.
       The old limit was per (IP + card token), so a guesser could rotate
       IPs or cards and hammer one account's password; and these accounts
       authorize real payments and payouts. After 8 wrong passwords the
       account is locked for 15 minutes on this endpoint (whatever IP or
       card is used); a correct password clears the count. */
    protected static function verify_member_password($member, $plain) {
        $lock_key = 'nfc_acct_' . strtolower((string) $member->etel_id);
        if (class_exists('EtelID_Security') && EtelID_Security::fail_blocked($lock_key, 8, 900)) {
            if (class_exists('EtelID_DB')) {
                EtelID_DB::log($member->id, $member->etel_id, 'login_rate_limited', 'NFC password attempts blocked - too many wrong passwords');
            }
            wp_send_json_error(array('message' => 'Too many wrong passwords for this account. Please wait 15 minutes, or reset the password.'));
        }
        if (empty($member->pin_hash) || !EtelID_Auth::verify_password($plain, $member->pin_hash)) {
            if (class_exists('EtelID_Security')) EtelID_Security::fail_hit($lock_key, 900);
            return false;
        }
        if (class_exists('EtelID_Security')) EtelID_Security::fail_clear($lock_key);
        return true;
    }

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

    /* Outbound call to Etel Gebeya's shared-service-key API, same shape
       as etelpay_service_call()/madeya_service_call() above - reuses
       the same ETELID_SERVICE_KEY, since Etel Gebeya's own
       api/config.php now defines the matching ETELID_SERVICE_KEY
       constant for exactly this inbound direction. */
    protected static function gebeya_service_call($path, $payload) {
        $key = defined('ETELID_SERVICE_KEY') ? ETELID_SERVICE_KEY : get_option('etelid_service_key', '');
        if (!$key) return new WP_Error('no_key', 'etelid_service_key is not configured.');

        $home_parts = wp_parse_url(home_url());
        $site_root  = (isset($home_parts['scheme']) ? $home_parts['scheme'] : 'https') . '://'
                    . (isset($home_parts['host']) ? $home_parts['host'] : $_SERVER['HTTP_HOST']);
        $base = get_option('etelid_gebeya_api_base', $site_root . '/EtelGebeya');
        $url  = trailingslashit($base) . 'api/' . $path;

        $resp = wp_remote_post($url, array(
            'timeout' => 12,
            'headers' => array('X-Etel-Service-Key' => $key, 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) return new WP_Error('bad_response', 'Etel Gebeya returned an unreadable response.');
        if ($code < 200 || $code >= 300 || empty($body['ok'])) {
            $msg = !empty($body['error']) ? $body['error'] : ('Etel Gebeya request failed (HTTP ' . $code . ').');
            return new WP_Error('gebeya_error', $msg);
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

        $allowedStages = array('pickup', 'delivery', 'ride_start', 'driver_status', 'etelpay_payout', 'etelpay_cardholder_pay', 'etelmadeya_register', 'gebeya_delivery');
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

        if (!self::verify_member_password($confirmer, $pass)) {
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

        /* "Confirm Gebeya Order" (Etel Gebeya marketplace deliveries):
           same generic list-then-confirm shape as pickup/delivery
           above, just routed to Etel Gebeya's own service instead of
           Delivery's - the tapped card's owner is the courier whose
           still-open deliveries get listed. */
        if ('gebeya_delivery' === $stage) {
            $orders = self::gebeya_service_call('service_nfc_order_list.php', array(
                'courier_etel_id'   => $courier->etel_id,
                'courier_phone'     => $courier->phone,
                'confirmer_etel_id' => $confirmer->etel_id,
                'confirmer_phone'   => $confirmer->phone,
                'confirmer_name'    => $confirmer->full_name,
            ));
        } else {
            $orders = self::delivery_service_call('nfc_list_courier_orders', array(
                'stage'              => $stage,
                'courier_etel_id'    => $courier->etel_id,
                'courier_phone'      => $courier->phone,
                'confirmer_etel_id'  => $confirmer->etel_id,
                'confirmer_phone'    => $confirmer->phone,
                'confirmer_name'     => $confirmer->full_name,
            ));
        }
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

        if ('gebeya_delivery' === $data['stage']) {
            $result = self::gebeya_service_call('service_nfc_order_confirm.php', array(
                'order_vendor_id'   => $order_id,
                'courier_phone'     => $data['courier_phone'],
                'confirmer_name'    => $data['confirmer_name'],
            ));
        } else {
            $delivery_action = 'pickup' === $data['stage'] ? 'nfc_confirm_pickup' : 'nfc_confirm_delivery';

            $result = self::delivery_service_call($delivery_action, array(
                'order_id'           => $order_id,
                'courier_etel_id'    => $data['courier_etel_id'],
                'courier_phone'      => $data['courier_phone'],
                'confirmer_etel_id'  => $data['confirmer_etel_id'],
                'confirmer_phone'    => $data['confirmer_phone'],
                'confirmer_name'     => $data['confirmer_name'],
            ));
        }

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

        if (!self::verify_member_password($payer, $password)) {
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

    /* Reveal gate: verifies the SCANNING person's own EtelID login
       (same identity that then also authorizes whichever action they
       pick next - the frontend keeps this identifier/password in
       memory and reuses it for the rest of this page visit, so this
       is the only login prompt for a normal visit) and, only once
       that succeeds, returns the tapped card's public display fields.
       An invalid token still fails before this (see resolve_token()
       above), so a dead/revoked card never even offers a login form. */
    public static function ajax_reveal() {
        if (!check_ajax_referer('etelid_public', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired, please reload the page.'));
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $ident = isset($_POST['identifier']) ? sanitize_text_field(wp_unslash($_POST['identifier'])) : '';
        $pass  = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if (!$token || !$ident || !$pass) {
            wp_send_json_error(array('message' => 'Missing required fields.'));
        }

        $resolved = self::resolve_token($token);
        if (!$resolved) wp_send_json_error(array('message' => 'This card is not active.'));
        $courier = $resolved['member'];

        $ip = class_exists('EtelID_Security') ? EtelID_Security::get_ip() : '';
        if (class_exists('EtelID_Security') && !EtelID_Security::rate_limit('nfc_reveal_' . md5($ip . $token), 15, 900)) {
            wp_send_json_error(array('message' => 'Too many attempts. Please wait a few minutes.'));
        }

        $confirmer = EtelID_DB::get_by_etel_id(strtoupper($ident));
        if (!$confirmer) $confirmer = EtelID_DB::find_by_phone($ident);
        if (!$confirmer) wp_send_json_error(array('message' => 'No EtelID account found for that phone/Etel-ID.'));

        if (!self::verify_member_password($confirmer, $pass)) {
            wp_send_json_error(array('message' => 'Incorrect password.'));
        }
        if ('suspended' === $confirmer->status || 'revoked' === $confirmer->status) {
            wp_send_json_error(array('message' => 'This EtelID account is not active.'));
        }

        /* Full front/back card - reuses the exact same premium card
           templates the member's own portal renders (templates/card-
           front.php, templates/card-back.php), so the NFC page shows
           the real card, not a stripped-down summary. These templates
           read $member (and $companies for the back's role chips) from
           the including scope, which is why they're set as locals
           right before include() rather than passed as arguments. */
        $card_front_html = '';
        $card_back_html  = '';
        if (file_exists(ETELID_PATH . 'templates/card-front.php') && file_exists(ETELID_PATH . 'templates/card-back.php')) {
            $member    = $courier;
            $companies = class_exists('EtelID_DB') ? EtelID_DB::get_companies($courier->id) : array();

            ob_start();
            include ETELID_PATH . 'templates/card-front.php';
            $card_front_html = ob_get_clean();

            ob_start();
            include ETELID_PATH . 'templates/card-back.php';
            $card_back_html = ob_get_clean();
        }

        wp_send_json_success(array(
            'name'            => $courier->full_name,
            'etel_id'         => $courier->etel_id,
            'tier'            => $courier->tier,
            'photo_url'       => !empty($courier->photo_url) ? $courier->photo_url : '',
            'capabilities'    => self::scanner_capabilities($confirmer),
            'card_front_html' => $card_front_html,
            'card_back_html'  => $card_back_html,
            'links'           => class_exists('EtelID_Links') ? EtelID_Links::public_payload_for_member($courier->id) : array(),
        ));
    }

    /* Which of the 8 card-service buttons this SCANNING person (not
       the tapped card's owner) can actually use, so the page can show
       only the ones relevant to them instead of all 8 to everyone.
       pickup/delivery and both EtelPay stages stay universally
       visible - they have no real "role" of their own (a pickup
       service giver or a paying customer is not an assigned staff
       role, and every EtelID member already has an EtelPay wallet),
       and were already self-filtering via "no matching orders found"
       / a failed charge. The other four map to an actual role that
       can be checked right now: Traffic Officer and Driver are
       assigned in EtelID's own cross-company Employees panel
       (etelid_companies), while Etel Madeya controllers/admins and
       Etel Gebeya couriers live only in THEIR OWN databases, so a
       small live lookup call is needed for those two - same
       service-key bridge pattern as every other cross-system call on
       this card. A lookup failure hides that button rather than
       failing the whole reveal - one product being briefly
       unreachable should never block signing in to view the card. */
    protected static function scanner_capabilities($confirmer) {
        /* Etel Group admin/owner - assigned through the existing Company
           Roles admin tool (role_at 'Admin' or 'Owner' at company_slug
           'etel-group'), NOT EtelID_Auth::is_site_admin() - that check
           depends on a WP option (etelid_admin_member_id) or a matching
           admin_email that may not be configured for every real admin,
           and this page has no WP session to fall back on either way.
           A role in the Employees panel is something any current admin
           can already grant to a new admin themselves, with no code
           change and no dependency on WordPress-level settings. Sees and
           can use every button - not individually a Delivery driver,
           Traffic Officer, Madeya controller or Gebeya courier, but
           administering all of these companies is exactly what this role
           is for. Short-circuits before the two live Madeya/Gebeya
           lookups below, so an admin login is not slowed down by checks
           it does not need. */
        if (self::has_company_role($confirmer->phone, 'etel-group', 'Admin')
            || self::has_company_role($confirmer->phone, 'etel-group', 'Owner')) {
            return array(
                'pickup'                 => true,
                'delivery'                => true,
                'ride_start'              => true,
                'driver_status'           => true,
                'etelpay_payout'          => true,
                'etelpay_cardholder_pay'  => true,
                'etelmadeya_register'     => true,
                'gebeya_delivery'         => true,
            );
        }

        $caps = array(
            'pickup'                 => true,
            'delivery'                => true,
            'ride_start'              => self::has_company_role($confirmer->phone, 'etel-delivery', 'Driver'),
            'driver_status'           => self::has_company_role($confirmer->phone, 'etel-delivery', 'Traffic Officer'),
            'etelpay_payout'          => true,
            'etelpay_cardholder_pay'  => true,
            'etelmadeya_register'     => false,
            'gebeya_delivery'         => false,
        );

        $madeya = self::madeya_service_call('service_nfc_check_role.php', array('phone' => $confirmer->phone));
        if (!is_wp_error($madeya)) {
            $caps['etelmadeya_register'] = !empty($madeya['is_eligible']);
        }

        $gebeya = self::gebeya_service_call('service_nfc_check_courier.php', array('phone' => $confirmer->phone));
        if (!is_wp_error($gebeya)) {
            $caps['gebeya_delivery'] = !empty($gebeya['is_courier']);
        }

        return $caps;
    }

}
