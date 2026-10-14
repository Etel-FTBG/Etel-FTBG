<?php
// What EtelID's check_role answer means for the admin sign-in. "role was removed" must only be said when EtelID really says so.
define('APP_VER', 'test');
require_once __DIR__ . '/../api/etelid_bridge.php';
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }
echo "EtelID role verdict\n";
check('ok answer = role', etelid_bridge_role_verdict(array('ok' => true, 'role_at' => 'Manager')) === 'role');
check('EtelID "not_found" = none (role removed or never given)', etelid_bridge_role_verdict(array('code' => 'not_found', 'message' => 'No active role', 'data' => array('status' => 404))) === 'none');
check('no answer (null) = unreachable, not "removed"', etelid_bridge_role_verdict(null) === 'unreachable');
check('a bad service key (rest_forbidden) = unreachable', etelid_bridge_role_verdict(array('code' => 'rest_forbidden', 'data' => array('status' => 401))) === 'unreachable');
check('a server error body = unreachable', etelid_bridge_role_verdict(array('code' => 'internal_server_error')) === 'unreachable');
check('ok:false without not_found = unreachable', etelid_bridge_role_verdict(array('ok' => false)) === 'unreachable');
check('a non-array answer = unreachable', etelid_bridge_role_verdict('oops') === 'unreachable');
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
