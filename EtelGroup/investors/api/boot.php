<?php
// ============================================================
// Investor Relations (Etel Delivery & Ride) — API router
// Deploy as: EtelGroup/investors/api/boot.php
// Same lightweight action-router convention as EtelGroup/api/boot.php
// and every other Etel Group project: POST/GET with an `action` field,
// JSON in, JSON out. Deliberately NOT named api.php and kept small -
// this host's WAF has silently deleted files matching that exact name
// or over ~30KB on sibling systems.
//
// Reuses EtelGroup's OWN database and admin auth (eg_db(),
// eg_require_admin()) - this module lives in EtelGroup's codebase and
// EtelGroup's own DB, per the "own DB, separate from Delivery & Ride's
// DB" convention. The ONLY connection to Delivery & Ride anywhere in
// this module is the read-only cross-DB SELECT in lib/DeliveryReadonly.php.
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../php/config.php';   // eg_db()
require_once __DIR__ . '/../../api/auth.php';     // eg_require_admin(), eg_current_actor(), eg_log_activity()
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/Crypto.php';
require_once __DIR__ . '/lib/InvestorAuth.php';
require_once __DIR__ . '/lib/DeliveryReadonly.php';
require_once __DIR__ . '/lib/DistributionEngine.php';

function ok($data = null) {
    echo json_encode(['ok' => true, 'success' => true, 'data' => $data]);
    exit;
}
function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'success' => false, 'error' => $msg, 'message' => $msg]);
    exit;
}

$action = $_GET['action'] ?? null;
$raw = file_get_contents('php://input');
$body = [];
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
        if ($action === null && isset($body['action'])) $action = $body['action'];
    }
}
if (!$action) fail('Missing action', 400);

// Routed by action-name prefix (setup_*, admin_*, investor_*) rather
// than listing every action here - each controller is itself a
// switch($action){...} that calls ok()/fail() (which exit()) for every
// case it owns, so only ONE controller file is ever loaded per request.
try {
    if (str_starts_with($action, 'setup_')) {
        require __DIR__ . '/controllers/SetupController.php';
    } elseif (str_starts_with($action, 'admin_')) {
        require __DIR__ . '/controllers/AdminController.php';
    } elseif (str_starts_with($action, 'investor_')) {
        require __DIR__ . '/controllers/PortalController.php';
    }
} catch (Throwable $e) {
    error_log('[InvestorRelations][api] ' . $e->getMessage());
    fail('Server error - please try again', 500);
}

fail('Unknown action: ' . $action, 404);
