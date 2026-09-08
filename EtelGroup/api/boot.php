<?php
// ============================================================
// Etel Group super-page — Admin API router
// Deploy as: EtelGroup/api/boot.php
// Same lightweight action-router convention used across the other
// Etel Group projects (Delivery, EtelTaxi): POST/GET with an `action`
// field, JSON in, JSON out.
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/auth.php';

function ok($data = null) {
    echo json_encode(array('ok' => true, 'success' => true, 'data' => $data));
    exit;
}
function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(array('ok' => false, 'success' => false, 'error' => $msg, 'message' => $msg));
    exit;
}

$action = $_GET['action'] ?? null;
$raw = file_get_contents('php://input');
$body = array();
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
        if ($action === null && isset($body['action'])) $action = $body['action'];
    }
}
if (!$action) fail('Missing action', 400);

try {
    require __DIR__ . '/controllers/AdminController.php';
} catch (Throwable $e) {
    error_log('[EtelGroup][api] ' . $e->getMessage());
    fail('Server error - please try again', 500);
}

fail('Unknown action: ' . $action, 404);
