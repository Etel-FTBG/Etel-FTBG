<?php
// ============================================================
// ETEL - Send a package: the sender's own recent packages (for "Send again" and the My packages list).
// Only the signed-in customer's own packages. A recipient's phone is the customer's own data (they typed it), nothing else is exposed.
// ============================================================

// Pure. active / delivered / cancelled, for the filter chips.
function etel_package_status_group($status) {
    $s = (string) $status;
    if ($s === 'delivered') return 'delivered';
    if ($s === 'cancelled' || $s === 'rejected' || $s === 'failed') return 'cancelled';
    return 'active';
}

// Pure. One orders row -> what the app needs to list it and to start the same package again.
function etel_package_history_row($r) {
    $num = function ($v) { return ($v !== null && $v !== '' && is_numeric($v)) ? floatval($v) : null; };
    return array(
        'order_id' => intval($r['id']),
        'order_code' => (string) $r['order_code'],
        'status' => (string) $r['status'],
        'group' => etel_package_status_group($r['status']),
        'placed_at' => isset($r['placed_at']) ? (string) $r['placed_at'] : '',
        'delivered_at' => !empty($r['delivered_at']) ? (string) $r['delivered_at'] : '',
        'fare_total' => $num(isset($r['fare_total']) ? $r['fare_total'] : null),
        'speed' => (isset($r['pkg_speed']) && $r['pkg_speed'] === 'express') ? 'express' : 'standard',
        'pickup' => array('address' => (string) (isset($r['pickup_address']) ? $r['pickup_address'] : ''), 'lat' => $num(isset($r['pickup_lat']) ? $r['pickup_lat'] : null),
            'lng' => $num(isset($r['pickup_lng']) ? $r['pickup_lng'] : null), 'zone' => (string) (isset($r['from_zone']) ? $r['from_zone'] : '')),
        'drop' => array('address' => (string) (isset($r['recipient_address']) ? $r['recipient_address'] : ''), 'lat' => $num(isset($r['delivery_lat']) ? $r['delivery_lat'] : null),
            'lng' => $num(isset($r['delivery_lng']) ? $r['delivery_lng'] : null), 'zone' => (string) (isset($r['to_zone']) ? $r['to_zone'] : '')),
        'sender_name' => (string) (isset($r['sender_name']) ? $r['sender_name'] : ''),
        'sender_phone' => (string) (isset($r['sender_phone']) ? $r['sender_phone'] : ''),
        'recipient_name' => (string) (isset($r['recipient_name']) ? $r['recipient_name'] : ''),
        'recipient_phone' => (string) (isset($r['recipient_phone']) ? $r['recipient_phone'] : ''),
        'size' => (string) (isset($r['pkg_size']) ? $r['pkg_size'] : ''),
        'weight_kg' => $num(isset($r['pkg_weight_kg']) ? $r['pkg_weight_kg'] : null),
        'desc' => (string) (isset($r['pkg_desc']) ? $r['pkg_desc'] : ''),
        'vehicle_class' => (string) (isset($r['vehicle_class']) ? $r['vehicle_class'] : ''),
    );
}

// The customer's last packages, newest first.
function etel_package_history($pdo, $customer_id, $limit = 40) {
    $limit = max(1, min(100, intval($limit)));
    $s = $pdo->prepare("SELECT id, order_code, status, placed_at, delivered_at, fare_total, pkg_speed, pickup_address, pickup_lat, pickup_lng, from_zone,
            recipient_address, delivery_lat, delivery_lng, to_zone, sender_name, sender_phone, recipient_name, recipient_phone, pkg_size, pkg_weight_kg, pkg_desc, vehicle_class
        FROM orders WHERE customer_id = ? AND order_kind = 'package' ORDER BY id DESC LIMIT " . $limit);
    $s->execute(array(intval($customer_id)));
    $out = array();
    foreach ($s->fetchAll() as $r) $out[] = etel_package_history_row($r);
    return $out;
}
