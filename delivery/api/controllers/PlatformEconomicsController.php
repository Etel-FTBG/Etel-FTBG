<?php
// Platform Economics - answers "is our 5% commission actually
// profitable for the company", using REAL completed-ride revenue
// against cost assumptions the founder enters (payment processing,
// customer acquisition, hosting, support, incentives, refunds,
// overhead). Revenue side is never guessed; cost side is only ever as
// good as what's entered here, and is clearly labeled as such.
switch ($action) {

case 'platform_economics_get_costs': {
    $pdo = db();
    require_admin($pdo, 'platform_economics.view');
    try {
        $row = $pdo->query('SELECT * FROM platform_cost_assumptions WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load cost assumptions - has migration 037 been applied?', 500);
    }
    ok(array('costs' => $row ?: array(
        'payment_processing_pct' => 0, 'monthly_customer_acquisition' => 0, 'monthly_hosting_cost' => 0,
        'monthly_support_cost' => 0, 'monthly_incentive_budget' => 0, 'monthly_refunds_estimate' => 0,
        'monthly_admin_overhead' => 0,
    )));
    break;
}

case 'platform_economics_update_costs': {
    $pdo = db();
    $u = require_admin($pdo, 'platform_economics.manage');
    $fields = array('payment_processing_pct', 'monthly_customer_acquisition', 'monthly_hosting_cost',
        'monthly_support_cost', 'monthly_incentive_budget', 'monthly_refunds_estimate', 'monthly_admin_overhead');
    $vals = array();
    foreach ($fields as $f) {
        $v = isset($body[$f]) ? floatval($body[$f]) : 0;
        if ($v < 0) fail("$f cannot be negative", 400);
        $vals[$f] = $v;
    }
    try {
        $s = $pdo->prepare('UPDATE platform_cost_assumptions SET
            payment_processing_pct=:payment_processing_pct, monthly_customer_acquisition=:monthly_customer_acquisition,
            monthly_hosting_cost=:monthly_hosting_cost, monthly_support_cost=:monthly_support_cost,
            monthly_incentive_budget=:monthly_incentive_budget, monthly_refunds_estimate=:monthly_refunds_estimate,
            monthly_admin_overhead=:monthly_admin_overhead, updated_by=:updated_by WHERE id=1');
        $vals['updated_by'] = $u['id'];
        $s->execute($vals);
        ok(array('message' => 'Cost assumptions updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save cost assumptions', 500);
    }
    break;
}

/* ---------- Growth & Goal Planner - persisted scenario ----------
 * One saved scenario at a time (single row, id=1) - not a history.
 * Read by every Finance & Planning sub-tab (Equity, Payroll, Business
 * Plan) that wants to reuse the same growth assumptions the founder
 * last calculated, instead of each tab silently assuming its own
 * numbers. See migration 047 for why this exists - previously these
 * values only ever lived as hardcoded template defaults in
 * admin-platform-economics.js, never actually saved anywhere. */
case 'platform_economics_get_growth_assumptions': {
    $pdo = db();
    require_admin($pdo, 'platform_economics.view');
    try {
        $row = $pdo->query('SELECT * FROM growth_goal_assumptions WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load growth assumptions - has migration 047 been applied?', 500);
    }
    ok(array('assumptions' => $row ?: array(
        'registered_drivers' => 20, 'trips_per_driver_day' => 12, 'avg_distance_km' => 5,
        'base_fare' => 120, 'per_km_rate' => 30, 'avg_wait_min' => 2, 'wait_rate' => 3,
        'surge_share_pct' => 25, 'surge_multiplier' => 1.2,
    )));
    break;
}

case 'platform_economics_save_growth_assumptions': {
    $pdo = db();
    $u = require_admin($pdo, 'platform_economics.manage');
    $fields = array('registered_drivers', 'trips_per_driver_day', 'avg_distance_km', 'base_fare',
        'per_km_rate', 'avg_wait_min', 'wait_rate', 'surge_share_pct', 'surge_multiplier');
    $vals = array();
    foreach ($fields as $f) {
        $v = isset($body[$f]) ? floatval($body[$f]) : 0;
        if ($v < 0) fail("$f cannot be negative", 400);
        $vals[$f] = $v;
    }
    try {
        $s = $pdo->prepare('UPDATE growth_goal_assumptions SET
            registered_drivers=:registered_drivers, trips_per_driver_day=:trips_per_driver_day,
            avg_distance_km=:avg_distance_km, base_fare=:base_fare, per_km_rate=:per_km_rate,
            avg_wait_min=:avg_wait_min, wait_rate=:wait_rate, surge_share_pct=:surge_share_pct,
            surge_multiplier=:surge_multiplier, updated_by=:updated_by WHERE id=1');
        $vals['updated_by'] = $u['id'];
        $s->execute($vals);
        ok(array('message' => 'Growth assumptions saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save growth assumptions', 500);
    }
    break;
}

case 'platform_economics_reset_growth_assumptions': {
    $pdo = db();
    $u = require_admin($pdo, 'platform_economics.manage');
    try {
        $s = $pdo->prepare('UPDATE growth_goal_assumptions SET
            registered_drivers=20, trips_per_driver_day=12, avg_distance_km=5, base_fare=120,
            per_km_rate=30, avg_wait_min=2, wait_rate=3, surge_share_pct=25, surge_multiplier=1.2,
            updated_by=:updated_by WHERE id=1');
        $s->execute(array('updated_by' => $u['id']));
        ok(array('message' => 'Growth assumptions reset to defaults'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not reset growth assumptions', 500);
    }
    break;
}

case 'platform_economics_get_summary': {
    $pdo = db();
    require_admin($pdo, 'platform_economics.view');
    $period = isset($body['period']) ? $body['period'] : 'month';
    if (!in_array($period, array('week', 'month', 'year'), true)) fail('period must be week, month, or year', 400);
    $days = $period === 'week' ? 7 : ($period === 'month' ? 30 : 365);
    $sinceExpr = "DATE_SUB(NOW(), INTERVAL $days DAY)";

    try {
        $s = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(fare_paid),0) AS gross
            FROM rides WHERE status='completed' AND completed_at >= $sinceExpr");
        $r = $s->fetch();
        $costs = $pdo->query('SELECT * FROM platform_cost_assumptions WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load platform economics data - has migration 037 been applied?', 500);
    }
    if (!$costs) $costs = array('payment_processing_pct' => 0, 'monthly_customer_acquisition' => 0, 'monthly_hosting_cost' => 0,
        'monthly_support_cost' => 0, 'monthly_incentive_budget' => 0, 'monthly_refunds_estimate' => 0, 'monthly_admin_overhead' => 0);

    $completedRides = intval($r['n']);
    $grossFares = round(floatval($r['gross']), 2);
    // Real 5% commission revenue - same rate as RideController's live
    // commission logic, applied to real completed-ride fares in the
    // window, not a projection.
    $commissionRevenue = round($grossFares * 0.05, 2);
    $paymentProcessingCost = round($grossFares * (floatval($costs['payment_processing_pct']) / 100), 2);

    // Monthly cost assumptions are prorated to the requested window.
    $monthlyFixedCosts = floatval($costs['monthly_customer_acquisition']) + floatval($costs['monthly_hosting_cost'])
        + floatval($costs['monthly_support_cost']) + floatval($costs['monthly_incentive_budget'])
        + floatval($costs['monthly_refunds_estimate']) + floatval($costs['monthly_admin_overhead']);
    $proratedFixedCosts = round($monthlyFixedCosts * ($days / 30), 2);

    $totalCosts = round($paymentProcessingCost + $proratedFixedCosts, 2);
    $netPlatformProfit = round($commissionRevenue - $totalCosts, 2);

    // Breakeven: how much gross fare volume (and how many rides, at
    // the period's real average fare) would be needed for the 5%
    // commission alone to cover the fixed costs for this window.
    $avgFare = $completedRides > 0 ? $grossFares / $completedRides : null;
    $breakevenGrossFareNeeded = null; $breakevenRidesNeeded = null;
    $effectiveRate = 0.05 - (floatval($costs['payment_processing_pct']) / 100);
    if ($effectiveRate > 0) {
        $breakevenGrossFareNeeded = round($proratedFixedCosts / $effectiveRate, 2);
        if ($avgFare && $avgFare > 0) {
            $breakevenRidesNeeded = (int) ceil($breakevenGrossFareNeeded / $avgFare);
        }
    }

    ok(array(
        'period' => $period, 'days' => $days,
        'completed_rides' => $completedRides, 'gross_fares' => $grossFares, 'avg_fare' => $avgFare ? round($avgFare, 2) : null,
        'commission_revenue' => $commissionRevenue, 'payment_processing_cost' => $paymentProcessingCost,
        'prorated_fixed_costs' => $proratedFixedCosts, 'total_costs' => $totalCosts,
        'net_platform_profit' => $netPlatformProfit,
        'breakeven_gross_fare_needed' => $breakevenGrossFareNeeded,
        'breakeven_rides_needed' => $breakevenRidesNeeded,
        'effective_commission_rate_after_processing' => round($effectiveRate * 100, 3),
        'costs' => $costs,
        'warning' => $effectiveRate <= 0 ? 'Payment processing cost alone exceeds the 5% commission rate - every ride currently loses money before any other cost is even counted.' : null,
    ));
    break;
}

}
