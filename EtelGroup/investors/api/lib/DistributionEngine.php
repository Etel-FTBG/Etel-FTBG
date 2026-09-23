<?php
// ============================================================
// Investor Relations — distribution calculation engine
// Pure calculation logic, deliberately separated from any DB/HTTP
// concern so the math itself is easy to read, test, and audit
// independently of how its inputs are fetched or its output stored.
//
// Fixed equity constants for this single-company (Etel Delivery &
// Ride) instance - see the spec: total shares 10,000, investor pool
// 2,000 (20%), founder 8,000 (80%, not tracked as a distributable
// holding - it's the residual after investor payouts).
// ============================================================
declare(strict_types=1);

define('IR_TOTAL_SHARES', 10000);
define('IR_INVESTOR_POOL_SHARES', 2000);
define('IR_SHARE_PRICE', 1000.00);
define('IR_BOOSTED_MULTIPLIER', 2.5);
define('IR_STANDARD_MULTIPLIER', 1.0);

/**
 * Prorates a monthly cost amount against a period of arbitrary length.
 * Denominator is the number of days in the calendar month the period
 * STARTS in (a reasonable, stated convention for a period that spans
 * or is shorter/longer than exactly one month - documented here rather
 * than left implicit).
 */
function ir_prorate_monthly(float $monthlyAmount, string $periodStart, string $periodEnd): float {
    $start = new DateTime($periodStart);
    $end = new DateTime($periodEnd);
    $periodDays = (int)$start->diff($end)->days + 1; // inclusive
    $daysInMonth = (int)$start->format('t');
    return $monthlyAmount * ($periodDays / $daysInMonth);
}

/**
 * Runs the full distribution calculation for one period.
 *
 * @param float $grossFareTotal   Real gross fares for the period (from Delivery & Ride, via the read-only connection)
 * @param float $commissionRate   e.g. 0.05 for 5%
 * @param array $activeCostCategories  [['monthly_amount' => float], ...] - only active categories
 * @param string $periodStart / $periodEnd  Y-m-d
 * @param array $investors  [['id'=>, 'shares_owned'=>int, 'total_invested'=>float, 'cumulative_received_before'=>float], ...]
 *              shares_owned = sum of this investor's real (non-demo, unless a demo run) purchased shares as of period_end.
 *              cumulative_received_before = sum of total_payout from all PRIOR locked periods for this investor.
 *
 * @return array Full breakdown, including a per-investor 'lines' array - every field a caller needs
 *               to both display the result and persist it as an immutable ir_distribution_periods +
 *               ir_distribution_investor_lines record.
 */
function ir_run_distribution(
    float $grossFareTotal,
    float $commissionRate,
    array $activeCostCategories,
    string $periodStart,
    string $periodEnd,
    array $investors
): array {
    $commissionRevenue = $grossFareTotal * $commissionRate;

    $totalCosts = 0.0;
    foreach ($activeCostCategories as $cat) {
        $totalCosts += ir_prorate_monthly((float)$cat['monthly_amount'], $periodStart, $periodEnd);
    }

    $netProfit = $commissionRevenue - $totalCosts;

    // Step 2: standard 20% investor pool entitlement. Floored at zero -
    // in a loss month (net_profit <= 0) there is no profit pool to
    // share, so investors are entitled to nothing, not a negative
    // amount. Without this floor, every downstream figure (base
    // entitlement, bounce, total_payout) went negative in a loss month,
    // meaning investors were "charged" a share of the company's losses
    // and could end up with a negative cumulative_received and a
    // remaining_principal that EXCEEDED what they originally invested -
    // investors only ever share in profit, never absorb losses; the
    // founder/company residual (netProfit - totalInvestorPayout, below)
    // still correctly reflects the full loss.
    $poolEntitlement = max(0.0, $netProfit) * (IR_INVESTOR_POOL_SHARES / IR_TOTAL_SHARES);

    $soldShares = 0;
    foreach ($investors as $inv) $soldShares += (int)$inv['shares_owned'];
    $unsoldShares = max(0, IR_INVESTOR_POOL_SHARES - $soldShares);

    // Steps 3-4: per-investor payback phase, multiplier, and base
    // entitlement - pro-rata against the FIXED 2,000-share pool (not
    // just sold shares), so an investor's stated "effectively 50%
    // instead of 20%" example holds exactly when multiplier=2.5 and
    // they hold 100% of the pool.
    $lines = [];
    $totalBaseEntitlement = 0.0;
    foreach ($investors as $inv) {
        $sharesOwned = (int)$inv['shares_owned'];
        $totalInvested = (float)$inv['total_invested'];
        $cumulativeBefore = (float)$inv['cumulative_received_before'];
        $paybackPhase = $cumulativeBefore < $totalInvested;
        $multiplier = $paybackPhase ? IR_BOOSTED_MULTIPLIER : IR_STANDARD_MULTIPLIER;

        $baseEntitlement = $poolEntitlement * ($sharesOwned / IR_INVESTOR_POOL_SHARES) * $multiplier;
        $totalBaseEntitlement += $baseEntitlement;

        $lines[$inv['id']] = [
            'investor_id' => $inv['id'],
            'shares_at_time' => $sharesOwned,
            'invested_principal_at_time' => $totalInvested,
            'cumulative_received_before' => $cumulativeBefore,
            'payback_phase' => $paybackPhase,
            'multiplier' => $multiplier,
            'base_entitlement' => round($baseEntitlement, 2),
            'bounce_share' => 0.0, // filled below
            'total_payout' => 0.0, // filled below
            'cumulative_received_after' => 0.0, // filled below
        ];
    }

    // Step 5: unsold-share bounce, always at the boosted rate,
    // regardless of any individual investor's own payback status -
    // this reflects what the unsold shares WOULD have earned an
    // investor in payback phase, not any real investor's own rate.
    $unsoldBounceTheoretical = $poolEntitlement * ($unsoldShares / IR_INVESTOR_POOL_SHARES) * IR_BOOSTED_MULTIPLIER;
    $bounceToInvestors = $unsoldBounceTheoretical / 2;
    $bounceToFounder = $unsoldBounceTheoretical / 2;

    if ($soldShares > 0) {
        foreach ($investors as $inv) {
            $sharesOwned = (int)$inv['shares_owned'];
            $bounceShare = $bounceToInvestors * ($sharesOwned / $soldShares);
            $lines[$inv['id']]['bounce_share'] = round($bounceShare, 2);
        }
    }
    // If no shares are sold at all, the whole bounce-to-investors half
    // has nobody to go to - it simply isn't distributed (stays
    // uncounted here; total_investor_payout will be 0 either way).

    // Step 6-7: total payout per investor, founder residual.
    $totalInvestorPayout = 0.0;
    foreach ($lines as $id => &$line) {
        $line['total_payout'] = round($line['base_entitlement'] + $line['bounce_share'], 2);
        $line['cumulative_received_after'] = round($line['cumulative_received_before'] + $line['total_payout'], 2);
        $totalInvestorPayout += $line['total_payout'];
    }
    unset($line);

    $founderAmount = $netProfit - $totalInvestorPayout;

    return [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'commission_rate' => $commissionRate,
        'gross_fare_total' => round($grossFareTotal, 2),
        'commission_revenue' => round($commissionRevenue, 2),
        'total_costs' => round($totalCosts, 2),
        'net_profit' => round($netProfit, 2),
        'pool_entitlement' => round($poolEntitlement, 2),
        'sold_shares' => $soldShares,
        'unsold_shares' => $unsoldShares,
        'unsold_bounce_theoretical' => round($unsoldBounceTheoretical, 2),
        'unsold_bounce_to_investors' => round($bounceToInvestors, 2),
        'unsold_bounce_to_founder' => round($bounceToFounder, 2),
        'total_investor_payout' => round($totalInvestorPayout, 2),
        'founder_amount' => round($founderAmount, 2),
        'lines' => array_values($lines),
    ];
}
