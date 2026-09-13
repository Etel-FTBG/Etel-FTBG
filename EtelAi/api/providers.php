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
//   - action=update_key {provider_id, key_id, model?, label?, base?, key?}
//                         - base/key only changed if actually present in the
//                           request body (an empty base clears it back to
//                           the provider's default endpoint - that's a real
//                           edit, not "field omitted")
//   - action=test_key   {provider_id, key_id} - fires one real, minimal
//                         completion through that exact key right now and
//                         reports back {success, latency_ms, reply|message}
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // This list includes masked keys, models, tunnel URLs, and live error
    // logs - the exact data the admin-only dashboard exists to protect.
    // This branch used to run before any admin check at all (fixed here):
    // anyone who hit this URL with no session got the full config back.
    $getToken = isset($_GET['token']) ? (string) $_GET['token'] : '';
    $getAdmin = etelai_verify_admin_token($pdo, $getToken);
    if (!$getAdmin) {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized - please sign in again'));
        exit;
    }

    $out = array();
    foreach ($cfg['providers'] as $p) {
        $keys = array();
        foreach ((isset($p['keys']) ? $p['keys'] : array()) as $k) {
            $keys[] = array(
                'id'             => $k['id'],
                'label'          => $k['label'],
                'masked_key'     => isset($k['key']) ? providers_mask_key($k['key']) : null,
                'model'          => isset($k['model']) ? $k['model'] : null,
                'base'           => isset($k['base']) ? $k['base'] : null,
                'tag'            => isset($k['tag']) ? $k['tag'] : null,
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
    // Optional free-text task tag ('coding', 'general', etc.) - lets a
    // provider with several keys (e.g. OpenRouter with a few free models)
    // have one preferred for a given kind of task. See chat.php's
    // etelai_try_provider_keys $preferredTag param.
    $tag        = isset($body['tag']) ? trim($body['tag']) : '';
    if ($label === '' || $key === '' || $model === '') {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'label, key, and model are all required.'));
        exit;
    }
    // Catches the exact mistake that actually happened once: typing the
    // provider's display name ("Gemini", "Kimi", "Ollama") into the Model
    // field instead of a real model ID (e.g. "gemini-flash-latest"). Not
    // a full validator - the provider's own API is the real check - just
    // stops this one specific, easy-to-make error before it wastes a key.
    $genericWords = array('gemini', 'kimi', 'moonshot', 'ollama', 'model', 'ai');
    if (in_array(strtolower($model), $genericWords, true)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'That doesn\'t look like a real model ID - it should look like "gemini-flash-latest" or "kimi-k2-0711-preview", not just the provider name.'));
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
        if ($tag !== '') $newKey['tag'] = $tag;
        $p['keys'][] = $newKey;
        break;
    }
    unset($p);
    if (!$found) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Unknown provider: ' . $providerId)); exit; }
    if (providers_save($cfg) === false) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Could not save providers.json')); exit; }
    echo json_encode(array('ok' => true, 'data' => array('added' => true)));
    exit;
}

// ---- action=update_key: change a key's model/label/base/key without losing its stats ----
if ($action === 'update_key') {
    $providerId = isset($body['provider_id']) ? $body['provider_id'] : '';
    $keyId      = isset($body['key_id']) ? $body['key_id'] : '';
    $model      = isset($body['model']) ? trim($body['model']) : '';
    $label      = isset($body['label']) ? trim($body['label']) : '';
    // 'base' and 'key' use array_key_exists rather than a truthy check,
    // since an intentionally-cleared base (going back to a provider's
    // default endpoint) is a legitimate edit, not "field not sent."
    $hasBase = array_key_exists('base', $body);
    $base    = $hasBase ? trim((string)$body['base']) : null;
    $hasRawKey = array_key_exists('key', $body) && trim((string)$body['key']) !== '';
    $rawKey    = $hasRawKey ? trim((string)$body['key']) : null;
    $hasTag  = array_key_exists('tag', $body);
    $tag     = $hasTag ? trim((string)$body['tag']) : null;
    $found = false;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] !== $providerId) continue;
        foreach ($p['keys'] as &$k) {
            if ($k['id'] === $keyId) {
                if ($model !== '') $k['model'] = $model;
                if ($label !== '') $k['label'] = $label;
                if ($hasBase) { if ($base === '') unset($k['base']); else $k['base'] = $base; }
                if ($hasRawKey) $k['key'] = $rawKey;
                if ($hasTag) { if ($tag === '') unset($k['tag']); else $k['tag'] = $tag; }
                // Give it a fresh shot rather than waiting out any old
                // cooldown, since the edit is presumably meant to fix
                // exactly the problem that caused it (e.g. a stale
                // tunnel URL after a restart).
                $k['cooldown_until'] = null;
                $k['last_error'] = null;
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
    echo json_encode(array('ok' => true, 'data' => array('updated' => true)));
    exit;
}

// ---- action=test_key: fire one real, minimal completion through a specific
//      key right now and report back pass/fail + latency - the same check
//      we were doing by hand with curl all session, now one click in the
//      dashboard. Deliberately standalone here (not reusing chat.php's
//      etelai_call_kimi/etelai_call_gemini) since those live inside a file
//      that dispatches on $action itself - including it as a library would
//      risk executing that dispatch logic as a side effect. This mirrors
//      just the generic OpenAI-compatible request shape those functions use. ----
if ($action === 'test_key') {
    $providerId = isset($body['provider_id']) ? $body['provider_id'] : '';
    $keyId      = isset($body['key_id']) ? $body['key_id'] : '';
    $target = null;
    foreach ($cfg['providers'] as $p) {
        if ($p['id'] !== $providerId) continue;
        foreach ($p['keys'] as $k) {
            if ($k['id'] === $keyId) { $target = $k; break 2; }
        }
    }
    if (!$target) { http_response_code(404); echo json_encode(array('ok' => false, 'error' => 'Key not found.')); exit; }

    $model = isset($target['model']) ? $target['model'] : '';
    $t0 = microtime(true);

    if ($providerId === 'gemini') {
        // Gemini's native REST API - completely different shape from
        // everything else here: API key as a query param (not a Bearer
        // header), "contents"/"parts" instead of "messages", and its own
        // endpoint path. Mirrors etelai_call_gemini() in chat.php exactly,
        // just as a single-shot test rather than the full tool-calling loop.
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
             . ':generateContent?key=' . rawurlencode($target['key']);
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode(array(
                'contents' => array(array('role' => 'user', 'parts' => array(array('text' => 'Reply with exactly the word: pong')))),
                'generationConfig' => array('maxOutputTokens' => 30),
            )),
        ));
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $t0) * 1000);

        if ($raw === false) {
            echo json_encode(array('ok' => true, 'data' => array('success' => false, 'latency_ms' => $latencyMs, 'message' => 'Connection failed: ' . $curlErr)));
            exit;
        }
        $decoded = json_decode($raw, true);
        $parts = isset($decoded['candidates'][0]['content']['parts']) ? $decoded['candidates'][0]['content']['parts'] : null;
        $textReply = null;
        if (is_array($parts)) { foreach ($parts as $pp) { if (isset($pp['text'])) { $textReply = trim($pp['text']); break; } } }
        if ($httpCode >= 200 && $httpCode < 300 && $textReply !== null) {
            echo json_encode(array('ok' => true, 'data' => array('success' => true, 'latency_ms' => $latencyMs, 'reply' => $textReply, 'model_used' => $model)));
        } else {
            $errMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : ('HTTP ' . $httpCode . ': ' . substr($raw, 0, 200));
            echo json_encode(array('ok' => true, 'data' => array('success' => false, 'latency_ms' => $latencyMs, 'message' => $errMsg)));
        }
        exit;
    }

    // Every other provider (kimi, ollama, omniroute, and any future one) -
    // generic OpenAI-compatible chat completions, same shape
    // etelai_call_kimi() in chat.php uses for the real thing.
    $base = !empty($target['base']) ? rtrim($target['base'], '/') : '';
    if ($base === '') {
        echo json_encode(array('ok' => true, 'data' => array('success' => false, 'latency_ms' => 0, 'message' => 'No base URL set for this key - required for non-Gemini providers.')));
        exit;
    }
    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $target['key'],
        ),
        CURLOPT_POSTFIELDS => json_encode(array(
            'model' => $model,
            'stream' => false,
            'max_tokens' => 30,
            'messages' => array(array('role' => 'user', 'content' => 'Reply with exactly the word: pong')),
        )),
    ));
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $latencyMs = (int) round((microtime(true) - $t0) * 1000);

    if ($raw === false) {
        echo json_encode(array('ok' => true, 'data' => array(
            'success' => false, 'latency_ms' => $latencyMs,
            'message' => 'Connection failed: ' . $curlErr,
        )));
        exit;
    }
    $decoded = json_decode($raw, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['choices'][0]['message']['content'])) {
        $content = trim($decoded['choices'][0]['message']['content']);
        $usage = isset($decoded['usage']) ? $decoded['usage'] : null;
        echo json_encode(array('ok' => true, 'data' => array(
            'success' => true, 'latency_ms' => $latencyMs, 'reply' => $content,
            'model_used' => isset($decoded['model']) ? $decoded['model'] : $model,
            'usage' => $usage,
        )));
    } else {
        $errMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : ('HTTP ' . $httpCode . ': ' . substr($raw, 0, 200));
        echo json_encode(array('ok' => true, 'data' => array(
            'success' => false, 'latency_ms' => $latencyMs, 'message' => $errMsg,
        )));
    }
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
