<?php
// Ride tips.
//
// How it works (no wallet needed):
//  - After a completed trip the rider can leave a tip for the driver (one tip per trip, within 72 hours).
//  - The rider sends the tip to one of Etel's bank accounts (the same copy-and-paste accounts as the checkout) and enters
//    the transfer reference. The tip is saved as 'pending'.
//  - An admin with rides.manage checks that the money arrived and approves it. Only then does the driver get it, 100%
//    (no commission), added to total_earnings, the balance the driver can cash out. A rejected tip adds nothing.
//  - The reference is unique, so one transfer cannot back two tips.

if (!function_exists('rides_tip_ensure')) {

function rides_tip_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ride_tips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ride_id INT NOT NULL,
            rider_id INT NOT NULL,
            driver_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            bank_key VARCHAR(30) NULL,
            payment_ref VARCHAR(60) NOT NULL,
            note VARCHAR(255) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ride_tip_ride (ride_id),
            UNIQUE KEY uq_ride_tip_ref (payment_ref),
            KEY idx_ride_tip_status (status, id),
            KEY idx_ride_tip_driver (driver_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[ETEL] rides_tip_ensure: ' . $e->getMessage());
    }
}

function rides_tip_max($pdo) {
    require_once __DIR__ . '/ride_fare.php';
    return max(10, rides_rule_value($pdo, 'ride_tip_max', 500));
}

function rides_tip_window_hours($pdo) {
    require_once __DIR__ . '/ride_fare.php';
    return max(1, rides_rule_value($pdo, 'ride_tip_window_hours', 72));
}

// Can this rider tip for this ride? Returns array(ok, error, ride).
function rides_tip_eligible($pdo, $rideId, $riderId) {
    rides_tip_ensure($pdo);
    $s = $pdo->prepare("SELECT id, driver_id, status, completed_at FROM rides WHERE id=? AND passenger_id=? LIMIT 1");
    $s->execute(array($rideId, $riderId));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return array('ok' => false, 'error' => 'Trip not found', 'ride' => null);
    if ($r['status'] !== 'completed' || !$r['driver_id']) return array('ok' => false, 'error' => 'You can tip once the trip is completed', 'ride' => $r);
    $at = $r['completed_at'] ? strtotime($r['completed_at']) : 0;
    if ($at && time() - $at > rides_tip_window_hours($pdo) * 3600) return array('ok' => false, 'error' => 'It is too late to tip for this trip', 'ride' => $r);
    return array('ok' => true, 'error' => '', 'ride' => $r);
}

}
