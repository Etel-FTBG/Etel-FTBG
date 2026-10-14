<?php
// ============================================================
// ETEL Delivery - dispatch settings (admin only)
//   admin_get_stale_assign   {}              -> { minutes, taken_back_7d, job_max_age }
//   admin_save_stale_assign  { minutes, job_max_age? } -> { minutes, job_max_age }
//        minutes: 0 = off, else 10..180. job_max_age: minutes before an unaccepted order is hidden from couriers (0 = never, else 30..1440)
// See api/lib/courier_stale.php.
// ============================================================
require_once __DIR__ . '/../lib/courier_stale.php';

switch ($action) {

case 'admin_get_stale_assign': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $n = 0;
    try { $n = intval($pdo->query("SELECT COUNT(*) FROM order_courier_offers WHERE response='stale' AND offered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn()); } catch (Exception $e) {}
    ok(array('minutes' => etel_stale_minutes($pdo), 'taken_back_7d' => $n, 'job_max_age' => etel_job_age_minutes($pdo)));
    break;
}

case 'admin_save_stale_assign': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $m = isset($body['minutes']) ? $body['minutes'] : 0;
    try {
        $saved = array('minutes' => etel_stale_save($pdo, $m));
        if (isset($body['job_max_age'])) $saved['job_max_age'] = etel_job_age_save($pdo, $body['job_max_age']);
        else $saved['job_max_age'] = etel_job_age_minutes($pdo);
        ok($saved);
    }
    catch (Exception $e) { fail_ex($e, 'Could not save the setting', 500); }
    break;
}

}
