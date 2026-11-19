<?php
// ============================================================
// Live operations (Admin > Operations > Live operations). Read only.
//   admin_live_ops {}  -> { now, alerts, speed, generated_at }   admins who may view rides
// ============================================================
require_once __DIR__ . '/../lib/live_ops.php';

switch ($action) {
case 'admin_live_ops': {
    $pdo = db(); require_admin($pdo, 'rides.view');
    try { ok(live_ops_snapshot($pdo)); }
    catch (Exception $e) { error_log('[ETEL][live_ops] ' . $e->getMessage()); fail('Could not load the live numbers.', 500); }
    break;
}
}
