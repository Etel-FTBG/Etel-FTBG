<?php
/**
 * Who is coming: the courier's details for the customer who ordered, so the customer can recognise the person and the vehicle at the door.
 * Sent in track_order as order.courier_card only while a courier has the order and it is not finished (never after delivery or cancellation).
 * Shown: name, phone, photo (the courier's selfie, as a signed expiring link), vehicle type, plate, make / colour text, the real vehicle pictures
 * (front and side), rating, number of deliveries, year of joining. NEVER: ID document, licence, home address, emergency contact.
 */
function etel_courier_card($pdo, $orderId) {
    try {
        $q = $pdo->prepare("SELECT o.status, c.id AS cid, c.user_id, c.vehicle_type, c.plate_number, c.rating, c.total_deliveries, c.created_at, u.name, u.phone
                            FROM orders o JOIN couriers c ON c.id = o.courier_id JOIN users u ON u.id = c.user_id WHERE o.id = ? LIMIT 1");
        $q->execute(array(intval($orderId)));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || in_array($r['status'], array('delivered', 'cancelled'), true)) return null;
        $card = array(
            'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'vehicle_type' => (string) $r['vehicle_type'],
            'plate' => trim((string) $r['plate_number']), 'rating' => round(floatval($r['rating']), 1), 'deliveries' => intval($r['total_deliveries']),
            'since' => $r['created_at'] ? intval(substr((string) $r['created_at'], 0, 4)) : null,
            'photo' => '', 'vehicle_info' => '', 'vehicle_photo_front' => '', 'vehicle_photo_side' => '',
        );
        try {
            require_once __DIR__ . '/courier_details.php';
            $d = etel_courier_details_get($pdo, $r['user_id']);
            if ($d) {
                $card['photo'] = (string) $d['selfie_url'];
                $card['vehicle_info'] = trim((string) $d['vehicle_info']);
                $card['vehicle_photo_front'] = isset($d['vehicle_photo_front']) ? (string) $d['vehicle_photo_front'] : '';
                $card['vehicle_photo_side'] = isset($d['vehicle_photo_side']) ? (string) $d['vehicle_photo_side'] : '';
            }
        } catch (Exception $e) { /* no details table yet: the card is simply shorter */ }
        return $card;
    } catch (Exception $e) { return null; }
}
