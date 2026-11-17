<?php
/**
 * Etel Code name check (action etel_code_whois): shows the owner's name under a typed Etel Code.
 *
 *   php delivery/tests/etel_code_whois_test.php
 *
 * Runs the REAL case block from api/boot.php and the REAL api/etelid_bridge.php; only the EtelID network call is a stand-in.
 * Covers: admin, driver and approved agent get the name; a pending agent, a customer get the same plain "not found" as a wrong
 * code (codes cannot be guessed); nobody signed in gets 401; text that is not a code is never sent to EtelID; EtelID down answers
 * "not found" without an error.
 */
define('APP_VER', 'test');

/* ---- the real bridge, with its network call replaced ---- */
$bridge = file_get_contents(__DIR__ . '/../api/etelid_bridge.php');
if (strpos($bridge, 'function etelid_bridge_service_call(') === false) { echo "FAIL: cannot find etelid_bridge_service_call in etelid_bridge.php\n"; exit(1); }
$bridge = str_replace('function etelid_bridge_service_call(', 'function etelid_bridge_service_call_real(', $bridge);
$bridge = preg_replace('/^<\?php/', '', $bridge);
$GLOBALS['svc_calls'] = array();
$GLOBALS['svc_down'] = false;
function etelid_bridge_service_call($path, $payload, $timeout = 5) {
    $GLOBALS['svc_calls'][] = array($path, $payload);
    if ($GLOBALS['svc_down']) return null;
    // like EtelID itself (Etelid_Code::normalize): upper case, no spaces, dashes or underscores
    $id = preg_replace('/[\s\-_]+/', '', strtoupper(trim(isset($payload['identifier']) ? $payload['identifier'] : '')));
    $people = array(
        'E1616'   => array('etel_id' => 'EG-2026-00001', 'full_name' => 'Test Person One',  'phone' => '+251911000001', 'status' => 'active'),
        'EABEBE'  => array('etel_id' => 'EG-2026-00002', 'full_name' => 'Abebe K',    'phone' => '0922000002',    'status' => 'active'),
        'ENOPHONE'=> array('etel_id' => 'EG-2026-00003', 'full_name' => 'No Phone',   'phone' => '',              'status' => 'active'),
    );
    return isset($people[$id]) ? array('ok' => true, 'member' => $people[$id]) : array('ok' => false, 'message' => 'not found');
}
eval($bridge);


$boot = file_get_contents(__DIR__ . '/../api/boot.php');
$a = strpos($boot, "case 'etel_code_whois': {");
$b = strpos($boot, "case 'get_my_notifications': {", $a === false ? 0 : $a);
if ($a === false || $b === false) { echo "FAIL: cannot find the etel_code_whois case in boot.php\n"; exit(1); }
$block = substr($boot, $a, $b - $a);
$block = str_replace("require_once __DIR__ . '/etelid_bridge.php';", '', $block);
class FailEx extends Exception { public $code2; }
class OkEx extends Exception { public $data; }
$GLOBALS['__pdo'] = new PDO('sqlite::memory:');
$GLOBALS['__pdo']->exec("CREATE TABLE fa_agents (user_id INT, status VARCHAR(12))");
$GLOBALS['__pdo']->exec("INSERT INTO fa_agents VALUES (7, 'approved'), (8, 'pending')");
$GLOBALS['__user'] = null;
function db() { return $GLOBALS['__pdo']; }
function get_auth_user($pdo) { return $GLOBALS['__user']; }
function fail($m, $c = 400) { $e = new FailEx($m); $e->code2 = $c; throw $e; }
function ok($d) { $e = new OkEx('ok'); $e->data = $d; throw $e; }
function run_case($code, $user) {
    $GLOBALS['__user'] = $user; $action = 'etel_code_whois'; $body = array('code' => $code); $_GET = array();
    try { eval('switch ($action) { ' . $GLOBALS['__blk'] . ' }'); return array('ok' => null, 'err' => 'fell through'); }
    catch (OkEx $e) { return array('ok' => $e->data, 'err' => null); }
    catch (FailEx $e) { return array('ok' => null, 'err' => $e->code2); }
}
$__blk = $block;
$fails = 0;
function check($label, $cond) { global $fails; if ($cond) echo "ok   $label\n"; else { echo "FAIL $label\n"; $fails++; } }

$ADMIN = array('id' => 1, 'role' => 'admin'); $DRIVER = array('id' => 5, 'role' => 'driver');
$AGENT = array('id' => 7, 'role' => 'customer'); $PENDING = array('id' => 8, 'role' => 'customer'); $CUST = array('id' => 9, 'role' => 'customer');

$r = run_case('E1616', $ADMIN);   check('admin sees the name', $r['ok'] && $r['ok']['found'] === true && $r['ok']['name'] === 'Test Person One');
$r = run_case('e1616', $ADMIN);   check('lower case code works', $r['ok'] && $r['ok']['found'] === true);
$r = run_case('E-1616', $DRIVER); check('driver sees the name (dash ignored)', $r['ok'] && $r['ok']['found'] === true);
$r = run_case('EABEBE', $AGENT);  check('approved agent sees the name', $r['ok'] && $r['ok']['name'] === 'Abebe K');
$r = run_case('E9999', $ADMIN);   check('unknown code: found false', $r['ok'] && $r['ok']['found'] === false);
$n = count($GLOBALS['svc_calls']);
$r = run_case('E1616', $PENDING); check('pending agent: found false, name not leaked', $r['ok'] && $r['ok']['found'] === false && !isset($r['ok']['name']));
$r = run_case('E1616', $CUST);    check('customer: found false, name not leaked', $r['ok'] && $r['ok']['found'] === false && !isset($r['ok']['name']));
check('no EtelID call was made for pending agent or customer', count($GLOBALS['svc_calls']) === $n);
$r = run_case('E1616', null);     check('not signed in: 401', $r['err'] === 401);
$n = count($GLOBALS['svc_calls']);
$r = run_case('0911000001', $ADMIN); check('a phone number is not a code: found false', $r['ok'] && $r['ok']['found'] === false);
check('a phone number is never sent to EtelID', count($GLOBALS['svc_calls']) === $n);
$GLOBALS['svc_down'] = true;
$r = run_case('E1616', $ADMIN);   check('EtelID down: found false, no error', $r['ok'] && $r['ok']['found'] === false);
$GLOBALS['svc_down'] = false;
$r = run_case('ENOPHONE', $ADMIN); check('member with no phone still shows the name', $r['ok'] && $r['ok']['found'] === true);

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
