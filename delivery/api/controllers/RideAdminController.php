<?php
// ============================================================
// ETEL Ride - admin actions of the ride desk (split out of RideController.php, which was over 5,000 lines).
// Loaded by RideController.php just before its own switch, in the same scope, so $pdo, $u, $body and $action are the ones it set up.
// An action that is not listed here falls through to RideController.php, which answers "Unknown ride action" as before.
// ============================================================
if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

switch ($action) {


case 'admin_ride_tip_list': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    rides_tip_ensure($pdo);
    $st = isset($body['status']) ? $body['status'] : '';
    $where = in_array($st, array('pending', 'approved', 'rejected'), true) ? "WHERE t.status = " . $pdo->quote($st) : '';
    $rows = $pdo->query("SELECT t.*, r.name AS rider_name, r.phone AS rider_phone, d.name AS driver_name, d.phone AS driver_phone
                         FROM ride_tips t LEFT JOIN users r ON r.id=t.rider_id LEFT JOIN users d ON d.id=t.driver_id
                         $where ORDER BY FIELD(t.status,'pending','approved','rejected'), t.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = intval($pdo->query("SELECT COUNT(*) FROM ride_tips WHERE status='pending'")->fetchColumn());
    ok(array('tips' => $rows, 'pending_count' => $pendingCount));
    break;
}


case 'admin_ride_tip_review': {
    // POST: id, decision (approve|reject), note?   approve = the money arrived: the driver gets all of it
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_tip_ensure($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $decision = isset($body['decision']) ? $body['decision'] : '';
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : null;
    if ($id <= 0 || !in_array($decision, array('approve', 'reject'), true)) fail('id and a decision (approve or reject) are required', 400);
    $q = $pdo->prepare("SELECT * FROM ride_tips WHERE id=?");
    $q->execute(array($id));
    $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t) fail('Tip not found', 404);
    if ($t['status'] !== 'pending') fail('This tip was already decided', 409);
    $pdo->beginTransaction();
    try {
        // the status flip is the claim: a double click credits the driver once
        $c = $pdo->prepare("UPDATE ride_tips SET status=?, reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status='pending'");
        $c->execute(array($decision === 'approve' ? 'approved' : 'rejected', $u['id'], $note, $id));
        if ($c->rowCount() < 1) { $pdo->rollBack(); fail('This tip was already decided', 409); }
        if ($decision === 'approve') {
            rides_add_missing_cols($pdo, 'ride_drivers', array('total_earnings' => "total_earnings DECIMAL(12,2) DEFAULT 0"));
            $pdo->prepare("UPDATE ride_drivers SET total_earnings = total_earnings + ? WHERE user_id=?")->execute(array($t['amount'], $t['driver_id']));
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($ex, 'Could not save the decision', 500);
    }
    ok(array('id' => $id, 'status' => $decision === 'approve' ? 'approved' : 'rejected'));
    break;
}


case 'admin_ride_promo_list': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $st = isset($body['status']) ? $body['status'] : (isset($_GET['status']) ? $_GET['status'] : '');
    $where = in_array($st, array('pending', 'approved', 'rejected', 'disabled'), true) ? "WHERE p.status = " . $pdo->quote($st) : '';
    $rows = $pdo->query("SELECT p.*, u.name AS creator_name, u.phone AS creator_phone,
                                COALESCE((SELECT SUM(r.discount) FROM ride_promo_redemptions r WHERE r.promo_id=p.id),0) AS total_discount
                         FROM ride_promo_codes p LEFT JOIN users u ON u.id = p.created_by
                         $where ORDER BY FIELD(p.status,'pending','approved','disabled','rejected'), p.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = intval($pdo->query("SELECT COUNT(*) FROM ride_promo_codes WHERE status='pending'")->fetchColumn());
    ok(array('promos' => $rows, 'pending_count' => $pendingCount));
    break;
}


case 'admin_ride_promo_review': {
    // POST: id, decision (approve|reject|disable|enable), note?, and on approve optional adjustments:
    //       value, max_discount, min_fare, expires_at, max_uses, per_rider_limit
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $decision = isset($body['decision']) ? $body['decision'] : '';
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : null;
    if ($id <= 0 || !in_array($decision, array('approve', 'reject', 'disable', 'enable'), true)) fail('id and a decision (approve, reject, disable, enable) are required', 400);
    $q = $pdo->prepare("SELECT * FROM ride_promo_codes WHERE id=?");
    $q->execute(array($id));
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) fail('Promo code not found', 404);
    if ($decision === 'approve' && $p['status'] !== 'pending') fail('Only a code waiting for approval can be approved', 409);
    if ($decision === 'reject' && $p['status'] !== 'pending') fail('Only a code waiting for approval can be rejected', 409);
    if ($decision === 'disable' && $p['status'] !== 'approved') fail('Only an approved code can be disabled', 409);
    if ($decision === 'enable' && $p['status'] !== 'disabled') fail('Only a disabled code can be enabled', 409);
    $newStatus = array('approve' => 'approved', 'reject' => 'rejected', 'disable' => 'disabled', 'enable' => 'approved');
    $set = "status=?, reviewed_by=?, reviewed_at=NOW(), review_note=?";
    $args = array($newStatus[$decision], $u['id'], $note);
    if ($decision === 'approve') {
        // the admin may tighten or correct the proposal when approving
        if (isset($body['value']) && $body['value'] !== '') {
            $v = floatval($body['value']);
            if ($v <= 0 || ($p['discount_type'] === 'percent' && $v > 80)) fail('Percent must be between 1 and 80', 400);
            $set .= ", value=?"; $args[] = $v;
        }
        if (isset($body['max_discount'])) { $set .= ", max_discount=?"; $args[] = ($body['max_discount'] === '' ? null : max(0, floatval($body['max_discount']))); }
        if (isset($body['min_fare']) && $body['min_fare'] !== '') { $set .= ", min_fare=?"; $args[] = max(0, floatval($body['min_fare'])); }
        if (isset($body['max_uses']) && $body['max_uses'] !== '') { $set .= ", max_uses=?"; $args[] = max(1, intval($body['max_uses'])); }
        if (isset($body['per_rider_limit']) && $body['per_rider_limit'] !== '') { $set .= ", per_rider_limit=?"; $args[] = max(1, intval($body['per_rider_limit'])); }
        if (isset($body['expires_at'])) {
            $e = trim($body['expires_at']);
            if ($e === '') { $set .= ", expires_at=?"; $args[] = null; }
            else { $ts = strtotime($e); if ($ts === false) fail('Invalid expiry date', 400); $set .= ", expires_at=?"; $args[] = date('Y-m-d H:i:s', $ts); }
        }
    }
    $args[] = $id;
    $pdo->prepare("UPDATE ride_promo_codes SET $set WHERE id=?")->execute($args);
    ok(array('id' => $id, 'status' => $newStatus[$decision]));
    break;
}


case 'admin_ride_promo_save': {
    // POST: code, discount_type, value, max_discount?, min_fare?, starts_at?, expires_at?, max_uses?, per_rider_limit?, description?  -> approved at once
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    rides_promo_ensure($pdo);
    $code = rides_promo_normalize(isset($body['code']) ? $body['code'] : '');
    if (strlen($code) < 3 || strlen($code) > 40) fail('Code must be 3-40 letters or numbers', 400);
    $type = isset($body['discount_type']) ? $body['discount_type'] : 'percent';
    if (!in_array($type, array('percent', 'fixed'), true)) fail('Discount must be a percent or a fixed amount', 400);
    $value = isset($body['value']) ? floatval($body['value']) : 0;
    if ($type === 'percent' && ($value < 1 || $value > 80)) fail('Percent must be between 1 and 80', 400);
    if ($type === 'fixed' && $value < 1) fail('Amount must be at least ETB 1', 400);
    $nn = function ($k) use ($body) { return (isset($body[$k]) && $body[$k] !== '' && $body[$k] !== null) ? $body[$k] : null; };
    $max = $nn('max_discount') !== null ? max(0, floatval($body['max_discount'])) : null;
    $minFare = $nn('min_fare') !== null ? max(0, floatval($body['min_fare'])) : 0;
    $starts = $nn('starts_at'); $exp = $nn('expires_at');
    foreach (array($starts, $exp) as $dt) { if ($dt !== null && strtotime($dt) === false) fail('Invalid date', 400); }
    if ($starts !== null) $starts = date('Y-m-d H:i:s', strtotime($starts));
    if ($exp !== null) $exp = date('Y-m-d H:i:s', strtotime($exp));
    $maxUses = $nn('max_uses') !== null ? max(1, intval($body['max_uses'])) : null;
    $perRider = max(1, intval($nn('per_rider_limit') !== null ? $body['per_rider_limit'] : 1));
    $desc = isset($body['description']) ? mb_substr(trim($body['description']), 0, 255) : null;
    try {
        $pdo->prepare("INSERT INTO ride_promo_codes (code, description, discount_type, value, max_discount, min_fare, starts_at, expires_at, max_uses, per_rider_limit, status, created_by, created_by_role, reviewed_by, reviewed_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?, 'approved', ?, 'admin', ?, NOW())")
            ->execute(array($code, $desc, $type, $value, $max, $minFare, $starts, $exp, $maxUses, $perRider, $u['id'], $u['id']));
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('That code already exists', 409);
        fail_ex($e, 'Could not save the promo code', 500);
    }
    ok(array('id' => intval($pdo->lastInsertId()), 'code' => $code, 'status' => 'approved'));
    break;
}


case 'admin_phone_book_ride': {
    /* An admin books a ride for someone who phoned in. The caller's account is found by phone (or created without a
       password, so they can sign in later through the normal reset flow); the ride is then created exactly as
       book_ride would, in 'searching', so nearby drivers see it. km > 0 = fixed distance; open = metered trip. */
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $phoneRaw = isset($body['phone']) ? trim($body['phone']) : '';
    $phone = etel_validate_phone($phoneRaw);
    if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567', 400);
    $cname = isset($body['name']) ? trim(preg_replace('/\s+/u', ' ', (string) $body['name'])) : '';
    $cname = function_exists('mb_substr') ? mb_substr($cname, 0, 100, 'UTF-8') : substr($cname, 0, 100);
    $open = !empty($body['open']);
    $km = isset($body['km']) ? floatval($body['km']) : 0;
    if (!$open && $km <= 0) fail('Enter a distance, or choose Open Trip', 400);
    $km = min(100, max(0, $km));
    $vtype = rides_normalize_vehicle_type($pdo, isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj');
    $note = isset($body['pickup_note']) ? trim((string) $body['pickup_note']) : '';
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 200, 'UTF-8') : substr($note, 0, 200);
    $plat = isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0;
    $plng = isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0;
    $paddr = isset($body['pickup_addr']) ? mb_substr(trim((string) $body['pickup_addr']), 0, 200, 'UTF-8') : '';
    if (!$plat || !$plng || abs($plat) > 90 || abs($plng) > 180) { $plat = 7.0621; $plng = 38.4763; }   // Hawassa centre when the caller was not placed on the map
    require_once __DIR__ . '/../lib/service_areas.php';
    $areaChk = etel_area_check_point($pdo, $plat, $plng, 'pickup location');
    if (!$areaChk['ok']) fail($areaChk['message'], 409, array('reason' => $areaChk['reason'], 'message_am' => $areaChk['message_am']));
    if ($paddr === '') $paddr = $note !== '' ? $note : 'Phone booking';
    elseif ($note !== '') $paddr = mb_substr($paddr . ' - ' . $note, 0, 255, 'UTF-8');

    if (!etel_phone_lock($pdo, $phone)) fail('Please try again in a moment', 429);
    $cust = etel_find_user_by_phone($pdo, $phone, 'id,phone,name,role');
    if ($cust && $cust['role'] !== 'customer' && $cust['role'] !== 'passenger') {
        etel_phone_unlock($pdo, $phone);
        fail('That number belongs to a ' . $cust['role'] . ' account, not a rider', 409);
    }
    $newAcct = false;
    if (!$cust) {
        $newAcct = true;
        $userCols = etel_table_columns($pdo, 'users');
        $f = array('name', 'phone', 'password_hash', 'role'); $m = array('?', '?', '?', '?');
        $v = array($cname !== '' ? $cname : 'Phone caller', $phone, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), 'customer');
        if (in_array('active', $userCols, true)) { $f[] = 'active'; $m[] = '1'; }
        $f[] = 'created_at'; $m[] = 'NOW()';
        try {
            $pdo->prepare('INSERT INTO users (' . implode(',', $f) . ') VALUES (' . implode(',', $m) . ')')->execute($v);
        } catch (Exception $e) { etel_phone_unlock($pdo, $phone); fail_ex($e, 'Could not create the caller account', 500); }
        $cust = array('id' => intval($pdo->lastInsertId()), 'phone' => $phone, 'name' => $cname, 'role' => 'customer');
    }
    etel_phone_unlock($pdo, $phone);
    $cid = intval($cust['id']);
    $q = $pdo->prepare("SELECT id FROM rides WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived') ORDER BY id DESC LIMIT 1");
    $q->execute(array($cid));
    $ex = $q->fetch();
    if ($ex) fail('This caller already has an active ride (#' . intval($ex['id']) . '). Cancel it first.', 409, array('existing_ride_id' => intval($ex['id'])));

    $fareRes = rides_calc_fare($pdo, $km, $vtype, $open);
    $fare = $fareRes['fare'];
    $have = array();
    foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) { $have[strtolower($c['Field'])] = true; }
    $cols = 'passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, pickup_address, dropoff_address, distance_km, fare_estimate, fare_final, payment_method, status, expires_at, created_at';
    $ph = "?,?,?,NULL,NULL,?,?,?,?,?,'cash','searching', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW()";
    $vals = array($cid, $plat, $plng, $paddr, $open ? 'Open trip (driver directed on the way)' : 'By distance: ' . $km . ' km (driver directed on the way)', round($km, 2), $fare, $fare);
    foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) {
        if (isset($have[$cc])) { $cols .= ", `$cc`"; $ph .= ', ?'; $vals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5)); }
    }
    if (isset($have['vehicle_type'])) { $cols .= ', vehicle_type'; $ph .= ', ?'; $vals[] = $vtype; }
    if (isset($have['open_trip'])) { $cols .= ', open_trip'; $ph .= ', ?'; $vals[] = $open ? 1 : 0; }
    if (rides_has_surge_column($pdo)) { $cols .= ', surge_applied'; $ph .= ', ?'; $vals[] = $fareRes['surge'] ? 1 : 0; }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    $rid = intval($pdo->lastInsertId());

    try {
        $nd = $pdo->prepare("SELECT user_id, lat, lng FROM ride_drivers WHERE online = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND (LOWER(vehicle_type) = LOWER(?) OR vehicle_type IS NULL)");
        $nd->execute(array($vtype));
        $inRange = array();
        foreach ($nd->fetchAll() as $rd) {
            $d = rides_haversine_km($plat, $plng, floatval($rd['lat']), floatval($rd['lng']));
            if ($d <= 8) { $rd['km'] = $d; $inRange[] = $rd; }
        }
        foreach (rides_offer_first_push_targets($pdo, $inRange, $plat, $plng) as $rd) {
            etel_push_send($pdo, intval($rd['user_id']), 'New ride request nearby!',
                'ETB ' . number_format($fare, 0) . ' • ' . round($rd['km'], 1) . ' km away',
                array('type' => 'new_ride_request', 'ride_id' => $rid, 'url' => '/delivery/driver/'));
        }
    } catch (Exception $e) { error_log('[ETEL][push] phone-booking push failed: ' . $e->getMessage()); }
    ok(array('ride_id' => $rid, 'passenger_id' => $cid, 'fare' => $fare, 'open' => $open ? 1 : 0, 'new_account' => $newAcct ? 1 : 0));
    break;
}


/* 
   ADMIN RIDE MANAGEMENT ACTIONS
    */

case 'admin_get_rides': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $status  = isset($body['status'])  ? $body['status']  : '';
    $limit   = min(100, max(1, intval($body['limit']  ?? 50)));
    $offset  = max(0, intval($body['offset'] ?? 0));
    $search  = isset($body['search']) ? '%' . trim($body['search']) . '%' : '';

    $where = "WHERE 1=1";
    $vals  = array();
    if ($status && $status !== 'all') { $where .= " AND r.status=?"; $vals[] = $status; }
    if ($search) { $where .= " AND (p.name LIKE ? OR p.phone LIKE ? OR d.name LIKE ? OR rd.vehicle_plate LIKE ?)"; $vals = array_merge($vals, [$search,$search,$search,$search]); }

    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM rides r
            LEFT JOIN users p ON p.id = r.passenger_id
            LEFT JOIN users d ON d.id = r.driver_id
            LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
            $where");
        $count->execute($vals);
        $total = $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT r.id, r.status, r.created_at, r.started_at, r.completed_at,
            r.pickup_address, r.dropoff_address, r.distance_km, r.fare_estimate, r.fare_final,
            r.payment_method, r.fare_paid, r.open_trip, r.traveled_km,
            p.name AS passenger_name, p.phone AS passenger_phone,
            d.name AS driver_name, d.phone AS driver_phone,
            COALESCE(rd.vehicle_plate, '') AS vehicle_plate,
            rd.vehicle_type, rd.total_rides AS driver_total_rides,
            CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
            FROM rides r
            LEFT JOIN users p ON p.id = r.passenger_id
            LEFT JOIN users d ON d.id = r.driver_id
            LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
            $where ORDER BY r.id DESC LIMIT ? OFFSET ?");
        $vals[] = $limit; $vals[] = $offset;
        $stmt->execute($vals);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        fail_ex($e, 'admin_get_rides query failed', 500);
    }
    ok(array('rides' => $rows, 'total' => intval($total), 'limit' => $limit, 'offset' => $offset));
    break;
}


case 'admin_backup_download': {
    /* The newest database backup as a file, so a copy can be kept off the server.
       Owner-level admins only (the new 'system.backup' permission is held by full
       admins; managed staff would need it listed). Every download is logged. */
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    require_once __DIR__ . '/../lib/system_health.php';
    $bk = etel_backup_latest();
    if (!$bk) fail('No backup found yet', 404);
    error_log('[ETEL][backup] admin ' . intval($u['id']) . ' downloaded ' . $bk['name']);
    etel_health_heartbeat($pdo, 'backup_download', 'admin #' . intval($u['id']) . ' ' . $bk['name']);   // the health card reminds owners to keep fetching copies
    if (!etel_backup_send($bk)) fail('The backup file does not match its checksum - do not use it. Run a new backup.', 500);
    break;
}


case 'admin_system_health': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    require_once __DIR__ . '/../lib/ride_monitor.php';
    require_once __DIR__ . '/../lib/totp.php';
    require_once __DIR__ . '/../lib/system_health.php';
    ok(etel_system_health($pdo, intval($u['id'])));
    break;
}


case 'admin_client_errors': {
    // The browser errors the rider and driver apps reported (api/client-error.php), newest first. Read-only.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $days = isset($body['days']) ? max(1, min(30, intval($body['days']))) : 7;
    try {
        $q = $pdo->prepare("SELECT id, app, kind, page, message, source, line_no, rid, ua, user_id, hits, first_seen, last_seen
                            FROM client_errors WHERE last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY last_seen DESC LIMIT 100");
        $q->execute(array($days));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = array(); }   // table not created yet: nothing has been reported
    ok(array('errors' => $rows, 'days' => $days));
    break;
}


case 'admin_client_errors_clear': {
    // POST: id? (one error) or nothing (all of them). Needs rides.manage.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    try {
        if (!empty($body['id'])) $pdo->prepare("DELETE FROM client_errors WHERE id=?")->execute(array(substr(preg_replace('/[^a-f0-9]/', '', (string)$body['id']), 0, 32)));
        else $pdo->exec("DELETE FROM client_errors");
    } catch (Exception $e) { /* nothing to clear */ }
    ok(array('cleared' => true));
    break;
}


case 'admin_set_ride_pin_required': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    $on = !empty($body['required']) ? 1 : 0;
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_pin_required', ?, 'Driver must type the rider 4-digit trip PIN to start a trip (1 = required)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $on));
    error_log('[ETEL][pin] admin ' . intval($u['id']) . ' set ride_pin_required=' . $on);
    ok(array('required' => $on));
    break;
}


case 'admin_get_2fa_rule': {
    /* Read-only, any admin: is two-step sign-in required for admins, and is it on for me? Used by the Settings card. */
    if ($u['role'] !== 'admin') fail('Admin only', 403);
    require_once __DIR__ . '/../lib/totp.php';
    $req = 0;
    try { $req = intval($pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='require_admin_2fa' LIMIT 1")->fetchColumn()); } catch (Exception $e) {}
    $mine = false;
    try { $mine = (bool) etel_2fa_is_enabled($pdo, intval($u['id'])); } catch (Exception $e) {}
    ok(array('required' => $req === 1 ? 1 : 0, 'mine' => $mine ? 1 : 0, 'can_change' => user_has_capability($pdo, $u, 'system.backup') ? 1 : 0));
    break;
}


case 'admin_set_2fa_required': {
    /* Owner-level admins only. Turning it ON needs the caller's own 2FA to be on (no self lock-out). */
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'system.backup')) fail('Owner admins only', 403);
    require_once __DIR__ . '/../lib/totp.php';
    $on = !empty($body['required']) ? 1 : 0;
    if ($on && !etel_2fa_is_enabled($pdo, intval($u['id']))) fail('Turn on your own two-step sign-in first, then require it for everyone.', 409);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('require_admin_2fa', ?, 'Admins must have two-step sign-in on to use the admin pages (1 = required)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $on));
    error_log('[ETEL][2fa] admin ' . intval($u['id']) . ' set require_admin_2fa=' . $on);
    ok(array('required' => $on));
    break;
}


case 'admin_ride_watch': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    require_once __DIR__ . '/../lib/ride_monitor.php';
    ok(rides_monitor_scan($pdo));
    break;
}


case 'admin_get_ride_stats': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $today = date('Y-m-d');
    $week  = date('Y-m-d', strtotime('-7 days'));

    $stats = array();

    /* Status counts */
    $sc = $pdo->query("SELECT status, COUNT(*) as n FROM rides GROUP BY status");
    $byStatus = array();
    foreach ($sc->fetchAll() as $r) $byStatus[$r['status']] = intval($r['n']);
    $stats['by_status'] = $byStatus;

    /* Today's rides */
    $stmtTd = $pdo->prepare("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE DATE(created_at)=? AND status='completed'");
    $stmtTd->execute(array($today));
    $tdRow = $stmtTd->fetch();
    $stats['today_completed'] = intval($tdRow['n']);
    $stats['today_revenue']   = round(floatval($tdRow['rev']), 2);

    /* This week */
    $stmtWk = $pdo->prepare("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE created_at>=? AND status='completed'");
    $stmtWk->execute(array($week));
    $wkRow = $stmtWk->fetch();
    $stats['week_completed'] = intval($wkRow['n']);
    $stats['week_revenue']   = round(floatval($wkRow['rev']), 2);

    /* Active right now */
    $ac = $pdo->query("SELECT COUNT(*) FROM rides WHERE status IN ('searching','accepted','in_progress','arrived')");
    $stats['active_now'] = intval($ac->fetchColumn());

    /* Online drivers */
    $od = $pdo->query("SELECT COUNT(*) FROM ride_drivers WHERE online=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stats['online_drivers'] = intval($od->fetchColumn());

    /* Total all time */
    $tot = $pdo->query("SELECT COUNT(*) as n, COALESCE(SUM(fare_paid),0) as rev FROM rides WHERE status='completed'");
    $totRow = $tot->fetch();
    $stats['total_completed'] = intval($totRow['n']);
    $stats['total_revenue']   = round(floatval($totRow['rev']), 2);

    ok($stats);
    break;
}


case 'admin_cancel_ride': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = intval($body['ride_id'] ?? 0);
    if (!$rid) fail('ride_id required');
    $n = $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND status IN ('searching','accepted')");
    $n->execute(array($rid));
    if (!$n->rowCount()) fail('Ride not cancellable (already in progress or done)');
    ok(array('cancelled' => $rid));
    break;
}


case 'admin_manual_complete_ride': {
    // Rescue path for a trip that can't complete itself: the driver's
    // phone died, lost signal, or the app got killed mid-trip, so
    // driver_update_location never accumulated a usable traveled_km and
    // driver_arrived has nothing real to bill from. The driver calls
    // support, reads out the pickup point and where they actually
    // dropped the passenger, and an admin/support agent enters both
    // here as lat/lng (from the same Google Places-autocomplete map
    // every other booking screen in this app already uses, not a raw
    // address string - there's no server-side geocoder available on
    // this code path, see AgentController.php's agent_geocode_place for
    // why that one lives only there). Computes the real distance/fare
    // from those two points and completes the trip immediately - waiting
    // on driver_arrived/customer_confirm_arrival isn't realistic here
    // since the driver's own device is the reason this is needed in the
    // first place - through rides_complete_and_pay() (lib/ride_fare.php),
    // the exact same payout path every other completed ride uses.
    // Logged to ride_issues (already admin-visible under Ride Issues) so
    // every manual override has a permanent audit trail.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = intval($body['ride_id'] ?? 0);
    if (!$rid) fail('ride_id required');
    $plat = isset($body['pickup_lat'])  ? floatval($body['pickup_lat'])  : 0;
    $plng = isset($body['pickup_lng'])  ? floatval($body['pickup_lng'])  : 0;
    $dlat = isset($body['dropoff_lat']) ? floatval($body['dropoff_lat']) : 0;
    $dlng = isset($body['dropoff_lng']) ? floatval($body['dropoff_lng']) : 0;
    if (!$plat || !$plng || !$dlat || !$dlng) fail('pickup_lat/pickup_lng/dropoff_lat/dropoff_lng are all required');
    $pickupAddr  = isset($body['pickup_address'])  ? trim($body['pickup_address'])  : '';
    $dropoffAddr = isset($body['dropoff_address']) ? trim($body['dropoff_address']) : '';
    // Optional stops between pickup and drop-off (e.g. the passenger had
    // the driver swing by a second location) - same call-and-read-out
    // origin as pickup/dropoff above, just more than two points. Each
    // must carry real lat/lng (a raw address string alone can't be
    // distanced); anything malformed is dropped rather than failing the
    // whole request, since a stop is a refinement, not a required field.
    $waypoints = array();
    if (isset($body['waypoints']) && is_array($body['waypoints'])) {
        foreach ($body['waypoints'] as $wp) {
            if (!is_array($wp) || !isset($wp['lat']) || !isset($wp['lng'])) continue;
            $wlat = floatval($wp['lat']); $wlng = floatval($wp['lng']);
            if (!$wlat || !$wlng) continue;
            $waypoints[] = array('lat' => $wlat, 'lng' => $wlng, 'address' => isset($wp['address']) ? trim($wp['address']) : '');
        }
    }
    $pm = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    // Wallet needs an independently-verified transaction reference
    // (etel_pay_verify_tx) - there's no such reference to check here,
    // just a phone call, so wallet can't be trusted as "already paid"
    // through this path the way customer_confirm_arrival's wallet
    // branch can.
    if ($pm === 'wallet') fail('Wallet payment cannot be confirmed on a support call - use cash or another off-app method for a manually-closed trip.', 400);
    $note = isset($body['note']) ? trim($body['note']) : '';

    rides_ensure_wait_columns($pdo);
    $vtypeCol = rides_has_vehicle_type_column($pdo) ? ', vehicle_type' : '';
    // 'arrived' included alongside 'in_progress': driver_arrived already
    // computes a distance/fare from whatever GPS was tracked up to that
    // point, but if the same call/connection that killed live tracking
    // also stops the passenger from ever reaching customer_confirm_arrival,
    // the trip is stuck exactly the same way - waiting on a device that
    // isn't coming back. Admin re-entering the real pickup/drop-off points
    // here and recomputing the distance is the same trusted-override path
    // either way, not just for 'in_progress'.
    $s = $pdo->prepare("SELECT id, driver_id, open_trip, wait_active, wait_started_at, wait_seconds_total" . $vtypeCol . "
                        FROM rides WHERE id=? AND status IN ('in_progress','arrived')");
    $s->execute(array($rid));
    $r = $s->fetch();
    if (!$r) fail('Ride not found, or not currently in progress/arrived', 404);
    if (!$r['driver_id']) fail('This ride has no assigned driver', 400);

    // Same auto-close-forgotten-wait-clock safeguard as driver_arrived.
    if (intval($r['wait_active']) === 1) {
        $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
        $r['wait_seconds_total'] = intval($r['wait_seconds_total']) + $elapsed;
        $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=?")
            ->execute(array($r['wait_seconds_total'], $rid));
    }
    $waitMinutes = rides_current_wait_minutes($r);

    $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $r['driver_id']);
    $isOpenTrip = intval($r['open_trip']) === 1;
    // Distance is summed leg-by-leg across pickup -> each stop in order ->
    // drop-off, not a single point-to-point line, so a trip with stops
    // bills for the real route the driver actually drove rather than the
    // straight-line shortcut between just the first and last point.
    $chain = array_merge(array(array('lat' => $plat, 'lng' => $plng)), $waypoints, array(array('lat' => $dlat, 'lng' => $dlng)));
    $km = 0;
    for ($i = 1; $i < count($chain); $i++) {
        $km += rides_haversine_km($chain[$i - 1]['lat'], $chain[$i - 1]['lng'], $chain[$i]['lat'], $chain[$i]['lng']);
    }
    if ($km <= 0) fail('Pickup and drop-off cannot be the same point', 400);

    $fareResult = rides_calc_fare($pdo, $km, $vtype, $isOpenTrip, $waitMinutes);
    $fare = $fareResult['fare'];
    $breakdown = rides_fare_breakdown_for_ui($fareResult);
    $breakdown['manual_completion']  = true;
    $breakdown['completed_by_admin'] = $u['name'];
    if ($note !== '') $breakdown['admin_note'] = $note;
    if ($waypoints) $breakdown['manual_waypoints'] = $waypoints;
    $breakdownJson = json_encode($breakdown);

    $sets = array("distance_km=?", "traveled_km=?", "fare_breakdown_json=?");
    $params = array($km, $km, $breakdownJson);
    if ($pickupAddr !== '') { $sets[] = "pickup_lat=?"; $sets[] = "pickup_lng=?"; $sets[] = "pickup_address=?"; array_push($params, $plat, $plng, $pickupAddr); }
    if ($dropoffAddr !== '') { $sets[] = "dropoff_lat=?"; $sets[] = "dropoff_lng=?"; $sets[] = "dropoff_address=?"; array_push($params, $dlat, $dlng, $dropoffAddr); }
    if (rides_has_surge_column($pdo)) { $sets[] = "surge_applied=?"; $params[] = $fareResult['surge'] ? 1 : 0; }
    $params[] = $rid;
    $pdo->prepare("UPDATE rides SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);

    rides_complete_and_pay($pdo, $rid, $r['driver_id'], $fare, $pm);

    try {
        $details = 'Manually completed by ' . $u['name'] . ' - distance ' . round($km, 2) . ' km'
            . ($waypoints ? ' via ' . count($waypoints) . ' stop(s)' : '') . ', fare ETB ' . number_format($fare, 2)
            . ($note !== '' ? '. Note: ' . $note : '');
        $pdo->prepare("INSERT INTO ride_issues (ride_id, reporter_id, category, details, status, resolved_at) VALUES (?, ?, 'manual_completion', ?, 'resolved', NOW())")
            ->execute(array($rid, $u['id'], $details));
    } catch (Exception $e) {
        // Audit trail only - never let a logging failure undo a
        // trip that already completed and paid out successfully above.
        error_log('[ETEL][admin_manual_complete_ride] audit log insert failed for ride ' . $rid . ': ' . $e->getMessage());
    }

    ok(array_merge(array('completed' => true, 'ride_id' => $rid, 'distance_km' => round($km, 2),
             'fare' => $fare, 'payment_method' => $pm), $breakdown));
    break;
}


case 'admin_get_drivers': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    // Guarantee these columns exist even if no ride has completed yet
    // since deploy (they're otherwise only lazily created inside
    // customer_confirm_arrival) - this query would throw "Unknown
    // column" on a fresh install/deploy without this.
    require_once __DIR__ . '/../lib/vehicle_photos.php'; etel_vp_ensure($pdo);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'commission_owed'    => "commission_owed DECIMAL(12,2) DEFAULT 0",
        'cash_fares_total'   => "cash_fares_total DECIMAL(12,2) DEFAULT 0",
        'etel_money_balance' => "etel_money_balance DECIMAL(12,2) DEFAULT 0"
    ));

    // Optional search-by name/phone, used by any admin screen where a
    // driver needs to be picked from a list (e.g. the manual top-up
    // form) - matches on either field so admins can type whichever one
    // they have on hand. Accepted from JSON body ('q') or query string
    // ('q'/'search') to cover both POST and GET callers. Digits-only
    // phone matching so "+251 91..." vs "091..." vs spaced/dashed input
    // all still find the same driver.
    $q = isset($body['q']) ? trim($body['q'])
       : (isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['search']) ? trim($_GET['search']) : ''));

    $where = '';
    $params = array();
    if ($q !== '') {
        $qDigits = preg_replace('/[^0-9]/', '', $q);
        if ($qDigits !== '') {
            // Looks phone-ish - match against the phone with all
            // non-digits stripped too, so formatting differences don't
            // cause a miss. Still also checks name in case of a
            // numeric-looking name/nickname.
            $where = "WHERE (u.name LIKE ? OR REPLACE(REPLACE(REPLACE(u.phone,'+',''),' ',''),'-','') LIKE ?)";
            $params = array('%' . $q . '%', '%' . $qDigits . '%');
        } else {
            $where = "WHERE u.name LIKE ? OR u.phone LIKE ?";
            $params = array('%' . $q . '%', '%' . $q . '%');
        }
    }

    $stmt = $pdo->prepare("SELECT u.id, u.name, u.phone, u.created_at,
        rd.online, rd.vehicle_type, rd.vehicle_make, rd.vehicle_color, rd.vehicle_plate,
        rd.license_number, rd.license_name, rd.license_dob, rd.identity_match,
        rd.selfie_url, rd.license_photo_url, rd.vehicle_photo_front, rd.vehicle_photo_side, rd.vehicle_photo_back,
        rd.onboarded, rd.total_earnings, rd.total_rides,
        rd.commission_owed, rd.etel_money_balance,
        rd.last_seen,
        CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS rating
        FROM users u
        JOIN ride_drivers rd ON rd.user_id = u.id
        $where
        ORDER BY (rd.identity_match = 'mismatch') DESC, rd.online DESC, rd.last_seen DESC");
    $stmt->execute($params);
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}


case 'admin_get_live_drivers': {
    // Feeds admin's live driver map (admin-growth-charts.js's
    // pdRefreshDrivers, polled every 20s). "Online" is the driver's own
    // toggle (see driver_go_online/driver_go_offline) - last_seen alone
    // isn't checked here since a driver marked online is presumed
    // online until they explicitly go offline or the app's own
    // heartbeat logic decides otherwise elsewhere; this endpoint just
    // reports current state, it doesn't second-guess it.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    $lat = isset($body['lat']) ? floatval($body['lat']) : null;
    $lng = isset($body['lng']) ? floatval($body['lng']) : null;
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.lat, rd.lng, rd.vehicle_type, rd.vehicle_plate, rd.last_seen
        FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
        WHERE rd.online = 1 AND rd.lat IS NOT NULL AND rd.lng IS NOT NULL");
    $stmt->execute();
    $drivers = $stmt->fetchAll();
    // PDO returns DECIMAL columns as PHP strings, which json_encode then
    // serializes as JSON strings ("38.4723000") instead of numbers - the
    // frontend's google.maps.Marker({position: {lat, lng}}) strictly
    // requires actual numbers and throws "not a number" on a string,
    // even a numeric-looking one. Cast explicitly before it ever reaches
    // json_encode.
    foreach ($drivers as &$d) {
        $d['lat'] = floatval($d['lat']);
        $d['lng'] = floatval($d['lng']);
    }
    unset($d);
    if ($lat !== null && $lng !== null) {
        foreach ($drivers as &$d) {
            $d['distance_km'] = round(6371 * acos(min(1.0, cos(deg2rad($lat))
                * cos(deg2rad($d['lat']))
                * cos(deg2rad($d['lng']) - deg2rad($lng))
                + sin(deg2rad($lat)) * sin(deg2rad($d['lat'])))), 2);
        }
        unset($d);
    }
    ok(array('drivers' => $drivers));
    break;
}


case 'admin_get_suspended_drivers': {
    // Feeds the suspended-drivers panel on the growth dashboard
    // (growthLoadSuspendedDrivers). No auto-suspend trigger exists yet -
    // this is the read side plus the two actions below to manually
    // suspend/unsuspend from a ride issue report. Auto-suspend-on-N-
    // complaints (mentioned as a planned feature) is a separate,
    // not-yet-built piece - this just makes the existing frontend work
    // instead of 404ing.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.suspended_at, rd.suspended_reason,
        (SELECT ri.id FROM ride_issues ri WHERE ri.reporter_id != u.id
            AND ri.ride_id IN (SELECT id FROM rides WHERE driver_id = u.id)
            ORDER BY ri.id DESC LIMIT 1) AS latest_issue_id
        FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
        WHERE rd.suspended_at IS NOT NULL
        ORDER BY rd.suspended_at DESC");
    $stmt->execute();
    ok(array('suspended' => $stmt->fetchAll()));
    break;
}


case 'admin_suspend_driver': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : '';
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NOW(), suspended_reason=?, suspended_by=?, online=0 WHERE user_id=?")
        ->execute(array($reason, $u['id'], $did));
    etel_bump_token_version($pdo, $did); // force any active session to re-auth
    ok(array('suspended' => true));
    break;
}


case 'admin_unsuspend_driver': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NULL, suspended_reason=NULL, suspended_by=NULL WHERE user_id=?")
        ->execute(array($did));
    ok(array('unsuspended' => true));
    break;
}


/* ── Identity mismatch review ──────────────────────────────────────
   Registration already flags a driver as identity_match='mismatch' and
   tells them their account needs admin review before they can go
   online (now actually enforced in driver_go_online above - it never
   was before). These three actions are the missing other half: a way
   for an admin to actually see and act on that flag. Modeled directly
   on the pending-vehicle-change review pattern already built and
   working in admin-rides.js/admin_get_pending_vehicle_changes. */

case 'admin_get_flagged_identities': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.phone, u.created_at,
        rd.license_name, rd.license_number, rd.license_dob,
        rd.selfie_url, rd.license_photo_url, rd.vehicle_type, rd.vehicle_plate
        FROM users u JOIN ride_drivers rd ON rd.user_id = u.id
        WHERE rd.identity_match = 'mismatch'
        ORDER BY u.created_at DESC");
    $stmt->execute();
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}


case 'admin_approve_driver_identity': {
    // The license details are confirmed correct on manual review (e.g.
    // a middle name/nickname mismatch, a transliteration difference) -
    // clears the flag and lets the driver go online normally. Must also
    // finish onboarding and promote users.role to 'driver' here, the
    // same two things driver_update_profile does on a CLEAN match -
    // otherwise an admin-approved driver would still be stuck as
    // onboarded=0 (invisible to their own app's normal flow) and still
    // carrying whatever role they had before (usually 'customer'),
    // which is the exact bug this whole review panel was built to fix
    // in the first place, just recreated via the approval path instead
    // of the auto-match path.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');
    $row = $pdo->prepare("SELECT vehicle_type, vehicle_plate, selfie_url, license_photo_url FROM ride_drivers WHERE user_id=?");
    $row->execute(array($did));
    $d = $row->fetch();
    $canFullyOnboard = $d && $d['vehicle_type'] && $d['vehicle_plate'] && $d['selfie_url'] && $d['license_photo_url'];
    $sql = "UPDATE ride_drivers SET identity_match='matched'" . ($canFullyOnboard ? ", onboarded=1" : "") . " WHERE user_id=?";
    $pdo->prepare($sql)->execute(array($did));
    if ($canFullyOnboard) {
        $pdo->prepare("UPDATE users SET role='driver' WHERE id=?")->execute(array($did));
    }
    ok(array('approved' => true, 'fully_onboarded' => (bool) $canFullyOnboard));
    break;
}


case 'admin_reject_driver_identity': {
    // A genuine identity mismatch, not a false positive - suspends the
    // account outright (reusing the same suspend mechanism/columns as
    // admin_suspend_driver) rather than leaving it in limbo.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'suspended_at'     => "suspended_at DATETIME NULL",
        'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
        'suspended_by'     => "suspended_by INT UNSIGNED NULL"
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : 'License identity did not match EtelID record.';
    if (!$did) fail('user_id is required');
    $pdo->prepare("UPDATE ride_drivers SET suspended_at=NOW(), suspended_reason=?, suspended_by=?, online=0 WHERE user_id=?")
        ->execute(array($reason, $u['id'], $did));
    etel_bump_token_version($pdo, $did);
    ok(array('rejected' => true));
    break;
}


/* ── Driver compliance (association dues / insurance) ──────────────
   Feeds the Traffic Officer NFC "Driver Info" check (see
   NfcController.php's nfc_driver_status). Admin sets each status after
   confirming payment offline; the receipt photo is uploaded through the
   existing generic api/upload.php the same way selfie_url/
   license_photo_url already are, then its returned URL is submitted
   here alongside the status. */
case 'admin_list_driver_compliance': {
    // Association dues are no longer managed here at all - see
    // nfc_driver_status in NfcController.php, which now checks
    // EtelTaxi's own live database (via taxi_bridge.php) instead of a
    // manually-toggled Delivery-side field, since EtelTaxi is the
    // actual system of record for that data. This panel only manages
    // the three physical documents Delivery has no other source for:
    // insurance, technical inspection, and Road Fund sticker - each
    // tracked by real expiry date (matching what's printed on the
    // actual certificate/sticker) rather than a manually-flipped
    // paid/unpaid switch an admin could forget to update.
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.view')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.phone,
        rd.vehicle_type, rd.vehicle_plate, rd.onboarded,
        rd.insurance_expiry_date, rd.insurance_receipt_url,
        rd.technical_inspection_expiry_date, rd.technical_inspection_receipt_url,
        rd.road_fund_expiry_date, rd.road_fund_receipt_url
        FROM users u JOIN ride_drivers rd ON rd.user_id = u.id
        WHERE rd.onboarded = 1
        ORDER BY u.name ASC");
    $stmt->execute();
    ok(array('drivers' => $stmt->fetchAll()));
    break;
}


case 'admin_update_driver_compliance': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $did = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$did) fail('user_id is required');

    $dateFields = array('insurance_expiry_date', 'technical_inspection_expiry_date', 'road_fund_expiry_date');
    $urlFields  = array('insurance_receipt_url', 'technical_inspection_receipt_url', 'road_fund_receipt_url');

    $sets = array(); $vals = array();
    foreach ($dateFields as $f) {
        if (!isset($body[$f])) continue;
        $v = trim($body[$f]);
        if ($v === '') { $sets[] = "$f=NULL"; continue; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) fail("$f must be a YYYY-MM-DD date or empty");
        $sets[] = "$f=?"; $vals[] = $v;
    }
    foreach ($urlFields as $f) {
        if (!isset($body[$f])) continue;
        $sets[] = "$f=?"; $vals[] = substr(trim($body[$f]), 0, 500);
    }
    if (empty($sets)) fail('No valid fields provided');

    $vals[] = $did;
    $pdo->prepare("UPDATE ride_drivers SET " . implode(',', $sets) . " WHERE user_id=?")->execute($vals);
    ok(array('updated' => true));
    break;
}


case 'admin_get_pending_compliance_requests': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $stmt = $pdo->prepare("SELECT r.id, r.driver_user_id, r.doc_type, r.requested_expiry_date,
        r.requested_receipt_url, r.created_at, us.name, us.phone
        FROM driver_compliance_requests r JOIN users us ON us.id = r.driver_user_id
        WHERE r.status = 'pending'
        ORDER BY r.created_at ASC");
    $stmt->execute();
    ok(array('requests' => $stmt->fetchAll()));
    break;
}


case 'admin_approve_compliance_document': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id is required');

    $r = $pdo->prepare("SELECT * FROM driver_compliance_requests WHERE id=? AND status='pending'");
    $r->execute(array($rid));
    $req = $r->fetch();
    if (!$req) fail('Request not found or already reviewed.', 404);

    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'insurance_expiry_date'              => "insurance_expiry_date DATE NULL",
        'insurance_receipt_url'              => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
        'technical_inspection_expiry_date'   => "technical_inspection_expiry_date DATE NULL",
        'technical_inspection_receipt_url'   => "technical_inspection_receipt_url VARCHAR(500) DEFAULT ''",
        'road_fund_expiry_date'              => "road_fund_expiry_date DATE NULL",
        'road_fund_receipt_url'              => "road_fund_receipt_url VARCHAR(500) DEFAULT ''",
    ));
    $expiryCol  = $req['doc_type'] . '_expiry_date';
    $receiptCol = $req['doc_type'] . '_receipt_url';
    $pdo->prepare("UPDATE ride_drivers SET $expiryCol=?, $receiptCol=? WHERE user_id=?")
        ->execute(array($req['requested_expiry_date'], $req['requested_receipt_url'], $req['driver_user_id']));
    $pdo->prepare("UPDATE driver_compliance_requests SET status='approved', reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=?")
        ->execute(array($u['id'], $rid));
    ok(array('approved' => true));
    break;
}


case 'admin_reject_compliance_document': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'users.manage')) fail('Admin only', 403);
    rides_ensure_compliance_requests_table($pdo);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id is required');
    $reason = isset($body['reason']) ? trim(substr($body['reason'], 0, 255)) : '';
    $n = $pdo->prepare("UPDATE driver_compliance_requests SET status='rejected', reject_reason=?, reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=? AND status='pending'");
    $n->execute(array($reason, $u['id'], $rid));
    if ($n->rowCount() === 0) fail('Request not found or already reviewed.', 404);
    ok(array('rejected' => true));
    break;
}


case 'admin_get_pending_vehicle_changes': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.view')) fail('Admin only', 403);
    $s = $pdo->query("SELECT r.id, r.driver_user_id, r.requested_vehicle_type, r.requested_vehicle_make,
        r.requested_vehicle_color, r.requested_vehicle_year, r.requested_vehicle_plate, r.created_at,
        u.name AS driver_name, u.phone AS driver_phone,
        rd.vehicle_type AS current_vehicle_type, rd.vehicle_make AS current_vehicle_make,
        rd.vehicle_color AS current_vehicle_color, rd.vehicle_year AS current_vehicle_year,
        rd.vehicle_plate AS current_vehicle_plate
        FROM driver_vehicle_change_requests r
        JOIN users u ON u.id = r.driver_user_id
        JOIN ride_drivers rd ON rd.user_id = r.driver_user_id
        WHERE r.status='pending' ORDER BY r.created_at ASC");
    ok(array('requests' => $s->fetchAll()));
    break;
}


case 'admin_approve_vehicle_change': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    if (!$rid) fail('request_id required');
    $r = $pdo->prepare("SELECT * FROM driver_vehicle_change_requests WHERE id=? AND status='pending'");
    $r->execute(array($rid));
    $row = $r->fetch();
    if (!$row) fail('Request not found or already reviewed', 404);

    // This is the ONLY place a driver's declared vehicle actually
    // changes in ride_drivers going forward - map markers, ride
    // previews, and the driver's own profile all read from here, so
    // approving here is what makes the change visible everywhere at once.
    $upd = $pdo->prepare("UPDATE ride_drivers SET vehicle_type=?, vehicle_make=?, vehicle_color=?, vehicle_year=?, vehicle_plate=? WHERE user_id=?");
    $upd->execute(array($row['requested_vehicle_type'], $row['requested_vehicle_make'], $row['requested_vehicle_color'],
        $row['requested_vehicle_year'], $row['requested_vehicle_plate'], $row['driver_user_id']));

    $pdo->prepare("UPDATE driver_vehicle_change_requests SET status='approved', reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=?")
        ->execute(array($u['id'], $rid));

    etel_push_send($pdo, intval($row['driver_user_id']), 'Vehicle Update Approved',
        'Your vehicle change was approved and is now active.',
        array('action' => 'vehicle_change_approved'));
    ok(array('approved' => true));
    break;
}


case 'admin_reject_vehicle_change': {
    if ($u['role'] !== 'admin' || !user_has_capability($pdo, $u, 'rides.manage')) fail('Admin only', 403);
    $rid = isset($body['request_id']) ? intval($body['request_id']) : 0;
    $reason = isset($body['reason']) ? substr(trim($body['reason']), 0, 255) : '';
    if (!$rid) fail('request_id required');
    $n = $pdo->prepare("UPDATE driver_vehicle_change_requests SET status='rejected', reject_reason=?, reviewed_at=NOW(), reviewed_by_user_id=? WHERE id=? AND status='pending'");
    $n->execute(array($reason, $u['id'], $rid));
    if (!$n->rowCount()) fail('Request not found or already reviewed', 404);

    $r2 = $pdo->prepare("SELECT driver_user_id FROM driver_vehicle_change_requests WHERE id=?");
    $r2->execute(array($rid));
    $row2 = $r2->fetch();
    if ($row2) {
        etel_push_send($pdo, intval($row2['driver_user_id']), 'Vehicle Update Rejected',
            $reason !== '' ? $reason : 'Your vehicle change request was not approved.',
            array('action' => 'vehicle_change_rejected'));
    }
    ok(array('rejected' => true));
    break;
}

}
