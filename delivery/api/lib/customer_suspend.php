<?php
// Admin can suspend a customer from ordering (users.active = 0). The person can still sign in, see their
// history and use Ride; only new shop orders and package bookings are refused.
// etel_customer_suspended($pdo, $user_id) -> true when the account is suspended.
function etel_customer_suspended($pdo, $user_id) {
    try {
        $s = $pdo->prepare('SELECT active FROM users WHERE id = ? LIMIT 1');
        $s->execute(array(intval($user_id)));
        $v = $s->fetchColumn();
        return $v !== false && $v !== null && intval($v) === 0;
    } catch (Exception $e) { return false; }
}
function etel_customer_suspended_message() {
    return 'Your account is suspended from ordering. Please contact ETEL support.';
}
