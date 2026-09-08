<?php
// ============================================================
// ETEL AI - Model registry endpoint  v1.0
// Deploy as: EtelAi/api/models.php
//
// Serves models-config.json to the widget so its picker is built
// from data, not hardcoded HTML - editing the JSON is enough to
// add/remove/reorder models, no JS changes needed.
//
// Also reports whether each provider is actually usable right now
// (has a real API key / etc.) so the UI can be honest about a
// model being listed but not yet configured, instead of the person
// picking it and getting a confusing failure.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';

$path = __DIR__ . '/models-config.json';
$raw  = @file_get_contents($path);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'models-config.json not found'));
    exit;
}
$cfg = json_decode($raw, true);
if (!is_array($cfg) || !isset($cfg['models'])) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'models-config.json is invalid JSON'));
    exit;
}

function etelai_provider_available($provider) {
    if ($provider === 'gemini') return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
    if ($provider === 'ollama') return true; // local, no key needed - actual reachability checked at chat time
    return false;
}

$models = array();
foreach ($cfg['models'] as $m) {
    $m['available'] = etelai_provider_available(isset($m['provider']) ? $m['provider'] : '');
    unset($m['_comment']);
    $models[] = $m;
}

echo json_encode(array(
    'ok' => true,
    'data' => array(
        'models'         => $models,
        'default_effort' => isset($cfg['default_effort']) ? $cfg['default_effort'] : 'Medium',
    ),
));
