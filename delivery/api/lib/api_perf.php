<?php
/**
 * API health: how fast and how reliable is boot.php right now?
 *
 * Every request leaves one cheap counter update in api_perf_minute (one row
 * per minute per action: requests, server errors, slow requests, total and
 * worst milliseconds). Nothing about the caller is stored. Streams
 * (text/event-stream) are skipped, since they stay open on purpose.
 *
 * etel_perf_scan() reads the last 10 minutes and returns the same kind of
 * finding the trip watch uses, so the cron alert and the admin panel pick it
 * up with no extra wiring (see ride_monitor.php). Settings are numbers in
 * pricing_rules (0 turns that rule off):
 *   watch_api_avg_ms        default 1500  average response time that is "slow"
 *   watch_api_error_pct     default 5     percent of 5xx answers that is "failing"
 *   watch_api_min_requests  default 20    do not judge a quiet window
 * 4xx answers (wrong password, not signed in) are normal and never counted as errors.
 *
 * Time zone: minutes are written with PHP's clock and read with SQL NOW();
 * the connection is aligned to PHP's zone in db() (see ride_sweeper.php).
 */

if (!function_exists('etel_perf_install')) {

/* Call once, early, with the action name. Records at shutdown so every exit
   path (ok(), fail(), a fatal) is covered. Never throws, never blocks the answer. */
function etel_perf_install($action) {
    if (defined('ETEL_PERF_OFF') && ETEL_PERF_OFF) return;
    if (!isset($GLOBALS['_etel_t0'])) $GLOBALS['_etel_t0'] = microtime(true);
    register_shutdown_function(function () use ($action) {
        try {
            $pdo = isset($GLOBALS['_etel_pdo']) ? $GLOBALS['_etel_pdo'] : null;
            if (!$pdo) return; // request never touched the database
            foreach (headers_list() as $h) {
                if (stripos($h, 'text/event-stream') !== false) return; // long-lived stream
            }
            $ms = (int) round((microtime(true) - $GLOBALS['_etel_t0']) * 1000);
            $status = function_exists('http_response_code') ? (int) http_response_code() : 200;
            etel_perf_record($pdo, $action, $ms, $status);
        } catch (Exception $e) {
        } catch (Throwable $t) {
        }
    });
}

function etel_perf_record($pdo, $action, $ms, $status) {
    $action = preg_replace('/[^a-z0-9_]/i', '', (string) $action);
    $action = $action === '' ? '(none)' : substr($action, 0, 60);
    $sql = "INSERT INTO api_perf_minute (minute, action, n, errors, slow, total_ms, max_ms) VALUES (?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE n = n + 1, errors = errors + VALUES(errors), slow = slow + VALUES(slow),
                                    total_ms = total_ms + VALUES(total_ms), max_ms = GREATEST(max_ms, VALUES(max_ms))";
    $args = array(date('Y-m-d H:i:00'), $action, $status >= 500 ? 1 : 0, $ms >= 2000 ? 1 : 0, $ms, $ms);
    try {
        $pdo->prepare($sql)->execute($args);
    } catch (Exception $e) {
        // First request ever (or after a manual drop): create the table, try once more.
        etel_perf_ensure_table($pdo);
        $pdo->prepare($sql)->execute($args);
    }
    if (mt_rand(1, 500) === 1) { // housekeeping: keep two days
        $pdo->exec("DELETE FROM api_perf_minute WHERE minute < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    }
}

function etel_perf_ensure_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_perf_minute (
        minute   DATETIME    NOT NULL,
        action   VARCHAR(60) NOT NULL,
        n        INT         NOT NULL DEFAULT 0,
        errors   INT         NOT NULL DEFAULT 0,
        slow     INT         NOT NULL DEFAULT 0,
        total_ms BIGINT      NOT NULL DEFAULT 0,
        max_ms   INT         NOT NULL DEFAULT 0,
        PRIMARY KEY (minute, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function etel_perf_item($key, $sev, $title, $detail, $minutes) {
    return array('key' => $key, 'rule' => $key, 'ride_id' => 0, 'severity' => $sev, 'minutes' => intval($minutes),
        'title' => $title, 'detail' => $detail, 'status' => '', 'passenger' => '', 'passenger_phone' => '',
        'driver' => '', 'driver_phone' => '', 'pickup' => '');
}

/* Returns array('stats' => array|null, 'findings' => [...]). Read-only. */
function etel_perf_scan($pdo, $windowMin = 10) {
    $out = array('stats' => null, 'findings' => array());
    $avgThr = max(0, intval(rides_rule_value($pdo, 'watch_api_avg_ms', 1500)));
    $errThr = max(0, intval(rides_rule_value($pdo, 'watch_api_error_pct', 5)));
    $minReq = max(1, intval(rides_rule_value($pdo, 'watch_api_min_requests', 20)));
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(n),0) AS n, COALESCE(SUM(errors),0) AS errors, COALESCE(SUM(slow),0) AS slow,
                                   COALESCE(SUM(total_ms),0) AS total_ms, COALESCE(MAX(max_ms),0) AS max_ms
                            FROM api_perf_minute WHERE minute >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $s->execute(array(intval($windowMin)));
        $t = $s->fetch();
    } catch (Exception $e) {
        return $out; // table not created yet: no traffic recorded
    }
    $n = intval($t['n']);
    if ($n <= 0) return $out;
    $avg = (int) round($t['total_ms'] / $n);
    $errPct = round($t['errors'] * 100.0 / $n, 1);
    $slowPct = round($t['slow'] * 100.0 / $n, 1);
    $out['stats'] = array('window_min' => intval($windowMin), 'requests' => $n, 'avg_ms' => $avg, 'max_ms' => intval($t['max_ms']),
                          'error_pct' => $errPct, 'slow_pct' => $slowPct);
    if ($n < $minReq) return $out;

    // Which actions are the slowest / failing (for the message).
    $worst = array();
    try {
        $q = $pdo->prepare("SELECT action, SUM(n) AS n, SUM(errors) AS errors, SUM(total_ms) / SUM(n) AS avg_ms
                            FROM api_perf_minute WHERE minute >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                            GROUP BY action HAVING SUM(n) >= 3 ORDER BY avg_ms DESC LIMIT 3");
        $q->execute(array(intval($windowMin)));
        foreach ($q->fetchAll() as $r) $worst[] = $r['action'] . ' (' . (int) round($r['avg_ms']) . ' ms)';
    } catch (Exception $e) {}
    $worstTxt = $worst ? ' Slowest: ' . implode(', ', $worst) . '.' : '';

    if ($avgThr > 0 && $avg >= $avgThr) {
        $out['findings'][] = etel_perf_item('api:slow', $avg >= 2 * $avgThr ? 'critical' : 'warn', 'API is slow',
            'Average answer time ' . $avg . ' ms over the last ' . $windowMin . ' min (' . $n . ' requests).' . $worstTxt, $windowMin);
    }
    if ($errThr > 0 && $errPct >= $errThr) {
        $out['findings'][] = etel_perf_item('api:errors', $errPct >= 3 * $errThr ? 'critical' : 'warn', 'API is returning errors',
            $errPct . '% of requests failed with a server error over the last ' . $windowMin . ' min (' . intval($t['errors']) . ' of ' . $n . '). Check the server error log.', $windowMin);
    }
    return $out;
}

} // end function_exists guard
