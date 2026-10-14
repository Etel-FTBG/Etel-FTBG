<?php
// ============================================================
// ETEL Delivery - ratings report for staff (admin only)
//   admin_ratings_report  { days? 7|30|90 }  -> summary, common tags, lowest couriers and shops, newest ratings
// See api/lib/ratings_report.php.
// ============================================================
require_once __DIR__ . '/../lib/ratings_report.php';

switch ($action) {

case 'admin_ratings_report': {
    $pdo = db();
    require_admin($pdo, 'orders.view');
    $days = isset($body['days']) ? $body['days'] : (isset($_GET['days']) ? $_GET['days'] : 30);
    ok(etel_ratings_report($pdo, $days));
    break;
}

}
