<?php
// ============================================================
// ETEL AI - Admin dashboard stats  v1.0
// Deploy as: EtelAi/api/stats.php
//
// Simple counts for the admin dashboard. Authorized via the caller's own
// EtelID admin session (same check that gates the Settings/Memory panel
// itself) rather than a separately-typed secret. Returns no member names,
// no message content, no keys - just counts.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/admin-check.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'DB connection failed'));
    exit;
}

$token = isset($body['token']) ? (string) $body['token'] : '';
$admin = etelai_verify_admin_token($pdo, $token);
if (!$admin) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
    exit;
}

$p = DB_PREFIX;
$stats = array('members_remembered' => 0, 'total_facts' => 0, 'messages_last_7d' => 0, 'memory_installed' => false);

try {
    $stats['members_remembered'] = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM {$p}etelai_facts")->fetchColumn();
    $stats['total_facts']        = (int)$pdo->query("SELECT COUNT(*) FROM {$p}etelai_facts")->fetchColumn();
    $stats['messages_last_7d']   = (int)$pdo->query("SELECT COUNT(*) FROM {$p}etelai_messages WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['memory_installed']   = true;
} catch (Exception $e) {
    // Tables not installed yet - memory_installed stays false, everything else 0.
}

echo json_encode(array('ok' => true, 'data' => $stats));
