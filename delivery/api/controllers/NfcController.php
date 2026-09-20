<?php
/**
 * NFC card actions: "order picked up" / "delivery complete" confirmed by
 * someone scanning a courier's EtelID NFC card (service giver confirms
 * pickup, customer confirms delivery). These actions are called
 * server-to-server by EtelID (the courier's card resolves to a page on
 * EtelID's own site; that page authenticates the scanning person's own
 * EtelID password, then EtelID calls in here on their behalf) - never
 * directly by a browser, so they are gated by the shared service key
 * (same header EtelID's other Delivery integrations already use), not by
 * a Delivery bearer token.
 */

if (!function_exists('nfc_require_service_key')) {
    function nfc_require_service_key() {
        $configured_key = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
        if (!$configured_key) fail('Service key not configured on Delivery.', 500);
        $provided = '';
        if (isset($_SERVER['HTTP_X_ETEL_SERVICE_KEY'])) {
            $provided = $_SERVER['HTTP_X_ETEL_SERVICE_KEY'];
        } elseif (isset($_GET['key'])) {
            /* Fallback for hosts that strip custom X-* headers before PHP sees them. */
            $provided = $_GET['key'];
        } elseif (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            foreach ($hdrs as $hk => $hv) {
                if (strcasecmp($hk, 'X-Etel-Service-Key') === 0) { $provided = $hv; break; }
            }
        }
        $match = function_exists('hash_equals') ? hash_equals($configured_key, (string) $provided) : ($provided === $configured_key);
        if (!$match) fail('Invalid or missing service key.', 401);
    }
}

/* Find (and lightly self-heal) the local `users` row for an EtelID
   identity. $create=true auto-provisions a brand-new local account
   (role 'customer') the same way the normal EtelID-SSO-login bridge
   already does for anyone tapping in as a customer/confirmer - never
   used for resolving the courier, who must already exist. No pin_hash
   is ever passed across this boundary; an auto-created account gets a
   random local password, same as the existing SSO bridge's fallback
   branch, since it is always accessed via EtelID SSO afterward. */
if (!function_exists('nfc_find_or_create_user')) {
    function nfc_find_or_create_user($pdo, $etel_id, $phone, $full_name, $create = false) {
        $etel_id = trim((string) $etel_id);
        $digits  = preg_replace('/[^0-9]/', '', (string) $phone);
        $last9   = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

        if ($etel_id) {
            $s = $pdo->prepare('SELECT id, name, phone, etel_id, role FROM users WHERE etel_id = ? LIMIT 1');
            $s->execute(array($etel_id));
            $u = $s->fetch();
            if ($u) return $u;
        }

        $s = $pdo->prepare(
            "SELECT id, name, phone, etel_id, role FROM users
             WHERE REPLACE(REPLACE(phone,'+',''),' ','') = ?
                OR RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''), 9) = ?
             LIMIT 1"
        );
        $s->execute(array($digits, $last9));
        $u = $s->fetch();
        if ($u) {
            if ($etel_id && empty($u['etel_id'])) {
                $pdo->prepare('UPDATE users SET etel_id = ? WHERE id = ?')->execute(array($etel_id, $u['id']));
                $u['etel_id'] = $etel_id;
            }
            return $u;
        }

        if (!$create) return null;

        $hash = password_hash(bin2hex(openssl_random_pseudo_bytes(16)), PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name, phone, etel_id, password_hash, role, active, created_at)
                       VALUES (?,?,?,?, 'customer', 1, NOW())")
            ->execute(array($full_name, $phone, $etel_id ?: null, $hash));
        $s = $pdo->prepare('SELECT id, name, phone, etel_id, role FROM users WHERE id = ?');
        $s->execute(array($pdo->lastInsertId()));
        return $s->fetch();
    }
}

/* Needed by nfc_start_ride below (rides_get_active_for_driver) and by
   nfc_driver_status's schema self-heal - this file is required directly
   by boot.php's router (see the nfc_ case), never through
   RideController.php, so its helper libs are not already loaded the way
   they would be for a normal ride_* action. */
require_once __DIR__ . '/../lib/ride_fare.php';
require_once __DIR__ . '/../lib/schema_helpers.php';

/* One-time (per-request-cheap, idempotent) grant of the capability the
   Traffic Officer staff role needs for nfc_driver_status below. Migration
   018_role_permissions.sql seeded this role with only "orders.view" -
   this adds "drivers.compliance.view" without disturbing whatever an
   admin has since customized the row to, mirroring rides_add_missing_cols'
   self-heal-without-clobbering approach but for a JSON array field
   instead of a table column. */
if (!function_exists('nfc_ensure_traffic_officer_capability')) {
    function nfc_ensure_traffic_officer_capability($pdo) {
        try {
            $s = $pdo->prepare("SELECT capabilities FROM role_permissions WHERE LOWER(role_name) = LOWER('Traffic Officer') LIMIT 1");
            $s->execute();
            $row = $s->fetch();
            if (!$row) {
                $pdo->prepare("INSERT IGNORE INTO role_permissions (role_name, capabilities) VALUES ('Traffic Officer', ?)")
                    ->execute(array(json_encode(array('orders.view', 'drivers.compliance.view'))));
                return;
            }
            $caps = json_decode($row['capabilities'], true);
            if (!is_array($caps)) $caps = array();
            if (!in_array('*', $caps, true) && !in_array('drivers.compliance.view', $caps, true)) {
                $caps[] = 'drivers.compliance.view';
                $pdo->prepare("UPDATE role_permissions SET capabilities = ? WHERE LOWER(role_name) = LOWER('Traffic Officer')")
                    ->execute(array(json_encode($caps)));
            }
        } catch (Exception $e) {
            error_log('[ETEL][nfc] Traffic Officer capability self-heal failed: ' . $e->getMessage());
        }
    }
}

switch ($action) {

case 'nfc_driver_status': {
    // POST: etel_id/phone - identifies whoever's card was scanned (the
    // driver being checked). officer_etel_id/officer_phone - the
    // confirming person, who already authenticated with their OWN
    // EtelID password on the /m/{token} page before this call ever
    // fires (see class-etelid-nfc.php's ajax_start 'driver_status'
    // branch). Unlike the rest of the public profile, this data
    // (license, compliance status) is only released to someone who
    // holds Delivery's "Traffic Officer" staff role (or a full,
    // non-EtelGroup-managed admin account) - a bare card tap, or a tap
    // confirmed by an ordinary EtelID login, gets a 403.
    nfc_require_service_key();
    $pdo = db();

    $officer_etel_id = isset($body['officer_etel_id']) ? trim($body['officer_etel_id']) : '';
    $officer_phone   = isset($body['officer_phone'])   ? trim($body['officer_phone'])   : '';
    if (!$officer_etel_id && !$officer_phone) fail('officer_etel_id or officer_phone is required', 400);

    $officer_user = nfc_find_or_create_user($pdo, $officer_etel_id, $officer_phone, '', false);
    if (!$officer_user) fail('No Delivery account found for this EtelID. Sign in once in the Delivery admin panel first.', 404);

    $userCols = etel_table_columns($pdo, 'users');
    $officerSel = 'SELECT role'
        . (in_array('staff_role', $userCols, true) ? ', staff_role' : '')
        . (in_array('is_etelgroup_managed', $userCols, true) ? ', is_etelgroup_managed' : '')
        . ' FROM users WHERE id = ?';
    $os = $pdo->prepare($officerSel);
    $os->execute(array($officer_user['id']));
    $officerRow = $os->fetch(PDO::FETCH_ASSOC);

    nfc_ensure_traffic_officer_capability($pdo);
    if (!$officerRow || $officerRow['role'] !== 'admin' || !user_has_capability($pdo, $officerRow, 'drivers.compliance.view')) {
        fail('Traffic Officer access required to view driver compliance info.', 403);
    }

    $etel_id = isset($body['etel_id']) ? trim($body['etel_id']) : '';
    $phone   = isset($body['phone'])   ? trim($body['phone'])   : '';
    if (!$etel_id && !$phone) fail('etel_id or phone is required', 400);

    $user = nfc_find_or_create_user($pdo, $etel_id, $phone, '', false);
    if (!$user) { ok(array('is_driver' => false)); break; }

    rides_add_missing_cols($pdo, 'ride_drivers', array(
        'association_payment_status' => "association_payment_status ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid'",
        'association_receipt_url'    => "association_receipt_url VARCHAR(500) DEFAULT ''",
        'insurance_status'           => "insurance_status ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid'",
        'insurance_receipt_url'      => "insurance_receipt_url VARCHAR(500) DEFAULT ''",
    ));

    $s = $pdo->prepare(
        'SELECT vehicle_type, vehicle_plate, license_name, license_number, license_photo_url,
                identity_match, onboarded,
                association_payment_status, association_receipt_url,
                insurance_status, insurance_receipt_url
         FROM ride_drivers WHERE user_id = ?'
    );
    $s->execute(array($user['id']));
    $d = $s->fetch();
    if (!$d) { ok(array('is_driver' => false)); break; }

    $license_no = trim((string) $d['license_number']);
    $masked = '';
    if ($license_no !== '') {
        $tail = substr($license_no, -4);
        $masked = str_repeat('*', max(0, strlen($license_no) - strlen($tail))) . $tail;
    }

    ok(array(
        'is_driver'                  => true,
        'onboarded'                  => intval($d['onboarded']) === 1,
        'vehicle_type'               => $d['vehicle_type'],
        'vehicle_plate'              => $d['vehicle_plate'],
        'license_name'               => $d['license_name'],
        'license_masked'             => $masked,
        'license_photo_url'          => $d['license_photo_url'],
        'identity_match'             => $d['identity_match'] ? $d['identity_match'] : 'unverified',
        'association_payment_status' => $d['association_payment_status'] ? $d['association_payment_status'] : 'unpaid',
        'association_receipt_url'    => $d['association_receipt_url'],
        'insurance_status'           => $d['insurance_status'] ? $d['insurance_status'] : 'unpaid',
        'insurance_receipt_url'      => $d['insurance_receipt_url'],
    ));
    break;
}

case 'nfc_start_ride': {
    // POST: driver_etel_id/phone (the confirming driver, who scanned the
    // rider's card and entered THEIR OWN password on EtelID), plus
    // customer_etel_id/phone/name (the card owner, i.e. the rider).
    // Mirrors driver_hail_customer's street-hail insert, but the rider is
    // resolved from the tapped card's identity instead of a typed phone
    // number - see RideController.php's driver_hail_customer for the
    // original, phone-entry version of this same flow.
    nfc_require_service_key();
    $pdo = db();

    $driver_etel_id = isset($body['driver_etel_id']) ? trim($body['driver_etel_id']) : '';
    $driver_phone   = isset($body['driver_phone'])   ? trim($body['driver_phone'])   : '';
    if (!$driver_etel_id && !$driver_phone) fail('driver_etel_id or driver_phone is required', 400);

    $driver_user = nfc_find_or_create_user($pdo, $driver_etel_id, $driver_phone, '', false);
    if (!$driver_user) fail('No Delivery driver account found for this EtelID. Sign in once in the Driver app first.', 404);
    if ($driver_user['role'] !== 'driver' && $driver_user['role'] !== 'admin') {
        fail('This EtelID is not registered as a Driver on Delivery.', 403);
    }

    $ob = $pdo->prepare('SELECT vehicle_type, onboarded FROM ride_drivers WHERE user_id = ?');
    $ob->execute(array($driver_user['id']));
    $driverRow = $ob->fetch();
    if (!$driverRow || intval($driverRow['onboarded']) !== 1) {
        fail('Your driver account is not fully verified yet. Finish onboarding (vehicle info, selfie, license photo) before starting trips.', 403);
    }

    $customer_etel_id = isset($body['customer_etel_id']) ? trim($body['customer_etel_id']) : '';
    $customer_phone   = isset($body['customer_phone'])   ? trim($body['customer_phone'])   : '';
    $customer_name    = isset($body['customer_name'])    ? trim($body['customer_name'])    : '';
    if (!$customer_etel_id && !$customer_phone) fail('customer_etel_id or customer_phone is required', 400);

    $customer_user = nfc_find_or_create_user($pdo, $customer_etel_id, $customer_phone, $customer_name, true);
    if (!$customer_user) fail('Could not resolve the rider\'s EtelID account.', 404);
    if (intval($customer_user['id']) === intval($driver_user['id'])) fail('You cannot start a trip with yourself');

    $mine = rides_get_active_for_driver($pdo, $driver_user['id']);
    if ($mine) fail('You have an active ride #' . $mine['id'] . ' (' . $mine['status'] . '). Complete or cancel it first.');

    $s2 = $pdo->prepare("SELECT id, status FROM rides WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived') LIMIT 1");
    $s2->execute(array($customer_user['id']));
    $theirs = $s2->fetch();
    if ($theirs) {
        if ($theirs['status'] === 'searching') {
            $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=? AND status='searching'")
                ->execute(array($theirs['id']));
        } else {
            fail($customer_user['name'] . ' already has an active ride #' . $theirs['id'] . ' (' . $theirs['status'] . '). Ask them to cancel it first.', 409);
        }
    }

    $driverVtype = !empty($driverRow['vehicle_type']) ? $driverRow['vehicle_type'] : 'bajaj';

    // No GPS/distance is captured at NFC-tap time (unlike the phone-entry
    // flow, which can send the driver's current lat/lng) - an NFC-started
    // street hail always begins as an open (metered) trip, same as the
    // driver app's own "open trip" toggle when no km is entered.
    $extraCols = array(); $extraVals = array();
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            $have[strtolower($c['Field'])] = true;
        }
        foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) {
            if (isset($have[$cc])) {
                $extraCols[] = "`$cc`";
                $extraVals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
            }
        }
        if (isset($have['vehicle_type'])) {
            $extraCols[] = '`vehicle_type`';
            $extraVals[] = $driverVtype;
        }
    } catch (Exception $e) { /* non-fatal */ }

    $cols = "passenger_id, driver_id, pickup_lat, pickup_lng,
             pickup_address, dropoff_address, distance_km,
             fare_estimate, fare_final, payment_method,
             status, open_trip, assigned_at, created_at";
    $ph   = "?,?,?,?, 'NFC tap - street hail', ?, 0, 0, 0, 'cash', 'accepted', 1, NOW(), NOW()";
    $vals = array($customer_user['id'], $driver_user['id'], null, null,
                  'Open trip - customer directs the driver (GPS meter)');
    if ($extraCols) {
        $cols .= ', ' . implode(', ', $extraCols);
        $ph   .= str_repeat(', ?', count($extraCols));
        $vals  = array_merge($vals, $extraVals);
    }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);

    ok(array('message' => 'Trip started with ' . $customer_user['name'] . '.'));
    break;
}

case 'nfc_list_courier_orders': {
    // POST: courier_etel_id, courier_phone, courier_name, confirmer_etel_id,
    //       confirmer_phone, stage ('pickup'|'delivery')
    // Returns the courier's active orders that the confirming person is
    // actually authorized to act on - a service giver only sees orders
    // assigned to their own business, a customer only sees their own order.
    nfc_require_service_key();
    $pdo = db();

    $stage = isset($body['stage']) ? trim($body['stage']) : '';
    if (!in_array($stage, array('pickup', 'delivery'), true)) fail('stage must be pickup or delivery', 400);

    $courier_etel_id = isset($body['courier_etel_id']) ? trim($body['courier_etel_id']) : '';
    $courier_phone   = isset($body['courier_phone'])   ? trim($body['courier_phone'])   : '';
    if (!$courier_etel_id && !$courier_phone) fail('courier_etel_id or courier_phone is required', 400);

    $courier_user = nfc_find_or_create_user($pdo, $courier_etel_id, $courier_phone, '', false);
    if (!$courier_user) fail('This card is not linked to a courier account on Delivery yet. The courier needs to sign in with EtelID in the Courier app once first.', 404);

    $cs = $pdo->prepare('SELECT id, zone, home_zone FROM couriers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $cs->execute(array($courier_user['id']));
    $courier = $cs->fetch();
    if (!$courier) fail('No courier profile for this account.', 404);

    $confirmer_etel_id = isset($body['confirmer_etel_id']) ? trim($body['confirmer_etel_id']) : '';
    $confirmer_phone   = isset($body['confirmer_phone'])   ? trim($body['confirmer_phone'])   : '';
    $confirmer_name    = isset($body['confirmer_name'])    ? trim($body['confirmer_name'])    : '';
    if (!$confirmer_etel_id && !$confirmer_phone) fail('confirmer_etel_id or confirmer_phone is required', 400);

    if ($stage === 'pickup') {
        $confirmer_user = nfc_find_or_create_user($pdo, $confirmer_etel_id, $confirmer_phone, $confirmer_name, false);
        if (!$confirmer_user) fail('No Delivery account found for this EtelID. Sign in once in the Service Giver app first.', 404);
        if ($confirmer_user['role'] !== 'service_giver' && $confirmer_user['role'] !== 'admin') {
            fail('This EtelID is not registered as a Service Giver on Delivery.', 403);
        }
        $sgs = $pdo->prepare('SELECT id, zone FROM service_givers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $sgs->execute(array($confirmer_user['id']));
        $sg = $sgs->fetch();
        if (!$sg) fail('Service giver profile not found.', 404);

        $q = $pdo->prepare(
            "SELECT o.id, o.order_code, o.from_zone, o.to_zone, o.package_type, o.status
             FROM orders o
             LEFT JOIN order_assignments oa ON oa.order_id = o.id
             WHERE o.courier_id = ? AND o.status = 'assigned'
               AND (o.service_giver_id = ? OR oa.service_giver_id = ? OR (o.from_zone = ? AND ? != ''))
             GROUP BY o.id
             ORDER BY o.placed_at DESC LIMIT 20"
        );
        $q->execute(array($courier['id'], $sg['id'], $sg['id'], $sg['zone'], $sg['zone']));
        $orders = $q->fetchAll();
    } else {
        $confirmer_user = nfc_find_or_create_user($pdo, $confirmer_etel_id, $confirmer_phone, $confirmer_name, true);
        if (!$confirmer_user) fail('Could not resolve your EtelID account.', 404);

        $q = $pdo->prepare(
            "SELECT id, order_code, from_zone, to_zone, package_type, status
             FROM orders
             WHERE courier_id = ? AND customer_id = ? AND status IN ('picked_up','in_transit')
             ORDER BY placed_at DESC LIMIT 20"
        );
        $q->execute(array($courier['id'], $confirmer_user['id']));
        $orders = $q->fetchAll();
    }

    $out = array();
    foreach ($orders as $o) {
        $out[] = array(
            'id'           => (int) $o['id'],
            'order_code'   => $o['order_code'],
            'from_zone'    => $o['from_zone'],
            'to_zone'      => $o['to_zone'],
            'package_type' => isset($o['package_type']) ? $o['package_type'] : '',
            'status'       => $o['status'],
        );
    }
    ok(array('orders' => $out));
    break;
}

case 'nfc_confirm_pickup': {
    // POST: courier_etel_id/phone, confirmer_etel_id/phone/name, order_id
    nfc_require_service_key();
    $pdo = db();

    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);

    $courier_etel_id = isset($body['courier_etel_id']) ? trim($body['courier_etel_id']) : '';
    $courier_phone   = isset($body['courier_phone'])   ? trim($body['courier_phone'])   : '';
    $courier_user = nfc_find_or_create_user($pdo, $courier_etel_id, $courier_phone, '', false);
    if (!$courier_user) fail('Courier account not found on Delivery.', 404);
    $cs = $pdo->prepare('SELECT id, zone FROM couriers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $cs->execute(array($courier_user['id']));
    $courier = $cs->fetch();
    if (!$courier) fail('Courier profile not found.', 404);

    $confirmer_etel_id = isset($body['confirmer_etel_id']) ? trim($body['confirmer_etel_id']) : '';
    $confirmer_phone   = isset($body['confirmer_phone'])   ? trim($body['confirmer_phone'])   : '';
    $confirmer_name    = isset($body['confirmer_name'])    ? trim($body['confirmer_name'])    : '';
    $confirmer_user = nfc_find_or_create_user($pdo, $confirmer_etel_id, $confirmer_phone, $confirmer_name, false);
    if (!$confirmer_user) fail('No Delivery account found for this EtelID. Sign in once in the Service Giver app first.', 404);
    if ($confirmer_user['role'] !== 'service_giver' && $confirmer_user['role'] !== 'admin') {
        fail('This EtelID is not registered as a Service Giver on Delivery.', 403);
    }

    $sgs = $pdo->prepare('SELECT id, zone FROM service_givers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $sgs->execute(array($confirmer_user['id']));
    $sg = $sgs->fetch();
    if (!$sg) fail('Service giver profile not found.', 404);

    // Same ownership priority as sg_confirm_pickup: direct link, then
    // assignment link, then zone as a last resort.
    $allowed = false;
    $chk0 = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND courier_id = ? AND service_giver_id = ?');
    $chk0->execute(array($order_id, $courier['id'], $sg['id']));
    $allowed = (bool) $chk0->fetch();
    if (!$allowed) {
        $chk1 = $pdo->prepare('SELECT o.id FROM orders o JOIN order_assignments oa ON oa.order_id = o.id WHERE o.id = ? AND o.courier_id = ? AND oa.service_giver_id = ?');
        $chk1->execute(array($order_id, $courier['id'], $sg['id']));
        $allowed = (bool) $chk1->fetch();
    }
    if (!$allowed && !empty($sg['zone'])) {
        $chk2 = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND courier_id = ? AND from_zone = ? AND status = 'assigned'");
        $chk2->execute(array($order_id, $courier['id'], $sg['zone']));
        $allowed = (bool) $chk2->fetch();
    }
    if (!$allowed) fail('This order is not assigned to your business.', 403);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE order_id=? AND type='pickup' AND status='pending'")
            ->execute(array($confirmer_user['id'], $order_id));
        $pdo->prepare("UPDATE orders SET status='picked_up' WHERE id=? AND status='assigned'")
            ->execute(array($order_id));
        $pdo->prepare("UPDATE order_assignments SET status='picked_up' WHERE order_id=?")
            ->execute(array($order_id));
        $pdo->commit();
        ok(array('message' => 'Pickup confirmed via NFC card.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to confirm pickup', 500);
    }
    break;
}

case 'nfc_confirm_delivery': {
    // POST: courier_etel_id/phone, confirmer_etel_id/phone/name, order_id
    nfc_require_service_key();
    $pdo = db();

    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    if (!$order_id) fail('order_id is required', 400);

    $courier_etel_id = isset($body['courier_etel_id']) ? trim($body['courier_etel_id']) : '';
    $courier_phone   = isset($body['courier_phone'])   ? trim($body['courier_phone'])   : '';
    $courier_user = nfc_find_or_create_user($pdo, $courier_etel_id, $courier_phone, '', false);
    if (!$courier_user) fail('Courier account not found on Delivery.', 404);
    $cs = $pdo->prepare('SELECT id FROM couriers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $cs->execute(array($courier_user['id']));
    $courier = $cs->fetch();
    if (!$courier) fail('Courier profile not found.', 404);

    $confirmer_etel_id = isset($body['confirmer_etel_id']) ? trim($body['confirmer_etel_id']) : '';
    $confirmer_phone   = isset($body['confirmer_phone'])   ? trim($body['confirmer_phone'])   : '';
    $confirmer_name    = isset($body['confirmer_name'])    ? trim($body['confirmer_name'])    : '';
    $confirmer_user = nfc_find_or_create_user($pdo, $confirmer_etel_id, $confirmer_phone, $confirmer_name, true);
    if (!$confirmer_user) fail('Could not resolve your EtelID account.', 404);

    // Same ownership rule as customer_confirm_delivery: order.customer_id
    // must be this exact confirming person.
    $s = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND courier_id = ? AND customer_id = ?");
    $s->execute(array($order_id, $courier['id'], $confirmer_user['id']));
    $order = $s->fetch();
    if (!$order) fail('This is not your order to confirm.', 403);
    if (!in_array($order['status'], array('picked_up', 'in_transit'), true)) {
        fail('This order has not been picked up yet.', 400);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE order_id=? AND type='delivery' AND status='pending'")
            ->execute(array($confirmer_user['id'], $order_id));
        $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")
            ->execute(array($order_id));
        $pdo->prepare("UPDATE order_assignments SET status='delivered' WHERE order_id=?")
            ->execute(array($order_id));
        $pdo->commit();
        if (function_exists('etel_maybe_pay_referral_reward')) {
            etel_maybe_pay_referral_reward($pdo, $confirmer_user['id']);
        }
        ok(array('message' => 'Delivery confirmed via NFC card.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed to confirm delivery', 500);
    }
    break;
}

}
