<?php
// ============================================================
// ETEL ID — Standalone API Gateway v1.0
// Place at: public_html/EtelId/etel-api.php
// Access:   https://ethelmarket.com/EtelId/etel-api.php
//
// Connects directly to MySQL — no WordPress required.
// Handles login, verify_token, logout from other Etel projects.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key, X-Etel-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Database config (matches wp-config.php) ───────────────────
// REDACTED for the git repo — the real values live only on the server,
// set as environment variables (or edit these getenv() fallbacks
// locally on the box, never commit real credentials here again).
define('DB_HOST',   getenv('ETELID_DB_HOST') ?: 'localhost');
define('DB_NAME',   getenv('ETELID_DB_NAME') ?: 'etelid');
define('DB_USER',   getenv('ETELID_DB_USER') ?: 'CHANGE_ME');
define('DB_PASS',   getenv('ETELID_DB_PASS') ?: 'CHANGE_ME');
define('DB_PREFIX', 'Etelid_');

// ── API key — must match ETELID_KEY in EtelAi/api/etel-os.php ─
// REDACTED for the git repo — set ETEL_API_KEY in the server environment.
define('API_KEY', getenv('ETEL_API_KEY') ?: 'CHANGE_ME');

// ── Helpers ───────────────────────────────────────────────────
function ok($d)            { echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Exception $e) {
        err('Database connection failed: ' . $e->getMessage(), 500);
    }
    return $pdo;
}

function pfx($table) { return DB_PREFIX . 'etelid_' . $table; }

// Auto-link a member to whichever project they just logged into, so the
// brain-routing / cross-company nav on every project can see it without
// needing a separate manual phpMyAdmin row. Never downgrades an existing
// admin/creator/owner role that may already be set for that company.
function ensure_company_link(PDO $pdo, $memberId, $projectSlug) {
    $projectSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($projectSlug));
    if (!$projectSlug || $projectSlug === 'api' || $projectSlug === 'unknown') return;

    $stmt = $pdo->prepare("SELECT role_at FROM " . pfx('companies') . " WHERE member_id = ? AND company_slug = ? LIMIT 1");
    $stmt->execute([$memberId, $projectSlug]);
    $existing = $stmt->fetchColumn();
    $alreadyAdmin = $existing && in_array(strtolower($existing), ['admin', 'creator', 'owner'], true);
    $role = $alreadyAdmin ? $existing : ($existing ?: 'Member');

    $pdo->prepare(
        "INSERT INTO " . pfx('companies') . " (member_id, company_slug, company_name, role_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE role_at = VALUES(role_at)"
    )->execute([$memberId, $projectSlug, ucfirst($projectSlug), $role]);
}

function auth() {
    $k = $_SERVER['HTTP_X_ETEL_KEY'] ?? $_GET['key'] ?? '';
    if ($k !== API_KEY) err('Unauthorized', 401);
}

// ── Route ─────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── Status ─────────────────────────────────────────────────
    case 'status':
        ok(['project' => 'Etel-ID', 'status' => 'online', 'time' => date('Y-m-d H:i:s')]);

    // ── Login ──────────────────────────────────────────────────
    case 'login':
        $phone    = preg_replace('/\s+/', '', $body['phone'] ?? '');
        $password = $body['password'] ?? '';
        $project  = $body['project']  ?? 'api';

        if (!$phone || !$password) err('phone and password required');

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM " . pfx('members') . " WHERE phone = ? LIMIT 1");
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
            $pdo->prepare("INSERT INTO " . pfx('log') . " (member_id, etel_id, event, detail) VALUES (?,?,?,?)")
                ->execute([$member['id'], $member['etel_id'], 'login_failed', "Failed login from: $project"]);
            err('Incorrect password', 401);
        }

        // Create session token
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO " . pfx('sessions') . " (member_id, token, ip, ua, expires_at) VALUES (?,?,?,?,?)")
            ->execute([
                $member['id'], $token,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                date('Y-m-d H:i:s', strtotime('+30 days')),
            ]);
        $pdo->prepare("INSERT INTO " . pfx('log') . " (member_id, etel_id, event, detail) VALUES (?,?,?,?)")
            ->execute([$member['id'], $member['etel_id'], 'login', "Cross-project login from: $project"]);

        unset($member['pin_hash'], $member['password']);

        ensure_company_link($pdo, $member['id'], $project);

        $stmt2 = $pdo->prepare("SELECT company_slug, company_name, role_at AS role FROM " . pfx('companies') . " WHERE member_id = ?");
        $stmt2->execute([$member['id']]);
        $member['companies'] = $stmt2->fetchAll();

        ok(['member' => $member, 'token' => $token, 'expires_in' => 2592000]);

    // ── Verify token ───────────────────────────────────────────
    case 'verify_token':
        $token = $body['token'] ?? $_GET['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if (!$token) err('token required');

        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT s.member_id, s.expires_at,
                    m.etel_id, m.fin, m.full_name, m.phone, m.status AS member_status
             FROM " . pfx('sessions') . " s
             JOIN " . pfx('members') . " m ON m.id = s.member_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session)                              err('Invalid or expired token', 401);
        if ($session['member_status'] !== 'active') err('Account ' . $session['member_status'], 403);

        $stmt3 = $pdo->prepare("SELECT company_slug, company_name, role_at AS role FROM " . pfx('companies') . " WHERE member_id = ?");
        $stmt3->execute([$session['member_id']]);

        ok(['valid' => true, 'member' => [
            'id'        => $session['member_id'],
            'etel_id'   => $session['etel_id'],
            'fin'       => $session['fin'],
            'full_name' => $session['full_name'],
            'phone'     => $session['phone'],
            'companies' => $stmt3->fetchAll(),
        ]]);

    // ── Logout ─────────────────────────────────────────────────
    case 'logout':
        $token = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if ($token) {
            db()->prepare("DELETE FROM " . pfx('sessions') . " WHERE token = ?")
                ->execute([$token]);
        }
        ok(['logged_out' => true]);

    // ── Stats (authenticated) ───────────────────────────────────
    case 'stats':
        auth();
        $pdo = db();
        ok([
            'total_members'   => (int)$pdo->query("SELECT COUNT(*) FROM " . pfx('members') . " WHERE status='active'")->fetchColumn(),
            'new_today'       => (int)$pdo->query("SELECT COUNT(*) FROM " . pfx('members') . " WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'active_sessions' => (int)$pdo->query("SELECT COUNT(*) FROM " . pfx('sessions') . " WHERE expires_at > NOW()")->fetchColumn(),
        ]);

    default:
        err('Unknown action: ' . $action, 404);
}
