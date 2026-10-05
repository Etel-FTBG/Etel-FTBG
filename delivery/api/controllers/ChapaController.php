<?php
// ============================================================
// ETEL Delivery & Ride - Chapa actions (customer-authenticated)
//   chapa_init   { kind: 'ride'|'order', id }  -> { checkout_url, tx_ref }
//   chapa_status { tx_ref }                    -> { result }
// Amounts always come from the ride/order row, never the client.
// ============================================================
require_once __DIR__ . '/../chapa_bridge.php';

switch ($action) {

case 'chapa_init': {
    $pdo = db();
    $u = require_auth($pdo);
    if (!chapa_enabled()) fail('Chapa is not set up on this server yet. Please use another payment method.', 503);
    $kind = isset($body['kind']) ? strtolower(trim($body['kind'])) : '';
    $id   = isset($body['id']) ? intval($body['id']) : 0;
    if (!in_array($kind, array('ride', 'order'), true) || !$id) fail('kind (ride|order) and id are required');

    if ($kind === 'ride') {
        $s = $pdo->prepare("SELECT id, fare_final FROM rides WHERE id=? AND passenger_id=? AND status='arrived'");
        $s->execute(array($id, $u['id']));
        $row = $s->fetch();
        if (!$row) fail('No arrived ride waiting for your payment', 404);
        $amount = floatval($row['fare_final']);
    } else {
        $s = $pdo->prepare("SELECT id, fare_total, payment_status FROM orders WHERE id=? AND customer_id=?");
        $s->execute(array($id, $u['id']));
        $row = $s->fetch();
        if (!$row) fail('Order not found', 404);
        if ($row['payment_status'] === 'paid') fail('This order is already paid', 409);
        $amount = floatval($row['fare_total']);
    }
    if ($amount <= 0) fail('Nothing to pay for this ' . $kind);

    rate_limit_check($pdo, 'chapa_init:' . $u['id'], 15, 600, 600);
    rate_limit_fail($pdo, 'chapa_init:' . $u['id']);
    chapa_ensure_table($pdo);
    $txRef = 'ED' . strtoupper(substr($kind, 0, 1)) . '-' . $id . '-' . strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare("INSERT INTO chapa_payments (tx_ref, kind, target_id, user_id, amount) VALUES (?,?,?,?,?)")
        ->execute(array($txRef, $kind, $id, $u['id'], $amount));

    $base = chapa_base_url();
    $url = chapa_initialize(
        $txRef, $amount, $u,
        $base . '/api/chapa-return.php?tx_ref=' . rawurlencode($txRef) . '&kind=' . $kind,
        $base . '/api/chapa-callback.php'
    );
    if (!$url) {
        $pdo->prepare("UPDATE chapa_payments SET status='failed' WHERE tx_ref=?")->execute(array($txRef));
        fail('Could not reach Chapa right now. Please try again or use another method.', 502);
    }
    ok(array('checkout_url' => $url, 'tx_ref' => $txRef, 'amount' => $amount));
    break;
}

case 'chapa_status': {
    $pdo = db();
    $u = require_auth($pdo);
    $txRef = isset($body['tx_ref']) ? trim($body['tx_ref']) : '';
    if ($txRef === '') fail('tx_ref is required');
    chapa_ensure_table($pdo);
    $own = $pdo->prepare("SELECT id FROM chapa_payments WHERE tx_ref=? AND user_id=?");
    $own->execute(array($txRef, $u['id']));
    if (!$own->fetch()) fail('Payment not found', 404);
    ok(array('result' => chapa_settle($pdo, $txRef)));
    break;
}

}
