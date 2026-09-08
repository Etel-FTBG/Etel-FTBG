<?php
// ============================================================
// ETEL AI - Provider & key registry  v2.0
// Deploy as: EtelAi/api/providers.php
//
// v1 never let keys touch this endpoint by design (keys lived only in
// secrets.php, edited on cPanel). v2 deliberately relaxes that: keys
// can now be added/removed/toggled from the admin dashboard, each
// provider can hold several, and chat.php automatically rotates past
// one that hits its quota. Trade-off worth knowing: a key is now
// sitting in a JSON file a browser can write to, gated only by the
// same admin-session check as everything else here - same trust
// boundary as the rest of this dashboard, not a new one, but real.
//
// GET  -> providers with their keys, each key's actual secret MASKED
//         (only ever shown as e.g. "AIza...xyz9") - the raw key is
//         never sent back to the browser once it's been saved.
// POST actions (all require {token, ...}, admin-only):
//   - (no action / action=toggle) {id, enabled}      - provider on/off (unchanged from v1)
//   - action=add_key    {provider_id, label, key, model, base?}
//   - action=remove_key {provider_id, key_id}
//   - action=toggle_key {provider_id, key_id, enabled}
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

function providers_save($cfg) {
    return @file_put_contents(PROVIDERS_PATH, json_encode($cfg, JSON_PRETTY_PRINT));
}

// "AIzaSyABC123xyz9" -> "AIza...yz9" - enough to tell keys apart in the
// list without ever reconstructing the real value from what's shown.
function providers_mask_key($key) {
    $len = strlen($key);
    if ($len <= 8) return str_repeat('•', $len);
    return substr($key, 0, 4) . '...' . substr($key, -3);
}

function providers_key_status($k) {
    if (empty($k['enabled'])) return 'disabled';
    if (!empty($k['cooldown_until']) && strtotime($k['cooldown_until']) > time()) return 'cooldown';
    return 'active';
}

$cfg = providers_load();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = array();
    foreach ($cfg['providers'] as $p) {
        $keys = array();
        foreach ((isset($p['keys']) ? $p['keys'] : array()) as $k) {
            $keys[] = array(
                'id'             => $k['id'],
                'label'          => $k['label'],
                'masked_key'     => isset($k['key']) ? providers_mask_key($k['key']) : null,
                'model'          => isset($k['model']) ? $k['model'] : null,
                'enabled'        => !empty($k['enabled']),
                'status'         => providers_key_status($k),
                'cooldown_until' => isset($k['cooldown_until']) ? $k['cooldown_until'] : null,
                'last_error'     => isset($k['last_error']) ? $k['last_error'] : null,
                'last_used'      => isset($k['last_used']) ? $k['last_used'] : null,
            );
        }
        $out[] = array('id' => $p['id'], 'name' => $p['name'], 'enabled' => !empty($p['enabled']), 'keys' => $keys);
    }
    echo json_encode(array('ok' => true, 'data' => array('providers' => $out)));
    exit;
}

// ---- POST: everything below needs a verified admin ----
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

$action = isset($body['action']) ? $body['action'] : 'toggle';

// ---- action=toggle (default, unchanged): provider-level on/off ----
if ($action === 'toggle') {
    $id      = isset($body['id']) ? $body['id'] : '';
    $enabled = !empty($body['enabled']);
    $found   = false;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] === $id) { $p['enabled'] = $enabled; $found = true; break; }
    }
    unset($p);
    if (!$found) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Unknown provider: ' . $id)); exit; }
    if (providers_save($cfg) === false) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json - check file permissions')); exit; }
    echo json_encode(array('ok' => true, 'data' => array('id' => $id, 'enabled' => $enabled)));
    exit;
}

// ---- action=add_key: append a new key to a provider ----
if ($action === 'add_key') {
    $providerId = isset($body['provider_id']) ? trim($body['provider_id']) : '';
    $label      = isset($body['label']) ? trim($body['label']) : '';
    $key        = isset($body['key']) ? trim($body['key']) : '';
    $model      = isset($body['model']) ? trim($body['model']) : '';
    $base       = isset($body['base']) ? trim($body['base']) : '';
    if ($label === '' || $key === '' || $model === '') {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'label, key, and model are all required.'));
        exit;
    }
    $found = false;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] !== $providerId) continue;
        $found = true;
        if (!isset($p['keys'])) $p['keys'] = array();
        $newKey = array(
            'id' => $providerId[0] . substr(md5(uniqid('', true)), 0, 6),
            'label' => $label, 'key' => $key, 'model' => $model,
            'enabled' => true, 'cooldown_until' => null, 'last_error' => null, 'last_used' => null,
        );
        if ($base !== '') $newKey['base'] = $base;
        $p['keys'][] = $newKey;
        break;
    }
    unset($p);
    if (!$found) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Unknown provider: ' . $providerId)); exit; }
    if (providers_save($cfg) === false) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json')); exit; }
    echo json_encode(array('ok' => true, 'data' => array('added' => true)));
    exit;
}

// ---- action=remove_key ----
if ($action === 'remove_key') {
    $providerId = isset($body['provider_id']) ? $body['provider_id'] : '';
    $keyId      = isset($body['key_id']) ? $body['key_id'] : '';
    $found = false;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] !== $providerId) continue;
        $before = count($p['keys']);
        $p['keys'] = array_values(array_filter($p['keys'], function ($k) use ($keyId) { return $k['id'] !== $keyId; }));
        $found = count($p['keys']) < $before;
        break;
    }
    unset($p);
    if (!$found) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Key not found.')); exit; }
    if (providers_save($cfg) === false) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json')); exit; }
    echo json_encode(array('ok' => true, 'data' => array('removed' => true)));
    exit;
}

// ---- action=toggle_key ----
if ($action === 'toggle_key') {
    $providerId = isset($body['provider_id']) ? $body['provider_id'] : '';
    $keyId      = isset($body['key_id']) ? $body['key_id'] : '';
    $enabled    = !empty($body['enabled']);
    $found = false;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] !== $providerId) continue;
        foreach ($p['keys'] as &$k) {
            if ($k['id'] === $keyId) {
                $k['enabled'] = $enabled;
                // Manually re-enabling a key should give it an immediate
                // fresh shot rather than waiting out an old cooldown.
                if ($enabled) $k['cooldown_until'] = null;
                $found = true;
                break;
            }
        }
        unset($k);
        break;
    }
    unset($p);
    if (!$found) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Key not found.')); exit; }
    if (providers_save($cfg) === false) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json')); exit; }
    echo json_encode(array('ok' => true, 'data' => array('id' => $keyId, 'enabled' => $enabled)));
    exit;
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'Unknown action: ' . $action));
