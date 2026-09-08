<?php
// ============================================================
// ETEL OS Connector — Etel-ID Premium v3.9.0
// WordPress Plugin — Central Identity System
//
// Deploy as: wp-content/plugins/etel-id-v3/etel-os.php
// Access at: https://yourdomain.com/wp-content/plugins/etel-id-v3/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'etel-id');
define('PROJECT_VERSION', '3.9.0');

// Bootstrap WordPress to use $wpdb and WP functions
$wp_root = dirname(__FILE__, 5); // plugins/etel-id-v3/etel-os.php → wp-root
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
    $k = $_SERVER['HTTP_X_ETEL_KEY'] ?? $_GET['key'] ?? '';
    if ($k !== ETEL_API_KEY) err('Unauthorized', 401);
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── Public ─────────────────────────────────────────────
    case 'status':
        ok([
            'project' => 'Etel-ID',
            'id'      => PROJECT_ID,
            'version' => PROJECT_VERSION,
            'status'  => 'online',
            'time'    => date('Y-m-d H:i:s'),
        ]);

    // ── Stats — dashboard card ──────────────────────────────
    case 'stats':
        auth();
        ok([
            'total_members'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE status='active'"),
            'new_today'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE DATE(created_at)=CURDATE()"),
            'active_sessions' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_sessions WHERE expires_at > NOW()"),
            'ids_issued'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pfx}etelid_members WHERE etel_id IS NOT NULL"),
        ]);

    // ── Members list ────────────────────────────────────────
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

    // ── Single member by FIN or Etel-ID ─────────────────────
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
            "SELECT company_slug, company_name, role FROM {$pfx}etelid_companies WHERE member_id=%d", $row['id']
        ), ARRAY_A);
        ok($row);

    // ── Login — cross-project auth entry point ───────────────
    case 'login':
        $phone    = preg_replace('/\s+/', '', $body['phone'] ?? '');
        $password = $body['password'] ?? '';
        $project  = $body['project']  ?? 'api';
        if (!$phone || !$password) err('phone and password required');

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pfx}etelid_members WHERE phone=%s LIMIT 1", $phone
        ), ARRAY_A);

        if (!$member)                          err('No account found with this phone number', 404);
        if ($member['status'] === 'suspended') err('Account suspended. Contact support.', 403);
        if ($member['status'] === 'revoked')   err('Account revoked.', 403);
        if (empty($member['pin_hash']))        err('No password set. Reset your password.', 400);

        // Verify bcrypt or legacy SHA-256
        $valid = false;
        if (function_exists('password_verify')) {
            $valid = password_verify($password, $member['pin_hash']);
            if (!$valid && strlen($member['pin_hash']) === 64) {
                $valid = hash_equals($member['pin_hash'], hash('sha256', 'etelid_v2_' . $password . '_salt_9x'));
            }
        } else {
            $valid = hash_equals($member['pin_hash'], hash('sha256', 'etelid_v2_' . $password . '_salt_9x'));
        }

        if (!$valid) {
            $wpdb->insert("{$pfx}etelid_log", [
                'member_id' => $member['id'], 'etel_id' => $member['etel_id'],
                'event' => 'login_failed', 'detail' => "Cross-project login failed from: $project",
            ]);
            err('Incorrect password', 401);
        }

        // Create session token
        $token = bin2hex(random_bytes(32));
        $wpdb->insert("{$pfx}etelid_sessions", [
            'member_id'  => $member['id'],
            'token'      => $token,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        $wpdb->insert("{$pfx}etelid_log", [
            'member_id' => $member['id'], 'etel_id' => $member['etel_id'],
            'event' => 'login', 'detail' => "Cross-project login from: $project",
        ]);

        unset($member['pin_hash'], $member['password']);
        // Attach company links
        $member['companies'] = $wpdb->get_results($wpdb->prepare(
            "SELECT company_slug, company_name, role FROM {$pfx}etelid_companies WHERE member_id=%d", $member['id']
        ), ARRAY_A);
        ok(['member' => $member, 'token' => $token, 'expires_in' => 2592000]);

    // ── Verify session token (called by other projects) ───────
    case 'verify_token':
        $token = $body['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if (!$token) err('token required');

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.member_id, s.expires_at,
                    m.etel_id, m.fin, m.full_name, m.phone, m.status AS member_status
             FROM {$pfx}etelid_sessions s
             JOIN {$pfx}etelid_members m ON m.id = s.member_id
             WHERE s.token=%s AND s.expires_at > NOW() LIMIT 1", $token
        ), ARRAY_A);

        if (!$session)                              err('Invalid or expired token', 401);
        if ($session['member_status'] !== 'active') err('Account ' . $session['member_status'], 403);

        ok(['valid' => true, 'member' => [
            'id'        => $session['member_id'],
            'etel_id'   => $session['etel_id'],
            'fin'       => $session['fin'],
            'full_name' => $session['full_name'],
            'phone'     => $session['phone'],
        ]]);

    // ── Logout — invalidate token ────────────────────────────
    case 'logout':
        $token = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if ($token) $wpdb->delete("{$pfx}etelid_sessions", ['token' => $token]);
        ok(['logged_out' => true]);

    // ── Verify QR / FIN (for cross-project auth) ─────────────
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

    // ── Activity log ────────────────────────────────────────
    case 'get_log':
        auth();
        $limit = (int)($_GET['limit'] ?? 30);
        ok($wpdb->get_results("SELECT l.id, l.etel_id, l.event, l.detail, l.created_at, m.full_name, m.phone
            FROM {$pfx}etelid_log l
            LEFT JOIN {$pfx}etelid_members m ON m.id = l.member_id
            ORDER BY l.created_at DESC LIMIT {$limit}", ARRAY_A));

    // ── Update member status ─────────────────────────────────
    case 'set_status':
        auth();
        $id     = (int)($body['member_id'] ?? 0);
        $status = $body['status'] ?? '';
        $allowed = ['active', 'suspended', 'blocked'];
        if (!$id || !in_array($status, $allowed)) err('member_id and valid status required');
        $wpdb->update("{$pfx}etelid_members", ['status' => $status], ['id' => $id]);
        ok(['updated' => true, 'member_id' => $id, 'status' => $status]);

    default:
        err('Unknown action: ' . $action, 404);
}
