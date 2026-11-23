<?php
// A ride driver's extra job: deliveries. The driver applies in the Driver app, an admin approves in Admin -> Couriers, and the SAME account then
// does both (it becomes a courier-role account that keeps its ride driver profile, as many bajaj drivers already have).
// Until an admin approves, the driver gets no delivery job at all: the courier row is 'pending' and dispatch only uses approved couriers.
// ASCII only. Never throws.
require_once __DIR__ . '/courier_approval.php';

// The ride driver's profile row, or null. vehicle_type and vehicle_plate are what they registered with.
function etel_dc_ride_driver($pdo, $uid) {
    try {
        $q = $pdo->prepare('SELECT user_id, vehicle_type, vehicle_plate, onboarded FROM ride_drivers WHERE user_id = ? LIMIT 1');
        $q->execute(array(intval($uid)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    } catch (Exception $e) { return null; }
}

// A ride vehicle name as a courier vehicle name (what the package vehicles and the cart match on). Motorbike words become 'motorcycle'.
function etel_dc_vehicle_name($v) {
    $v = strtolower(trim((string) $v));
    if ($v === '') return '';
    if (in_array($v, array('moto', 'motorbike', 'motorcycle', 'scooter'), true)) return 'motorcycle';
    return preg_match('/^[a-z][a-z0-9_]{1,29}$/', $v) ? $v : '';
}

// The state of the extra job for one user: none | pending | approved | rejected | suspended. can_apply is true only for an onboarded ride driver
// (role driver) who has no courier row, or whose last request was not approved.
function etel_dc_status($pdo, $u) {
    $uid = intval($u['id']); $role = isset($u['role']) ? $u['role'] : '';
    $rd = etel_dc_ride_driver($pdo, $uid);
    $cr = null;
    try {
        etel_courier_approval_ready($pdo);
        $q = $pdo->prepare('SELECT id, courier_code, active, approval_status, approval_note FROM couriers WHERE user_id = ? LIMIT 1');
        $q->execute(array($uid));
        $cr = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $cr = null; }
    $state = 'none';
    if ($cr) {
        $as = isset($cr['approval_status']) ? $cr['approval_status'] : 'approved';
        if ($as === 'pending') $state = 'pending';
        elseif ($as === 'rejected') $state = 'rejected';
        else $state = (isset($cr['active']) && intval($cr['active']) === 0) ? 'suspended' : 'approved';
    }
    $isDriver = $rd !== null;
    $can = $isDriver && $role === 'driver' && intval($rd['onboarded']) === 1 && ($state === 'none' || $state === 'rejected');
    return array('state' => $state, 'role' => $role, 'is_ride_driver' => $isDriver, 'onboarded' => $rd ? intval($rd['onboarded']) === 1 : false,
                 'can_apply' => $can, 'note' => ($cr && !empty($cr['approval_note'])) ? $cr['approval_note'] : '',
                 'courier_code' => $cr ? $cr['courier_code'] : '', 'vehicle' => $rd ? etel_dc_vehicle_name($rd['vehicle_type']) : '');
}

// The driver asks for the courier job. Returns array(ok, message, status).
function etel_dc_apply($pdo, $u) {
    $uid = intval($u['id']);
    if (!isset($u['role']) || $u['role'] !== 'driver') return array('ok' => false, 'message' => $u['role'] === 'courier' ? 'You already have the courier job.' : 'Only ride drivers can ask for this.');
    $st = etel_dc_status($pdo, $u);
    if (!$st['is_ride_driver']) return array('ok' => false, 'message' => 'Finish registering as a ride driver first.');
    if (!$st['onboarded']) return array('ok' => false, 'message' => 'Finish your driver verification first (vehicle, selfie, licence). Then you can ask for the courier job.');
    if ($st['state'] === 'pending') return array('ok' => false, 'message' => 'Your request is already waiting for the admin.');
    if (!etel_courier_approval_ready($pdo)) return array('ok' => false, 'message' => 'The courier job is not available on this system yet. Contact support.');
    require_once __DIR__ . '/courier_schema.php'; etel_ensure_courier_vehicle_column($pdo);
    $rd = etel_dc_ride_driver($pdo, $uid);
    $veh = etel_dc_vehicle_name($rd ? $rd['vehicle_type'] : '');
    $plate = $rd && !empty($rd['vehicle_plate']) ? substr(trim($rd['vehicle_plate']), 0, 30) : '';
    try {
        if ($st['state'] === 'rejected') {
            $pdo->prepare("UPDATE couriers SET approval_status = 'pending', approval_note = NULL, approval_at = NULL, status = 'offline' WHERE user_id = ?")->execute(array($uid));
        } elseif ($st['state'] === 'none') {
            $code = 'C-' . str_pad($uid, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT IGNORE INTO couriers (user_id, courier_code, status, rating, total_deliveries, active, created_at) VALUES (?, ?, 'offline', 0.00, 0, 1, NOW())")->execute(array($uid, $code));
            $pdo->prepare("UPDATE couriers SET approval_status = 'pending', approval_note = NULL, approval_at = NULL, status = 'offline' WHERE user_id = ?")->execute(array($uid));
        } else {
            return array('ok' => false, 'message' => 'You already have the courier job.');
        }
        if ($veh !== '') $pdo->prepare('UPDATE couriers SET vehicle_type = ? WHERE user_id = ?')->execute(array($veh, $uid));
        if ($plate !== '') $pdo->prepare('UPDATE couriers SET plate_number = ? WHERE user_id = ? AND (plate_number IS NULL OR plate_number = \'\')')->execute(array($plate, $uid));
    } catch (Exception $e) { error_log('[ETEL] driver courier apply: ' . $e->getMessage()); return array('ok' => false, 'message' => 'Could not send your request. Try again.'); }
    error_log('[ETEL][driver-courier] driver ' . $uid . ' asked for the courier job');
    return array('ok' => true, 'message' => 'Sent. An admin will check your request. You will see the answer here.', 'status' => etel_dc_status($pdo, $u));
}

// Admin approved a driver's request: the account becomes a courier account (its ride driver profile stays). Returns true when the role changed.
function etel_dc_promote($pdo, $uid) {
    try {
        $q = $pdo->prepare("UPDATE users SET role = 'courier' WHERE id = ? AND role = 'driver'");
        $q->execute(array(intval($uid)));
        return $q->rowCount() > 0;
    } catch (Exception $e) { error_log('[ETEL] driver courier promote: ' . $e->getMessage()); return false; }
}
