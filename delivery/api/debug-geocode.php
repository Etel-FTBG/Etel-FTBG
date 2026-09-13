<?php
// TEMPORARY DIAGNOSTIC - DELETE THIS FILE after checking.
// Tests agent_geocode_place() directly against a real query, to see the
// exact underlying cause (missing key, Geocoding API not enabled on the
// key, genuinely zero results, etc.) instead of guessing from the AI's
// paraphrased error message.
//
// Deliberately does NOT require boot.php - that file's own router runs
// unconditionally at the bottom and would exit before this code ever
// ran. Loads just the .env parsing it needs, standalone.
header('Content-Type: application/json');

$_cfg_file = __DIR__ . '/config.php';
if (file_exists($_cfg_file)) require_once $_cfg_file;
require_once __DIR__ . '/env_loader.php';
$_etel_env = etel_parse_env_file(__DIR__ . '/.env');
function etel_env($key, $cfg_const, $default = '') {
    global $_etel_env;
    if (isset($_etel_env[$key]) && $_etel_env[$key] !== '') return $_etel_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($cfg_const) && constant($cfg_const) !== '') return constant($cfg_const);
    return $default;
}

$query = isset($_GET['q']) ? $_GET['q'] : 'Atote';

$mapsKey = etel_env('GOOGLE_MAPS_API_KEY', 'CFG_MAPS_KEY', '');
if (!$mapsKey) {
    echo json_encode(array('ok' => false, 'diagnosis' => 'GOOGLE_MAPS_API_KEY is not set at all in .env or config.php.'));
    exit;
}

$bias = '7.0504,38.4955';
$url = 'https://maps.googleapis.com/maps/api/geocode/json'
     . '?address=' . rawurlencode($query . ', Hawassa, Ethiopia')
     . '&location=' . rawurlencode($bias)
     . '&radius=30000'
     . '&region=et'
     . '&key=' . rawurlencode($mapsKey);

$ctx = stream_context_create(array('http' => array('timeout' => 15, 'ignore_errors' => true)));
$resp = @file_get_contents($url, false, $ctx);

if ($resp === false) {
    echo json_encode(array('ok' => false, 'diagnosis' => 'Could not reach Google at all (network/firewall issue on this server).'));
    exit;
}

$decoded = json_decode($resp, true);
echo json_encode(array(
    'ok' => true,
    'query' => $query,
    'google_status' => isset($decoded['status']) ? $decoded['status'] : 'UNKNOWN',
    'error_message' => isset($decoded['error_message']) ? $decoded['error_message'] : null,
    'result_count' => isset($decoded['results']) ? count($decoded['results']) : 0,
    'first_result' => isset($decoded['results'][0]['formatted_address']) ? $decoded['results'][0]['formatted_address'] : null,
), JSON_PRETTY_PRINT);
