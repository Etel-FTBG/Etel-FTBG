<?php
// Delivery/Ride only match an EtelID to a local account by phone number when EtelID says the number is trusted
// (verified, or verification is not required yet).   php delivery/tests/etelid_phone_trust_test.php
define('APP_VER', 'test');
require_once __DIR__ . '/../api/etelid_bridge.php';
$fails = 0;
function check($name, $ok) { global $fails; echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n"; if (!$ok) $fails++; }
echo "EtelID phone trust\n";
$base = array('ok' => true, 'member' => array('etel_id' => 'EG-1', 'full_name' => 'A B', 'phone' => '0911', 'status' => 'active'));
$m = etelid_bridge_member_from_service($base);
check('an older EtelID that sends no field is trusted', $m['phone_trusted'] === true && !etelid_bridge_phone_untrusted($m));
$base['member']['phone_trusted'] = true;
check('phone_trusted true is trusted', !etelid_bridge_phone_untrusted(etelid_bridge_member_from_service($base)));
$base['member']['phone_trusted'] = false;
$m = etelid_bridge_member_from_service($base);
check('phone_trusted false is carried through', $m['phone_trusted'] === false);
check('...and counts as untrusted', etelid_bridge_phone_untrusted($m));
check('a member row without the field (SSO token path, verified) is not untrusted', !etelid_bridge_phone_untrusted(array('etel_id' => 'EG-1')));
check('the SSO token path can mark a member untrusted', etelid_bridge_phone_untrusted(array('etel_id' => 'EG-1', 'phone_trusted' => false)));
check('a non-array is not untrusted', !etelid_bridge_phone_untrusted(null));
echo $fails ? "FAILED: $fails\n" : "ALL PASS\n";
exit($fails ? 1 : 0);
