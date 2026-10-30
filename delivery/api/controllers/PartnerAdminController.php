<?php
// ============================================================
// ETEL Delivery - Admin -> Couriers and Service givers boards (read only; the controls are the existing admin_update_courier,
// admin_courier_set_approval, admin_person_set_active, admin_verify_service_giver, admin_suspend_service_giver, sg_update_profile)
//   admin_couriers_board        {}        -> every courier with today's deliveries and live jobs, plus a summary          (users.view)
//   admin_courier_detail        { id }    -> one courier (couriers.id): profile, 30-day stats, recent orders              (users.view)
//   admin_service_givers_board  {}        -> every business with today's / 30-day orders, plus a summary                  (service_givers.view)
//   admin_service_giver_detail  { id }    -> one business: profile, 30-day stats, recent orders                           (service_givers.view)
// Staff who may only see Ride accounts get nothing here. Columns that older installs may lack are only selected when they exist.
// ============================================================

function etel_pb_cols($pdo, $table) { return etel_table_columns($pdo, $table); }

switch ($action) {

case 'admin_couriers_board': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    require_once __DIR__ . '/../lib/courier_approval.php';
    $cc = etel_pb_cols($pdo, 'couriers'); $uc = etel_pb_cols($pdo, 'users');
    $cols = array('c.id', 'c.user_id', 'c.courier_code', 'c.status', 'c.created_at', 'u.name', 'u.phone');
    foreach (array('vehicle_type', 'plate_number', 'home_zone', 'zone', 'rating', 'total_deliveries', 'active', 'last_seen') as $c) { if (in_array($c, $cc, true)) $cols[] = 'c.' . $c; }
    if (in_array('active', $uc, true)) $cols[] = 'u.active AS account_active';
    if (etel_courier_approval_ready($pdo)) { $cols[] = 'c.approval_status'; $cols[] = 'c.approval_note'; }
    $cols[] = "(SELECT COUNT(*) FROM orders o WHERE o.courier_id = c.id AND o.status = 'delivered' AND o.delivered_at >= CURDATE()) AS today_deliveries";
    $cols[] = "(SELECT COUNT(*) FROM orders o WHERE o.courier_id = c.id AND o.status IN ('assigned','picked_up','in_transit')) AS live_jobs";
    try {
        $rows = $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM couriers c JOIN users u ON u.id = c.user_id ORDER BY c.created_at DESC, c.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { fail_ex($e, 'Could not load the couriers', 500); }
    $sum = array('total' => 0, 'online' => 0, 'busy' => 0, 'offline' => 0, 'pending' => 0, 'suspended' => 0, 'deliveries_today' => 0, 'avg_rating' => null);
    $rsum = 0; $rn = 0;
    foreach ($rows as &$r) {
        $r['id'] = intval($r['id']); $r['user_id'] = intval($r['user_id']);
        $r['today_deliveries'] = intval($r['today_deliveries']); $r['live_jobs'] = intval($r['live_jobs']);
        $r['rating'] = isset($r['rating']) ? round(floatval($r['rating']), 2) : 0;
        $r['total_deliveries'] = isset($r['total_deliveries']) ? intval($r['total_deliveries']) : 0;
        $r['suspended'] = (isset($r['active']) && intval($r['active']) === 0) || (isset($r['account_active']) && $r['account_active'] !== null && intval($r['account_active']) === 0);
        $sum['total']++; $sum['deliveries_today'] += $r['today_deliveries'];
        if ($r['suspended']) $sum['suspended']++;
        if (isset($r['approval_status']) && $r['approval_status'] === 'pending') $sum['pending']++;
        elseif (isset($r['status']) && isset($sum[$r['status']])) $sum[$r['status']]++;
        if ($r['rating'] > 0) { $rsum += $r['rating']; $rn++; }
    }
    unset($r);
    $sum['avg_rating'] = $rn ? round($rsum / $rn, 2) : null;
    ok(array('couriers' => $rows, 'count' => count($rows), 'summary' => $sum));
    break;
}

case 'admin_courier_detail': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    require_once __DIR__ . '/../lib/courier_approval.php';
    $id = isset($body['id']) ? intval($body['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    if ($id <= 0) fail('id required', 400);
    $one = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $e) { return null; } };
    $all = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); } };
    $c = $one('SELECT c.*, u.name, u.phone FROM couriers c JOIN users u ON u.id = c.user_id WHERE c.id = ? LIMIT 1', array($id));
    if (!$c) fail('Courier not found', 404);
    $keep = array('id', 'user_id', 'courier_code', 'name', 'phone', 'vehicle_type', 'plate_number', 'home_zone', 'zone', 'status', 'rating', 'total_deliveries', 'active', 'created_at', 'last_seen', 'approval_status', 'approval_note');
    $out = array();
    foreach ($keep as $k) { if (array_key_exists($k, $c)) $out[$k] = $c[$k]; }
    $st = $one("SELECT SUM(status = 'delivered' AND delivered_at >= CURDATE()) AS today, SUM(status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d7,
                       SUM(status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d30, SUM(status = 'cancelled' AND placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS x30,
                       SUM(status IN ('assigned','picked_up','in_transit')) AS live,
                       AVG(CASE WHEN status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN TIMESTAMPDIFF(MINUTE, placed_at, delivered_at) END) AS avg_min
                FROM orders WHERE courier_id = ?", array($id));
    $fees = in_array('delivery_fee', etel_pb_cols($pdo, 'orders'), true)
        ? $one("SELECT IFNULL(SUM(delivery_fee), 0) AS f FROM orders WHERE courier_id = ? AND status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", array($id)) : null;
    $recent = $all('SELECT order_code, status, fare_total, from_zone, to_zone, placed_at FROM orders WHERE courier_id = ? ORDER BY id DESC LIMIT 10', array($id));
    foreach ($recent as &$r) { $r['fare_total'] = round(floatval($r['fare_total']), 2); }
    unset($r);
    $acc = $one('SELECT active FROM users WHERE id = ?', array($c['user_id']));
    ok(array('courier' => $out, 'account_active' => $acc ? intval($acc['active']) !== 0 : true,
             'stats' => array('today' => intval($st['today']), 'd7' => intval($st['d7']), 'd30' => intval($st['d30']), 'cancelled_30d' => intval($st['x30']), 'live' => intval($st['live']),
                              'avg_minutes' => $st['avg_min'] === null ? null : round(floatval($st['avg_min'])), 'fees_30d' => $fees ? round(floatval($fees['f']), 2) : null),
             'recent' => $recent));
    break;
}

case 'admin_service_givers_board': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $sc = etel_pb_cols($pdo, 'service_givers');
    $cols = array('sg.id', 'sg.user_id', 'u.name', 'u.phone');
    foreach (array('service_giver_code', 'business_name', 'service_type', 'zone', 'address', 'status', 'is_online', 'average_rating', 'rating_count', 'completed_deliveries', 'total_deliveries', 'failed_deliveries', 'total_earnings', 'logo_url', 'created_at') as $c) {
        if (in_array($c, $sc, true)) $cols[] = 'sg.' . $c;
    }
    $cols[] = "(SELECT COUNT(*) FROM order_assignments oa JOIN orders o ON o.id = oa.order_id WHERE oa.service_giver_id = sg.id AND o.placed_at >= CURDATE()) AS orders_today";
    $cols[] = "(SELECT COUNT(*) FROM order_assignments oa JOIN orders o ON o.id = oa.order_id WHERE oa.service_giver_id = sg.id AND o.placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS orders_30d";
    $cols[] = "(SELECT COUNT(*) FROM order_assignments oa JOIN orders o ON o.id = oa.order_id WHERE oa.service_giver_id = sg.id AND o.status IN ('pending','assigned','picked_up','in_transit')) AS open_orders";
    try {
        $rows = $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM service_givers sg JOIN users u ON u.id = sg.user_id ORDER BY sg.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { fail_ex($e, 'Could not load the service givers', 500); }
    $sum = array('total' => 0, 'verified' => 0, 'pending' => 0, 'suspended' => 0, 'online' => 0, 'orders_today' => 0, 'avg_rating' => null, 'earnings' => 0);
    $rsum = 0; $rn = 0;
    foreach ($rows as &$r) {
        $r['id'] = intval($r['id']); $r['user_id'] = intval($r['user_id']);
        $r['orders_today'] = intval($r['orders_today']); $r['orders_30d'] = intval($r['orders_30d']); $r['open_orders'] = intval($r['open_orders']);
        $r['is_online'] = !empty($r['is_online']) ? 1 : 0;
        $r['average_rating'] = isset($r['average_rating']) ? round(floatval($r['average_rating']), 2) : 0;
        $r['total_earnings'] = isset($r['total_earnings']) ? round(floatval($r['total_earnings']), 2) : 0;
        $sum['total']++; $sum['orders_today'] += $r['orders_today']; $sum['earnings'] += $r['total_earnings'];
        if ($r['is_online'] && (!isset($r['status']) || $r['status'] !== 'suspended')) $sum['online']++;
        if (isset($r['status']) && isset($sum[$r['status']])) $sum[$r['status']]++;
        if ($r['average_rating'] > 0) { $rsum += $r['average_rating']; $rn++; }
    }
    unset($r);
    $sum['avg_rating'] = $rn ? round($rsum / $rn, 2) : null; $sum['earnings'] = round($sum['earnings'], 2);
    ok(array('service_givers' => $rows, 'count' => count($rows), 'summary' => $sum));
    break;
}

case 'admin_service_giver_detail': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.view');
    if (etel_is_ride_only($pdo, $u)) fail('You can only see ride accounts', 403);
    $id = isset($body['id']) ? intval($body['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    if ($id <= 0) fail('id required', 400);
    $one = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $e) { return null; } };
    $all = function ($sql, $args) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); } };
    $g = $one('SELECT sg.*, u.name, u.phone FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE sg.id = ? LIMIT 1', array($id));
    if (!$g) fail('Service giver not found', 404);
    $keep = array('id', 'user_id', 'service_giver_code', 'business_name', 'service_type', 'zone', 'address', 'name', 'phone', 'status', 'is_online', 'average_rating', 'rating_count',
                  'completed_deliveries', 'total_deliveries', 'failed_deliveries', 'total_earnings', 'logo_url', 'cover_url', 'opens_at', 'closes_at', 'created_at', 'lat', 'lng', 'business_tools');
    $out = array();
    foreach ($keep as $k) { if (array_key_exists($k, $g)) $out[$k] = $g[$k]; }
    $st = $one("SELECT SUM(o.placed_at >= CURDATE()) AS today, SUM(o.placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d7, SUM(o.placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d30,
                       SUM(o.status = 'cancelled' AND o.placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS x30,
                       SUM(o.status IN ('pending','assigned','picked_up','in_transit')) AS open_orders,
                       IFNULL(SUM(CASE WHEN o.status = 'delivered' AND o.placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.fare_total ELSE 0 END), 0) AS sales_30d
                FROM order_assignments oa JOIN orders o ON o.id = oa.order_id WHERE oa.service_giver_id = ?", array($id));
    $recent = $all('SELECT o.order_code, o.status, o.fare_total, o.placed_at FROM order_assignments oa JOIN orders o ON o.id = oa.order_id WHERE oa.service_giver_id = ? ORDER BY o.id DESC LIMIT 10', array($id));
    foreach ($recent as &$r) { $r['fare_total'] = round(floatval($r['fare_total']), 2); }
    unset($r);
    $staff = $one('SELECT COUNT(*) AS n FROM sg_staff WHERE service_giver_id = ?', array($id));
    require_once __DIR__ . '/../lib/sg_claim.php';
    try { $claim = etel_sg_claim_state($pdo, $id); } catch (Exception $e) { $claim = null; }
    $nitems = $one('SELECT COUNT(*) AS n FROM items WHERE service_giver_id = ?', array($id));
    $readiness = etel_sg_readiness($g, $nitems ? intval($nitems['n']) : 0, $claim ? $claim : array());
    ok(array('service_giver' => $out, 'claim' => $claim, 'readiness' => $readiness,
             'stats' => array('today' => intval($st['today']), 'd7' => intval($st['d7']), 'd30' => intval($st['d30']), 'cancelled_30d' => intval($st['x30']), 'open' => intval($st['open_orders']),
                              'sales_30d' => round(floatval($st['sales_30d']), 2), 'staff' => $staff ? intval($staff['n']) : null),
             'recent' => $recent));
    break;
}

}
