<?php
// Payment / bank connections (api/lib/integrations.php): encryption, saving from Admin, fallback to api/.env, secrets never shown.
// Throwaway test database only (api/.env). Exits 0 on pass, 1 on failure, 0 with SKIP when there is no database.
require_once __DIR__ . '/../api/env_loader.php';
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $ex) { echo "SKIP: no test database (" . $ex->getMessage() . ")\n"; exit(0); }
if (!defined('APP_VER')) define('APP_VER', 'integrations-test');
putenv('INTEGRATIONS_KEY=test-key-one');
require_once __DIR__ . '/../api/lib/integrations.php';

$fails = 0; $done = false;
register_shutdown_function(function () { global $done; if (!$done) { echo "FAILED: the test stopped before it finished\n"; exit(1); } });
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

etel_integration_ensure_schema($pdo);
$pdo->exec('DELETE FROM integration_settings');
etel_integration_saved($pdo, true);

echo "encryption\n";
$blob = etel_integ_encrypt('CHASECK_TEST-abc123');
check('encrypts to something that does not contain the text', $blob !== '' && strpos($blob, 'abc123') === false && strpos(base64_decode($blob), 'abc123') === false);
check('decrypts back', etel_integ_decrypt($blob) === 'CHASECK_TEST-abc123');
check('two encryptions of the same text differ', etel_integ_encrypt('x') !== etel_integ_encrypt('x'));
$raw = base64_decode($blob); $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
check('a tampered value is refused', etel_integ_decrypt(base64_encode($raw)) === null);
check('garbage is refused', etel_integ_decrypt('not base64 !!') === null && etel_integ_decrypt('') === null);
putenv('INTEGRATIONS_KEY=test-key-two');
check('a different server secret cannot read it', etel_integ_decrypt($blob) === null);
putenv('INTEGRATIONS_KEY=test-key-one');

echo "saving and reading\n";
check('nothing saved: get falls back to the default', etel_integration_get('chapa', 'secret_key', 'dflt') === 'dflt' || etel_integ_env('CHAPA_SECRET_KEY') !== '');
$r = etel_integration_save($pdo, 'chapa', array('secret_key' => 'CHASECK_TEST-realkey999'), 1);
check('save works', $r['ok'] === true);
check('the saved value is used', etel_integration_get('chapa', 'secret_key') === 'CHASECK_TEST-realkey999');
check('source says admin', etel_integration_source('chapa', 'secret_key') === 'admin');
$stored = $pdo->query("SELECT value_enc FROM integration_settings WHERE provider='chapa'")->fetchColumn();
check('the database holds only the encrypted text', $stored !== '' && strpos($stored, 'realkey999') === false);
$ov = json_encode(etel_integration_overview($pdo));
check('the overview never contains the secret', strpos($ov, 'realkey999') === false && strpos($ov, 'CHASECK') === false);
$chapa = null; foreach (etel_integration_overview($pdo) as $p) { if ($p['provider'] === 'chapa') $chapa = $p; }
check('overview says chapa is ready, from admin, and that it is wired', $chapa && $chapa['state'] === 'ready' && $chapa['fields'][0]['source'] === 'admin' && $chapa['wired'] === true);

echo "non-secret fields, blanks, clearing\n";
$r = etel_integration_save($pdo, 'telebirr', array('merchant_id' => 'M-1234', 'api_key' => 'ak', 'secret_key' => ''), 1);
check('saves the filled fields and skips the blank one', $r['ok'] === true && etel_integration_get('telebirr', 'merchant_id') === 'M-1234' && etel_integration_source('telebirr', 'secret_key') !== 'admin');
$tb = null; foreach (etel_integration_overview($pdo) as $p) { if ($p['provider'] === 'telebirr') $tb = $p; }
check('a non-secret value is shown so staff can check it, a secret is not', $tb['fields'][0]['value'] === 'M-1234' && $tb['fields'][1]['value'] === '' && $tb['state'] !== 'ready');
$r = etel_integration_save($pdo, 'chapa', array('secret_key' => ''), 1);
check('a blank value keeps what is saved and says nothing to save', $r['ok'] === false && etel_integration_get('chapa', 'secret_key') === 'CHASECK_TEST-realkey999');
$r = etel_integration_save($pdo, 'telebirr', array('merchant_id' => '__clear__'), 1);
check('__clear__ removes a saved value', $r['ok'] === true && etel_integration_source('telebirr', 'merchant_id') !== 'admin');

echo "refusals\n";
check('an unknown provider is refused', etel_integration_save($pdo, 'nope', array('x' => 'y'), 1)['ok'] === false);
check('an API address must be https', etel_integration_save($pdo, 'santimpay', array('base_url' => 'http://example.com'), 1)['ok'] === false);
check('an https API address is accepted', etel_integration_save($pdo, 'santimpay', array('base_url' => 'https://api.example.com/v1'), 1)['ok'] === true);
check('hidden characters are refused', etel_integration_save($pdo, 'santimpay', array('api_key' => "abc\ndef"), 1)['ok'] === false);
check('a provider with no automatic test says so', strpos(etel_integration_test('santimpay')['message'], 'no automatic test') !== false && etel_integration_test('santimpay')['ok'] === false);

echo "adding a company or bank of our own\n";
$pdo->exec('DELETE FROM integration_custom'); etel_integration_providers(true);
$ad = etel_integration_add_custom($pdo, 'Awash Bank', 'bank', 1);
check('a company is added with a short key', $ad['ok'] === true && $ad['provider'] === 'c1');
$aw = null; foreach (etel_integration_overview($pdo) as $p) { if ($p['provider'] === 'c1') $aw = $p; }
check('it shows in the overview as a bank, not wired, removable, with the standard four fields', $aw && $aw['name'] === 'Awash Bank' && $aw['wired'] === false && $aw['custom'] === true && count($aw['fields']) === 4);
check('keys can be saved for it and are used', etel_integration_save($pdo, 'c1', array('merchant_id' => 'AW-1', 'secret_key' => 'sk-awash-777'), 1)['ok'] === true && etel_integration_get('c1', 'secret_key') === 'sk-awash-777' && etel_integration_get('c1', 'merchant_id') === 'AW-1');
check('the secret is not in the overview', strpos(json_encode(etel_integration_overview($pdo)), 'sk-awash-777') === false);
check('the same name twice, or a built-in name, is refused', etel_integration_add_custom($pdo, 'awash bank', 'bank', 1)['ok'] === false && etel_integration_add_custom($pdo, 'Chapa', 'payment', 1)['ok'] === false);
check('empty, too long, or markup names are refused', etel_integration_add_custom($pdo, '  ', 'bank', 1)['ok'] === false && etel_integration_add_custom($pdo, str_repeat('a', 61), 'bank', 1)['ok'] === false && etel_integration_add_custom($pdo, '<b>x</b>', 'bank', 1)['ok'] === false);
check('a built-in company cannot be removed', etel_integration_remove_custom($pdo, 'chapa', 1)['ok'] === false && etel_integration_get('chapa', 'secret_key') === 'CHASECK_TEST-realkey999');
check('removing an added company also deletes its keys', etel_integration_remove_custom($pdo, 'c1', 1)['ok'] === true && etel_integration_get('c1', 'secret_key') === ''
    && (int) $pdo->query("SELECT COUNT(*) FROM integration_settings WHERE provider='c1'")->fetchColumn() === 0 && etel_integration_field('c1', 'secret_key') === null);

echo "an unreadable saved value (the server secret changed)\n";
putenv('INTEGRATIONS_KEY=test-key-two');
etel_integration_saved($pdo, true);
check('it counts as not set, so nothing breaks', etel_integration_source('chapa', 'secret_key') !== 'admin');
$chapa = null; foreach (etel_integration_overview($pdo) as $p) { if ($p['provider'] === 'chapa') $chapa = $p; }
check('and Admin is told to re-enter it', $chapa['fields'][0]['unreadable'] === true);
putenv('INTEGRATIONS_KEY=test-key-one');

echo "audit trail\n";
$n = (int) $pdo->query("SELECT COUNT(*) FROM integration_changes WHERE provider='chapa' AND action='saved'")->fetchColumn();
check('every change is recorded (who, what, when) without the value', $n >= 1 && $pdo->query("SHOW COLUMNS FROM integration_changes LIKE 'value%'")->fetch() === false);

$pdo->exec('DELETE FROM integration_settings'); $pdo->exec('DELETE FROM integration_changes'); $pdo->exec('DELETE FROM integration_custom');
$done = true;
echo $fails ? "FAILED: $fails check(s)\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
