<?php
/**
 * Etel Code in Delivery: an agent or admin types a code (E1616) where a phone number goes.
 *
 *   php delivery/tests/etel_code_agent_test.php
 *
 * Runs the REAL code block from api/boot.php that turns a code into the member's phone before the action runs, and the REAL
 * api/etelid_bridge.php (etelid_bridge_code_to_phone / etelid_bridge_find). Only the network call to EtelID is replaced by a stand-in
 * that answers like EtelID's service/resolve. No database and no web server needed.
 *
 * Covers: agent booking (fa_agent_book_ride) and admin phone booking take a code; the other mapped admin phone boxes take a code;
 * a phone number is left exactly as typed; sign-up, profile, payout and emergency-contact fields are NEVER touched; an unknown code
 * stops the action with a 404 (it is never booked for the wrong person or treated as a phone number). A code is only turned into a phone
 * for someone who may use the action (an admin; an approved agent for agent booking): a stranger or a customer learns nothing about codes.
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
        'E1616'   => array('etel_id' => 'EG-2026-00001', 'full_name' => 'Dagi Test',  'phone' => '+251911000001', 'status' => 'active'),
        'EABEBE'  => array('etel_id' => 'EG-2026-00002', 'full_name' => 'Abebe K',    'phone' => '0922000002',    'status' => 'active'),
        'ENOPHONE'=> array('etel_id' => 'EG-2026-00003', 'full_name' => 'No Phone',   'phone' => '',              'status' => 'active'),
    );
    return isset($people[$id]) ? array('ok' => true, 'member' => $people[$id]) : array('ok' => false, 'message' => 'not found');
}
eval($bridge);

/* ---- the real code block from boot.php ---- */
$boot = file_get_contents(__DIR__ . '/../api/boot.php');
$a = strpos($boot, '$etelCodeFields = array(');
$b = strpos($boot, "\nswitch (\$action) {", $a === false ? 0 : $a);
if ($a === false || $b === false) { echo "FAIL: cannot find the Etel Code block in boot.php\n"; exit(1); }
$block = substr($boot, $a, $b - $a);
$block = str_replace("require_once __DIR__ . '/etelid_bridge.php';", '', $block);   // already loaded above
class FailEx extends Exception { public $code2; }
$GLOBALS['__pdo'] = new PDO('sqlite::memory:');
$GLOBALS['__pdo']->exec("CREATE TABLE fa_agents (user_id INT, status VARCHAR(12))");
$GLOBALS['__pdo']->exec("INSERT INTO fa_agents VALUES (7, 'approved'), (8, 'pending')");
$GLOBALS['__user'] = null;
function db() { return $GLOBALS['__pdo']; }
function get_auth_user($pdo) { return $GLOBALS['__user']; }
$ADMIN = array('id' => 1, 'role' => 'admin'); $AGENT = array('id' => 7, 'role' => 'customer'); $PENDING = array('id' => 8, 'role' => 'customer'); $CUSTOMER = array('id' => 9, 'role' => 'customer');
function who($act) { return $act === 'fa_agent_book_ride' ? $GLOBALS['AGENT'] : $GLOBALS['ADMIN']; }
function fail($m, $c = 400) { $e = new FailEx($m); $e->code2 = $c; throw $e; }
function run_block($action, $body) { eval($GLOBALS['__block']); return $body; }
$__block = $block;
function try_block($action, $body, $user = false) { $GLOBALS['__user'] = ($user === false) ? who($action) : $user; try { return array('body' => run_block($action, $body), 'err' => null); } catch (FailEx $e) { return array('body' => $body, 'err' => $e); } }

$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

/* ---- the bridge ---- */
check('code_to_phone: a code gives the member phone', etelid_bridge_code_to_phone('E1616') === '+251911000001');
check('code_to_phone: spaces around the code are fine', etelid_bridge_code_to_phone('  EABEBE ') === '0922000002');
check('code_to_phone: an unknown code gives empty', etelid_bridge_code_to_phone('ENOBODY') === '');
check('code_to_phone: a member with no phone gives empty', etelid_bridge_code_to_phone('ENOPHONE') === '');
$GLOBALS['svc_calls'] = array();
check('code_to_phone: a phone number is not a code and EtelID is not asked', etelid_bridge_code_to_phone('0911000001') === '' && count($GLOBALS['svc_calls']) === 0);
check('code_to_phone: a long ID is not a code', etelid_bridge_code_to_phone('1234567890') === '');
$GLOBALS['svc_down'] = true;
check('code_to_phone: EtelID unreachable gives empty (fails closed)', etelid_bridge_code_to_phone('E1616') === '');
$GLOBALS['svc_down'] = false;

/* ---- the mapped actions: agent booking and the admin phone boxes ---- */
$mapped = array('fa_agent_book_ride', 'admin_phone_book_ride', 'equb_admin_add_member', 'equb_admin_grant_access', 'equity_link_investor_user', 'admin_set_etelpay_override');
foreach ($mapped as $act) {
    $r = try_block($act, array('phone' => 'E1616', 'name' => 'x'));
    check("$act: a code becomes the member's phone", $r['err'] === null && $r['body']['phone'] === '+251911000001' && $r['body']['name'] === 'x');
}
$r = try_block('fa_agent_book_ride', array('phone' => 'e-1616'));
check('agent booking: lower case and a dash are fine', $r['err'] === null && $r['body']['phone'] === '+251911000001');
$r = try_block('fa_agent_book_ride', array('phone' => ' E 1616 '));
check('agent booking: spaces inside are fine', $r['err'] === null && $r['body']['phone'] === '+251911000001');
$r = try_block('fa_agent_book_ride', array('phone' => 'ENOBODY'));
check('agent booking: an unknown code stops with 404 and a clear message', $r['err'] !== null && $r['err']->code2 === 404 && strpos($r['err']->getMessage(), 'Etel Code not found') === 0);
$r = try_block('fa_agent_book_ride', array('phone' => 'ENOPHONE'));
check('agent booking: a member without a phone stops with 404 (nobody to book for)', $r['err'] !== null && $r['err']->code2 === 404);
$GLOBALS['svc_down'] = true;
$r = try_block('fa_agent_book_ride', array('phone' => 'E1616'));
check('agent booking: EtelID unreachable stops with 404, the code is NOT used as a phone', $r['err'] !== null && $r['err']->code2 === 404);
$GLOBALS['svc_down'] = false;
foreach (array('0911000001', '+251911000001', '911000001', '+251 911 000 001') as $ph) {
    $GLOBALS['svc_calls'] = array();
    $r = try_block('fa_agent_book_ride', array('phone' => $ph));
    check("agent booking: phone $ph is left exactly as typed (EtelID not asked)", $r['err'] === null && $r['body']['phone'] === $ph && count($GLOBALS['svc_calls']) === 0);
}
$r = try_block('fa_agent_book_ride', array('phone' => 'E12'));
check('agent booking: E12 is too short to be a code, so it is left as typed', $r['err'] === null && $r['body']['phone'] === 'E12');
$r = try_block('fa_agent_book_ride', array('phone' => array('E1616')));
check('agent booking: a non-text phone value is ignored', $r['err'] === null && $r['body']['phone'] === array('E1616'));
$r = try_block('fa_agent_book_ride', array('name' => 'no phone box'));
check('agent booking: no phone box at all is fine', $r['err'] === null && !isset($r['body']['phone']));
$r = try_block('fa_agent_book_ride', array('phone' => 'EG-2026-00001'));
check('agent booking: a long Etel ID typed in the phone box is looked up like a code and, if EtelID does not answer for it, refused', $r['err'] !== null || $r['body']['phone'] !== 'EG-2026-00001');

/* ---- who may turn a code into a phone: nobody learns which codes exist ---- */
foreach ($mapped as $act) {
    foreach (array('not signed in' => null, 'a customer' => $CUSTOMER, 'a pending agent' => $PENDING) as $label => $u) {
        $GLOBALS['svc_calls'] = array();
        $r = try_block($act, array('phone' => 'ENOBODY'), $u);
        check("$act, $label, unknown code: no 404 about codes, the value is left as typed, EtelID is not asked",
            $r['err'] === null && $r['body']['phone'] === 'ENOBODY' && count($GLOBALS['svc_calls']) === 0);
        $GLOBALS['svc_calls'] = array();
        $r = try_block($act, array('phone' => 'E1616'), $u);
        check("$act, $label, real code: the same (a stranger cannot tell a real code from a wrong one)",
            $r['err'] === null && $r['body']['phone'] === 'E1616' && count($GLOBALS['svc_calls']) === 0);
    }
}
$GLOBALS['svc_calls'] = array();
$r = try_block('admin_phone_book_ride', array('phone' => 'E1616'), $AGENT);
check('an agent cannot use a code in an ADMIN action (left as typed, EtelID not asked)', $r['err'] === null && $r['body']['phone'] === 'E1616' && count($GLOBALS['svc_calls']) === 0);
$r = try_block('fa_agent_book_ride', array('phone' => 'E1616'), $ADMIN);
check('an admin who is not an agent cannot use a code in AGENT booking (they use admin phone booking)', $r['err'] === null && $r['body']['phone'] === 'E1616');
$r = try_block('admin_phone_book_ride', array('phone' => 'E1616'), $ADMIN);
check('an admin can use a code in an admin action', $r['err'] === null && $r['body']['phone'] === '+251911000001');
$r = try_block('fa_agent_book_ride', array('phone' => 'E1616'), $AGENT);
check('an approved agent can use a code in agent booking', $r['err'] === null && $r['body']['phone'] === '+251911000001');
$GLOBALS['__pdo']->exec("DROP TABLE fa_agents");
$r = try_block('fa_agent_book_ride', array('phone' => 'E1616'), $AGENT);
check('if the agents table cannot be read, nothing is converted (fails closed)', $r['err'] === null && $r['body']['phone'] === 'E1616');
$GLOBALS['__pdo']->exec("CREATE TABLE fa_agents (user_id INT, status VARCHAR(12))");
$GLOBALS['__pdo']->exec("INSERT INTO fa_agents VALUES (7, 'approved'), (8, 'pending')");

/* ---- fields that must stay exactly what the person typed ---- */
foreach (array('register', 'sg_register', 'courier_register', 'update_profile', 'driver_update_profile', 'driver_cashout', 'ride_safety_save',
               'admin_register_person', 'admin_register_driver', 'admin_create_user', 'login', 'sg_login', 'fa_agent_apply', 'submit_ride_issue') as $act) {
    $GLOBALS['svc_calls'] = array();
    $r = try_block($act, array('phone' => 'E1616', 'emergency_phone' => 'E1616', 'payout_phone' => 'E1616'));
    check("$act: a code in a phone box is NOT converted here (sign-up, profile, payout, emergency contact stay as typed)",
        $r['err'] === null && $r['body']['phone'] === 'E1616' && $r['body']['payout_phone'] === 'E1616' && count($GLOBALS['svc_calls']) === 0);
}

/* ---- the list itself is the six actions the owner approved, nothing else ---- */
preg_match('/\$etelCodeFields = array\((.*?)\);/s', $block, $m);
preg_match_all("/'([a-z_]+)'\s*=>\s*'phone'/", $m[1], $keys);
sort($keys[1]); $want = $mapped; sort($want);
check('the mapped action list is exactly the six agreed actions', $keys[1] === $want);

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails ? 1 : 0);
