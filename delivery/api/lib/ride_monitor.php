<?php
/**
 * Trip watch: finds rides that are stuck BEFORE the sweeper closes them, and
 * tells the admins.
 *
 * The sweeper (ride_sweeper.php) cleans up after hours. This is the early
 * warning: it only reads rides, it never changes one. Thresholds are minutes
 * in pricing_rules (0 turns that rule off):
 *   watch_searching_minutes   default 10   searching, nobody accepted
 *   watch_pickup_minutes      default 25   accepted, driver has not arrived or started
 *   watch_gps_silent_minutes  default 10   in_progress, no GPS point for this long
 *   watch_long_trip_minutes   default 120  in_progress for this long in total
 *   watch_arrived_minutes     default 15   arrived, passenger has not confirmed
 *   watch_issue_minutes       default 60   sweeper issue still open for an admin
 * Severity: 'critical' once a ride is past twice its threshold, else 'warn'.
 * (GPS silence is critical straight away: the fare meter is not running.)
 *
 * Alerts: each (rule, ride) pair is pushed to the admins once, then again only
 * if it turns critical, or every 60 min while it stays critical (max 3 times).
 * When a ride leaves the list its alert row is marked resolved.
 *
 * Time zone: every comparison is in SQL against NOW() (see ride_sweeper.php).
 */

if (!function_exists('rides_monitor_scan')) {

function rides_monitor_thresholds($pdo) {
    $d = array(
        'searching'  => rides_rule_value($pdo, 'watch_searching_minutes', 10),
        'pickup'     => rides_rule_value($pdo, 'watch_pickup_minutes', 25),
        'gps'        => rides_rule_value($pdo, 'watch_gps_silent_minutes', 10),
        'long'       => rides_rule_value($pdo, 'watch_long_trip_minutes', 120),
        'arrived'    => rides_rule_value($pdo, 'watch_arrived_minutes', 15),
        'issue'      => rides_rule_value($pdo, 'watch_issue_minutes', 60),
    );
    foreach ($d as $k => $v) $d[$k] = max(0, intval($v));
    return $d;
}

function rides_monitor_sev($minutes, $threshold, $always = false) {
    return ($always || $minutes >= 2 * $threshold) ? 'critical' : 'warn';
}

/* One finding. $parts: ride row + minutes. */
function rides_monitor_item($rule, $r, $minutes, $sev, $title, $detail) {
    return array(
        'key'          => $rule . ':' . intval($r['id']),
        'rule'         => $rule,
        'ride_id'      => intval($r['id']),
        'severity'     => $sev,
        'minutes'      => intval($minutes),
        'title'        => $title,
        'detail'       => $detail,
        'status'       => isset($r['status']) ? $r['status'] : '',
        'passenger'    => isset($r['passenger_name']) ? $r['passenger_name'] : '',
        'passenger_phone' => isset($r['passenger_phone']) ? $r['passenger_phone'] : '',
        'driver'       => isset($r['driver_name']) ? $r['driver_name'] : '',
        'driver_phone' => isset($r['driver_phone']) ? $r['driver_phone'] : '',
        'pickup'       => isset($r['pickup_address']) ? $r['pickup_address'] : '',
    );
}

function rides_monitor_fmt($min) {
    $min = intval($min);
    if ($min < 60) return $min . ' min';
    return floor($min / 60) . ' h ' . ($min % 60) . ' min';
}

/* Returns array('findings' => [...], 'thresholds' => [...], 'active' => n, 'sweeper_last_run' => str|null). Read-only. */
function rides_monitor_scan($pdo, $limit = 40) {
    $t = rides_monitor_thresholds($pdo);
    $limit = max(1, intval($limit));
    $out = array();
    $join = "FROM rides r
             LEFT JOIN users p ON p.id = r.passenger_id
             LEFT JOIN users d ON d.id = r.driver_id";
    $cols = "r.id, r.status, r.pickup_address, p.name AS passenger_name, p.phone AS passenger_phone,
             d.name AS driver_name, d.phone AS driver_phone";

    $run = function ($sql, $params, $cb) use ($pdo, &$out) {
        try {
            $q = $pdo->prepare($sql);
            $q->execute($params);
            foreach ($q->fetchAll() as $r) { $item = $cb($r); if ($item) $out[] = $item; }
        } catch (Exception $e) {
            error_log('[ETEL][monitor] rule query failed: ' . $e->getMessage());
        }
    };

    // 1. searching, nobody accepted. Scheduled rides wait for their time on purpose.
    if ($t['searching'] > 0) {
        // Scheduled rides wait for their time on purpose; older databases have no such column.
        $sched = '';
        try { $pdo->query("SELECT scheduled_at FROM rides LIMIT 0"); $sched = " AND (r.scheduled_at IS NULL OR r.scheduled_at <= NOW())"; } catch (Exception $e) {}
        $run("SELECT $cols, TIMESTAMPDIFF(MINUTE, r.created_at, NOW()) AS mins $join
              WHERE r.status='searching' AND r.driver_id IS NULL
                AND r.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)" . $sched . "
              ORDER BY r.id ASC LIMIT " . $limit, array($t['searching']), function ($r) use ($t) {
            $m = intval($r['mins']);
            return rides_monitor_item('searching', $r, $m, rides_monitor_sev($m, $t['searching']),
                'No driver yet', 'Passenger has waited ' . rides_monitor_fmt($m) . ' with no driver accepting.');
        });
    }

    // 2. accepted but the driver never reached / started the pickup
    if ($t['pickup'] > 0) {
        $run("SELECT $cols, TIMESTAMPDIFF(MINUTE, COALESCE(r.assigned_at, r.created_at), NOW()) AS mins $join
              WHERE r.status='accepted' AND COALESCE(r.assigned_at, r.created_at) < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY r.id ASC LIMIT " . $limit, array($t['pickup']), function ($r) use ($t) {
            $m = intval($r['mins']);
            return rides_monitor_item('pickup', $r, $m, rides_monitor_sev($m, $t['pickup']),
                'Driver not at pickup', 'Accepted ' . rides_monitor_fmt($m) . ' ago and the trip has not started.');
        });
    }

    // 3. in_progress but the phone stopped reporting GPS (meter is not running)
    if ($t['gps'] > 0) {
        $run("SELECT $cols,
                     TIMESTAMPDIFF(MINUTE, COALESCE((SELECT MAX(pp.recorded_at) FROM ride_path_points pp WHERE pp.ride_id=r.id), r.started_at), NOW()) AS mins $join
              WHERE r.status='in_progress' AND r.started_at IS NOT NULL
                AND r.started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                AND COALESCE((SELECT MAX(pp.recorded_at) FROM ride_path_points pp WHERE pp.ride_id=r.id), r.started_at)
                    < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY r.id ASC LIMIT " . $limit, array($t['gps'], $t['gps']), function ($r) use ($t) {
            $m = intval($r['mins']);
            return rides_monitor_item('gps', $r, $m, 'critical',
                'GPS went silent', 'No location from the driver for ' . rides_monitor_fmt($m) . '. The fare meter is not counting.');
        });
    }

    // 4. in_progress for very long in total
    if ($t['long'] > 0) {
        $run("SELECT $cols, TIMESTAMPDIFF(MINUTE, r.started_at, NOW()) AS mins $join
              WHERE r.status='in_progress' AND r.started_at IS NOT NULL
                AND r.started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY r.id ASC LIMIT " . $limit, array($t['long']), function ($r) use ($t) {
            $m = intval($r['mins']);
            return rides_monitor_item('long', $r, $m, rides_monitor_sev($m, $t['long']),
                'Very long trip', 'Trip has been running ' . rides_monitor_fmt($m) . '.');
        });
    }

    // 5. arrived, passenger has not confirmed
    if ($t['arrived'] > 0) {
        $run("SELECT $cols, TIMESTAMPDIFF(MINUTE, r.arrived_at, NOW()) AS mins $join
              WHERE r.status='arrived' AND r.arrived_at IS NOT NULL
                AND r.arrived_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY r.id ASC LIMIT " . $limit, array($t['arrived']), function ($r) use ($t) {
            $m = intval($r['mins']);
            return rides_monitor_item('arrived', $r, $m, rides_monitor_sev($m, $t['arrived']),
                'Not confirmed', 'Driver arrived ' . rides_monitor_fmt($m) . ' ago and the passenger has not confirmed.');
        });
    }

    // 6. sweeper issues nobody has looked at
    if ($t['issue'] > 0) {
        $run("SELECT $cols, i.category, TIMESTAMPDIFF(MINUTE, i.created_at, NOW()) AS mins
              FROM ride_issues i JOIN rides r ON r.id = i.ride_id
              LEFT JOIN users p ON p.id = r.passenger_id
              LEFT JOIN users d ON d.id = r.driver_id
              WHERE i.status='open' AND i.category IN ('auto_ended','unconfirmed_arrival','auto_close_skipped')
                AND i.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY i.id ASC LIMIT " . $limit, array($t['issue']), function ($r) use ($t) {
            $m = intval($r['mins']);
            $why = array('auto_ended' => 'Trip was ended automatically (no movement)',
                         'unconfirmed_arrival' => 'Non-cash trip never confirmed',
                         'auto_close_skipped' => 'Arrived trip has no fare');
            $title = isset($why[$r['category']]) ? $why[$r['category']] : 'Needs review';
            return rides_monitor_item('issue', $r, $m, rides_monitor_sev($m, $t['issue']),
                $title, 'Open for ' . rides_monitor_fmt($m) . ' - review it and mark it resolved (Ride issue reports).');
        });
    }

    // Worst first, then oldest.
    usort($out, function ($a, $b) {
        $sa = $a['severity'] === 'critical' ? 0 : 1; $sb = $b['severity'] === 'critical' ? 0 : 1;
        if ($sa !== $sb) return $sa - $sb;
        return $b['minutes'] - $a['minutes'];
    });

    $active = 0; $last = null;
    try { $active = intval($pdo->query("SELECT COUNT(*) FROM rides WHERE status IN ('searching','accepted','in_progress','arrived')")->fetchColumn()); } catch (Exception $e) {}
    try { $v = $pdo->query("SELECT last_run FROM ride_sweeper_state WHERE id=1")->fetchColumn(); if ($v) $last = $v; } catch (Exception $e) {}
    // API health (lib/api_perf.php): slow or failing server, same alert path.
    $apiStats = null;
    if (is_file(__DIR__ . '/api_perf.php')) {
        require_once __DIR__ . '/api_perf.php';
        $perf = etel_perf_scan($pdo);
        $apiStats = $perf['stats'];
        foreach ($perf['findings'] as $pf) $out[] = $pf;
        usort($out, function ($a, $b) {
            $sa = $a['severity'] === 'critical' ? 0 : 1; $sb = $b['severity'] === 'critical' ? 0 : 1;
            if ($sa !== $sb) return $sa - $sb;
            return $b['minutes'] - $a['minutes'];
        });
    }
    return array('findings' => $out, 'thresholds' => $t, 'active' => $active, 'sweeper_last_run' => $last, 'api' => $apiStats);
}

function rides_monitor_ensure_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ride_monitor_alerts (
        alert_key     VARCHAR(40) NOT NULL PRIMARY KEY,
        rule          VARCHAR(20) NOT NULL,
        ride_id       INT NOT NULL,
        severity      VARCHAR(10) NOT NULL,
        first_seen    DATETIME NOT NULL,
        last_notified DATETIME NULL,
        notify_count  INT NOT NULL DEFAULT 0,
        resolved_at   DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Decides which findings are due an alert and records it. Pure bookkeeping on
   ride_monitor_alerts; returns the findings to announce. */
function rides_monitor_due($pdo, $findings) {
    $due = array();
    try {
        rides_monitor_ensure_table($pdo);
        $seen = array();
        foreach ($findings as $f) {
            $seen[$f['key']] = true;
            $s = $pdo->prepare("SELECT severity, notify_count, resolved_at,
                                       TIMESTAMPDIFF(MINUTE, last_notified, NOW()) AS since FROM ride_monitor_alerts WHERE alert_key=?");
            $s->execute(array($f['key']));
            $row = $s->fetch();
            if (!$row) {
                $pdo->prepare("INSERT INTO ride_monitor_alerts (alert_key, rule, ride_id, severity, first_seen, last_notified, notify_count) VALUES (?,?,?,?,NOW(),NOW(),1)")
                    ->execute(array($f['key'], $f['rule'], $f['ride_id'], $f['severity']));
                $due[] = $f;
                continue;
            }
            $again = false;
            if ($row['resolved_at'] !== null) $again = true; // came back after being cleared
            elseif ($f['severity'] === 'critical' && $row['severity'] !== 'critical') $again = true; // escalated
            elseif ($f['severity'] === 'critical' && intval($row['notify_count']) < 3 && intval($row['since']) >= 60) $again = true;
            if ($again) {
                $pdo->prepare("UPDATE ride_monitor_alerts SET severity=?, last_notified=NOW(), resolved_at=NULL,
                               notify_count=IF(resolved_at IS NULL, notify_count + 1, 1) WHERE alert_key=?")
                    ->execute(array($f['severity'], $f['key']));
                $due[] = $f;
            }
        }
        // Anything that is no longer a finding is resolved.
        $open = $pdo->query("SELECT alert_key FROM ride_monitor_alerts WHERE resolved_at IS NULL")->fetchAll();
        foreach ($open as $o) {
            if (!isset($seen[$o['alert_key']])) {
                $pdo->prepare("UPDATE ride_monitor_alerts SET resolved_at=NOW() WHERE alert_key=? AND resolved_at IS NULL")->execute(array($o['alert_key']));
            }
        }
    } catch (Exception $e) {
        error_log('[ETEL][monitor] alert bookkeeping failed: ' . $e->getMessage());
    }
    return $due;
}

/* Push one grouped message to every admin. Returns the number of admins asked. */
function rides_monitor_notify($pdo, $due) {
    if (!$due || !function_exists('etel_push_send')) return 0;
    $crit = 0;
    foreach ($due as $f) if ($f['severity'] === 'critical') $crit++;
    $n = count($due);
    $title = ($crit > 0 ? 'Trip alert: ' : 'Trip watch: ') . $n . ($n === 1 ? ' problem needs' : ' problems need') . ' attention';
    $lines = array();
    foreach (array_slice($due, 0, 3) as $f) $lines[] = ($f['ride_id'] ? '#' . $f['ride_id'] . ' ' : '') . $f['title'] . ' (' . rides_monitor_fmt($f['minutes']) . ')';
    if ($n > 3) $lines[] = '+' . ($n - 3) . ' more';
    $body = implode(' - ', $lines);
    $sent = 0;
    try {
        $ids = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $uid) {
            try {
                etel_push_send($pdo, intval($uid), $title, $body, array('action' => 'ride_watch', 'url' => '/delivery/admin.html'));
                $sent++;
            } catch (Exception $e) { /* best-effort */ }
        }
    } catch (Exception $e) { error_log('[ETEL][monitor] admin lookup failed: ' . $e->getMessage()); }
    return $sent;
}

/* Full cycle for the cron: scan, announce what is new, return a summary. */
function rides_monitor_run($pdo, $push = true) {
    $scan = rides_monitor_scan($pdo);
    $due = rides_monitor_due($pdo, $scan['findings']);
    $sent = $push ? rides_monitor_notify($pdo, $due) : 0;
    return array('findings' => count($scan['findings']), 'announced' => count($due), 'admins_pushed' => $sent,
                 'critical' => count(array_filter($scan['findings'], function ($f) { return $f['severity'] === 'critical'; })));
}

} // end function_exists guard
