<?php
// ============================================================
// ETEL - Send a package: the public tracking link (js: track.html?c=CODE&t=TOKEN).
// Every new package gets a random token. The link needs the order code AND the token, so a code alone shows nothing.
// The public view has no phone numbers, street addresses, PINs, prices or exact positions: only the area names, the steps and the times.
// ============================================================

// Pure. A new random tracking token (12 hex digits).
function etel_package_track_token() {
    return bin2hex(random_bytes(6));
}

// Pure. The first word of a name, for "your courier Abebe".
function etel_package_first_name($name) {
    $n = trim((string) $name);
    if ($n === '') return '';
    $p = preg_split('/\s+/', $n);
    return substr($p[0], 0, 24);
}

// Pure. What the public page may see, from one orders row (joined with the courier name when there is one).
function etel_package_public_view($row) {
    $status = isset($row['status']) ? (string) $row['status'] : '';
    $steps = array('pending', 'assigned', 'picked_up', 'in_transit', 'delivered');
    $stage = $status;
    if ($status === 'accepted' || $status === 'preparing') $stage = 'assigned';
    return array(
        'order_code' => (string) $row['order_code'],
        'status' => $stage,
        'steps' => $steps,
        'from_zone' => isset($row['from_zone']) ? (string) $row['from_zone'] : '',
        'to_zone' => isset($row['to_zone']) ? (string) $row['to_zone'] : '',
        'package_type' => (isset($row['package_type']) && $row['package_type'] === 'document') ? 'document' : 'parcel',
        'speed' => (isset($row['pkg_speed']) && $row['pkg_speed'] === 'express') ? 'express' : 'standard',
        'vehicle_class' => isset($row['vehicle_class']) ? (string) $row['vehicle_class'] : '',
        'courier_first_name' => etel_package_first_name(isset($row['courier_name']) ? $row['courier_name'] : ''),
        'placed_at' => isset($row['placed_at']) ? (string) $row['placed_at'] : '',
        'delivered_at' => ($stage === 'delivered' && !empty($row['delivered_at'])) ? (string) $row['delivered_at'] : '',
    );
}

// The public view of the package with this code and token, or null (same answer for "no such code" and "wrong token").
function etel_package_public_lookup($pdo, $code, $token) {
    $code = strtoupper(trim((string) $code));
    $token = strtolower(trim((string) $token));
    if ($code === '' || strlen($code) > 40 || !preg_match('/^[0-9a-f]{12}$/', $token)) return null;
    $s = $pdo->prepare("SELECT o.order_code, o.status, o.from_zone, o.to_zone, o.package_type, o.pkg_speed, o.vehicle_class, o.placed_at, o.delivered_at, o.track_token, u.name AS courier_name
        FROM orders o LEFT JOIN couriers c ON o.courier_id = c.id LEFT JOIN users u ON c.user_id = u.id
        WHERE o.order_code = ? AND o.order_kind = 'package' LIMIT 1");
    $s->execute(array($code));
    $row = $s->fetch();
    if (!$row || empty($row['track_token']) || !hash_equals((string) $row['track_token'], $token)) return null;
    return etel_package_public_view($row);
}
