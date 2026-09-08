<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

// Catch any fatal PHP error and return it as JSON so the frontend can show it
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'PHP fatal: ' . $e['message']]);
    }
});

// ============================================================
// ETEL AI — EtelID Direct Auth Connector
// File: EtelAi/api/etel-os.php
//
// Connects directly to the EtelID MySQL database.
// No HTTP round-trip to EtelId server needed.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key, X-Etel-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── EtelID database credentials (from EtelId/wp-config.php) ──
// Kept in api/secrets.php, which api/.htaccess blocks from direct HTTP access.
require_once __DIR__ . '/secrets.php';

// ── Helpers ───────────────────────────────────────────────────
function ok($d)            { ob_clean(); echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { ob_clean(); http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (\Throwable $e) {
        err('Database error: ' . $e->getMessage(), 500);
    }
    return $pdo;
}

function tbl($t) { return DB_PREFIX . 'etelid_' . $t; }

// ── Route ─────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Map EtelAI action names → internal action names
$map = [
    'etelid_login'  => 'login',
    'etelid_verify' => 'verify_token',
    'etelid_logout' => 'logout',
];
if (isset($map[$action])) $action = $map[$action];

switch ($action) {

    // ── Status ─────────────────────────────────────────────────
    case 'status':
        ok(['project' => 'Etel-ID Connector', 'status' => 'online', 'time' => date('Y-m-d H:i:s')]);

    // ── Login ──────────────────────────────────────────────────
    case 'login':
        $phone    = preg_replace('/\s+/', '', $body['phone'] ?? '');
        $password = $body['password'] ?? '';
        $project  = $body['project']  ?? 'etel-ai';

        if (!$phone || !$password) err('phone and password required');

        $pdo  = db();
        $stmt = $pdo->prepare("SELECT * FROM " . tbl('members') . " WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $member = $stmt->fetch();

        if (!$member)                          err('No account found with this phone number', 404);
        if ($member['status'] === 'suspended') err('Account suspended. Contact support.', 403);
        if ($member['status'] === 'revoked')   err('Account revoked.', 403);
        if (empty($member['pin_hash']))        err('No password set. Reset your password.', 400);

        // Verify bcrypt or legacy SHA-256
        $valid = false;
        if (function_exists('password_verify')) {
            $valid = password_verify($password, $member['pin_hash']);
        }
        if (!$valid && strlen($member['pin_hash']) === 64) {
            $valid = hash_equals($member['pin_hash'], hash('sha256', 'etelid_v2_' . $password . '_salt_9x'));
        }

        if (!$valid) {
            $pdo->prepare("INSERT INTO " . tbl('log') . " (member_id, etel_id, event, detail) VALUES (?,?,?,?)")
                ->execute([$member['id'], $member['etel_id'], 'login_failed', "Failed login from: $project"]);
            err('Incorrect password', 401);
        }

        // Create session token
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO " . tbl('sessions') . " (member_id, token, ip, ua, expires_at) VALUES (?,?,?,?,?)")
            ->execute([
                $member['id'], $token,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                date('Y-m-d H:i:s', strtotime('+30 days')),
            ]);
        $pdo->prepare("INSERT INTO " . tbl('log') . " (member_id, etel_id, event, detail) VALUES (?,?,?,?)")
            ->execute([$member['id'], $member['etel_id'], 'login', "Cross-project login from: $project"]);

        unset($member['pin_hash'], $member['password']);

        $stmt2 = $pdo->prepare("SELECT company_slug, company_name, role_at AS role FROM " . tbl('companies') . " WHERE member_id = ?");
        $stmt2->execute([$member['id']]);
        $member['companies'] = $stmt2->fetchAll();

        ok(['member' => $member, 'token' => $token, 'expires_in' => 2592000]);

    // ── Verify token ───────────────────────────────────────────
    case 'verify_token':
        $token = $body['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if (!$token) err('token required');

        $pdo  = db();
        $stmt = $pdo->prepare(
            "SELECT s.member_id, s.expires_at,
                    m.etel_id, m.fin, m.full_name, m.phone, m.status AS member_status
             FROM " . tbl('sessions') . " s
             JOIN " . tbl('members') . " m ON m.id = s.member_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session)                              err('Invalid or expired token', 401);
        if ($session['member_status'] !== 'active') err('Account ' . $session['member_status'], 403);

        ok(['valid' => true, 'member' => [
            'id'        => $session['member_id'],
            'etel_id'   => $session['etel_id'],
            'fin'       => $session['fin'],
            'full_name' => $session['full_name'],
            'phone'     => $session['phone'],
        ]]);

    // ── Logout ─────────────────────────────────────────────────
    case 'logout':
        $token = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if ($token) {
            db()->prepare("DELETE FROM " . tbl('sessions') . " WHERE token = ?")
                ->execute([$token]);
        }
        ok(['logged_out' => true]);

    default:
        err('Unknown action: ' . $action, 404);
}
