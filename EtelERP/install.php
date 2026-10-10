<?php
/**
 * One-time installer: creates the EtelERP tables from db/schema.sql.
 * Open  https://ethelmarket.com/EtelERP/install.php?key=<INSTALL_KEY from .env>
 * It refuses to run twice (storage/installed.lock). DELETE this file when it says it is done.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/app/Env.php';
require __DIR__ . '/app/Db.php';

$key = Env::get('INSTALL_KEY');
if (strlen($key) < 12 || $key === 'CHANGE_ME_RANDOM') { http_response_code(503); exit("Set INSTALL_KEY in .env first.\n"); }
if (!isset($_GET['key']) || !hash_equals($key, (string) $_GET['key'])) { http_response_code(403); exit("Wrong key.\n"); }
$lock = __DIR__ . '/storage/installed.lock';
if (is_file($lock)) { http_response_code(409); exit("Already installed. Delete install.php from the server.\n"); }
if (!is_writable(__DIR__ . '/storage')) { http_response_code(500); exit("storage/ is not writable.\n"); }

try {
    $pdo = Db::pdo();
    $sql = file_get_contents(__DIR__ . '/db/schema.sql');
    $n = 0;
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/--[^\n]*/', '', $sql)))) as $stmt) { $pdo->exec($stmt); $n++; }
    file_put_contents($lock, date('c') . "\n");
    echo "OK: ran $n statements. EtelERP tables are ready.\nNow delete install.php (and remove INSTALL_KEY from .env).\n";
} catch (Exception $e) {
    error_log('[EtelERP install] ' . $e->getMessage());
    http_response_code(500);
    echo "Install failed: " . (strpos($e->getMessage(), 'Access denied') !== false ? 'database login refused - check DB_* in .env' : 'see the PHP error log') . "\n";
}
