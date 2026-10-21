<?php
// ============================================================
// ETEL Delivery - Admin -> People (every account, one place)
//   admin_people_list   { role?, status?: active|suspended, q?, sort?: recent|oldest|name|orders, limit? }
//                                                       -> accounts with order / ride counts + a summary per role  (users.view)
//   admin_person_get    { id }                          -> one account: profile, role details, orders, rides      (users.view)
//   admin_person_set_active { id, active: 0|1, reason? } -> disable / restore an account (users.manage)
//   admin_person_force_logout { id }                    -> sign the person out of every device (users.manage)
// Staff who may only see Ride accounts get customers and drivers only. Only an owner admin (full access) may change
// another admin. Nobody can disable or sign out their own account from here.
// ============================================================

// Rows of one grouped query keyed by the first column, or an empty list when the table / column is missing.
function etel_people_counts($pdo, $sql, $ids) {
    $out = array();
    if (!$ids) return $out;
    try {
        $in = implode(',', array_map('intval', $ids));
        foreach ($pdo->query(str_replace('{IDS}', $in, $sql))->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[intval($r['k'])] = $r;
        }
    } catch (Exception $e) { /* older install without the table: counts stay empty */ }
    return $out;
}

// The target account, refusing anything the caller may not touch. Returns the users row.
function etel_people_target($pdo, $caller, $id, $self_ok) {
    if ($id <= 0) fail('id required', 400);
    if (!$self_ok && intval($caller['id']) === $id) fail('You cannot do this to your own account', 400);
    try {
        $s = $pdo->prepare('SELECT id, name, phone, role, active FROM users WHERE id = ? LIMIT 1');
        $s->execute(array($id));
        $t = $s->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { fail_ex($e, 'Could not load the account', 500); }
    if (!$t) fail('Account not found', 404);
    if (etel_is_ride_only($pdo, $caller) && !in_array($t['role'], array('customer', 'driver'), true)) fail('You can only manage ride accounts', 403);
    if ($t['role'] === 'admin' && !in_array('*', user_capabilities($pdo, $caller), true)) fail('Only an owner admin can change another admin', 403);
    return $t;
}

switch ($action) {

case 'admin_people_list': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    $rideOnly = etel_is_ride_only($pdo, $u);
    $role   = isset($body['role'])   ? trim((string) $body['role'])   : '';
    $status = isset($body['status']) ? trim((string) $body['status']) : '';
    $q      = isset($body['q'])      ? trim((string) $body['q'])      : '';
    $sort   = isset($body['sort'])   ? (string) $body['sort']         : 'recent';
    $limit  = max(1, min(500, isset($body['limit']) ? intval($body['limit']) : 300));
    $roles  = $rideOnly ? array('customer', 'driver') : array('customer', 'courier', 'driver', 'service_giver', 'admin');
    $where = 'u.role IN (' . implode(',', array_map(function ($r) { return "'" . $r . "'"; }, $roles)) . ')';
    $params = array();
    if ($role !== '' && in_array($role, $roles, true)) { $where .= ' AND u.role = ?'; $params[] = $role; }
    if ($status === 'suspended') $where .= ' AND u.active = 0';
    elseif ($status === 'active') $where .= ' AND u.active <> 0';
    if ($q !== '') {
        $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
        $where .= ' AND (u.name LIKE ? OR u.phone LIKE ? OR u.id = ?)'; $params[] = $like; $params[] = $like; $params[] = intval($q);
    }
    $orderBy = array('recent' => 'u.id DESC', 'oldest' => 'u.id ASC', 'name' => 'u.name ASC', 'orders' => 'u.id DESC');
    $ob = isset($orderBy[$sort]) ? $orderBy[$sort] : $orderBy['recent'];
    try {
        $s = $pdo->prepare("SELECT u.id, u.name, u.phone, u.role, u.active, u.created_at FROM users u WHERE $where ORDER BY $ob LIMIT $limit");
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $sum = $pdo->query("SELECT role, COUNT(*) AS n, SUM(active = 0) AS off FROM users WHERE role IN ('" . implode("','", $roles) . "') GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
        $new = intval($pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('" . implode("','", $roles) . "') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn());
    } catch (Exception $e) { fail_ex($e, 'Could not load the accounts', 500); }
    $ids = array_map(function ($r) { return intval($r['id']); }, $rows);
    $ord = etel_people_counts($pdo, 'SELECT customer_id AS k, COUNT(*) AS n, MAX(placed_at) AS last_at FROM orders WHERE customer_id IN ({IDS}) GROUP BY customer_id', $ids);
    $ridesP = etel_people_counts($pdo, 'SELECT passenger_id AS k, COUNT(*) AS n FROM rides WHERE passenger_id IN ({IDS}) GROUP BY passenger_id', $ids);
    $ridesD = etel_people_counts($pdo, 'SELECT driver_id AS k, COUNT(*) AS n FROM rides WHERE driver_id IN ({IDS}) GROUP BY driver_id', $ids);
    foreach ($rows as &$r) {
        $id = intval($r['id']);
        $r['id'] = $id;
        $r['suspended'] = intval($r['active']) === 0; unset($r['active']);
        $r['orders_total'] = isset($ord[$id]) ? intval($ord[$id]['n']) : 0;
        $r['last_order_at'] = isset($ord[$id]) ? $ord[$id]['last_at'] : null;
        $r['rides_total'] = (isset($ridesP[$id]) ? intval($ridesP[$id]['n']) : 0) + (isset($ridesD[$id]) ? intval($ridesD[$id]['n']) : 0);
    }
    unset($r);
    if ($sort === 'orders') usort($rows, function ($a, $b) { return ($b['orders_total'] + $b['rides_total']) - ($a['orders_total'] + $a['rides_total']); });
    $byRole = array(); $total = 0; $off = 0;
    foreach ($sum as $x) { $byRole[$x['role']] = intval($x['n']); $total += intval($x['n']); $off += intval($x['off']); }
    ok(array('people' => $rows, 'count' => count($rows),
             'summary' => array('total' => $total, 'by_role' => $byRole, 'suspended' => $off, 'new_7d' => $new)));
    break;
}

case 'admin_person_get': {
    $pdo = db();
    $u = require_admin($pdo, 'users.view');
    $id = isset($body['id']) ? intval($body['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    $t = etel_people_target($pdo, $u, $id, true);
    try {
        $s = $pdo->prepare('SELECT id, name, phone, role, active, created_at FROM users WHERE id = ? LIMIT 1');
        $s->execute(array($id));
        $p = $s->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { fail_ex($e, 'Could not load the account', 500); }
    $one = function ($sql, $args) use ($pdo) {
        try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $e) { return null; }
    };
    $all = function ($sql, $args) use ($pdo) {
        try { $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); }
    };
    $detail = array();
    if ($p['role'] === 'courier') {
        $detail = $one('SELECT courier_code, vehicle_type, zone, status, rating, total_deliveries FROM couriers WHERE user_id = ? LIMIT 1', array($id)) ?: array();
    } elseif ($p['role'] === 'driver') {
        $detail = $one('SELECT vehicle_plate, vehicle_make, vehicle_color, online, total_rides, rating_sum, rating_count FROM ride_drivers WHERE user_id = ? LIMIT 1', array($id)) ?: array();
        if ($detail) { $detail['rating'] = intval($detail['rating_count']) > 0 ? round($detail['rating_sum'] / $detail['rating_count'], 2) : null; unset($detail['rating_sum'], $detail['rating_count']); }
    } elseif ($p['role'] === 'service_giver') {
        $detail = $one('SELECT service_giver_code, business_name, service_type, zone, status, completed_deliveries FROM service_givers WHERE user_id = ? LIMIT 1', array($id)) ?: array();
    } elseif ($p['role'] === 'admin') {
        $st = $one('SELECT staff_role, is_etelgroup_managed FROM users WHERE id = ? LIMIT 1', array($id));
        $detail = array('staff_role' => $st ? $st['staff_role'] : null, 'managed' => $st ? intval($st['is_etelgroup_managed']) === 1 : false);
    }
    $os = $one("SELECT COUNT(*) AS n, SUM(status = 'delivered') AS d, SUM(status = 'cancelled') AS x, IFNULL(SUM(CASE WHEN status = 'delivered' THEN fare_total ELSE 0 END), 0) AS spent, MAX(placed_at) AS last_at FROM orders WHERE customer_id = ?", array($id));
    $orders = $all('SELECT order_code, status, payment_method, fare_total, placed_at FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 10', array($id));
    $rides = $all('SELECT id, status, fare_final, fare_estimate, created_at, passenger_id, driver_id FROM rides WHERE passenger_id = ? OR driver_id = ? ORDER BY id DESC LIMIT 10', array($id, $id));
    foreach ($orders as &$o) { $o['fare_total'] = round(floatval($o['fare_total']), 2); }
    unset($o);
    foreach ($rides as &$r) {
        $r['as'] = intval($r['passenger_id']) === $id ? 'passenger' : 'driver';
        $r['fare'] = round(floatval($r['fare_final'] > 0 ? $r['fare_final'] : $r['fare_estimate']), 2);
        unset($r['passenger_id'], $r['driver_id'], $r['fare_final'], $r['fare_estimate']);
    }
    unset($r);
    $key = "RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),9)";
    $dup = $one("SELECT COUNT(*) AS n FROM users WHERE id <> ? AND $key = RIGHT(REPLACE(REPLACE(REPLACE(?,'+',''),' ',''),'-',''),9)", array($id, $p['phone']));
    $two = $one('SELECT 1 AS x FROM admin_2fa WHERE user_id = ? LIMIT 1', array($id));
    ok(array('person' => array(
        'id' => intval($p['id']), 'name' => $p['name'], 'phone' => $p['phone'], 'role' => $p['role'], 'suspended' => intval($p['active']) === 0,
        'created_at' => $p['created_at'], 'is_you' => intval($u['id']) === $id,
        'orders_total' => $os ? intval($os['n']) : 0, 'delivered' => $os ? intval($os['d']) : 0, 'cancelled' => $os ? intval($os['x']) : 0,
        'spent' => $os ? round(floatval($os['spent']), 2) : 0, 'last_order_at' => $os ? $os['last_at'] : null,
        'same_phone_accounts' => $dup ? intval($dup['n']) : 0, 'two_step' => (bool) $two, 'detail' => $detail),
        'orders' => $orders, 'rides' => $rides));
    break;
}

case 'admin_person_set_active': {
    $pdo = db();
    $u = require_admin($pdo, 'users.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $active = !empty($body['active']) ? 1 : 0;
    $reason = isset($body['reason']) ? trim(substr((string) $body['reason'], 0, 200)) : '';
    $t = etel_people_target($pdo, $u, $id, false);
    try {
        $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute(array($active, $id));
        if (!$active) {
            etel_bump_token_version($pdo, $id);              // every signed-in device is signed out now
            foreach (array('UPDATE ride_drivers SET online = 0 WHERE user_id = ?',
                           "UPDATE couriers SET status = 'offline' WHERE user_id = ?",
                           'UPDATE service_givers SET is_online = 0 WHERE user_id = ?') as $sql) {
                try { $pdo->prepare($sql)->execute(array($id)); } catch (Exception $e) { /* that profile table is not in this install */ }
            }
        }
    } catch (PDOException $e) { fail_ex($e, 'Could not update the account', 500); }
    error_log('[ETEL][admin] account ' . $id . ($active ? ' restored' : ' disabled') . ' by admin ' . intval($u['id']) . ($reason !== '' ? ' (' . $reason . ')' : ''));
    ok(array('id' => $id, 'suspended' => $active === 0));
    break;
}

case 'admin_person_force_logout': {
    $pdo = db();
    $u = require_admin($pdo, 'users.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    etel_people_target($pdo, $u, $id, false);
    $done = etel_bump_token_version($pdo, $id);
    error_log('[ETEL][admin] account ' . $id . ' signed out of every device by admin ' . intval($u['id']));
    ok(array('id' => $id, 'signed_out' => (bool) $done));
    break;
}

}
