<?php
/**
 * Courier earnings: what the courier actually earns is the delivery fee plus the customer's tip on delivered
 * orders (never the order total, which includes the food). Totals are computed in SQL so they are not capped
 * by a list limit. Tips are 100% the courier's (orders.courier_tip).
 */

// Largest tip a customer can add to one order (ETB). Keeps a mistyped amount from becoming a huge charge.
if (!defined('ETEL_MAX_TIP')) define('ETEL_MAX_TIP', 500);

/** Clean a tip amount sent by the app: a number >= 0, at most ETEL_MAX_TIP, 2 decimals. Anything else is 0. */
function etel_clean_tip($raw) {
    if (!is_numeric($raw)) return 0.0;
    $v = floatval($raw);
    if (!is_finite($v) || $v <= 0) return 0.0;
    return round(min($v, ETEL_MAX_TIP), 2);
}

/** Make sure orders.courier_tip exists (migration 073 adds it; this covers a server where it has not run yet). */
function etel_ensure_tip_column($pdo) {
    static $done = false;
    if ($done) return;
    // A marker file keeps this off the courier app's hot polling path once the column is known to exist.
    $marker = __DIR__ . '/../.tip_column_ok';
    if (@file_exists($marker)) { $done = true; return; }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'courier_tip'")->fetch();
        if (!$has) $pdo->exec("ALTER TABLE `orders` ADD COLUMN `courier_tip` DECIMAL(10,2) NOT NULL DEFAULT 0");
        $done = true;
        @file_put_contents($marker, '1');
    } catch (Exception $e) { error_log('[ETEL] courier_tip column: ' . $e->getMessage()); }
}

/**
 * For the courier app's order lists: "delivery_fee" becomes what the courier is paid for the order (fee + tip),
 * because that is what the app prints as "You earn". base_delivery_fee and courier_tip keep the two parts.
 */
function etel_apply_courier_tip($pdo, &$orders) {
    if (!$orders) return;
    etel_ensure_tip_column($pdo);
    $ids = array();
    foreach ($orders as $o) { if (isset($o['id'])) $ids[] = intval($o['id']); }
    $tips = array();
    if ($ids) {
        try {
            $q = $pdo->query('SELECT id, courier_tip FROM orders WHERE id IN (' . implode(',', $ids) . ')');
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $tips[intval($r['id'])] = floatval($r['courier_tip']);
        } catch (Exception $e) { return; }
    }
    foreach ($orders as $i => $o) {
        $tip = isset($tips[intval($o['id'])]) ? $tips[intval($o['id'])] : 0.0;
        $base = isset($o['delivery_fee']) ? floatval($o['delivery_fee']) : 0.0;
        $orders[$i]['base_delivery_fee'] = $base;
        $orders[$i]['courier_tip'] = $tip;
        $orders[$i]['delivery_fee'] = round($base + $tip, 2);
    }
}

function etel_courier_earnings($pdo, $courierId) {
    etel_ensure_tip_column($pdo);
    $day = 'DATE(COALESCE(delivered_at, placed_at))';
    $pay = '(delivery_fee + courier_tip)';
    $q = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN $day = CURDATE() THEN $pay END),0) AS today_earn,
            COALESCE(SUM($day = CURDATE()),0) AS today_n,
            COALESCE(SUM(CASE WHEN $day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN $pay END),0) AS week_earn,
            COALESCE(SUM($day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)),0) AS week_n,
            COALESCE(SUM($pay),0) AS all_earn,
            COUNT(*) AS all_n
        FROM orders WHERE courier_id = ? AND status = 'delivered'");
    $q->execute(array($courierId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    // Streak: consecutive days (ending today, or yesterday if nothing yet today) with at least one delivery.
    $d = $pdo->prepare("SELECT DISTINCT $day AS d FROM orders
        WHERE courier_id = ? AND status = 'delivered' AND COALESCE(delivered_at, placed_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)");
    $d->execute(array($courierId));
    $days = array();
    foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $x) $days[$x] = true;
    $streak = 0;
    $cur = strtotime(date('Y-m-d'));
    if (empty($days[date('Y-m-d', $cur)])) $cur = strtotime('-1 day', $cur);
    while (!empty($days[date('Y-m-d', $cur)])) { $streak++; $cur = strtotime('-1 day', $cur); }
    return array(
        'today' => array('earned' => round(floatval($r['today_earn']), 2), 'deliveries' => intval($r['today_n'])),
        'week'  => array('earned' => round(floatval($r['week_earn']), 2),  'deliveries' => intval($r['week_n'])),
        'all'   => array('earned' => round(floatval($r['all_earn']), 2),   'deliveries' => intval($r['all_n'])),
        'streak_days' => $streak,
    );
}
