<?php
// ============================================================
// ETEL Delivery - dispatch settings (admin only)
//   admin_get_stale_assign   {}              -> { minutes, taken_back_7d }
//   admin_save_stale_assign  { minutes }     -> { minutes }   (0 = off, else 10..180)
// See api/lib/courier_stale.php.
// ============================================================
require_once __DIR__ . '/../lib/courier_stale.php';

switch ($action) {

case 'admin_get_stale_assign': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $n = 0;
    try { $n = intval($pdo->query("SELECT COUNT(*) FROM order_courier_offers WHERE response='stale' AND offered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn()); } catch (Exception $e) {}
    ok(array('minutes' => etel_stale_minutes($pdo), 'taken_back_7d' => $n));
    break;
}

case 'admin_save_stale_assign': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $m = isset($body['minutes']) ? $body['minutes'] : 0;
    try { ok(array('minutes' => etel_stale_save($pdo, $m))); }
    catch (Exception $e) { fail_ex($e, 'Could not save the setting', 500); }
    break;
}

}
