<?php
// ONE-TIME SETUP SCRIPT - geocodes every delivery_zones row that doesn't
// have coordinates yet, using the exact same Google Geocoding API this
// codebase already uses for ride destinations (see AgentController.php's
// agent_geocode_place). Safe to run more than once - only fills in rows
// where lat/lng is still NULL, never overwrites an existing value.
//
// Deploy as: delivery/api/seed-zone-coords.php
// Visit once: https://ethelmarket.com/delivery/api/seed-zone-coords.php?key=SEED_ZONES_2026
// Delete this file afterward.

// Temporary, for this diagnostic script only - shows the real error
// instead of a blank 500, since this is a one-time manual tool, not a
// live user-facing endpoint.
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$SEED_KEY = 'SEED_ZONES_2026'; // change this if you want, just match it in the URL
if (!isset($_GET['key']) || $_GET['key'] !== $SEED_KEY) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized - add ?key=' . $SEED_KEY . ' to the URL'));
    exit;
}

// Self-contained .env parser (deliberately not depending on any assumed
// function name from env_loader.php, to avoid a fatal "undefined
// function" error if its actual internals differ from what was guessed).
$_seed_env = array();
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_seed_env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}
function seed_env($key, $cfg_const, $default = '') {
    global $_seed_env;
    if (isset($_seed_env[$key]) && $_seed_env[$key] !== '') return $_seed_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($cfg_const) && constant($cfg_const) !== '') return constant($cfg_const);
    return $default;
}

$mapsKey = seed_env('GOOGLE_MAPS_API_KEY', 'CFG_MAPS_KEY', '');
if (!$mapsKey) {
    echo json_encode(array('ok' => false, 'error' => 'GOOGLE_MAPS_API_KEY is not set in .env'));
    exit;
}

$dbHost = seed_env('DB_HOST', 'CFG_DB_HOST', 'localhost');
$dbName = seed_env('DB_NAME', 'CFG_DB_NAME', '');
$dbUser = seed_env('DB_USER', 'CFG_DB_USER', '');
$dbPass = seed_env('DB_PASS', 'CFG_DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser, $dbPass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    echo json_encode(array('ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()));
    exit;
}

$rows = $pdo->query("SELECT id, name FROM delivery_zones WHERE active=1 AND (lat IS NULL OR lng IS NULL)")->fetchAll();
$results = array();

foreach ($rows as $row) {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
         . '?address=' . rawurlencode($row['name'] . ', Hawassa, Ethiopia')
         . '&location=7.0504,38.4955&radius=30000'
         . '&bounds=6.85,38.25|7.35,38.85'
         . '&region=et'
         . '&key=' . rawurlencode($mapsKey);
    $ctx = stream_context_create(array('http' => array('timeout' => 15, 'ignore_errors' => true)));
    $resp = @file_get_contents($url, false, $ctx);
    $decoded = $resp ? json_decode($resp, true) : null;

    if ($decoded && $decoded['status'] === 'OK' && !empty($decoded['results'][0]['geometry']['location'])) {
        $loc = $decoded['results'][0]['geometry']['location'];
        $upd = $pdo->prepare('UPDATE delivery_zones SET lat=?, lng=? WHERE id=?');
        $upd->execute(array($loc['lat'], $loc['lng'], $row['id']));
        $results[] = array('zone' => $row['name'], 'status' => 'geocoded', 'lat' => $loc['lat'], 'lng' => $loc['lng']);
    } else {
        $results[] = array('zone' => $row['name'], 'status' => 'failed', 'google_status' => $decoded['status'] ?? 'unknown');
    }
    usleep(200000); // be polite to Google's API - 5 requests/sec max
}

echo json_encode(array('ok' => true, 'processed' => count($rows), 'results' => $results), JSON_PRETTY_PRINT);
