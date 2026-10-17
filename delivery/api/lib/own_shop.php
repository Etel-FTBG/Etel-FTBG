<?php
// A person who runs a shop must not order from it: the owner (service_givers.user_id) or an active staff login (sg_staff).
// Every EtelID can also order, so the order screen has to refuse this one case; otherwise points, promos and "delivered" counts can be farmed.
// Returns true when $user_id owns or works at the shop $service_giver_id.
function etel_user_runs_shop($pdo, $user_id, $service_giver_id) {
    $user_id = intval($user_id); $service_giver_id = intval($service_giver_id);
    if ($user_id <= 0 || $service_giver_id <= 0) return false;
    $s = $pdo->prepare('SELECT 1 FROM service_givers WHERE id = ? AND user_id = ? LIMIT 1');
    $s->execute(array($service_giver_id, $user_id));
    if ($s->fetchColumn()) return true;
    try {
        $t = $pdo->prepare('SELECT 1 FROM sg_staff WHERE service_giver_id = ? AND user_id = ? AND active = 1 LIMIT 1');
        $t->execute(array($service_giver_id, $user_id));
        return (bool) $t->fetchColumn();
    } catch (Exception $e) { return false; }   // no staff table on an old database: only the owner check applies
}
