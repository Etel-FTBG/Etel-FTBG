<?php
// ============================================================
// ETEL AI — EtelID Auth Proxy
// File: EtelAi/api/etel-os.php
//
// Forwards login/verify requests from the EtelAI frontend
// to the EtelID WordPress plugin API.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key, X-Etel-Token, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Configuration ─────────────────────────────────────────────
// URL of the EtelID WordPress plugin API endpoint
define('ETELID_ENDPOINT', 'https://ethelmarket.com/EtelId/wp-content/plugins/etel-id-v3/etel-os.php');

// Must match ETEL_API_KEY defined in etel-os.php on the EtelID server
// Kept in api/secrets.php, which api/.htaccess blocks from direct HTTP access.
require_once __DIR__ . '/secrets.php';

// ── Helpers ───────────────────────────────────────────────────
function ok($d)           { echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400){ http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

// ── Route ─────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Map EtelAI action names → EtelID action names
$map = [
    'etelid_login'  => 'login',
    'etelid_verify' => 'verify_token',
    'etelid_logout' => 'logout',
];
if (isset($map[$action])) $action = $map[$action];

// Status — answer locally so the frontend knows the proxy is alive
if ($action === 'status') {
    ok([
        'project' => 'Etel-ID Proxy',
        'status'  => 'online',
        'target'  => ETELID_ENDPOINT,
        'time'    => date('Y-m-d H:i:s'),
    ]);
}

// ── Proxy all other actions to the EtelID API ─────────────────
$url = ETELID_ENDPOINT . '?action=' . urlencode($action);

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => implode("\r\n", [
        'Content-Type: application/json',
        'X-Etel-Key: ' . ETELID_KEY,
        'X-Etel-Token: ' . ($_SERVER['HTTP_X_ETEL_TOKEN'] ?? ''),
    ]),
    'content'       => json_encode($body),
    'timeout'       => 10,
    'ignore_errors' => true,
]]);

$resp = @file_get_contents($url, false, $ctx);

if ($resp === false) {
    err('Cannot reach Etel-ID service. Check that EtelId is running at ethelmarket.com/EtelId/', 503);
}

// Pass the EtelID response straight back to the frontend
$decoded = json_decode($resp, true);
if ($decoded === null) {
    err('Etel-ID returned an unexpected response (not JSON). Check the etel-os.php on the EtelId server.', 503);
}

echo $resp;
exit;
