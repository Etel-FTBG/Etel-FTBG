<?php
// Customer push notifications for order status changes.
//
// etel_notify_order_status($pdo, $order_id [, $event]) is safe to call from ANY place that
// changes an order's status (and may be called more than once for the same change):
//  - it never throws and never blocks the caller's response,
//  - each (order, event) pair is sent at most once (order_push_log), so duplicate calls
//    from overlapping code paths are harmless,
//  - it respects the customer's "Order updates" switch (users.settings_json.notif_order_updates)
//    and their language (en / am),
//  - it uses the existing Web Push stack (api/push.php -> api/webpush.php), so it simply does
//    nothing for customers who haven't allowed notifications.
// With no $event it derives one from the order's current status:
//   assigned -> courier_assigned, picked_up / in_transit -> on_the_way, delivered, cancelled.
// The shop-side 'preparing' step is passed explicitly (orders.status stays 'pending' then).

function etel_order_push_text($event, $code, $lang) {
    $am = ($lang === 'am');
    $t = array(
        'preparing'        => array('Your order is being prepared',  'ሱቁ ትዕዛዝዎን እያዘጋጀ ነው',        'The shop has accepted ' . $code . ' and is preparing it.', 'ሱቁ ' . $code . ' ተቀብሎ እያዘጋጀው ነው።'),
        'courier_assigned' => array('A courier is on the way to the shop', 'ሹፌር ወደ ሱቁ እየሄደ ነው',     $code . ' has a courier now.',                              'ለ' . $code . ' ሹፌር ተመድቧል።'),
        'on_the_way'       => array('Your order is on the way',      'ትዕዛዝዎ በመንገድ ላይ ነው',         $code . ' was picked up and is heading to you.',            $code . ' ተነስቷል፤ ወደ እርስዎ እየመጣ ነው።'),
        'delivered'        => array('Order delivered',               'ትዕዛዝዎ ደርሷል',                    $code . ' has arrived. Tap to rate your order.',            $code . ' ደርሷል። ትዕዛዙን ለመገምገም ይንኩ።'),
        'cancelled'        => array('Order cancelled',               'ትዕዛዝዎ ተሰርዟል',                   $code . ' was cancelled.',                                  $code . ' ተሰርዟል።'),
    );
    if (!isset($t[$event])) return null;
    return $am ? array($t[$event][1], $t[$event][3]) : array($t[$event][0], $t[$event][2]);
}

function etel_notify_order_status($pdo, $order_id, $event = null) {
    try {
        $order_id = intval($order_id);
        if ($order_id <= 0) return false;
        $q = $pdo->prepare('SELECT o.customer_id, o.order_code, o.status, u.settings_json FROM orders o LEFT JOIN users u ON u.id = o.customer_id WHERE o.id = ?');
        $q->execute(array($order_id));
        $o = $q->fetch(PDO::FETCH_ASSOC);
        if (!$o || empty($o['customer_id'])) return false;

        if ($event === null) {
            $map = array('assigned' => 'courier_assigned', 'picked_up' => 'on_the_way', 'in_transit' => 'on_the_way', 'delivered' => 'delivered', 'cancelled' => 'cancelled');
            if (!isset($map[$o['status']])) return false;
            $event = $map[$o['status']];
        }

        $settings = $o['settings_json'] ? json_decode($o['settings_json'], true) : array();
        if (!is_array($settings)) $settings = array();
        if (isset($settings['notif_order_updates']) && !$settings['notif_order_updates']) return false;
        $lang = (isset($settings['language']) && $settings['language'] === 'am') ? 'am' : 'en';

        $text = etel_order_push_text($event, $o['order_code'], $lang);
        if (!$text) return false;

        $pdo->exec("CREATE TABLE IF NOT EXISTS order_push_log (
            order_id INT NOT NULL,
            event VARCHAR(30) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (order_id, event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $pdo->prepare('INSERT IGNORE INTO order_push_log (order_id, event) VALUES (?, ?)');
        $ins->execute(array($order_id, $event));
        if ($ins->rowCount() < 1) return false; // already sent for this order + event

        require_once __DIR__ . '/../push.php';
        return etel_push_send($pdo, intval($o['customer_id']), $text[0], $text[1], array(
            'type' => 'order_' . $event, 'order_id' => $order_id, 'tag' => 'order-' . $order_id,
            'url'  => '/delivery/customer.html?order=' . $order_id,
        ));
    } catch (Exception $e) {
        error_log('[ETEL] order push failed (non-fatal): ' . $e->getMessage());
        return false;
    }
}
