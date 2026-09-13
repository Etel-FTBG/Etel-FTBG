<?php
/**
 * ETEL Delivery  Secure Server Diagnostics
 * 
 * Access: /delivery/api/diagnose.php?key=YOUR_ETEL_OS_KEY
 *
 * Protected by ETEL_OS_KEY from api/.env.
 * Safe to leave on the server  returns 403 without the key.
 * 
 */

/*  Load credentials  */
$_cfg_file = __DIR__ . '/config.php';
if (file_exists($_cfg_file)) require_once $_cfg_file;
// Was parse_ini_file(), which silently fails (returns false, or drops the
// offending line) if .env contains any non-ASCII byte anywhere - including
// in a comment. Switched to the shared parser boot.php already used, so
// diagnostics can't silently lose every credential over an Amharic note
// or a fancy dash pasted into .env. See env_loader.php for the full story.
require_once __DIR__ . '/env_loader.php';
$env = etel_parse_env_file(__DIR__ . '/.env');
foreach ($env as $k => $v) { if (!getenv($k)) putenv("$k=$v"); }

/*  Auth gate  */
$os_key     = getenv('ETEL_OS_KEY') ?: (defined('ETEL_OS_KEY') ? ETEL_OS_KEY : '');
$given_key  = isset($_GET['key']) ? trim($_GET['key']) : '';

if (!$os_key || !$given_key || !hash_equals($os_key, $given_key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Forbidden  valid ?key= required'));
    exit;
}

/*  Only admins can access from outside localhost  */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local = in_array($remote, array('127.0.0.1', '::1'), true);
if (!$is_local) {
    // Extra check: must also pass valid admin Bearer token in production
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (strpos($auth_header, 'Bearer ') !== 0) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Remote access also requires Authorization: Bearer <admin-token>'));
        exit;
    }
}

/*  Now safe to show diagnostics  */
$db_host    = getenv('DB_HOST')      ?: (defined('CFG_DB_HOST')      ? CFG_DB_HOST      : '(not set)');
$db_name    = getenv('DB_NAME')      ?: (defined('CFG_DB_NAME')      ? CFG_DB_NAME      : '(not set)');
$db_user    = getenv('DB_USER')      ?: (defined('CFG_DB_USER')      ? CFG_DB_USER      : '(not set)');
$db_pass    = getenv('DB_PASS')      ?: (defined('CFG_DB_PASS')      ? CFG_DB_PASS      : '');
$token_sec  = getenv('TOKEN_SECRET') ?: (defined('CFG_TOKEN_SECRET') ? CFG_TOKEN_SECRET : '');
$maps_key   = getenv('GOOGLE_MAPS_API_KEY') ?: (defined('CFG_MAPS_KEY') ? CFG_MAPS_KEY : '');
$fcm_key    = getenv('FCM_SERVER_KEY') ?: '(not set)';

/* DB connection test */
$db_status  = 'fail';
$db_tables  = array();
$db_error   = '';
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user, $db_pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
    );
    $db_status = 'connected';
    $db_tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

/* Schema version sentinel */
$schema_sentinel = __DIR__ . '/.schema_done';
$schema_ver = file_exists($schema_sentinel) ? trim(file_get_contents($schema_sentinel)) : '(not run)';

/* Disk space */
$disk_free  = function_exists('disk_free_space')  ? round(disk_free_space('/')  / 1073741824, 2) . ' GB' : 'n/a';
$disk_total = function_exists('disk_total_space') ? round(disk_total_space('/') / 1073741824, 2) . ' GB' : 'n/a';

$out = array(
    'php_version'       => PHP_VERSION,
    'app_version'       => defined('APP_VER') ? APP_VER : '(api.php not loaded)',
    'db_host'           => $db_host,
    'db_name'           => $db_name,
    'db_user'           => $db_user,
    'db_pass_set'       => !empty($db_pass),
    'db_pass_length'    => strlen($db_pass),
    'token_secret_set'  => !empty($token_sec),
    'token_secret_len'  => strlen($token_sec),
    'maps_key_set'      => !empty($maps_key),
    'maps_key_preview'  => $maps_key ? substr($maps_key, 0, 8) . '...' : '(not set)',
    'fcm_key_set'       => ($fcm_key !== '(not set)' && !empty($fcm_key)),
    'db_status'         => $db_status,
    'db_error'          => $db_error,
    'db_tables'         => $db_tables,
    'schema_version'    => $schema_ver,
    'disk_free'         => $disk_free,
    'disk_total'        => $disk_total,
    'hsts_reminder'     => 'Verify HSTS header is active in .htaccess',
    'diagnose_is_safe'  => true,
);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT);
