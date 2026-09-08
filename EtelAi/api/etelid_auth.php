<?php
// ============================================================
// ETEL ID Shared Auth Client v1.0
// Drop this file into every Etel project root.
//
// SETUP (at the top of your project's config or API entry):
//   require_once __DIR__ . '/etelid_auth.php';
//   EtelID::configure('https://id.etelgroup.et', 'YOUR_ETELID_API_KEY');
//
// USAGE:
//   Login:         EtelID::login($phone, $password)
//   Protect route: $member = EtelID::require_auth();
//   Get member:    EtelID::current_member()
//   Logout:        EtelID::logout()
// ============================================================

class EtelID {

    private static $url = '';  // Etel-ID WordPress root URL
    private static $key = '';  // X-Etel-Key secret (same as in etel-os.php)
    private static $project = 'unknown';

    public static function configure($url, $key, $project = '') {
        self::$url     = rtrim($url, '/');
        self::$key     = $key;
        self::$project = $project ?: basename(dirname(__FILE__));
    }

    // ── Login ──────────────────────────────────────────────────
    // Returns ['ok'=>true, 'data'=>['member'=>[...], 'token'=>'...', 'expires_in'=>...]]
    // or      ['ok'=>false, 'error'=>'...']
    public static function login($phone, $password) {
        $r = self::post('login', [
            'phone'    => $phone,
            'password' => $password,
            'project'  => self::$project,
        ]);
        if ($r && $r['ok']) {
            self::set_session($r['data']['member'], $r['data']['token']);
        }
        return $r;
    }

    // ── Verify a token (called server-side by other projects) ──
    public static function verify_token($token) {
        return self::post('verify_token', ['token' => $token]);
    }

    // ── Verify a FIN or QR card number ────────────────────────
    public static function verify_fin($fin = null, $card = null) {
        $p = ['action' => 'verify'];
        if ($fin)  $p['fin']  = $fin;
        if ($card) $p['card'] = $card;
        return self::get($p);
    }

    // ── Middleware — protects an API route ────────────────────
    // Usage: $member = EtelID::require_auth();
    // Checks X-Etel-Token header (API) or session (web), dies 401 if missing.
    public static function require_auth() {
        // 1. Bearer token in header (for mobile/API clients)
        $token = self::bearer_token();
        if ($token) {
            $r = self::verify_token($token);
            if ($r && $r['ok']) return $r['data']['member'];
            self::deny('Invalid or expired Etel-ID token');
        }

        // 2. Session (for browser-based web apps)
        $member = self::current_member();
        if ($member) return $member;

        self::deny('Etel-ID authentication required');
    }

    // ── Session helpers ───────────────────────────────────────
    public static function current_member() {
        self::start_session();
        if (empty($_SESSION['etelid_member'])) return null;
        // Expire after 8 hours of inactivity
        if (time() - ($_SESSION['etelid_ts'] ?? 0) > 28800) {
            self::logout();
            return null;
        }
        return $_SESSION['etelid_member'];
    }

    public static function current_token() {
        self::start_session();
        return $_SESSION['etelid_token'] ?? null;
    }

    public static function logout() {
        $token = self::current_token() ?? self::bearer_token();
        if ($token) {
            self::post('logout', ['token' => $token]);
        }
        self::start_session();
        unset($_SESSION['etelid_member'], $_SESSION['etelid_token'], $_SESSION['etelid_ts']);
    }

    public static function is_logged_in() {
        return self::current_member() !== null;
    }

    // ── Private helpers ───────────────────────────────────────

    private static function set_session($member, $token) {
        self::start_session();
        $_SESSION['etelid_member'] = $member;
        $_SESSION['etelid_token']  = $token;
        $_SESSION['etelid_ts']     = time();
    }

    private static function start_session() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_name('etelid_session');
            session_start();
        }
    }

    private static function bearer_token() {
        $h = $_SERVER['HTTP_X_ETEL_TOKEN']     ?? '';
        if (!$h) $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$h) return null;
        return preg_replace('/^Bearer\s+/i', '', trim($h)) ?: null;
    }

    private static function deny($msg) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode([
            'ok'                    => false,
            'error'                 => $msg,
            'etelid_login_required' => true,
            'etelid_url'            => self::$url . '/etel-id/login/',
        ]));
    }

    private static function endpoint() {
        return self::$url . '/wp-content/plugins/etel-id-v3/etel-os.php';
    }

    private static function post($action, $body) {
        if (!self::$url) return ['ok' => false, 'error' => 'EtelID not configured'];
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Etel-Key: " . self::$key . "\r\n",
            'content'       => json_encode($body + ['action' => $action]),
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents(self::endpoint() . '?action=' . $action, false, $ctx);
        return $r ? json_decode($r, true) : ['ok' => false, 'error' => 'Etel-ID unreachable'];
    }

    private static function get($params) {
        if (!self::$url) return ['ok' => false, 'error' => 'EtelID not configured'];
        $params['key'] = self::$key;
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "X-Etel-Key: " . self::$key . "\r\n",
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents(self::endpoint() . '?' . http_build_query($params), false, $ctx);
        return $r ? json_decode($r, true) : ['ok' => false, 'error' => 'Etel-ID unreachable'];
    }
}
