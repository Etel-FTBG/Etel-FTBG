<?php
// Standalone Equb round processor - run via cron (same pattern as
// cron_dispatch_scheduled.php), NOT through boot.php's action router.
// The actual money-movement logic lives in lib/equb_processing.php,
// shared with the admin "force process" action, so there's only one
// place that logic can ever drift out of sync with itself.
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/lib/equb_processing.php';

if (!defined('DB_HOST')) define('DB_HOST', etel_env('DB_HOST', 'CFG_DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', etel_env('DB_NAME', 'CFG_DB_NAME', 'etel_delivery'));
if (!defined('DB_USER')) define('DB_USER', etel_env('DB_USER', 'CFG_DB_USER', ''));
if (!defined('DB_PASS')) define('DB_PASS', etel_env('DB_PASS', 'CFG_DB_PASS', ''));

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false)
    );
    return $pdo;
}

function equb_cron_log($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

try {
    $pdo = db();
} catch (Exception $e) {
    equb_cron_log('FATAL: could not connect to DB: ' . $e->getMessage());
    exit(1);
}

$today = date('Y-m-d');
$dueGroups = $pdo->prepare("SELECT id FROM equb_groups WHERE status IN ('active','at_risk') AND next_contribution_date <= ?");
$dueGroups->execute(array($today));
$groupIds = $dueGroups->fetchAll(PDO::FETCH_COLUMN);

equb_cron_log('Found ' . count($groupIds) . ' group(s) due for processing.');
foreach ($groupIds as $groupId) {
    foreach (equb_process_group_round($pdo, $groupId) as $line) {
        equb_cron_log($line);
    }
}
equb_cron_log('Done.');
