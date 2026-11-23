<?php
// couriers.vehicle_type must be a plain text column. On some installs it is an ENUM of a few vehicles (motorcycle, bicycle, car ...): a vehicle that is
// not on the list (qute, minibus, EV car, on foot, one staff added) is then silently stored as an EMPTY value and the admin is told "saved".
// This widens it to VARCHAR(30), keeping every value already there. It runs once per request at most and never throws.
function etel_ensure_courier_vehicle_column($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM couriers LIKE 'vehicle_type'")->fetch(PDO::FETCH_ASSOC);
        if (!$c) return;
        $type = strtolower((string) $c['Type']);
        $short = preg_match('/^varchar\((\d+)\)/', $type, $m) && intval($m[1]) < 30;
        if (strpos($type, 'enum') === 0 || $short) {
            $pdo->exec("ALTER TABLE couriers MODIFY COLUMN vehicle_type VARCHAR(30) NULL DEFAULT 'motorcycle'");
            error_log('[ETEL] couriers.vehicle_type widened to VARCHAR(30) (was ' . $type . ')');
        }
    } catch (Exception $e) { error_log('[ETEL] courier vehicle column: ' . $e->getMessage()); }
}
