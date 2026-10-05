<?php
/**
 * Trip start PIN (optional, off by default).
 *
 * When pricing_rules 'ride_pin_required' = 1, the driver must type the rider's
 * 4-digit PIN to start a trip, so the rider knows they are in the right car and
 * a driver cannot start (and bill) a trip for a rider who is not there.
 *
 * The PIN is not stored: it is derived from the ride id and the rider id with a
 * server secret, so it can never leak through "SELECT r.*" style queries that
 * the driver's screens also use. Street-hail trips (the rider is standing next
 * to the driver) are exempt. Wrong guesses are counted per ride; after 5 the
 * ride cannot be started with a PIN any more (rider cancels and rebooks).
 */

if (!function_exists('etel_ride_pin')) {

function etel_ride_pin_required($pdo) {
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_pin_required' LIMIT 1");
        $s->execute();
        return intval($s->fetchColumn()) === 1;
    } catch (Exception $e) { return false; } // fails open: never blocks trips on an error
}

function etel_ride_pin($rideId, $passengerId) {
    $secret = defined('TOKEN_SECRET') ? TOKEN_SECRET : 'unset';
    $h = hash_hmac('sha256', 'ride-pin|' . intval($rideId) . '|' . intval($passengerId), $secret);
    return str_pad((string) (hexdec(substr($h, 0, 8)) % 10000), 4, '0', STR_PAD_LEFT);
}

function etel_ride_pin_fails($pdo, $rideId) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_pin_fails (ride_id INT NOT NULL PRIMARY KEY, n INT NOT NULL DEFAULT 0, last_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $s = $pdo->prepare("SELECT n FROM ride_pin_fails WHERE ride_id=?");
        $s->execute(array(intval($rideId)));
        return intval($s->fetchColumn());
    } catch (Exception $e) { return 0; }
}

function etel_ride_pin_record_fail($pdo, $rideId) {
    try {
        $pdo->prepare("INSERT INTO ride_pin_fails (ride_id, n, last_at) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE n = n + 1, last_at = NOW()")
            ->execute(array(intval($rideId)));
    } catch (Exception $e) {}
}

/* Call from driver_start_ride before the UPDATE. Answers and exits when the PIN is missing/wrong. */
function etel_ride_pin_gate($pdo, $rideId, $driverId, $pin) {
    if (!etel_ride_pin_required($pdo)) return;
    try {
        $s = $pdo->prepare("SELECT passenger_id, pickup_address FROM rides WHERE id=? AND driver_id=? AND status='accepted' LIMIT 1");
        $s->execute(array(intval($rideId), intval($driverId)));
        $r = $s->fetch();
    } catch (Exception $e) { return; }
    if (!$r || !$r['passenger_id'] || $r['pickup_address'] === 'Street hail') return;
    if (etel_ride_pin_fails($pdo, $rideId) >= 5) {
        fail('Too many wrong PINs for this trip. Ask the rider to cancel and book again, or contact support.', 429, array('code' => 'pin_locked'));
    }
    $pin = preg_replace('/[^0-9]/', '', (string) $pin);
    if ($pin === '') fail('Ask the rider for their 4-digit trip PIN.', 403, array('code' => 'pin_required'));
    if (!hash_equals(etel_ride_pin($rideId, $r['passenger_id']), $pin)) {
        etel_ride_pin_record_fail($pdo, $rideId);
        fail('Wrong PIN. Check it with the rider.', 403, array('code' => 'pin_wrong'));
    }
}

} // end function_exists guard
