<?php
// Rider price offer and driver counter-offers.
//
// How it works:
//  - When booking a trip with a destination, the rider may offer their own price (book_ride offer_fare). It must sit
//    between ride_offer_min_pct and ride_offer_max_pct (percent of the quoted fare, default 70 and 200). The ride's
//    fare_final is set to the offer, so every driver screen already shows the offered price.
//  - A driver can accept the offer as it is (driver_accept_ride) or send ONE counter price per ride that is higher than
//    the offer and within the maximum (driver_counter_offer). The rider sees the counters and picks one, or raises the
//    offer (ride_raise_offer), or ignores them.
//  - When a driver is chosen the price is fixed: rides.agreed_fare. At arrival the rider pays exactly that (the meter
//    does not change it), see rides_agreed_apply(). A trip that is ended early or auto-ended is still billed by the
//    meter, because the agreed price was for the whole trip.
//  - Etel's 5% commission, promo codes and the fare review limit work on the agreed fare like on any other fare.

if (!function_exists('rides_offer_price_ensure')) {

function rides_offer_price_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $marker = __DIR__ . '/../.price_offer_ok';
    if (is_file($marker)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_counter_offers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ride_id INT NOT NULL,
            driver_id INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_counter_ride_driver (ride_id, driver_id),
            KEY idx_counter_ride (ride_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[strtolower($c['Field'])] = true;
        if (!isset($have['rider_offer'])) $pdo->exec("ALTER TABLE `rides` ADD COLUMN rider_offer DECIMAL(10,2) NULL DEFAULT NULL");
        if (!isset($have['agreed_fare'])) $pdo->exec("ALTER TABLE `rides` ADD COLUMN agreed_fare DECIMAL(10,2) NULL DEFAULT NULL");
        @file_put_contents($marker, '1');
    } catch (Exception $e) {
        error_log('[ETEL] rides_offer_price_ensure: ' . $e->getMessage());
    }
}

function rides_offer_price_enabled($pdo) {
    return rides_rule_value($pdo, 'ride_offer_enabled', 1) > 0;
}

// Lowest and highest price allowed for a trip quoted at $quote (whole ETB).
function rides_offer_bounds($pdo, $quote) {
    $quote = floatval($quote);
    $minPct = max(10, min(100, rides_rule_value($pdo, 'ride_offer_min_pct', 70)));
    $maxPct = max(100, min(400, rides_rule_value($pdo, 'ride_offer_max_pct', 200)));
    return array(
        'min' => max(10, intval(ceil($quote * $minPct / 100.0))),
        'max' => max(10, intval(floor($quote * $maxPct / 100.0)))
    );
}

// At arrival of a normal trip: a ride with an agreed price is billed at exactly that price. Returns the fare.
function rides_agreed_apply($pdo, $rideId, $fare) {
    try {
        rides_offer_price_ensure($pdo);
        $q = $pdo->prepare("SELECT agreed_fare, fare_breakdown_json FROM rides WHERE id=? LIMIT 1");
        $q->execute(array($rideId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['agreed_fare'] === null || floatval($r['agreed_fare']) <= 0) return $fare;
        $agreed = round(floatval($r['agreed_fare']), 2);
        $bj = json_decode((string)$r['fare_breakdown_json'], true);
        if (!is_array($bj)) $bj = array();
        $bj['agreed_fare'] = $agreed;
        $bj['metered_fare'] = round(floatval($fare), 2);
        $pdo->prepare("UPDATE rides SET fare_final=?, fare_breakdown_json=? WHERE id=?")->execute(array($agreed, json_encode($bj), $rideId));
        return $agreed;
    } catch (Exception $e) {
        error_log('[ETEL] rides_agreed_apply: ' . $e->getMessage());
        return $fare;
    }
}

}
