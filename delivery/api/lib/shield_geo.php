<?php
/**
 * Etel Shield - Ethiopia only (the owner switches it: off | monitor | enforce; monitor = only write the security log).
 * Two checks, each with its own mode:
 *   geo_mode         the CONNECTION: is the address an Ethiopian one (list in shield_geo_data.php; or the country Cloudflare reports, if the domain is behind it)?
 *   geo_device_mode  the DEVICE: the position the app sends (a courier's GPS, a delivery or pick-up point) must be inside Ethiopia.
 * Never touched: this server and local networks, the owner's allowed addresses, admin actions for the device check, the actions on the exempt list.
 * Honest limit: an address list is a good signal, not a certain one (phone networks share addresses, lists lag behind). That is why it starts in monitor mode and
 * the security log shows exactly who would have been turned away. PHP 5 style, ASCII only.
 */
require_once __DIR__ . '/shield_geo_data.php';

if (!function_exists('etel_shield_geo_is_et')) {

/* IPv4 as an unsigned integer, or null */
function etel_shield_geo_v4int($ip) {
    $b = @inet_pton($ip);
    if ($b === false || strlen($b) !== 4) return null;
    $a = unpack('N', $b);
    return sprintf('%u', $a[1]) + 0;
}

/* Ethiopian IPv4 ranges as sorted array(start, end) pairs, built once per request */
function etel_shield_geo_v4_table() {
    static $t = null;
    if ($t !== null) return $t;
    $r = etel_shield_geo_et_ranges();
    $t = array();
    foreach ($r['v4'] as $c) {
        $p = explode('/', $c);
        $s = etel_shield_geo_v4int($p[0]);
        if ($s === null) continue;
        $len = isset($p[1]) ? intval($p[1]) : 32;
        $size = pow(2, 32 - $len);
        $t[] = array($s, $s + $size - 1);
    }
    sort($t);
    return $t;
}

/* true when the address is in the Ethiopian list */
function etel_shield_geo_is_et($ip) {
    if (strpos($ip, ':') === false) {
        $n = etel_shield_geo_v4int($ip);
        if ($n === null) return false;
        $t = etel_shield_geo_v4_table();
        $lo = 0; $hi = count($t) - 1;
        while ($lo <= $hi) {
            $mid = (int) (($lo + $hi) / 2);
            if ($n < $t[$mid][0]) $hi = $mid - 1;
            elseif ($n > $t[$mid][1]) $lo = $mid + 1;
            else return true;
        }
        return false;
    }
    $r = etel_shield_geo_et_ranges();
    foreach ($r['v6'] as $c) { if (etel_shield_cidr_match($ip, $c)) return true; }
    return false;
}

/* true when this connection is clearly NOT Ethiopian. $country is what Cloudflare reported ('' = not known). */
function etel_shield_geo_foreign($ip, $country, $cfg) {
    if ($country !== '' && $country !== 'XX' && $country !== 'T1') {
        $allow = isset($cfg['geo_allow_countries']) ? $cfg['geo_allow_countries'] : array('ET');
        return !in_array($country, $allow, true);
    }
    return !etel_shield_geo_is_et($ip);
}

/* Ethiopia's outline, as a box: latitude 3.3 to 15.0, longitude 32.9 to 48.1 (a position outside the box is not in Ethiopia; inside it may be a neighbour's border strip) */
function etel_shield_geo_in_box($lat, $lng) {
    return $lat >= 3.3 && $lat <= 15.0 && $lng >= 32.9 && $lng <= 48.1;
}

/* the first position in the request that is outside Ethiopia: array(field, lat, lng), or null. (0,0) and missing values mean "not set". */
function etel_shield_geo_device_out($body) {
    $pairs = array(array('lat', 'lng'), array('latitude', 'longitude'), array('delivery_lat', 'delivery_lng'), array('customer_lat', 'customer_lng'), array('pickup_lat', 'pickup_lng'),
                   array('dest_lat', 'dest_lng'), array('dropoff_lat', 'dropoff_lng'), array('from_lat', 'from_lng'), array('to_lat', 'to_lng'), array('origin_lat', 'origin_lng'),
                   array('start_lat', 'start_lng'), array('end_lat', 'end_lng'), array('shop_lat', 'shop_lng'));
    if (!is_array($body)) return null;
    foreach ($pairs as $p) {
        if (!isset($body[$p[0]]) || !isset($body[$p[1]]) || !is_numeric($body[$p[0]]) || !is_numeric($body[$p[1]])) continue;
        $la = floatval($body[$p[0]]); $ln = floatval($body[$p[1]]);
        if ($la == 0 && $ln == 0) continue;
        if (abs($la) > 90 || abs($ln) > 180 || !etel_shield_geo_in_box($la, $ln)) return array($p[0], $la, $ln);
    }
    return null;
}

}
