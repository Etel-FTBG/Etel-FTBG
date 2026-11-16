<?php
/**
 * Etel Code - member-chosen short login code (vanity ID), e.g. E1616.
 *
 * NEW standalone file. PHP 5.6 compatible, pure ASCII.
 * Does not modify any existing EtelID file. See README.md for the
 * 3 small wiring steps (require line, login hook, portal UI).
 */
if (!defined('ABSPATH')) { exit; }

class Etelid_Code {

    const MIN_LEN        = 4;      // E + 3 chars
    const MAX_LEN        = 12;
    const CHANGE_DAYS    = 30;     // member may change once per 30 days
    const HOLD_DAYS      = 90;     // released codes stay blocked (anti-impersonation)

    /* ---------- table ---------- */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'etelid_codes';
    }

    public static function members_table() {
        global $wpdb;
        return $wpdb->prefix . 'etelid_members';
    }

    /** Create table (call from plugin activation OR lazily via ensure_table()). */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        // code_norm is the canonical uppercase form; UNIQUE guarantees "until it is taken".
        // member_id is NOT unique: old codes stay as 'released' rows for the hold period.
        $sql = "CREATE TABLE $t (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT(20) UNSIGNED NOT NULL,
            code_norm VARCHAR(16) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            released_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_norm (code_norm),
            KEY member_status (member_id, status)
        ) $charset;";
        dbDelta($sql);
        update_option('etelid_code_db_ver', '1');
    }

    public static function ensure_table() {
        if (get_option('etelid_code_db_ver') !== '1') { self::install(); }
    }

    /* ---------- validation ---------- */

    public static function normalize($raw) {
        $c = strtoupper(trim((string)$raw));
        $c = preg_replace('/[\s\-_]+/', '', $c);
        return $c;
    }

    private static function reserved() {
        return array(
            'ETEL','ETELID','ETELPAY','ETELGROUP','ETELTAXI','ETELGEBEYA','ETELMADEYA',
            'ETELAI','ETELMEDIA','ETELMARKET','ADMIN','ADMINISTRATOR','ROOT','SUPPORT',
            'HELP','SYSTEM','OWNER','STAFF','TEST','NULL','CEO','EG0000','E0000'
        );
    }

    /**
     * Returns '' if the format is acceptable, otherwise a human message.
     * Rules: starts with E, then 3-11 letters/digits, A-Z 0-9 only.
     */
    public static function validate_format($code) {
        $len = strlen($code);
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return 'Code must be ' . self::MIN_LEN . ' to ' . self::MAX_LEN . ' characters (example: E1616).';
        }
        if (!preg_match('/^E[A-Z0-9]+$/', $code)) {
            return 'Code must start with E and use only letters and numbers.';
        }
        if (in_array($code, self::reserved(), true)) {
            return 'That code is reserved.';
        }
        // Must not collide with the long ID format (EG-2026-00001 normalises to EG202600001).
        if (preg_match('/^EG\d{6,}$/', $code)) {
            return 'That code looks like a system Etel ID. Choose another.';
        }
        // Must not look like a phone number (E + 9 digits, e.g. E912345678).
        if (preg_match('/^E\d{9,}$/', $code)) {
            return 'Code cannot look like a phone number.';
        }
        // Basic profanity / abuse hook - sites can extend via filter.
        $blocked = apply_filters('etelid_code_blocked_words', array());
        foreach ($blocked as $w) {
            if ($w !== '' && strpos($code, strtoupper($w)) !== false) {
                return 'That code is not allowed.';
            }
        }
        return '';
    }

    /* ---------- availability ---------- */

    /** @return array(bool available, string message) */
    public static function check($raw, $for_member_id = 0) {
        global $wpdb;
        self::ensure_table();
        $code = self::normalize($raw);
        $err  = self::validate_format($code);
        if ($err !== '') { return array(false, $err); }

        $t   = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT member_id, status, released_at FROM $t WHERE code_norm = %s", $code));
        if (!$row) { return array(true, 'Available'); }

        if ((int)$row->member_id === (int)$for_member_id && $row->status === 'active') {
            return array(false, 'This is already your code.');
        }
        if ($row->status === 'released') {
            // Held for HOLD_DAYS after release; the previous owner may take it back.
            $until = strtotime($row->released_at . ' UTC') + self::HOLD_DAYS * 86400;
            if ((int)$row->member_id === (int)$for_member_id) { return array(true, 'Available (your old code)'); }
            if (time() < $until) { return array(false, 'Taken'); }
            return array(true, 'Available');
        }
        return array(false, 'Taken');
    }

    /** A few alternatives to show when a code is taken. */
    public static function suggest($raw, $n = 4) {
        $base = self::normalize($raw);
        $out  = array();
        $tries = 0;
        while (count($out) < $n && $tries < 40) {
            $tries++;
            $cand = substr($base, 0, self::MAX_LEN - 2) . rand(10, 99);
            $res  = self::check($cand, 0);
            if ($res[0] && !in_array($cand, $out, true)) { $out[] = $cand; }
        }
        return $out;
    }

    /* ---------- claim / change ---------- */

    public static function current_for_member($member_id) {
        global $wpdb;
        self::ensure_table();
        $t = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT code_norm, created_at FROM $t WHERE member_id = %d AND status = 'active' LIMIT 1",
            (int)$member_id));
    }

    /**
     * Claim or change a member's code. Caller MUST have already authenticated the
     * member (session + PIN re-check) - this method does not authenticate.
     * @return array(bool ok, string message, string code)
     */
    public static function claim($member_id, $raw) {
        global $wpdb;
        self::ensure_table();
        $member_id = (int)$member_id;
        $code = self::normalize($raw);

        $m = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM " . self::members_table() . " WHERE id = %d", $member_id));
        if ($m !== 'active') { return array(false, 'Account is not active.', ''); }

        $chk = self::check($code, $member_id);
        if (!$chk[0]) { return array(false, $chk[1], ''); }

        $t   = self::table();
        $now = gmdate('Y-m-d H:i:s');
        $cur = self::current_for_member($member_id);
        if ($cur) {
            $next = strtotime($cur->created_at . ' UTC') + self::CHANGE_DAYS * 86400;
            if (time() < $next) {
                return array(false, 'You can change your code again on ' . gmdate('Y-m-d', $next) . '.', '');
            }
        }

        // Transaction-safe against two people grabbing the same code at once:
        // the UNIQUE key makes the second insert fail.
        $wpdb->query('START TRANSACTION');
        if ($cur) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $t SET status='released', released_at=%s WHERE member_id=%d AND status='active'",
                $now, $member_id));
        }
        // If this member is re-taking their own previously released code, reuse that row.
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE code_norm=%s AND member_id=%d AND status='released'",
            $code, $member_id));
        // Expired holds from other members are cleared so the UNIQUE key does not block.
        $cut = gmdate('Y-m-d H:i:s', time() - self::HOLD_DAYS * 86400);
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE code_norm=%s AND status='released' AND released_at < %s",
            $code, $cut));

        $suppress = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($t, array(
            'member_id'  => $member_id,
            'code_norm'  => $code,
            'status'     => 'active',
            'created_at' => $now,
        ), array('%d', '%s', '%s', '%s'));
        $wpdb->suppress_errors($suppress);

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return array(false, 'Taken', '');
        }
        $wpdb->query('COMMIT');
        self::log($member_id, $cur ? 'code_changed' : 'code_claimed', $code);
        return array(true, 'Your Etel Code is ' . $code, $code);
    }

    /* ---------- resolve (used by login + sibling systems) ---------- */

    /** Return member row (object) for an Etel Code, or null. Active codes only. */
    public static function member_by_code($raw) {
        global $wpdb;
        self::ensure_table();
        $code = self::normalize($raw);
        if (self::validate_format($code) !== '') { return null; }
        $t = self::table();
        $mt = self::members_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT m.* FROM $t c INNER JOIN $mt m ON m.id = c.member_id
             WHERE c.code_norm = %s AND c.status = 'active' LIMIT 1", $code));
    }

    /**
     * Universal identifier resolver: Etel Code, long Etel ID, or phone.
     * Returns member row or null. Existing login code can call this FIRST and
     * fall back to its current lookup if it returns null.
     */
    public static function resolve($input) {
        global $wpdb;
        $in = trim((string)$input);
        if ($in === '') { return null; }
        $mt = self::members_table();

        $by_code = self::member_by_code($in);
        if ($by_code) { return $by_code; }

        // Long Etel ID (EG-2026-00001)
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $mt WHERE etel_id = %s LIMIT 1", strtoupper($in)));
        if ($row) { return $row; }

        // Phone: match last 9 digits (same rule as find_by_phone()).
        $digits = preg_replace('/\D+/', '', $in);
        if (strlen($digits) >= 9) {
            $last9 = substr($digits, -9);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $mt WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = %s", $last9));
            if (count($rows) === 1) { return $rows[0]; }
            if (count($rows) > 1) {
                // duplicates exist (known data issue): prefer one with email / telegram
                foreach ($rows as $r) { if ($r->email !== '' || $r->tg_chat_id !== '') { return $r; } }
                return $rows[0];
            }
        }
        return null;
    }

    private static function log($member_id, $event, $detail) {
        global $wpdb;
        $lt = $wpdb->prefix . 'etelid_log';
        // Columns verified against live schema: member_id, etel_id, event, actor_id,
        // actor_ip, actor_ua, detail, created_at. A log failure must never break the flow.
        $suppress = $wpdb->suppress_errors(true);
        $eid = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT etel_id FROM " . self::members_table() . " WHERE id = %d", (int)$member_id));
        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        $wpdb->insert($lt, array(
            'member_id'  => (int)$member_id,
            'etel_id'    => $eid,
            'event'      => $event,
            'actor_id'   => (int)$member_id,
            'actor_ip'   => $ip,
            'actor_ua'   => $ua,
            'detail'     => $detail,
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'));
        $wpdb->suppress_errors($suppress);
    }

    /* ---------- REST: service-key protected, for sibling systems ---------- */

    public static function register_routes() {
        register_rest_route('etelid/v1', '/code/resolve', array(
            'methods'             => 'GET,POST',
            'callback'            => array(__CLASS__, 'rest_resolve'),
            'permission_callback' => array(__CLASS__, 'rest_service_auth'),
        ));
        register_rest_route('etelid/v1', '/code/me', array(
            'methods'             => 'GET,POST',
            'callback'            => array(__CLASS__, 'rest_me'),
            'permission_callback' => array(__CLASS__, 'rest_member_auth'),
        ));
        register_rest_route('etelid/v1', '/code/claim', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_claim'),
            'permission_callback' => array(__CLASS__, 'rest_member_auth'),
        ));
        register_rest_route('etelid/v1', '/code/check', array(
            'methods'             => 'GET,POST',
            'callback'            => array(__CLASS__, 'rest_check'),
            'permission_callback' => '__return_true', // availability only, rate limited below
        ));
    }

    /* ---------- member-facing (portal) ---------- */

    /**
     * Who is logged in? Order: (1) site filter 'etelid_code_current_member_id'
     * (wire this to the portal's own session check if it differs), (2) token from
     * header X-Etelid-Token / Authorization: Bearer / param 'token' / known cookies,
     * matched against {prefix}etelid_sessions (token, expires_at).
     */
    public static function current_member_id($request = null) {
        global $wpdb;
        $id = (int)apply_filters('etelid_code_current_member_id', 0);
        if ($id > 0) { return $id; }

        $tok = '';
        if ($request) {
            $tok = (string)$request->get_header('x-etelid-token');
            if ($tok === '') {
                $a = (string)$request->get_header('authorization');
                if (stripos($a, 'bearer ') === 0) { $tok = trim(substr($a, 7)); }
            }
            if ($tok === '') { $tok = (string)$request->get_param('token'); }
        }
        if ($tok === '') {
            $names = apply_filters('etelid_code_cookie_names',
                array('etelid_token', 'etelid_session', 'etelid_sess', 'etelid_sid'));
            foreach ($names as $n) { if (!empty($_COOKIE[$n])) { $tok = (string)$_COOKIE[$n]; break; } }
        }
        if ($tok === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $tok)) { return 0; }

        // Sessions store a SHA-256 hash of the token, so let EtelID's own check resolve it.
        if (class_exists('EtelID_Auth') && method_exists('EtelID_Auth', 'member_from_token')) {
            $mem = EtelID_Auth::member_from_token($tok);
            return ($mem && !empty($mem->id)) ? (int)$mem->id : 0;
        }

        $st = $wpdb->prefix . 'etelid_sessions';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT member_id FROM $st WHERE token = %s AND expires_at > %s LIMIT 1",
            $tok, current_time('mysql')));
        return $row ? (int)$row->member_id : 0;
    }

    private static function pin_ok($member_id, $pin) {
        global $wpdb;
        $hash = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT pin_hash FROM " . self::members_table() . " WHERE id = %d", (int)$member_id));
        if ($hash === '' || (string)$pin === '') { return false; }
        if (class_exists('EtelID_Auth') && method_exists('EtelID_Auth', 'verify_password')) {
            return (bool)EtelID_Auth::verify_password((string)$pin, $hash);
        }
        if (function_exists('password_verify') && password_verify((string)$pin, $hash)) { return true; }
        return function_exists('wp_check_password') ? (bool)wp_check_password((string)$pin, $hash) : false;
    }

    public static function rest_member_auth($request) {
        return self::current_member_id($request) > 0;
    }

    public static function rest_me($request) {
        $mid = self::current_member_id($request);
        $c = self::current_for_member($mid);
        $next = '';
        if ($c) { $next = gmdate('Y-m-d', strtotime($c->created_at . ' UTC') + self::CHANGE_DAYS * 86400); }
        return rest_ensure_response(array(
            'success' => true,
            'code'    => $c ? $c->code_norm : '',
            'can_change_after' => ($c && time() < strtotime($next . ' UTC')) ? $next : '',
        ));
    }

    public static function rest_claim($request) {
        $mid = self::current_member_id($request);
        // Throttle password guessing: 5 attempts / 10 min per member.
        $key = 'etelid_rl_' . md5('codeclaim' . $mid);
        $n = (int)get_transient($key);
        if ($n >= 5) { return rest_ensure_response(array('success' => false, 'message' => 'Too many attempts. Try again in 10 minutes.')); }
        set_transient($key, $n + 1, 600);

        if (!self::pin_ok($mid, $request->get_param('pin'))) {
            return rest_ensure_response(array('success' => false, 'message' => 'Wrong password. Use your EtelID password.'));
        }
        $res = self::claim($mid, $request->get_param('code'));
        $out = array('success' => $res[0], 'message' => $res[1], 'code' => $res[2]);
        if (!$res[0] && $res[1] === 'Taken') { $out['suggestions'] = self::suggest($request->get_param('code')); }
        return rest_ensure_response($out);
    }

    /** [etel_code] shortcode / drop-in box for the portal. */
    public static function render_box() {
        $base = esc_url_raw(rest_url('etelid/v1/code/'));
        $js   = plugins_url('assets/etel-code-widget.js', dirname(__FILE__));
        return '<div id="etel-code-box" data-api="' . esc_attr($base) . '"></div>'
             . '<script src="' . esc_url($js) . '?v=1.0"></script>';
    }

    public static function rest_service_auth($request) {
        if (!defined('ETELID_SERVICE_KEY')) { return false; }
        $sent = $request->get_header('x-etel-service-key');
        if (!$sent) { $sent = $request->get_param('key'); } // FastCGI header-strip fallback
        return is_string($sent) && $sent !== '' && hash_equals((string)ETELID_SERVICE_KEY, $sent);
    }

    public static function rest_resolve($request) {
        $m = self::resolve($request->get_param('identifier'));
        if (!$m) { return rest_ensure_response(array('success' => false, 'error' => 'not_found')); }
        return rest_ensure_response(array('success' => true, 'member' => array(
            'id' => (int)$m->id, 'etel_id' => $m->etel_id, 'full_name' => $m->full_name,
            'phone' => $m->phone, 'status' => $m->status,
            'code' => ($c = self::current_for_member($m->id)) ? $c->code_norm : '',
        )));
    }

    public static function rest_check($request) {
        // Simple per-IP throttle so the endpoint cannot be used to enumerate all codes quickly.
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
        $key = 'etelid_rl_' . md5('codecheck' . $ip);
        $n   = (int)get_transient($key);
        if ($n >= 30) { return rest_ensure_response(array('available' => false, 'message' => 'Too many checks, wait a minute.')); }
        set_transient($key, $n + 1, 60);

        $res = self::check($request->get_param('code'), 0);
        $out = array('available' => $res[0], 'message' => $res[1]);
        if (!$res[0] && $res[1] === 'Taken') { $out['suggestions'] = self::suggest($request->get_param('code')); }
        return rest_ensure_response($out);
    }
}

add_action('rest_api_init', array('Etelid_Code', 'register_routes'));
add_shortcode('etel_code', array('Etelid_Code', 'render_box'));

/**
 * Auto-load on the front end so the "Etel Code" tile appears in the portal grid
 * without editing templates/portal.php. The script does nothing unless it finds
 * the portal's "Change Password" tile (or an #etel-code-box element).
 */
function etelid_code_footer_loader() {
    if (is_admin()) { return; }
    $js = plugins_url('assets/etel-code-widget.js', dirname(__FILE__));
    echo '<script>window.ETEL_CODE_API=' . wp_json_encode(esc_url_raw(rest_url('etelid/v1/code/'))) . ';</script>';
    echo '<script src="' . esc_url($js) . '?v=2.1" defer></script>';
}
add_action('wp_footer', 'etelid_code_footer_loader', 99);
