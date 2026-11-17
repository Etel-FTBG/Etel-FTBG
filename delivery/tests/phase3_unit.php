<?php
/**
 * Two-step requirement for admin/staff and the backup age rule (no database server needed; uses in-memory SQLite).
 *
 *   php delivery/tests/phase3_unit.php
 *
 * Covers api/lib/admin_2fa_policy.php (default required, owner setting, emergency .env switch) and
 * api/lib/backup_watch.php (the 26 hour rule).
 */
define('APP_VER', 'test');
require_once __DIR__ . '/../api/lib/admin_2fa_policy.php';
require_once __DIR__ . '/../api/lib/backup_watch.php';

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE pricing_rules (rule_key TEXT, rule_value TEXT)");

putenv('ADMIN_2FA_REQUIRED');
$p = etel_admin_2fa_policy($db);
check('nothing saved: two-step is REQUIRED (the launch default)', $p['required'] === true && $p['source'] === 'default');
check('etel_admin_2fa_required agrees', etel_admin_2fa_required($db) === true);

$db->exec("INSERT INTO pricing_rules VALUES ('require_admin_2fa', '0')");
$p = etel_admin_2fa_policy($db);
check('an owner who saved 0 switched it off', $p['required'] === false && $p['source'] === 'setting');
$db->exec("UPDATE pricing_rules SET rule_value = '1'");
$p = etel_admin_2fa_policy($db);
check('saved 1 = required, source setting', $p['required'] === true && $p['source'] === 'setting');

putenv('ADMIN_2FA_REQUIRED=0');
$p = etel_admin_2fa_policy($db);
check('emergency switch ADMIN_2FA_REQUIRED=0 beats the setting', $p['required'] === false && $p['source'] === 'env');
$db->exec("UPDATE pricing_rules SET rule_value = '0'");
putenv('ADMIN_2FA_REQUIRED=1');
$p = etel_admin_2fa_policy($db);
check('ADMIN_2FA_REQUIRED=1 forces it on even if the setting is 0', $p['required'] === true && $p['source'] === 'env');
foreach (array('false', 'off', 'no', ' 0 ') as $v) { putenv('ADMIN_2FA_REQUIRED=' . $v); check('env value "' . trim($v) . '" means off', etel_admin_2fa_env_override() === '0'); }
foreach (array('true', 'on', 'yes') as $v) { putenv('ADMIN_2FA_REQUIRED=' . $v); check('env value "' . $v . '" means on', etel_admin_2fa_env_override() === '1'); }
putenv('ADMIN_2FA_REQUIRED=banana');
check('an unknown env value is ignored', etel_admin_2fa_env_override() === null);
putenv('ADMIN_2FA_REQUIRED');

$empty = new PDO('sqlite::memory:');
$empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('no pricing_rules table at all: still required (fails safe, not open)', etel_admin_2fa_required($empty) === true);

// the 26 hour rule
$now = 1800000000;
check('no backup at all is stale', etel_backup_is_stale(null, $now, 26) === true);
check('a backup 2 hours old is fine', etel_backup_is_stale($now - 2 * 3600, $now, 26) === false);
check('a backup 25.9 hours old is fine', etel_backup_is_stale($now - (int) (25.9 * 3600), $now, 26) === false);
check('a backup 26.1 hours old is stale', etel_backup_is_stale($now - (int) (26.1 * 3600), $now, 26) === true);
check('a backup 3 days old is stale', etel_backup_is_stale($now - 72 * 3600, $now, 26) === true);

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
