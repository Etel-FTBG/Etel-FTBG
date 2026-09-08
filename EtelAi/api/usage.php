<?php
// ============================================================
// ETEL AI - Usage Dashboard  v1.0
// Deploy as: EtelAi/api/usage.php
//
// Admin-only. Built entirely on the real etelai_messages table (see
// memory-install.php's schema) - no fabricated numbers, no client-side
// state. Gives a per-member breakdown of actual chat volume, a daily
// trend, and simple totals, the same way stats.php already reports
// memory-table health.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/admin-check.php';
require_once __DIR__ . '/memory.php'; // for etelai_mem_ensure_token_columns()

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

$msgTbl = DB_PREFIX . 'etelai_messages';
$memTbl = DB_PREFIX . 'etelid_members';
etelai_mem_ensure_token_columns($pdo);
etelai_mem_ensure_conversation_columns($pdo);

$action = isset($_GET['action']) ? $_GET['action'] : 'overview';

// ---- action=me: any signed-in member's OWN usage, not admin-only ----
// Verifies a real member session (not the admin phone check) - deliberately
// separate from etelai_verify_admin_token below, since every member should
// be able to see their own numbers, not just the admin see everyone's.
if ($action === 'me') {
    $meToken = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($meToken === '') {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT m.id FROM ' . DB_PREFIX . 'etelid_sessions s
         JOIN ' . DB_PREFIX . 'etelid_members m ON m.id = s.member_id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(array($meToken));
    $meRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meRow) {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
        exit;
    }
    $meId = (int) $meRow['id'];

    $s1 = $pdo->prepare("SELECT COUNT(*) FROM {$msgTbl} WHERE role='user' AND member_id = ?");
    $s1->execute(array($meId));
    $myTotal = (int) $s1->fetchColumn();

    $s2 = $pdo->prepare("SELECT COUNT(*) FROM {$msgTbl} WHERE role='user' AND member_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $s2->execute(array($meId));
    $my7d = (int) $s2->fetchColumn();

    $s3 = $pdo->prepare("SELECT COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout FROM {$msgTbl} WHERE member_id = ?");
    $s3->execute(array($meId));
    $myTokens = $s3->fetch(PDO::FETCH_ASSOC);

    $s4 = $pdo->prepare("SELECT COUNT(DISTINCT conversation_id) FROM {$msgTbl} WHERE member_id = ? AND conversation_id IS NOT NULL");
    $s4->execute(array($meId));
    $myConvos = (int) $s4->fetchColumn();

    echo json_encode(array('ok' => true, 'data' => array(
        'messages_total'   => $myTotal,
        'messages_7d'      => $my7d,
        'tokens_in_total'  => (int) $myTokens['tin'],
        'tokens_out_total' => (int) $myTokens['tout'],
        'conversations'    => $myConvos,
    )));
    exit;
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$admin = etelai_verify_admin_token($pdo, $token);
if (!$admin) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
    exit;
}

// ---- Totals ----
$totalMessages = (int) $pdo->query("SELECT COUNT(*) FROM {$msgTbl} WHERE role = 'user'")->fetchColumn();
$messages7d    = (int) $pdo->query("SELECT COUNT(*) FROM {$msgTbl} WHERE role = 'user' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$messagesPrev7d = (int) $pdo->query("SELECT COUNT(*) FROM {$msgTbl} WHERE role = 'user' AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$activeMembers7d = (int) $pdo->query("SELECT COUNT(DISTINCT member_id) FROM {$msgTbl} WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$totalMembers  = (int) $pdo->query("SELECT COUNT(DISTINCT member_id) FROM {$msgTbl}")->fetchColumn();

// Token totals - real numbers from each provider's own usage field
// (Gemini's usageMetadata, OpenAI-shape usage.*), captured per turn in
// chat.php. Columns are added on demand by memory.php if they don't
// exist yet, so older rows before this feature simply have NULL here -
// COALESCE/SUM already treats those as 0, nothing special needed.
$tokensAllTime = $pdo->query(
    "SELECT COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout FROM {$msgTbl}"
)->fetch(PDO::FETCH_ASSOC);
$tokens7dRow = $pdo->query(
    "SELECT COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout FROM {$msgTbl}
     WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetch(PDO::FETCH_ASSOC);

// ---- Daily trend, last 14 days (user messages only, so a back-and-forth
// exchange counts once per turn the member actually sent, not per reply) ----
$dailyStmt = $pdo->query(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c
     FROM {$msgTbl}
     WHERE role = 'user' AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
     GROUP BY DATE(created_at)
     ORDER BY d ASC"
);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
$dailyByDate = array();
foreach ($dailyRows as $r) { $dailyByDate[$r['d']] = (int) $r['c']; }
$daily = array();
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[] = array('date' => $d, 'count' => isset($dailyByDate[$d]) ? $dailyByDate[$d] : 0);
}

// ---- Per-member breakdown, most active first ----
$memberStmt = $pdo->query(
    "SELECT m.member_id,
            SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) AS message_count,
            MAX(m.created_at) AS last_active,
            SUM(CASE WHEN m.role = 'user' AND m.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS messages_7d,
            COALESCE(SUM(CASE WHEN m.role = 'assistant' THEN m.tokens_in END),0) AS tokens_in,
            COALESCE(SUM(CASE WHEN m.role = 'assistant' THEN m.tokens_out END),0) AS tokens_out
     FROM {$msgTbl} m
     GROUP BY m.member_id
     ORDER BY message_count DESC
     LIMIT 100"
);
$memberRows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

$members = array();
if ($memberRows) {
    $ids = array_map(function ($r) { return (int) $r['member_id']; }, $memberRows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $infoStmt = $pdo->prepare("SELECT id, full_name, phone, etel_id FROM {$memTbl} WHERE id IN ($placeholders)");
    $infoStmt->execute($ids);
    $infoById = array();
    foreach ($infoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $infoById[$row['id']] = $row; }

    foreach ($memberRows as $r) {
        $mid = (int) $r['member_id'];
        $info = isset($infoById[$mid]) ? $infoById[$mid] : null;
        $members[] = array(
            'member_id'      => $mid,
            'full_name'      => $info ? $info['full_name'] : '(deleted member)',
            'phone'          => $info ? $info['phone'] : null,
            'etel_id'        => $info ? $info['etel_id'] : null,
            'message_count'  => (int) $r['message_count'],
            'messages_7d'    => (int) $r['messages_7d'],
            'tokens_in'      => (int) $r['tokens_in'],
            'tokens_out'     => (int) $r['tokens_out'],
            'last_active'    => $r['last_active'],
        );
    }
}

echo json_encode(array('ok' => true, 'data' => array(
    'totals' => array(
        'messages_total'    => $totalMessages,
        'messages_7d'       => $messages7d,
        'messages_prev_7d'  => $messagesPrev7d,
        'active_members_7d' => $activeMembers7d,
        'members_total'     => $totalMembers,
        'tokens_in_total'   => (int) $tokensAllTime['tin'],
        'tokens_out_total'  => (int) $tokensAllTime['tout'],
        'tokens_in_7d'      => (int) $tokens7dRow['tin'],
        'tokens_out_7d'     => (int) $tokens7dRow['tout'],
    ),
    'daily'   => $daily,
    'members' => $members,
)));
