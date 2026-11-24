<?php
/* Etel Shield geo: the Ethiopian address list, the country decision and the position (box) check. Pure functions, no server, no database.
   php delivery/tests/shield_geo_test.php */
require_once __DIR__ . '/../api/lib/shield_store.php';
require_once __DIR__ . '/../api/lib/shield_geo.php';
$pass = 0; $fails = array();
function t($n, $c, $x = '') { global $pass, $fails; if ($c) $pass++; else $fails[] = $n . ($x !== '' ? ' -> ' . $x : ''); }

$r = etel_shield_geo_et_ranges();
t('the list is there: IPv4 and IPv6 ranges', count($r['v4']) > 50 && count($r['v6']) > 20, count($r['v4']) . '/' . count($r['v6']));
t('Ethio telecom 196.188.x is Ethiopian', etel_shield_geo_is_et('196.188.1.5') && etel_shield_geo_is_et('196.188.250.9'));
t('Ethio telecom 196.189.x is Ethiopian', etel_shield_geo_is_et('196.189.200.1'));
t('197.156.64.x (Ethio telecom) is Ethiopian', etel_shield_geo_is_et('197.156.70.3'));
t('Google DNS 8.8.8.8 is not', !etel_shield_geo_is_et('8.8.8.8'));
t('a US address is not', !etel_shield_geo_is_et('52.14.11.9'));
t('the first and the last address of a range are inside', etel_shield_geo_is_et('196.189.0.0') && etel_shield_geo_is_et('196.189.255.255'));
t('garbage is not Ethiopian and does not crash', !etel_shield_geo_is_et('not-an-ip') && !etel_shield_geo_is_et(''));
$v6 = explode('/', $r['v6'][0]);
t('an IPv6 address inside a listed range is Ethiopian', etel_shield_geo_is_et($v6[0]));
t('Google IPv6 DNS is not', !etel_shield_geo_is_et('2001:4860:4860::8888'));

$cfg = array('geo_allow_countries' => array('ET'));
t('the list decides when no country is reported', !etel_shield_geo_foreign('196.188.1.5', '', $cfg) && etel_shield_geo_foreign('8.8.8.8', '', $cfg));
t('a reported country ET is not foreign, whatever the list says', !etel_shield_geo_foreign('8.8.8.8', 'ET', $cfg));
t('a reported country US is foreign, whatever the list says', etel_shield_geo_foreign('196.188.1.5', 'US', $cfg));
t('XX and T1 (unknown / Tor) fall back to the list', !etel_shield_geo_foreign('196.188.1.5', 'XX', $cfg) && etel_shield_geo_foreign('8.8.8.8', 'T1', $cfg));
$cfg2 = array('geo_allow_countries' => array('ET', 'KE'));
t('an extra allowed country is accepted', !etel_shield_geo_foreign('8.8.8.8', 'KE', $cfg2) && etel_shield_geo_foreign('8.8.8.8', 'US', $cfg2));

t('Addis Ababa is in the box', etel_shield_geo_in_box(9.03, 38.74));
t('Hawassa is in the box', etel_shield_geo_in_box(7.05, 38.48));
t('Mekelle and Gambela are in the box', etel_shield_geo_in_box(13.5, 39.47) && etel_shield_geo_in_box(8.25, 34.58));
t('Nairobi is not', !etel_shield_geo_in_box(-1.29, 36.82));
t('Paris and New York are not', !etel_shield_geo_in_box(48.85, 2.35) && !etel_shield_geo_in_box(40.7, -74.0));
t('Dubai is not', !etel_shield_geo_in_box(25.2, 55.27));

t('a request with a position in Ethiopia passes', etel_shield_geo_device_out(array('lat' => 7.05, 'lng' => 38.48)) === null);
t('a delivery point in Paris is found', ($o = etel_shield_geo_device_out(array('delivery_lat' => 48.85, 'delivery_lng' => 2.35))) !== null && $o[0] === 'delivery_lat', json_encode($o));
t('(0,0) and missing values mean "not set"', etel_shield_geo_device_out(array('lat' => 0, 'lng' => 0)) === null && etel_shield_geo_device_out(array('lat' => 7.0)) === null && etel_shield_geo_device_out(array()) === null);
t('text values and impossible numbers do not crash', etel_shield_geo_device_out(array('lat' => 'abc', 'lng' => 'x')) === null && etel_shield_geo_device_out(array('lat' => 95, 'lng' => 38)) !== null);
t('a non-array body is ignored', etel_shield_geo_device_out(null) === null && etel_shield_geo_device_out('x') === null);
t('the pick-up point is checked too', etel_shield_geo_device_out(array('pickup_lat' => 51.5, 'pickup_lng' => -0.12)) !== null);

echo $fails ? "FAILED:\n" . implode("\n", $fails) . "\n" : "ok\n";
echo 'PASSED: ' . $pass . ' FAILED: ' . count($fails) . "\n";
exit($fails ? 1 : 0);
