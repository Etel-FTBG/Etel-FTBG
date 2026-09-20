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

switch ($action) {

case 'nfc_list_courier_orders': {
    // POST: courier_etel_id, courier_phone, courier_name, confirmer_etel_id,
    //       confirmer_phone, stage ('pickup'|'delivery')
    // Returns the courier's active orders that the confirming person is
    // actually authorized to act on - a service giver only sees orders
    // assigned to their own business, a customer only sees their own order.
    nfc_require_service_key();
    $pdo = db();

    $stage = isset($body['stage']) ? trim($body['stage']) : '';
    if (!in_array($stage, array('pickup', 'delivery', 'accepted'), true)) fail('stage must be pickup, accepted, or delivery', 400);

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

    if ($stage === 'pickup' || $stage === 'accepted') {
        // 'accepted' is a read-only variant of the exact same lookup: the
        // service giver verifying which courier is assigned to their
        // order(s) before handoff, not confirming anything - the caller
        // (EtelID) never calls nfc_confirm_pickup for this stage, only
        // displays the list. Same ownership query either way.
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
