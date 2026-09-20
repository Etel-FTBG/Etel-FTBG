<?php
// TEMPORARY DIAGNOSTIC - DELETE THIS FILE after checking.
// Shows the actual DB_HOST/DB_NAME/DB_USER being used (never the
// password) and attempts a real connection, showing MySQL's own error
// message if it fails - that message says exactly what's wrong
// (unknown database, access denied, unknown host, etc.) instead of
// guessing further.
//
// SECURITY: this file was found reachable with NO auth gate, leaking
// DB host/name/user/prefix and (on the cross-DB failure branch) the
// full EtelID session/member table+column list to any unauthenticated
// request. Gated the same way migrate.php/seed-admin.php already are -
// visit with ?key=YOUR_TAXI_ADMIN_KEY, or just delete this file (its
// own header above already said to).
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!defined('TAXI_ADMIN_KEY') || TAXI_ADMIN_KEY === '' || !hash_equals(TAXI_ADMIN_KEY, $key)) {
    http_response_code(401);
    echo json_encode(array('error' => 'Unauthorized - pass ?key=YOUR_TAXI_ADMIN_KEY'));
    exit;
}

$envExists = is_readable(__DIR__ . '/.env');

$result = array(
    'env_file_exists' => $envExists,
    'db_host' => DB_HOST,
    'db_name' => DB_NAME,
    'db_user' => DB_USER,
    'db_pass_length' => strlen(DB_PASS), // length only, never the value
    'db_prefix' => DB_PREFIX,
);

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
    $result['connection'] = 'SUCCESS';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $result['tables_found'] = count($tables);

    // Cross-DB grant check - the actual thing blocking driver EtelID
    // registration. Reports the real MySQL error instead of guessing.
    try {
        $pdo->query('SELECT 1 FROM ' . ETELID_DB_NAME . '.' . ETELID_DB_PREFIX . 'sessions LIMIT 1');
        $result['etelid_cross_db_select'] = 'SUCCESS';
    } catch (Exception $e2) {
        $result['etelid_cross_db_select'] = 'FAILED';
        $result['etelid_cross_db_error'] = $e2->getMessage();
        $result['etelid_db_name_used'] = ETELID_DB_NAME;
        $result['etelid_prefix_used'] = ETELID_DB_PREFIX;
        // Grant clearly works now (we got "table doesn't exist", not
        // "access denied") - list the real table names so we can see
        // the actual casing/prefix instead of guessing.
        try {
            $real = $pdo->query("SHOW TABLES FROM `" . ETELID_DB_NAME . "`")->fetchAll(PDO::FETCH_COLUMN);
            $result['etelid_real_tables'] = $real;
        } catch (Exception $e3) {
            $result['etelid_real_tables_error'] = $e3->getMessage();
        }
        // Also confirm the real column names on the actual tables, so
        // the fixed query is right on the first try instead of guessing.
        foreach (array('Etelid_etelid_sessions', 'Etelid_etelid_members') as $rt) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM `" . ETELID_DB_NAME . "`.`$rt`")->fetchAll(PDO::FETCH_COLUMN);
                $result['columns_' . $rt] = $cols;
            } catch (Exception $e4) {
                $result['columns_' . $rt . '_error'] = $e4->getMessage();
            }
        }
    }
} catch (Exception $e) {
    $result['connection'] = 'FAILED';
    $result['real_error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
