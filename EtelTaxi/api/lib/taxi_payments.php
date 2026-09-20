<?php
// ============================================================
// ETEL TAXI - Payment period logic  v1.0
//
// The rule, as described: a driver's due for month P is payable any
// time from the end of the PREVIOUS month through day
// {grace_period_days} of month P itself (default 5) - matching "pay
// from day 30 to day 5, after that a penalty applies." After that
// window closes without a verified payment, the penalty amount
// applies for that period.
//
// period_month is always stored as the 1st of the month it covers
// (e.g. 2026-08-01 = "August's permit due"). Real calendar month
// lengths are used throughout - never a hardcoded "30", since some
// months have 28, 29, or 31 days and a driver in February shouldn't
// get an unfair extra day or an unfairly short window.
// ============================================================

if (!function_exists('taxi_period_window')) {

// Returns ['window_start' => Y-m-d, 'window_end' => Y-m-d] for the
// on-time payment window covering $periodMonth (a 'Y-m-01' string).
function taxi_period_window($periodMonth, $gracePeriodDays) {
    $periodStart = new DateTime($periodMonth);
    $windowStart = (clone $periodStart)->modify('-1 day'); // last day of the previous month
    $windowEnd   = (clone $periodStart)->modify('+' . (max(1, intval($gracePeriodDays)) - 1) . ' days');
    return array(
        'window_start' => $windowStart->format('Y-m-d'),
        'window_end'   => $windowEnd->format('Y-m-d'),
    );
}

// Which period_month a driver should currently be judged against,
// given today's date. While we're still inside a period's grace
// window, that period is still "current" (they have until the window
// closes) - once the window has closed, the NEXT period becomes
// current (they should be starting to think about paying for it).
function taxi_current_period($pdo, $associationId, $refDate = null) {
    $today = $refDate ? new DateTime($refDate) : new DateTime();
    $s = $pdo->prepare('SELECT grace_period_days FROM ' . tbl('taxi_associations') . ' WHERE id = ?');
    $s->execute(array($associationId));
    $grace = intval($s->fetchColumn()) ?: 5;

    // Start from this calendar month and check whether its window has
    // already closed; if so, advance to next month.
    $candidate = new DateTime($today->format('Y-m-01'));
    $win = taxi_period_window($candidate->format('Y-m-d'), $grace);
    if ($today->format('Y-m-d') > $win['window_end']) {
        $candidate->modify('+1 month');
    }
    return $candidate->format('Y-m-d');
}

// Full status for a driver: are they in good standing, still inside a
// grace window, or overdue with a penalty owed - and for which period.
function taxi_compute_driver_status($pdo, $driverId, $associationId) {
    $currentPeriod = taxi_current_period($pdo, $associationId);
    $s = $pdo->prepare('SELECT grace_period_days, monthly_due_amount, penalty_amount FROM ' . tbl('taxi_associations') . ' WHERE id = ?');
    $s->execute(array($associationId));
    $assoc = $s->fetch();
    $grace = $assoc ? intval($assoc['grace_period_days']) : 5;
    $win   = taxi_period_window($currentPeriod, $grace);

    $ps = $pdo->prepare('SELECT * FROM ' . tbl('taxi_dues_payments') . '
                        WHERE driver_id = ? AND period_month = ? AND status = "verified" LIMIT 1');
    $ps->execute(array($driverId, $currentPeriod));
    $paid = $ps->fetch();

    $today = date('Y-m-d');
    if ($paid) {
        $status = 'active';
    } elseif ($today <= $win['window_end']) {
        $status = 'grace_period'; // still payable on time, no penalty yet
    } else {
        $status = 'overdue'; // window closed, penalty now applies
    }

    return array(
        'status'          => $status,
        'current_period'  => $currentPeriod,
        'window_start'    => $win['window_start'],
        'window_end'      => $win['window_end'],
        'monthly_due'     => $assoc ? floatval($assoc['monthly_due_amount']) : null,
        'penalty_amount'  => $assoc ? floatval($assoc['penalty_amount']) : null,
        'penalty_owed'    => ($status === 'overdue') ? ($assoc ? floatval($assoc['penalty_amount']) : 0) : 0,
    );
}

}
