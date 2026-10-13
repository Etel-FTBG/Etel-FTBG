<?php
/**
 * Courier earnings: what the courier actually earns is the delivery fee on delivered orders (never the order
 * total, which includes the food). Totals are computed in SQL so they are not capped by a list limit.
 */
function etel_courier_earnings($pdo, $courierId) {
    $day = 'DATE(COALESCE(delivered_at, placed_at))';
    $q = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN $day = CURDATE() THEN delivery_fee END),0) AS today_earn,
            COALESCE(SUM($day = CURDATE()),0) AS today_n,
            COALESCE(SUM(CASE WHEN $day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN delivery_fee END),0) AS week_earn,
            COALESCE(SUM($day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)),0) AS week_n,
            COALESCE(SUM(delivery_fee),0) AS all_earn,
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
