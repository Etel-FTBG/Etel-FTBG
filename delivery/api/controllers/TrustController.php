<?php
// ============================================================
// Driver trust (Admin > Operations > Driver trust). Read only here; the owner's switches are saved with admin_hub_settings_save.
//   admin_driver_trust {}  -> { documents: { block_expired, drivers: [...], expired, soon }, gps: { block_fake, drivers: [...] }, generated_at }   admins who may view rides
//   admin_gps_clear { driver_user_id }  -> { cleared }   owner admin: forget a driver's fake-GPS sightings (support checked the phone)
// ============================================================
require_once __DIR__ . '/../lib/driver_docs.php';
require_once __DIR__ . '/../lib/gps_check.php';

switch ($action) {
case 'admin_driver_trust': {
    $pdo = db(); require_admin($pdo, 'rides.view');
    try {
        $drivers = dd_list($pdo, 14);
        $expired = 0; $soon = 0;
        foreach ($drivers as $d) { $e = false; foreach ($d['docs'] as $doc) { if ($doc['state'] === 'expired') $e = true; } if ($e) $expired++; else $soon++; }
        usort($drivers, function ($a, $b) { $ma = 999; $mb = 999; foreach ($a['docs'] as $x) $ma = min($ma, $x['days_left']); foreach ($b['docs'] as $x) $mb = min($mb, $x['days_left']); return $ma - $mb; });
        ok(array('generated_at' => date('Y-m-d H:i:s'), 'documents' => array('block_expired' => dd_block_enabled($pdo), 'columns_ready' => dd_columns_ready($pdo), 'drivers' => $drivers, 'expired' => $expired, 'soon' => $soon),
                 'gps' => array('block_fake' => gps_block_enabled($pdo), 'drivers' => gps_overview($pdo, 7), 'block_hours' => gps_block_hours())));
    } catch (Exception $e) { error_log('[ETEL][trust] ' . $e->getMessage()); fail('Could not load the driver checks.', 500); }
    break;
}
case 'admin_gps_clear': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    $uid = isset($body['driver_user_id']) ? intval($body['driver_user_id']) : 0;
    if ($uid <= 0) fail('Choose a driver.', 422);
    ok(array('cleared' => gps_clear($pdo, $uid)));
    break;
}
}
