<?php
/**
 * Courier earnings: what the courier actually earns is the courier pay (the delivery fee, or the distance pay the
 * admin switched on, see courier_pay.php) plus the customer's tip on delivered orders - never the order total,
 * which includes the food. Totals are computed in SQL so they are not capped by a list limit. Tips are 100% the
 * courier's (orders.courier_tip).
 */

require_once __DIR__ . '/courier_pay.php';

// Largest tip a customer can add to one order (ETB) unless the admin sets another limit (Admin > Pricing > Courier pay).
if (!defined('ETEL_MAX_TIP')) define('ETEL_MAX_TIP', 500);

/** Clean a tip amount sent by the app: a number >= 0, at most $max, 2 decimals. Anything else is 0. */
function etel_clean_tip($raw, $max = ETEL_MAX_TIP) {
    if (!is_numeric($raw)) return 0.0;
    $v = floatval($raw);
    if (!is_finite($v) || $v <= 0) return 0.0;
    return round(min($v, $max), 2);
}

/** Kept for existing callers: makes sure orders.courier_tip (and the courier pay columns) exist. */
function etel_ensure_tip_column($pdo) { etel_ensure_order_pay_columns($pdo); }

/**
 * For the courier app's order lists: "delivery_fee" becomes what the courier is paid for the order
 * (pay + tip), because that is what the app prints as "You earn".
 *   base_delivery_fee  the fee the customer was charged
 *   courier_pay        the courier's pay before the tip (delivery fee while pay by distance is off)
 *   courier_tip        the customer's tip
 * $courierId is the courier looking at the list. $snapshot = true for the courier's own orders: the first time
 * the app sees an active order, its pay is saved on the order so later setting changes never rewrite it.
 */
function etel_apply_courier_tip($pdo, &$orders, $courierId = 0, $snapshot = false) {
    if (!$orders) return;
    etel_ensure_order_pay_columns($pdo);
    $ids = array();
    foreach ($orders as $o) { if (isset($o['id'])) $ids[] = intval($o['id']); }
    $rows = etel_cpay_fetch_rows($pdo, $ids);
    $s = etel_cpay_settings($pdo);
    $tierMults = array(); $cLat = 0; $cLng = 0;
    if (!empty($s['enabled'])) {
        $tierMults = etel_cpay_tier_mults($pdo);
        if ($courierId) {
            try {
                $c = $pdo->prepare('SELECT last_lat, last_lng FROM couriers WHERE id = ?');
                $c->execute(array(intval($courierId)));
                $cr = $c->fetch(PDO::FETCH_ASSOC);
                if ($cr) { $cLat = floatval($cr['last_lat']); $cLng = floatval($cr['last_lng']); }
            } catch (Exception $e) { /* unknown position: pickup distance counts as 0 */ }
        }
    }
    foreach ($orders as $i => $o) {
        $id = intval($o['id']);
        $r = isset($rows[$id]) ? $rows[$id] : array();
        $tip  = isset($r['courier_tip']) ? floatval($r['courier_tip']) : 0.0;
        $fee  = isset($o['delivery_fee']) ? floatval($o['delivery_fee']) : 0.0;
        $pay  = (isset($r['courier_pay']) && $r['courier_pay'] !== null) ? floatval($r['courier_pay']) : null;
        if ($pay === null && !empty($s['enabled']) && $r) {
            $calc = etel_cpay_for_row($s, $r, $cLat, $cLng, $tierMults);
            if ($calc) {
                $pay = floatval($calc['pay']);
                $active = isset($r['status']) && in_array($r['status'], array('assigned', 'picked_up', 'in_transit'), true);
                if ($snapshot && $active && intval(isset($r['courier_id']) ? $r['courier_id'] : 0) === intval($courierId) && $courierId) {
                    try {
                        $pdo->prepare('UPDATE orders SET courier_pay = ?, courier_pay_note = ? WHERE id = ? AND courier_pay IS NULL AND courier_id = ?')
                            ->execute(array($pay, 'distance ' . $calc['km_total'] . ' km', $id, intval($courierId)));
                    } catch (Exception $e) { error_log('[ETEL] courier pay snapshot: ' . $e->getMessage()); }
                }
            }
        }
        if ($pay === null) $pay = $fee;
        $orders[$i]['base_delivery_fee'] = $fee;
        $orders[$i]['courier_pay'] = $pay;
        $orders[$i]['courier_tip'] = $tip;
        $orders[$i]['delivery_fee'] = round($pay + $tip, 2);
    }
}

function etel_courier_earnings($pdo, $courierId) {
    etel_ensure_tip_column($pdo);
    $day = 'DATE(COALESCE(delivered_at, placed_at))';
    $pay = '(COALESCE(courier_pay, delivery_fee) + courier_tip)';
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
