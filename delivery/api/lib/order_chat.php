<?php
// Order chat: ONE group thread per order between everyone working on it - the customer, the shop
// (owner + staff) and the courier - plus platform admins for support.
//   - Open while the order is active, and for 2 hours after delivery (then read-only).
//     Closed for cancelled orders.
//   - Plain text only, max 500 characters, max 20 messages per user per minute.
//   - Every other participant gets a push notification; unread counts come from a per-user
//     "last read message" marker, so there is no per-message read bookkeeping.
// orders.courier_id is a couriers.id (see delivery_order_core.php), the courier's login is couriers.user_id.

define('ETEL_CHAT_MAX_LEN', 500);
define('ETEL_CHAT_RATE_PER_MIN', 20);
define('ETEL_CHAT_AFTER_DELIVERY_MIN', 120);

function etel_ensure_chat_tables($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_role VARCHAR(10) NOT NULL,
            body VARCHAR(600) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id, id),
            INDEX idx_sender_time (sender_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_message_reads (
            order_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_id INT NOT NULL DEFAULT 0,
            PRIMARY KEY (order_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { error_log('[ETEL] etel_ensure_chat_tables: ' . $e->getMessage()); }
}

// Pure: tidy a message body. Returns '' when there is nothing to send.
function etel_chat_clean_body($s) {
    $s = (string)$s;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);   // control chars (keep \n, \t)
    $s = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $s));
    $s = trim($s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, ETEL_CHAT_MAX_LEN);
    return substr($s, 0, ETEL_CHAT_MAX_LEN);
}

// Pure: may people still write in this order's chat?
function etel_chat_is_open($status, $delivered_at, $now = null) {
    $now = $now === null ? time() : $now;
    if ($status === 'cancelled') return false;
    if ($status === 'delivered') {
        if (!$delivered_at) return false;
        return ($now - strtotime($delivered_at)) <= ETEL_CHAT_AFTER_DELIVERY_MIN * 60;
    }
    return true;
}

// Everyone attached to the order: customer, courier (login id), shop user ids (owner + active staff), and facts.
function etel_chat_order($pdo, $order_id) {
    $q = $pdo->prepare("SELECT o.id, o.order_code, o.status, o.delivered_at, o.customer_id, o.courier_id,
                               (SELECT oa.service_giver_id FROM order_assignments oa WHERE oa.order_id = o.id LIMIT 1) AS sg_id
                        FROM orders o WHERE o.id = ?");
    $q->execute(array(intval($order_id)));
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $o['courier_user_id'] = 0;
    if (!empty($o['courier_id'])) {
        $c = $pdo->prepare('SELECT user_id FROM couriers WHERE id = ?');
        $c->execute(array($o['courier_id']));
        $o['courier_user_id'] = intval($c->fetchColumn());
    }
    $o['shop_user_ids'] = array();
    if (!empty($o['sg_id'])) {
        $s = $pdo->prepare('SELECT user_id FROM service_givers WHERE id = ?');
        $s->execute(array($o['sg_id']));
        $owner = intval($s->fetchColumn());
        if ($owner) $o['shop_user_ids'][] = $owner;
        try {
            $st = $pdo->prepare('SELECT user_id FROM sg_staff WHERE service_giver_id = ? AND active = 1');
            $st->execute(array($o['sg_id']));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) { if (!in_array(intval($uid), $o['shop_user_ids'], true)) $o['shop_user_ids'][] = intval($uid); }
        } catch (Exception $e) { /* staff table not migrated - owner only */ }
    }
    return $o;
}

// The caller's role in this order's chat: customer | courier | shop | support (admin) | null.
function etel_chat_role($order, $user) {
    $uid = intval($user['id']);
    if ($uid === intval($order['customer_id'])) return 'customer';
    if ($order['courier_user_id'] && $uid === intval($order['courier_user_id'])) return 'courier';
    if (in_array($uid, $order['shop_user_ids'], true)) return 'shop';
    if (isset($user['role']) && $user['role'] === 'admin') return 'support';
    return null;
}

// User ids that should be notified about a message from $sender_id.
function etel_chat_recipients($order, $sender_id) {
    $ids = array_merge(array(intval($order['customer_id']), intval($order['courier_user_id'])), $order['shop_user_ids']);
    $out = array();
    foreach ($ids as $i) { $i = intval($i); if ($i > 0 && $i !== intval($sender_id) && !in_array($i, $out, true)) $out[] = $i; }
    return $out;
}

function etel_chat_mark_read($pdo, $order_id, $user_id, $last_id) {
    $pdo->prepare('INSERT INTO order_message_reads (order_id, user_id, last_read_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))')
        ->execute(array(intval($order_id), intval($user_id), intval($last_id)));
}
