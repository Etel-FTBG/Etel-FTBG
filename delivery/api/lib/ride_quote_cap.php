<?php
/* ETEL Ride - fare versus quote (launch-readiness phase 2).
 *
 * The rider is shown an estimate before booking. The final fare comes from the real meter, so it can differ.
 * Rule chosen by the owner: WARN the rider while the meter runs above the estimate, and CAP the final fare at
 * (estimate + X percent). X is the pricing rule 'ride_quote_cap_pct' (default 25, 0 = no cap).
 * The cap does not apply to open trips (no destination, no real estimate) or to trips with an agreed price
 * (price offers already bill exactly the agreed price). Ride 372 is the reason: quote 205.68, charged 527.41.
 * Plain PHP5 style, ASCII only.
 */
if (!function_exists('rides_quote_cap_pct')) {

function rides_quote_cap_pct($pdo) {
    $p = rides_rule_value($pdo, 'ride_quote_cap_pct', 25);
    return max(0, min(200, floatval($p)));
}

/* Pure maths. $agreed = the agreed price or null. Returns array('applies'=>false) when the rule does not apply. */
function rides_quote_cap_info($pct, $estimate, $fare, $openTrip, $agreed) {
    $estimate = floatval($estimate);
    $fare = floatval($fare);
    if ($openTrip || $estimate <= 0 || ($agreed !== null && floatval($agreed) > 0)) {
        return array('applies' => false, 'fare' => round($fare, 2));
    }
    $cap = round($estimate * (1 + $pct / 100.0), 2);
    $capped = ($pct > 0 && $fare > $cap + 0.004);
    $final = $capped ? $cap : $fare;
    return array(
        'applies' => true,
        'estimate' => round($estimate, 2),
        'pct' => $pct,
        'cap' => ($pct > 0 ? $cap : null),
        'uncapped' => round($fare, 2),
        'fare' => round($final, 2),
        'capped' => $capped,
        'over' => ($final > $estimate + 0.5)
    );
}

/* Fields added to the live (in progress) ride answer so the rider sees the estimate and a warning. */
function rides_quote_cap_live($pdo, $ride, $liveFare) {
    $agreed = (isset($ride['agreed_fare']) && $ride['agreed_fare'] !== null) ? $ride['agreed_fare'] : null;
    $info = rides_quote_cap_info(rides_quote_cap_pct($pdo),
        isset($ride['fare_estimate']) ? $ride['fare_estimate'] : 0, $liveFare, intval(isset($ride['open_trip']) ? $ride['open_trip'] : 0) === 1, $agreed);
    if (empty($info['applies'])) return array();
    $out = array(
        'quote_estimate' => $info['estimate'],
        'quote_cap' => $info['cap'],
        'quote_over' => $info['over'] ? 1 : 0,
        'quote_capped' => $info['capped'] ? 1 : 0
    );
    if ($info['capped']) $out['fare_total'] = $info['fare'];
    return $out;
}

/* At arrival: caps fare_final in the database, stores the numbers in the saved breakdown, opens one review
   item for the office when a cap was used. Returns the fare to bill. Never throws. */
function rides_quote_cap_apply($pdo, $rideId, $fare) {
    try {
        if (function_exists('rides_offer_price_ensure')) rides_offer_price_ensure($pdo);
        $q = $pdo->prepare("SELECT fare_estimate, agreed_fare, open_trip, driver_id, fare_breakdown_json FROM rides WHERE id=? LIMIT 1");
        $q->execute(array($rideId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $fare;
        $agreed = ($r['agreed_fare'] !== null && floatval($r['agreed_fare']) > 0) ? $r['agreed_fare'] : null;
        $info = rides_quote_cap_info(rides_quote_cap_pct($pdo), $r['fare_estimate'], $fare, intval($r['open_trip']) === 1, $agreed);
        if (empty($info['applies'])) return $fare;
        $bj = json_decode((string)$r['fare_breakdown_json'], true);
        if (!is_array($bj)) $bj = array();
        $bj['quote_estimate'] = $info['estimate'];
        $bj['quote_cap_pct'] = $info['pct'];
        $bj['quote_cap'] = $info['cap'];
        $bj['quote_over'] = $info['over'] ? 1 : 0;
        $bj['quote_capped'] = $info['capped'] ? 1 : 0;
        if ($info['capped']) {
            $bj['quote_uncapped'] = $info['uncapped'];
            $bj['fare_total'] = $info['fare'];
            $pdo->prepare("UPDATE rides SET fare_final=?, fare_breakdown_json=? WHERE id=?")->execute(array($info['fare'], json_encode($bj), $rideId));
            if (function_exists('rides_sweeper_issue')) {
                $d = $pdo->prepare("SELECT 1 FROM ride_issues WHERE ride_id=? AND category='fare_review' AND details LIKE 'Fare capped%' LIMIT 1");
                $d->execute(array($rideId));
                if (!$d->fetchColumn()) {
                    rides_sweeper_issue($pdo, $rideId, intval($r['driver_id']), 'fare_review',
                        'Fare capped at ETB ' . number_format($info['fare'], 2) . ' (estimate ETB ' . number_format($info['estimate'], 2)
                        . ' + ' . $info['pct'] . ' percent). The meter said ETB ' . number_format($info['uncapped'], 2)
                        . '. Check why the trip was longer than quoted.', 'open');
                }
            }
        } else {
            $pdo->prepare("UPDATE rides SET fare_breakdown_json=? WHERE id=?")->execute(array(json_encode($bj), $rideId));
        }
        return $info['fare'];
    } catch (Exception $e) {
        error_log('[ETEL] rides_quote_cap_apply: ' . $e->getMessage());
        return $fare;
    }
}

} // end function_exists guard
