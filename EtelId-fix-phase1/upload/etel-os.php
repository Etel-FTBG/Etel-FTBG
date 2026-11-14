<?php
// ============================================================
// ETEL OS Connector - Etel-ID Premium v3.9.0
// WordPress Plugin - Central Identity System
//
// Deploy as: wp-content/plugins/etel-id-v3/etel-os.php
// Access at: https://yourdomain.com/wp-content/plugins/etel-id-v3/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// REDACTED for the git repo - set ETEL_API_KEY in the server environment
// (must match API_KEY in etel-api.php on this same deploy).
define('ETEL_API_KEY', getenv('ETEL_API_KEY') ?: 'CHANGE_ME');
define('PROJECT_ID',      'etel-id');
define('PROJECT_VERSION', '3.9.0');

// Bootstrap WordPress to use $wpdb and WP functions
$wp_root = dirname(__FILE__, 5); // plugins/etel-id-v3/etel-os.php - wp-root
if (file_exists($wp_root . '/wp-load.php')) {
    define('SHORTINIT', true); // load WP core but skip themes/plugins
    require_once $wp_root . '/wp-load.php';
} else {
    err('WordPress not found', 500);
}

global $wpdb;
$pfx = $wpdb->prefix;

function ok($d)  { echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }
function auth()  {
    // HARDENING: fail closed if the key was never actually configured -
    // 'CHANGE_ME' is the documented placeholder default (see the define()
    // above), so treating it as a valid, usable secret would silently
    // open every auth()-gated action below to the public on any deploy
    // that forgot to set ETEL_API_KEY. Also switched the comparison
    // itself to hash_equals() - the previous !== check is not
    // constant-time, unlike every other secret comparison in this
    // codebase (etel-api.php, the EtelID Service API, etc.).
    if (!defined('ETEL_API_KEY') || ETEL_API_KEY === '' || ETEL_API_KEY === 'CHANGE_ME') {
        err('Service key not configured on the server.', 503);
    }
    $k = $_SERVER['HTTP_X_ETEL_KEY'] ?? $_GET['key'] ?? '';
    if (!hash_equals(ETEL_API_KEY, (string) $k)) err('Unauthorized', 401);
}

// Same shape as etel-api.php's rate_limited() - this file bootstraps
// WordPress with SHORTINIT, which skips loading plugins (so the
// EtelID_Security/transient-backed rate limiter isn't available here),
// so it's reimplemented directly against etelid_log the same way
// etel-api.php's standalone gateway already does.
function os_rate_limited($wpdb, $pfx, $column, $value, $max, $windowSeconds) {
    if ($value === '' || $value === null) return false;
    $stmt = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$pfx}etelid_log WHERE event = 'login_failed' AND $column = %s AND created_at > (NOW() - INTERVAL %d SECOND)",
        $value, $windowSeconds
    );
    return (int) $wpdb->get_var($stmt) >= $max;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // - Public -
    case 'status':
        ok([
            'project' => 'Etel-ID',
            'id'      => PROJECT_ID,
            'version' => PROJECT_VERSION,
            'status'  => 'online',
            'time'    => date('Y-m-d H:i:s'),
        ]);

    // - Stats - dashboard card -
    case 'stats':
        auth();
        ok([
            'total_members'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE status='active'"),
            'new_today'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE DATE(created_at)=CURDATE()"),
            'active_sessions' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_sessions WHERE expires_at > NOW()"),
            'ids_issued'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE etel_id IS NOT NULL"),
        ]);

    // - Members list -
    case 'get_members':
        auth();
        $limit  = (int)($_GET['limit'] ?? 20);
        $search = $body['search'] ?? $_GET['q'] ?? '';
        $sql    = "SELECT id, etel_id, fin, full_name, phone, status, created_at
                   FROM {$pfx}etelid_members";
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= $wpdb->prepare(" WHERE full_name LIKE %s OR phone LIKE %s OR etel_id LIKE %s OR fin LIKE %s", $like, $like, $like, $like);
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
        ok($wpdb->get_results($sql, ARRAY_A));

    // - Single member by FIN or Etel-ID -
    case 'get_member':
        auth();
        $fin    = $body['fin']     ?? $_GET['fin']     ?? '';
        $eid    = $body['etel_id'] ?? $_GET['etel_id'] ?? '';
        $phone  = $body['phone']   ?? $_GET['phone']   ?? '';
        if ($fin) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}etelid_members WHERE fin=%s LIMIT 1", $fin), ARRAY_A);
        } elseif ($eid) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}etelid_members WHERE etel_id=%s LIMIT 1", $eid), ARRAY_A);
        } elseif ($phone) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pfx}etelid_members WHERE phone=%s LIMIT 1", $phone), ARRAY_A);
        } else {
            err('Provide fin, etel_id, or phone');
        }
        if (!$row) err('Member not found', 404);
        // Attach company links
        $row['companies'] = $wpdb->get_results($wpdb->prepare(
            "SELECT company_slug, company_name, role_at AS role FROM {$pfx}etelid_companies WHERE member_id=%d", $row['id']
        ), ARRAY_A);
        ok($row);

    // - Login - cross-project auth entry point -
    case 'login':
        err('This sign-in endpoint is retired. Use EtelID SSO.', 410);

    // - Verify session token (called by other projects) -
    case 'verify_token':
        $token = $body['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if (!$token) err('token required');

        // Hash the presented token the same way login (above) now hashes
        // it before storing - the column holds SHA-256 hashes, not raw
        // tokens, so comparing the raw incoming token directly would
        // never match anything.
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.member_id, s.expires_at,
                    m.etel_id, m.fin, m.full_name, m.phone, m.status AS member_status
             FROM {$pfx}etelid_sessions s
             JOIN {$pfx}etelid_members m ON m.id = s.member_id
             WHERE s.token=%s AND s.expires_at > NOW() LIMIT 1", hash('sha256', $token)
        ), ARRAY_A);

        if (!$session)                              err('Invalid or expired token', 401);
        if ($session['member_status'] !== 'active') err('Account ' . $session['member_status'], 403);

        $companies = $wpdb->get_results($wpdb->prepare(
            "SELECT company_slug, company_name, role_at AS role FROM {$pfx}etelid_companies WHERE member_id=%d", $session['member_id']
        ), ARRAY_A);

        ok(['valid' => true, 'member' => [
            'id'        => $session['member_id'],
            'etel_id'   => $session['etel_id'],
            'fin'       => $session['fin'],
            'full_name' => $session['full_name'],
            'phone'     => $session['phone'],
            'companies' => $companies,
        ]]);

    // - Logout - invalidate token -
    case 'logout':
        $token = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if ($token) $wpdb->delete("{$pfx}etelid_sessions", ['token' => hash('sha256', $token)]);
        ok(['logged_out' => true]);

    // - Verify QR / FIN (for cross-project auth) -
    case 'verify':
        auth();
        $fin  = $body['fin']  ?? $_GET['fin']  ?? '';
        $card = $body['card'] ?? $_GET['card'] ?? '';
        if (!$fin && !$card) err('Provide fin or card');
        if ($fin) {
            $member = $wpdb->get_row($wpdb->prepare(
                "SELECT id, etel_id, fin, full_name, phone, status FROM {$pfx}etelid_members WHERE fin=%s LIMIT 1", $fin
            ), ARRAY_A);
        } else {
            $member = $wpdb->get_row($wpdb->prepare(
                "SELECT id, etel_id, fin, full_name, phone, status FROM {$pfx}etelid_members WHERE card_number=%s LIMIT 1", $card
            ), ARRAY_A);
        }
        if (!$member) err('ID not found', 404);
        if ($member['status'] !== 'active') err('ID is ' . $member['status'], 403);
        ok(['valid' => true, 'member' => $member]);

    // - Activity log -
    case 'get_log':
        auth();
        $limit = (int)($_GET['limit'] ?? 30);
        ok($wpdb->get_results("SELECT l.id, l.etel_id, l.event, l.detail, l.created_at, m.full_name, m.phone
            FROM {$pfx}etelid_log l
            LEFT JOIN {$pfx}etelid_members m ON m.id = l.member_id
            ORDER BY l.created_at DESC LIMIT {$limit}", ARRAY_A));

    // - Update member status -
    case 'set_status':
        auth();
        $id     = (int)($body['member_id'] ?? 0);
        $status = $body['status'] ?? '';
        $allowed = ['active', 'suspended', 'blocked'];
        if (!$id || !in_array($status, $allowed)) err('member_id and valid status required');
        $wpdb->update("{$pfx}etelid_members", ['status' => $status], ['id' => $id]);
        ok(['updated' => true, 'member_id' => $id, 'status' => $status]);

    // Same lightweight keyword-matched proxy as Delivery's etel-os.php -
    // not a second AI pipeline, just routes a few obvious prompt keywords
    // to the relevant stat/list query and returns it with a short summary.
    case 'ai_query':
        auth();
        $prompt = strtolower($body['prompt'] ?? '');
        if (strpos($prompt, 'new') !== false || strpos($prompt, 'today') !== false) {
            $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE DATE(created_at)=CURDATE()");
            ok(['summary' => $n . ' new Etel-ID members registered today.', 'data' => ['new_today' => $n]]);
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE status='active'");
        $sessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_sessions WHERE expires_at > NOW()");
        ok(['summary' => $total . ' active members, ' . $sessions . ' currently signed in.', 'data' => ['total_members' => $total, 'active_sessions' => $sessions]]);

    default:
        err('Unknown action: ' . $action, 404);
}

