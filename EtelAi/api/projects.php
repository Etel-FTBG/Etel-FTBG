<?php
// ============================================================
// ETEL AI - Projects  v1.0 (Phase 1: table + basic CRUD, no chat
// integration yet - see scoping doc, Phase 2/3 wire this into
// chat.php's system prompt and etelai_messages.project_id)
// Deploy as: EtelAi/api/projects.php
//
// Member-scoped, not admin-only - any signed-in member manages their
// own projects, same tier as Profile/Memory. Table is created on
// first use rather than requiring a separate install step, matching
// memory.php's self-healing convention.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/admin-check.php'; // reuses etelai_verify_admin_token's DB pattern, not its admin-only check

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Database error'));
    exit;
}

function etelai_projects_tbl() { return DB_PREFIX . 'etelai_projects'; }

function etelai_projects_ensure_table($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $tbl = etelai_projects_tbl();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$tbl}` (
            `id`            INT(11) NOT NULL AUTO_INCREMENT,
            `member_id`     INT(11) NOT NULL,
            `name`          VARCHAR(120) NOT NULL,
            `instructions`  TEXT NULL,
            `created_at`    DATETIME NOT NULL,
            `updated_at`    DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Any real signed-in member (not admin-only) - verifies the session
// token against Etelid_sessions the same way admin-check.php does,
// just without the phone/role check afterward.
function etelai_verify_member_token($pdo, $token) {
    if ($token === '') return null;
    $stmt = $pdo->prepare(
        'SELECT m.id FROM ' . DB_PREFIX . 'etelid_sessions s
         JOIN ' . DB_PREFIX . 'etelid_members m ON m.id = s.member_id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(array($token));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

etelai_projects_ensure_table($pdo);
$tbl = etelai_projects_tbl();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $token  = isset($_GET['token']) ? (string) $_GET['token'] : '';
    $member = etelai_verify_member_token($pdo, $token);
    if (!$member) {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
        exit;
    }

    if ($action === 'list') {
        $stmt = $pdo->prepare("SELECT id, name, instructions, created_at, updated_at FROM {$tbl} WHERE member_id = ? ORDER BY updated_at DESC");
        $stmt->execute(array($member['id']));
        echo json_encode(array('ok' => true, 'data' => array('projects' => $stmt->fetchAll(PDO::FETCH_ASSOC))));
        exit;
    }

    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Unknown action: ' . $action));
    exit;
}

// ---- POST ----
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();

$token  = isset($body['token']) ? (string) $body['token'] : '';
$member = etelai_verify_member_token($pdo, $token);
if (!$member) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
    exit;
}

$action = isset($body['action']) ? $body['action'] : '';

if ($action === 'create') {
    $name = isset($body['name']) ? trim($body['name']) : '';
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Project name is required.'));
        exit;
    }
    $instructions = isset($body['instructions']) ? trim((string) $body['instructions']) : null;
    $stmt = $pdo->prepare("INSERT INTO {$tbl} (member_id, name, instructions, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->execute(array($member['id'], substr($name, 0, 120), $instructions));
    echo json_encode(array('ok' => true, 'data' => array('id' => (int) $pdo->lastInsertId())));
    exit;
}

if ($action === 'update') {
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    $name      = isset($body['name']) ? trim($body['name']) : null;
    $hasInstr  = array_key_exists('instructions', $body);
    $instructions = $hasInstr ? trim((string) $body['instructions']) : null;

    // Ownership check - a project only belongs to the member who
    // created it, no shared/admin override in v1 (see scoping doc).
    $check = $pdo->prepare("SELECT id FROM {$tbl} WHERE id = ? AND member_id = ?");
    $check->execute(array($projectId, $member['id']));
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => 'Project not found.'));
        exit;
    }

    $sets = array();
    $params = array();
    if ($name !== null && $name !== '') { $sets[] = 'name = ?'; $params[] = substr($name, 0, 120); }
    if ($hasInstr) { $sets[] = 'instructions = ?'; $params[] = ($instructions === '' ? null : $instructions); }
    $sets[] = 'updated_at = NOW()';
    $params[] = $projectId;
    $params[] = $member['id'];
    $pdo->prepare("UPDATE {$tbl} SET " . implode(', ', $sets) . " WHERE id = ? AND member_id = ?")->execute($params);
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'delete') {
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    $stmt = $pdo->prepare("DELETE FROM {$tbl} WHERE id = ? AND member_id = ?");
    $stmt->execute(array($projectId, $member['id']));
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => 'Project not found.'));
        exit;
    }
    // Messages/artifacts referencing this project_id are intentionally
    // left alone here (Phase 2+ adds project_id to etelai_messages) -
    // deleting a project should never silently delete conversation
    // history, just the grouping label.
    echo json_encode(array('ok' => true));
    exit;
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'Unknown action: ' . $action));
