<?php
/* ETEL Ride - sanity check for the peak-hour schedule (launch-readiness phase 2).
 * Returns plain-English warnings for the admin screen when the windows
 *   - cover most of the week (surge would almost always be on),
 *   - overlap each other (the first window in the list wins), or
 *   - carry a very high multiplier.
 * It uses the same matching rules as rides_active_peak_multiplier() in ride_fare.php
 * (days use date('N'): 1 = Monday ... 7 = Sunday; an end before the start runs past midnight; 24:00 = end of day).
 * It only warns. It never blocks a save. Plain PHP5 style, ASCII only.
 */
if (!function_exists('rides_peak_windows_warnings')) {

function rides_peak_slots($w) {
    $slots = array();   // key = (day-1)*1440 + minute
    $days = (isset($w['days']) && is_array($w['days'])) ? array_map('intval', $w['days']) : array();
    $s = rides_time_to_minutes(isset($w['start']) ? $w['start'] : '00:00');
    $e = rides_time_to_minutes(isset($w['end']) ? $w['end'] : '00:00');
    foreach ($days as $d) {
        if ($d < 1 || $d > 7) continue;
        if ($e > $s) {
            for ($m = $s; $m < $e && $m < 1440; $m++) $slots[($d - 1) * 1440 + $m] = true;
        } else {
            for ($m = $s; $m < 1440; $m++) $slots[($d - 1) * 1440 + $m] = true;
            $next = ($d % 7);   // the morning part falls on the next day
            for ($m = 0; $m < $e && $m < 1440; $m++) $slots[$next * 1440 + $m] = true;
        }
    }
    return $slots;
}

function rides_peak_windows_warnings($windows) {
    $out = array();
    if (!is_array($windows) || !count($windows)) return $out;
    $dayNames = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
    $all = array();
    $per = array();
    foreach ($windows as $i => $w) {
        $per[$i] = rides_peak_slots($w);
        foreach ($per[$i] as $k => $v) $all[$k] = true;
    }
    $pct = round(count($all) * 100 / 10080.0);
    if ($pct >= 50) {
        $out[] = 'Surge prices apply during ' . $pct . ' percent of the week. Peak pricing should be for the busy hours only (for example about 20 to 30 percent of the week). Shorten the windows.';
    }
    $n = count($windows);
    for ($a = 0; $a < $n; $a++) {
        for ($b = $a + 1; $b < $n; $b++) {
            $first = -1; $cnt = 0;
            foreach ($per[$a] as $k => $v) { if (isset($per[$b][$k])) { $cnt++; if ($first < 0 || $k < $first) $first = $k; } }
            if ($cnt > 0) {
                $la = isset($windows[$a]['label']) && $windows[$a]['label'] !== '' ? $windows[$a]['label'] : ('Window ' . ($a + 1));
                $lb = isset($windows[$b]['label']) && $windows[$b]['label'] !== '' ? $windows[$b]['label'] : ('Window ' . ($b + 1));
                $mins = $first % 1440;
                $out[] = '"' . $la . '" and "' . $lb . '" overlap (about ' . round($cnt / 60.0, 1) . ' hours a week, first on '
                    . $dayNames[intval($first / 1440)] . ' at ' . sprintf('%02d:%02d', intval($mins / 60), $mins % 60)
                    . '). In an overlap the window higher in the list wins, so fix the times.';
            }
        }
    }
    foreach ($windows as $i => $w) {
        $m = isset($w['multiplier']) ? floatval($w['multiplier']) : 0;
        if ($m > 1.5) {
            $l = isset($w['label']) && $w['label'] !== '' ? $w['label'] : ('Window ' . ($i + 1));
            $out[] = '"' . $l . '" charges ' . $m . ' times the normal fare. Riders may refuse to pay more than 1.5 times.';
        }
    }
    return $out;
}

} // end function_exists guard
