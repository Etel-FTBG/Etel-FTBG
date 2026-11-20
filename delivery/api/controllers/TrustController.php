<?php
// ============================================================
// Driver trust (Admin > Operations > Driver trust). Read only here; the owner's switches are saved with admin_hub_settings_save.
//   admin_driver_trust {}  -> { documents: { block_expired, drivers: [...], expired, soon }, generated_at }   admins who may view rides
// ============================================================
require_once __DIR__ . '/../lib/driver_docs.php';

switch ($action) {
case 'admin_driver_trust': {
    $pdo = db(); require_admin($pdo, 'rides.view');
    try {
        $drivers = dd_list($pdo, 14);
        $expired = 0; $soon = 0;
        foreach ($drivers as $d) { $e = false; foreach ($d['docs'] as $doc) { if ($doc['state'] === 'expired') $e = true; } if ($e) $expired++; else $soon++; }
        usort($drivers, function ($a, $b) { $ma = 999; $mb = 999; foreach ($a['docs'] as $x) $ma = min($ma, $x['days_left']); foreach ($b['docs'] as $x) $mb = min($mb, $x['days_left']); return $ma - $mb; });
        ok(array('generated_at' => date('Y-m-d H:i:s'), 'documents' => array('block_expired' => dd_block_enabled($pdo), 'columns_ready' => dd_columns_ready($pdo), 'drivers' => $drivers, 'expired' => $expired, 'soon' => $soon)));
    } catch (Exception $e) { error_log('[ETEL][trust] ' . $e->getMessage()); fail('Could not load the driver checks.', 500); }
    break;
}
}
