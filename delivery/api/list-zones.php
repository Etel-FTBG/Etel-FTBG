<?php
// TEMPORARY DIAGNOSTIC - lists every delivery_zones row (including
// inactive ones) so the real Hawassa ones can be told apart from
// mistakenly-added ones. Delete this file after checking.
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$SEED_KEY = 'SEED_ZONES_2026';
if (!isset($_GET['key']) || $_GET['key'] !== $SEED_KEY) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}

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

$pdo = new PDO(
    'mysql:host=' . seed_env('DB_HOST', 'CFG_DB_HOST', 'localhost') . ';dbname=' . seed_env('DB_NAME', 'CFG_DB_NAME', '') . ';charset=utf8mb4',
    seed_env('DB_USER', 'CFG_DB_USER', ''), seed_env('DB_PASS', 'CFG_DB_PASS', ''),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);

$rows = $pdo->query("SELECT id, name, base_fare, active, lat, lng FROM delivery_zones ORDER BY id")->fetchAll();
echo json_encode(array('ok' => true, 'total' => count($rows), 'zones' => $rows), JSON_PRETTY_PRINT);
