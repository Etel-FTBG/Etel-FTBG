<?php
/**
 * Stuck-trip sweeper.
 *
 * Production data (Oct 2026) showed trips that never finish themselves:
 *   - 38 of 149 completed rides ran 3+ hours between start and arrival;
 *   - 'arrived' rides waited ~22 hours for the passenger to confirm;
 *   - a wait clock left on for 23 hours billed ETB 4,808.
 * This closes those loops, using three settings in pricing_rules (minutes;
 * 0 turns that rule off):
 *   ride_auto_complete_minutes  default 30   arrived + cash  -> completed
 *   ride_idle_end_minutes       default 180  in_progress, no movement -> arrived
 *   ride_wait_cap_minutes       default 30   billable wait cap (also stops the clock)
 *
 * Rules, in order:
 *  A. 'arrived' CASH rides older than ride_auto_complete_minutes are completed
 *     with the stored fare through rides_complete_and_pay() (idempotent), so
 *     the driver is credited and commission is booked exactly as if the
 *     passenger had tapped confirm. Skips rides the sweeper itself ended.
 *  B. 'arrived' rides paid another way (wallet) cannot be settled without the
 *     passenger, so they get ONE open ride_issues row for an admin instead.
 *  C. A wait clock running past the cap is stopped.
 *  D. 'in_progress' rides with no movement for ride_idle_end_minutes are ended
 *     like driver_arrived would (meter, shortfall floor, capped wait), moved
 *     to 'arrived', and flagged with an open 'auto_ended' issue. They are NOT
 *     auto-completed: nobody confirmed that trip, so an admin reviews it
 *     (admin_manual_complete_ride accepts 'arrived').
 *
 * Time zones: arrived_at/started_at/recorded_at come from MySQL NOW() and are
 * compared with NOW() in SQL; wait_started_at is written with PHP's clock and
 * is compared with PHP's time(). Never mix the two.
 */

if (!function_exists('rides_sweep_stuck')) {

function rides_sweeper_issue($pdo, $rideId, $reporterId, $category, $details, $status) {
    try {
        $pdo->prepare('INSERT INTO ride_issues (ride_id, reporter_id, category, details, status, resolved_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(array($rideId, intval($reporterId), $category, $details, $status,
                $status === 'resolved' ? date('Y-m-d H:i:s') : null));
    } catch (Exception $e) {
        error_log('[ETEL][sweeper] ride_issues insert failed for ride ' . $rideId . ': ' . $e->getMessage());
    }
}

function rides_sweeper_push($pdo, $userId, $title, $body, $rideId) {
    if (!function_exists('etel_push_send') || !$userId) return;
    try {
        etel_push_send($pdo, intval($userId), $title, $body,
            array('action' => 'ride_auto_closed', 'ride_id' => $rideId, 'url' => '/delivery/ride/'));
    } catch (Exception $e) { /* best-effort */ }
}

/* Runs one sweep. $opts: limit (rows per rule, default 50), push (bool). */
function rides_sweep_stuck($pdo, $opts = array()) {
    $limit = isset($opts['limit']) ? max(1, intval($opts['limit'])) : 50;
    $push  = !isset($opts['push']) || $opts['push'];
    $out = array('completed' => array(), 'flagged' => array(), 'ended' => array(), 'waits_stopped' => array());

    $autoMin = rides_rule_value($pdo, 'ride_auto_complete_minutes', 30);
    $idleMin = rides_rule_value($pdo, 'ride_idle_end_minutes', 180);
    $capMin  = rides_wait_cap_minutes($pdo);

    // Make sure wait columns exist before any SELECT below names them.
    try { rides_ensure_wait_columns($pdo); } catch (Exception $e) { /* handled per rule */ }

    // A. arrived + cash -> completed
    if ($autoMin > 0) {
        try {
            $q = $pdo->prepare(
                "SELECT r.id, r.driver_id, r.passenger_id, r.fare_final FROM rides r
                 WHERE r.status='arrived' AND r.arrived_at IS NOT NULL
                   AND r.arrived_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                   AND r.driver_id IS NOT NULL
                   AND (r.payment_method IS NULL OR r.payment_method='' OR r.payment_method='cash')
                   AND NOT EXISTS (SELECT 1 FROM ride_issues i WHERE i.ride_id=r.id AND i.category='auto_ended')
                 ORDER BY r.id ASC LIMIT " . $limit);
            $q->execute(array(intval($autoMin)));
            foreach ($q->fetchAll() as $r) {
                $fare = floatval($r['fare_final']);
                if ($fare <= 0) {
                    rides_sweeper_issue($pdo, $r['id'], $r['driver_id'], 'auto_close_skipped',
                        'Arrived ride has no fare to settle - needs an admin.', 'open');
                    $out['flagged'][] = intval($r['id']);
                    continue;
                }
                if (rides_complete_and_pay($pdo, intval($r['id']), intval($r['driver_id']), $fare, 'cash')) {
                    rides_sweeper_issue($pdo, $r['id'], $r['driver_id'], 'auto_completed',
                        'Passenger did not confirm within ' . intval($autoMin) . ' min of arrival; completed as cash at ETB '
                        . number_format($fare, 2) . ' (rides_sweep_stuck).', 'resolved');
                    if ($push) {
                        rides_sweeper_push($pdo, $r['passenger_id'], 'Trip completed', 'Your trip was closed automatically. Fare ETB ' . number_format($fare, 2) . ', paid in cash.', $r['id']);
                        rides_sweeper_push($pdo, $r['driver_id'], 'Trip completed', 'Passenger did not confirm, so the trip was closed automatically.', $r['id']);
                    }
                    $out['completed'][] = intval($r['id']);
                }
            }
        } catch (Exception $e) {
            error_log('[ETEL][sweeper] rule A failed: ' . $e->getMessage());
        }

        // B. arrived, not cash -> one admin issue (4x the window, at least 2 h)
        try {
            $wait = max(120, intval($autoMin) * 4);
            $q = $pdo->prepare(
                "SELECT r.id, r.driver_id FROM rides r
                 WHERE r.status='arrived' AND r.arrived_at IS NOT NULL
                   AND r.arrived_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                   AND r.payment_method IS NOT NULL AND r.payment_method <> '' AND r.payment_method <> 'cash'
                   AND NOT EXISTS (SELECT 1 FROM ride_issues i WHERE i.ride_id=r.id AND i.category='unconfirmed_arrival')
                 ORDER BY r.id ASC LIMIT " . $limit);
            $q->execute(array($wait));
            foreach ($q->fetchAll() as $r) {
                rides_sweeper_issue($pdo, $r['id'], $r['driver_id'], 'unconfirmed_arrival',
                    'Passenger has not confirmed arrival after ' . $wait . ' min and the ride is not cash, so it cannot be auto-completed.', 'open');
                $out['flagged'][] = intval($r['id']);
            }
        } catch (Exception $e) {
            error_log('[ETEL][sweeper] rule B failed: ' . $e->getMessage());
        }
    }

    // C. wait clock running past the cap -> stop it
    if ($capMin > 0) {
        try {
            $q = $pdo->query("SELECT id, wait_seconds_total, wait_started_at FROM rides WHERE status='in_progress' AND wait_active=1 LIMIT " . $limit);
            foreach ($q->fetchAll() as $r) {
                $elapsed = $r['wait_started_at'] ? max(0, time() - strtotime($r['wait_started_at'])) : 0;
                if ($elapsed / 60.0 < $capMin) continue;
                $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=? WHERE id=? AND wait_active=1")
                    ->execute(array(intval($r['wait_seconds_total']) + $elapsed, $r['id']));
                $out['waits_stopped'][] = intval($r['id']);
            }
        } catch (Exception $e) {
            error_log('[ETEL][sweeper] rule C failed: ' . $e->getMessage());
        }
    }

    // D. in_progress with no movement -> ended (arrived) and flagged
    if ($idleMin > 0) {
        try {
            $q = $pdo->prepare(
                "SELECT r.* FROM rides r
                 WHERE r.status='in_progress' AND r.started_at IS NOT NULL
                   AND r.started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                   AND COALESCE((SELECT MAX(p.recorded_at) FROM ride_path_points p WHERE p.ride_id=r.id), r.started_at)
                       < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY r.id ASC LIMIT " . $limit);
            $q->execute(array(intval($idleMin), intval($idleMin)));
            foreach ($q->fetchAll() as $r) {
                $rid = intval($r['id']);
                $driverId = intval($r['driver_id']);
                $vtype = !empty($r['vehicle_type']) ? $r['vehicle_type'] : rides_driver_own_vtype($pdo, $driverId);
                $isOpen = intval($r['open_trip']) === 1;
                $waitMin = rides_current_wait_minutes($r);
                $traveled = round(floatval($r['traveled_km']), 2);
                $shortfall = rides_meter_shortfall_floor($pdo, $r, $driverId, $traveled);
                if ($shortfall['applied']) $traveled = $shortfall['km'];

                if ($traveled > 0.1) {
                    $km = $traveled;
                    $fareCalc = rides_calc_fare($pdo, $km, $vtype, $isOpen, $waitMin);
                    $fare = $fareCalc['fare'];
                    $breakdown = rides_fare_breakdown_for_ui($fareCalc);
                } else {
                    $km = floatval($r['distance_km']);
                    $base = floatval($r['fare_final']) > 0 ? floatval($r['fare_final']) : rides_calc_fare_simple($pdo, $km, $vtype);
                    $rule = rides_fare_rule($pdo, $vtype);
                    $billable = ($capMin > 0) ? min($waitMin, $capMin) : $waitMin;
                    $waitEtb = round($rule['wait_rate_per_min'] * $billable, 2);
                    $fare = $base + $waitEtb;
                    $breakdown = array('fare_base' => $base, 'fare_distance' => 0, 'fare_wait' => $waitEtb,
                        'fare_subtotal' => round($base + $waitEtb, 2), 'fare_surge_mult' => 1.0,
                        'fare_surge_etb' => 0, 'fare_floor_topup' => 0, 'fare_total' => $fare);
                }
                $breakdown['auto_ended'] = true;
                if ($shortfall['applied']) {
                    $breakdown['meter_shortfall'] = true;
                    $breakdown['meter_km'] = $shortfall['meter_km'];
                    $breakdown['meter_floor_basis'] = $shortfall['basis'];
                }
                $claim = $pdo->prepare(
                    "UPDATE rides SET status='arrived', arrived_at=NOW(), distance_km=?, fare_final=?, fare_breakdown_json=?,
                            wait_active=0, wait_started_at=NULL, wait_seconds_total=?
                     WHERE id=? AND status='in_progress'");
                $claim->execute(array($km, $fare, json_encode($breakdown), intval(round($waitMin * 60)), $rid));
                if ($claim->rowCount() < 1) continue; // someone else closed it first
                rides_sweeper_issue($pdo, $rid, $driverId, 'auto_ended',
                    'No movement for ' . intval($idleMin) . ' min, so the trip was ended automatically at ' . round($km, 2)
                    . ' km, fare ETB ' . number_format($fare, 2) . '. Review before collecting; complete it with admin_manual_complete_ride.', 'open');
                $out['ended'][] = $rid;
            }
        } catch (Exception $e) {
            error_log('[ETEL][sweeper] rule D failed: ' . $e->getMessage());
        }
    }
    return $out;
}

/* Runs a sweep at most once per $minGapMinutes across ALL requests, so it can
   piggyback on busy endpoints without a cron job. The one request that wins
   the UPDATE below does the work; everyone else returns immediately. */
function rides_sweep_maybe($pdo, $minGapMinutes = 5) {
    static $tried = false;
    if ($tried) return null;
    $tried = true;
    try {
        $claim = $pdo->prepare("UPDATE ride_sweeper_state SET last_run=NOW() WHERE id=1 AND last_run < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        try {
            $claim->execute(array(intval($minGapMinutes)));
        } catch (Exception $e) {
            // First ever run: create the one-row state table, then retry.
            $pdo->exec("CREATE TABLE IF NOT EXISTS ride_sweeper_state (id TINYINT NOT NULL PRIMARY KEY, last_run DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT IGNORE INTO ride_sweeper_state (id, last_run) VALUES (1, '2000-01-01 00:00:00')");
            $claim->execute(array(intval($minGapMinutes)));
        }
        if ($claim->rowCount() < 1) return null;
        return rides_sweep_stuck($pdo, array('limit' => 10, 'push' => false));
    } catch (Exception $e) {
        error_log('[ETEL][sweeper] maybe-run failed: ' . $e->getMessage());
        return null;
    }
}

} // end function_exists guard
