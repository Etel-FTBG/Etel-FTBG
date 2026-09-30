<?php
// Promo / discount codes.
//
// Money rules (all enforced SERVER-side at order creation; the checkout preview
// uses the same functions):
//  - percent / fixed discounts apply to the ITEMS subtotal only; free_delivery
//    discounts the delivery fee. The service charge is never discounted, so a
//    paid order always totals >= the service charge (> 0).
//  - a discount can never exceed what it applies to (items / fee).
//  - the discount is funded by the platform: orders.subtotal / delivery_fee /
//    service_charge stay as calculated, only fare_total is reduced.

// Thrown for customer-facing promo problems so order creation can show the real reason.
class EtelPromoException extends Exception {}

function etel_promo_normalize($code) {
    return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)$code)));
}

function etel_ensure_promo_tables($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS promo_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            discount_type ENUM('percent','fixed','free_delivery') NOT NULL DEFAULT 'percent',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_discount DECIMAL(10,2) NULL,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            max_uses INT NULL,
            used_count INT NOT NULL DEFAULT 0,
            per_user_limit INT NOT NULL DEFAULT 1,
            first_order_only TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS promo_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            promo_id INT NOT NULL,
            user_id INT NOT NULL,
            order_id INT NULL,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_promo_user (promo_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = etel_table_columns($pdo, 'orders');
        if ($cols && !in_array('promo_code', $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN promo_code VARCHAR(40) NULL DEFAULT NULL");
        if ($cols && !in_array('discount', $cols, true))   $pdo->exec("ALTER TABLE orders ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_promo_tables: ' . $e->getMessage());
    }
}

// Pure: how much does $promo take off for this basket? (no DB, no eligibility)
function etel_promo_compute($promo, $items_subtotal, $delivery_fee) {
    $items_subtotal = max(0, floatval($items_subtotal));
    $delivery_fee   = max(0, floatval($delivery_fee));
    $value = floatval($promo['value']);
    switch ($promo['discount_type']) {
        case 'percent':
            $d = $items_subtotal * min(100, max(0, $value)) / 100;
            if ($promo['max_discount'] !== null && $promo['max_discount'] !== '' && floatval($promo['max_discount']) > 0) {
                $d = min($d, floatval($promo['max_discount']));
            }
            $d = min($d, $items_subtotal);
            break;
        case 'fixed':
            $d = min(max(0, $value), $items_subtotal);
            break;
        case 'free_delivery':
            $d = $delivery_fee;
            break;
        default:
            $d = 0;
    }
    return round(max(0, $d), 2);
}

// Full check for one customer + basket. Returns
//   array('ok'=>true,'promo'=>row,'discount'=>float)  or  array('ok'=>false,'error'=>string)
function etel_promo_check($pdo, $code, $user_id, $items_subtotal, $delivery_fee) {
    etel_ensure_promo_tables($pdo);
    $code = etel_promo_normalize($code);
    if ($code === '') return array('ok' => false, 'error' => 'Enter a promo code');
    $s = $pdo->prepare('SELECT * FROM promo_codes WHERE code = ? LIMIT 1');
    $s->execute(array($code));
    $p = $s->fetch();
    if (!$p || !intval($p['is_active'])) return array('ok' => false, 'error' => 'This promo code is not valid');
    $now = time();
    if (!empty($p['starts_at']) && strtotime($p['starts_at']) > $now)   return array('ok' => false, 'error' => 'This promo code is not active yet');
    if (!empty($p['expires_at']) && strtotime($p['expires_at']) < $now) return array('ok' => false, 'error' => 'This promo code has expired');
    if ($p['max_uses'] !== null && $p['max_uses'] !== '' && intval($p['used_count']) >= intval($p['max_uses'])) {
        return array('ok' => false, 'error' => 'This promo code has been fully redeemed');
    }
    if (floatval($p['min_subtotal']) > 0 && floatval($items_subtotal) < floatval($p['min_subtotal'])) {
        return array('ok' => false, 'error' => 'Spend at least ETB ' . rtrim(rtrim(number_format(floatval($p['min_subtotal']), 2, '.', ''), '0'), '.') . ' in items to use this code');
    }
    $limit = max(1, intval($p['per_user_limit']));
    $u = $pdo->prepare('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ? AND user_id = ?');
    $u->execute(array($p['id'], $user_id));
    if (intval($u->fetchColumn()) >= $limit) return array('ok' => false, 'error' => 'You have already used this promo code');
    if (intval($p['first_order_only'])) {
        $o = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status <> 'cancelled'");
        $o->execute(array($user_id));
        if (intval($o->fetchColumn()) > 0) return array('ok' => false, 'error' => 'This code is for first orders only');
    }
    $d = etel_promo_compute($p, $items_subtotal, $delivery_fee);
    if ($d <= 0) return array('ok' => false, 'error' => 'This code gives no discount on this order');
    return array('ok' => true, 'promo' => $p, 'discount' => $d);
}

// Atomically take one use (respects max_uses even under concurrent orders).
function etel_promo_claim($pdo, $promo_id) {
    $u = $pdo->prepare('UPDATE promo_codes SET used_count = used_count + 1 WHERE id = ? AND (max_uses IS NULL OR used_count < max_uses)');
    $u->execute(array($promo_id));
    return $u->rowCount() > 0;
}
