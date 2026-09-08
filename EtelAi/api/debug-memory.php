<?php
// TEMPORARY DIAGNOSTIC - DELETE THIS FILE after checking.
// Read-only: counts rows in etelai_messages and shows the most recent
// few (role + a short content preview, no full content) so we can tell
// definitively whether conversation history is actually being saved,
// without exposing full message text.
header('Content-Type: application/json');
require_once __DIR__ . '/secrets.php';

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!defined('ETELAI_ADMIN_KEY') || ETELAI_ADMIN_KEY === '' || $key !== ETELAI_ADMIN_KEY) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    echo json_encode(array('ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()));
    exit;
}

$p = DB_PREFIX;
$tbl = $p . 'etelai_messages';

try {
    $count = $pdo->query("SELECT COUNT(*) c FROM `$tbl`")->fetch()['c'];
    $recent = $pdo->query("SELECT id, member_id, project, role, LEFT(content,60) AS preview, created_at
                            FROM `$tbl` ORDER BY id DESC LIMIT 15")->fetchAll();
    echo json_encode(array('ok' => true, 'total_rows' => intval($count), 'most_recent' => $recent));
} catch (Exception $e) {
    echo json_encode(array('ok' => false, 'error' => 'Query failed (table may not exist yet): ' . $e->getMessage()));
}
