<?php
// Ride promo codes.
//
// Who and how:
//  - Anyone who is signed in can PROPOSE a code (ride_promo_propose). It starts as 'pending' and does nothing until
//    an admin with rides.manage approves it. Admins can also create a code that is approved straight away.
//  - A rider enters a code when booking. The server checks it again at booking and stores it on the ride.
//
// Money rules (all server-side):
//  - The discount is worked out on the FINAL fare when the trip ends (the fare can change during the trip), see
//    rides_promo_apply_to_arrived(). rides.fare_final then holds what the rider PAYS, so the driver app, the Chapa
//    checkout and the receipt all show the same, discounted number.
//  - The discount is funded by Etel, not by the driver: at completion Etel's 5% commission is still taken on the
//    FULL fare and the discount is credited back to the driver's Etel money balance (see rides_complete_and_pay()).
//  - A discount never takes the fare below ETB 10 or more than 80% off, so a trip is never free.
//  - A code counts as used only when the trip completes. A cancelled trip does not use it up.

if (!function_exists('rides_promo_normalize')) {

function rides_promo_normalize($code) {
    return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)$code)));
}

function rides_promo_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_promo_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            description VARCHAR(255) NULL,
            discount_type VARCHAR(10) NOT NULL DEFAULT 'percent',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_discount DECIMAL(10,2) NULL,
            min_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            max_uses INT NULL,
            used_count INT NOT NULL DEFAULT 0,
            per_rider_limit INT NOT NULL DEFAULT 1,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_by INT NULL,
            created_by_role VARCHAR(20) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ride_promo_code (code),
            KEY idx_ride_promo_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_promo_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            promo_id INT NOT NULL,
            user_id INT NOT NULL,
            ride_id INT NOT NULL,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ride_promo_ride (ride_id),
            KEY idx_ride_promo_user (promo_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[ETEL] rides_promo_ensure tables: ' . $e->getMessage());
    }
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[strtolower($c['Field'])] = true;
        $add = array(
            'promo_id'       => "promo_id INT NULL DEFAULT NULL",
            'promo_code'     => "promo_code VARCHAR(40) NULL DEFAULT NULL",
            'promo_discount' => "promo_discount DECIMAL(10,2) NOT NULL DEFAULT 0",
            'promo_net'      => "promo_net DECIMAL(10,2) NULL DEFAULT NULL"
        );
        foreach ($add as $name => $ddl) {
            if (!isset($have[$name])) $pdo->exec("ALTER TABLE `rides` ADD COLUMN $ddl");
        }
    } catch (Exception $e) {
        error_log('[ETEL] rides_promo_ensure columns: ' . $e->getMessage());
    }
}

// Pure: what does this promo take off a fare of $gross? No database, no eligibility.
function rides_promo_compute($promo, $gross) {
    $gross = round(floatval($gross), 2);
    if ($gross <= 0) return 0.0;
    if ($gross < floatval($promo['min_fare'])) return 0.0;
    $v = floatval($promo['value']);
    if (($promo['discount_type'] === 'percent')) $d = $gross * $v / 100.0;
    else $d = $v;
    if ($promo['max_discount'] !== null && $promo['max_discount'] !== '' && floatval($promo['max_discount']) > 0) {
        $d = min($d, floatval($promo['max_discount']));
    }
    // never free, never more than 80% off
    $d = min($d, $gross * 0.8, $gross - 10.0);
    return round(max(0.0, $d), 2);
}

function rides_promo_find($pdo, $code) {
    rides_promo_ensure($pdo);
    $code = rides_promo_normalize($code);
    if ($code === '') return null;
    $s = $pdo->prepare("SELECT * FROM ride_promo_codes WHERE code=? LIMIT 1");
    $s->execute(array($code));
    $p = $s->fetch(PDO::FETCH_ASSOC);
    return $p ? $p : null;
}

// Can this rider use this code now, on a fare of about $gross? Returns array(ok, error, promo, discount).
function rides_promo_check($pdo, $code, $userId, $gross) {
    $code = rides_promo_normalize($code);
    if ($code === '') return array('ok' => false, 'error' => 'Enter a promo code', 'promo' => null, 'discount' => 0);
    $p = rides_promo_find($pdo, $code);
    $bad = function ($msg) { return array('ok' => false, 'error' => $msg, 'promo' => null, 'discount' => 0); };
    if (!$p || $p['status'] !== 'approved') return $bad('That promo code is not valid');
    $now = time();
    if ($p['starts_at'] && strtotime($p['starts_at']) > $now) return $bad('That promo code has not started yet');
    if ($p['expires_at'] && strtotime($p['expires_at']) < $now) return $bad('That promo code has expired');
    // Trips still on the way count as uses, so one code cannot be spread over several parallel bookings.
    $live = $pdo->prepare("SELECT COUNT(*) FROM rides WHERE promo_id=? AND status NOT IN ('completed','cancelled')");
    $live->execute(array($p['id']));
    $liveAll = intval($live->fetchColumn());
    if ($p['max_uses'] !== null && intval($p['used_count']) + $liveAll >= intval($p['max_uses'])) return $bad('That promo code has been used up');
    $mine = $pdo->prepare("SELECT (SELECT COUNT(*) FROM ride_promo_redemptions WHERE promo_id=? AND user_id=?)
                                + (SELECT COUNT(*) FROM rides WHERE promo_id=? AND passenger_id=? AND status NOT IN ('completed','cancelled'))");
    $mine->execute(array($p['id'], $userId, $p['id'], $userId));
    if (intval($mine->fetchColumn()) >= max(1, intval($p['per_rider_limit']))) return $bad('You have already used that promo code');
    $d = rides_promo_compute($p, $gross);
    return array('ok' => true, 'error' => '', 'promo' => $p, 'discount' => $d);
}

// Right after a ride becomes 'arrived' with its final (gross) fare in rides.fare_final: take the promo off. Returns the
// fare the rider pays (the gross fare when there is no promo). Safe to call again on the same fare.
function rides_promo_apply_to_arrived($pdo, $rideId) {
    $fallback = null;
    rides_promo_ensure($pdo);
    try {
        $q = $pdo->prepare("SELECT fare_final, promo_id, promo_net FROM rides WHERE id=? LIMIT 1");
        $q->execute(array($rideId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 0.0;
        $gross = floatval($r['fare_final']);
        $fallback = $gross;
        if (!$r['promo_id']) return $gross;
        // fare_final still equals the net we stored: this fare was already discounted
        if ($r['promo_net'] !== null && abs(floatval($r['promo_net']) - $gross) < 0.005) return $gross;
        $s = $pdo->prepare("SELECT * FROM ride_promo_codes WHERE id=? LIMIT 1");
        $s->execute(array(intval($r['promo_id'])));
        $p = $s->fetch(PDO::FETCH_ASSOC);
        // a code that was approved when the ride was booked stays honoured, unless an admin rejected it since
        if (!$p || $p['status'] === 'rejected') return $gross;
        $d = rides_promo_compute($p, $gross);
        if ($d <= 0) {
            $pdo->prepare("UPDATE rides SET promo_discount=0, promo_net=NULL WHERE id=?")->execute(array($rideId));
            return $gross;
        }
        $net = round($gross - $d, 2);
        $pdo->prepare("UPDATE rides SET fare_final=?, promo_discount=?, promo_net=? WHERE id=?")->execute(array($net, $d, $net, $rideId));
        // keep the stored itemised breakdown honest: the items add up to the gross fare, the promo line is shown next to it
        try {
            $b = $pdo->prepare("SELECT fare_breakdown_json FROM rides WHERE id=?");
            $b->execute(array($rideId));
            $bj = json_decode((string)$b->fetchColumn(), true);
            if (is_array($bj)) {
                $bj['promo_code'] = $p['code'];
                $bj['promo_discount'] = $d;
                $bj['fare_before_promo'] = $gross;
                $pdo->prepare("UPDATE rides SET fare_breakdown_json=? WHERE id=?")->execute(array(json_encode($bj), $rideId));
            }
        } catch (Exception $e) { /* breakdown column optional */ }
        return $net;
    } catch (Exception $e) {
        error_log('[ETEL] rides_promo_apply_to_arrived: ' . $e->getMessage());
        return $fallback === null ? 0.0 : $fallback;
    }
}

// What the promo did to a ride, for the screens that show the fare: array() when the ride has no promo discount.
function rides_promo_info($pdo, $rideId) {
    try {
        rides_promo_ensure($pdo);
        $q = $pdo->prepare("SELECT promo_code, promo_discount, promo_net FROM rides WHERE id=? LIMIT 1");
        $q->execute(array($rideId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['promo_code'] || floatval($r['promo_discount']) <= 0) return array();
        $net = $r['promo_net'] !== null ? floatval($r['promo_net']) : 0.0;
        return array('promo_code' => $r['promo_code'], 'promo_discount' => round(floatval($r['promo_discount']), 2),
                     'fare_before_promo' => round($net + floatval($r['promo_discount']), 2));
    } catch (Exception $e) { return array(); }
}

// At completion: the discount to fund, and the use is recorded once. Returns array(discount, passenger_id).
function rides_promo_settle($pdo, $rideId) {
    rides_promo_ensure($pdo);
    try {
        $q = $pdo->prepare("SELECT passenger_id, promo_id, promo_discount FROM rides WHERE id=? LIMIT 1");
        $q->execute(array($rideId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['promo_id'] || floatval($r['promo_discount']) <= 0) return 0.0;
        $d = round(floatval($r['promo_discount']), 2);
        $ins = $pdo->prepare("INSERT IGNORE INTO ride_promo_redemptions (promo_id, user_id, ride_id, discount) VALUES (?,?,?,?)");
        $ins->execute(array(intval($r['promo_id']), intval($r['passenger_id']), $rideId, $d));
        if ($ins->rowCount() > 0) {
            $pdo->prepare("UPDATE ride_promo_codes SET used_count=used_count+1 WHERE id=?")->execute(array(intval($r['promo_id'])));
        }
        return $d;
    } catch (Exception $e) {
        error_log('[ETEL] rides_promo_settle: ' . $e->getMessage());
        return 0.0;
    }
}

}
