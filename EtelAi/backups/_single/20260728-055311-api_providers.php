<?php
// ============================================================
// ETEL AI - Provider on/off registry  v1.0
// Deploy as: EtelAi/api/providers.php
//
// Replaces the old 7-engine Settings panel (Ollama / Claude /
// OpenAI-compat / OpenRouter / WebLLM / php-proxy / Gemini / Hermes)
// with exactly two things per provider: a name, and an on/off switch.
// API keys are NEVER read from or written to this endpoint - they
// live only in secrets.php, set once by whoever deploys the code.
//
// GET  -> list of {id, name, enabled} - no key material returned, ever.
// POST -> { token, id, enabled } toggles one provider. Authorized by
//         verifying the caller's own EtelID session token belongs to
//         an admin (same check that gates the Settings panel itself),
//         not a separately-typed secret - if you can see the toggle,
//         you already proved admin identity by logging in.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/admin-check.php';

define('PROVIDERS_PATH', __DIR__ . '/providers.json');

function providers_load() {
    $raw = @file_get_contents(PROVIDERS_PATH);
    $cfg = $raw ? json_decode($raw, true) : null;
    if (!is_array($cfg) || !isset($cfg['providers'])) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'providers.json missing or invalid'));
        exit;
    }
    return $cfg;
}

$cfg = providers_load();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = array();
    foreach ($cfg['providers'] as $p) {
        $out[] = array('id' => $p['id'], 'name' => $p['name'], 'enabled' => !empty($p['enabled']));
    }
    echo json_encode(array('ok' => true, 'data' => array('providers' => $out)));
    exit;
}

// ---- POST: toggle one provider ----
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
    echo json_encode(array('ok' => false, 'error' => 'Database error'));
    exit;
}

$token = isset($body['token']) ? (string) $body['token'] : '';
$admin = etelai_verify_admin_token($pdo, $token);
if (!$admin) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
    exit;
}

$id      = isset($body['id']) ? $body['id'] : '';
$enabled = !empty($body['enabled']);
$found   = false;

foreach ($cfg['providers'] as &$p) {
    if ($p['id'] === $id) {
        $p['enabled'] = $enabled;
        $found = true;
        break;
    }
}
unset($p);

if (!$found) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'Unknown provider: ' . $id));
    exit;
}

$written = @file_put_contents(PROVIDERS_PATH, json_encode($cfg, JSON_PRETTY_PRINT));
if ($written === false) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json - check file permissions'));
    exit;
}

echo json_encode(array('ok' => true, 'data' => array('id' => $id, 'enabled' => $enabled)));
