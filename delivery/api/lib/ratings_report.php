<?php
// Admin ratings report: what customers said about shops, couriers and food, with the low ratings pulled out.
//   etel_ratings_report($pdo, $days)  -> { days, summary, tags, couriers, shops, recent }
//   etel_low_ratings_count($pdo, $days) -> number of 1-2 star ratings in the last $days days (Needs attention row)
// A rating is 'low' when the overall stars are 1 or 2, or when the courier or food stars (if given) are 1.
// Names shown are first names only for customers; no phone numbers.

define('ETEL_LOW_STARS', 2);

function etel_ratings_days($d) {
    $d = (int) $d;
    return in_array($d, array(7, 30, 90), true) ? $d : 30;
}

// Pure: is this rating "low" and worth staff attention?
function etel_rating_is_low($stars, $courier_rating, $food_rating) {
    if ($stars !== null && (int) $stars >= 1 && (int) $stars <= ETEL_LOW_STARS) return true;
    if ($courier_rating !== null && $courier_rating !== '' && (int) $courier_rating === 1) return true;
    if ($food_rating !== null && $food_rating !== '' && (int) $food_rating === 1) return true;
    return false;
}

// Pure: "late,cold" -> array('late','cold')
function etel_rating_tag_list($tags) {
    if (!is_string($tags) || $tags === '') return array();
    return array_values(array_filter(array_map('trim', explode(',', $tags))));
}

function etel_low_ratings_count($pdo, $days = 7) {
    try {
        $days = etel_ratings_days($days);
        $s = $pdo->prepare("SELECT COUNT(*) FROM order_ratings WHERE rated_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
            AND (stars <= " . ETEL_LOW_STARS . " OR courier_rating = 1 OR food_rating = 1)");
        $s->execute();
        return (int) $s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function etel_ratings_report($pdo, $days = 30) {
    $days = etel_ratings_days($days);
    $out = array('days' => $days,
        'summary' => array('count' => 0, 'avg' => null, 'avg_courier' => null, 'avg_food' => null, 'low' => 0, 'stars' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0)),
        'tags' => array(), 'couriers' => array(), 'shops' => array(), 'recent' => array());
    $where = "r.rated_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    try {
        $s = $pdo->query("SELECT COUNT(*) AS n, AVG(r.stars) AS a, AVG(r.courier_rating) AS ac, AVG(r.food_rating) AS af,
                SUM(r.stars = 1) AS s1, SUM(r.stars = 2) AS s2, SUM(r.stars = 3) AS s3, SUM(r.stars = 4) AS s4, SUM(r.stars = 5) AS s5
            FROM order_ratings r WHERE $where")->fetch(PDO::FETCH_ASSOC);
        $out['summary']['count'] = (int) $s['n'];
        $out['summary']['avg'] = $s['a'] !== null ? round((float) $s['a'], 2) : null;
        $out['summary']['avg_courier'] = $s['ac'] !== null ? round((float) $s['ac'], 2) : null;
        $out['summary']['avg_food'] = $s['af'] !== null ? round((float) $s['af'], 2) : null;
        for ($i = 1; $i <= 5; $i++) $out['summary']['stars'][$i] = (int) $s['s' . $i];
    } catch (Exception $e) { return $out; }
    $out['summary']['low'] = etel_low_ratings_count($pdo, $days);

    // most common tags (the bad ones first matter most to staff, so both lists are kept and the app colours them)
    try {
        $count = array();
        foreach ($pdo->query("SELECT r.tags FROM order_ratings r WHERE $where AND r.tags IS NOT NULL AND r.tags <> ''")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            foreach (etel_rating_tag_list($t) as $k) $count[$k] = isset($count[$k]) ? $count[$k] + 1 : 1;
        }
        arsort($count);
        foreach ($count as $k => $n) $out['tags'][] = array('tag' => $k, 'n' => $n);
    } catch (Exception $e) {}

    // lowest-rated couriers (courier stars only) and shops (overall stars)
    try {
        $out['couriers'] = $pdo->query("SELECT c.id, c.courier_code, COALESCE(u.name, c.courier_code) AS name, COUNT(*) AS n, ROUND(AVG(r.courier_rating), 2) AS avg
            FROM order_ratings r JOIN orders o ON o.id = r.order_id JOIN couriers c ON c.id = o.courier_id LEFT JOIN users u ON u.id = c.user_id
            WHERE $where AND r.courier_rating IS NOT NULL GROUP BY c.id, c.courier_code, u.name ORDER BY avg ASC, n DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    try {
        $out['shops'] = $pdo->query("SELECT sg.id, sg.business_name AS name, COUNT(*) AS n, ROUND(AVG(r.stars), 2) AS avg
            FROM order_ratings r JOIN order_assignments oa ON oa.order_id = r.order_id JOIN service_givers sg ON sg.id = oa.service_giver_id
            WHERE $where GROUP BY sg.id, sg.business_name ORDER BY avg ASC, n DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // newest ratings, low ones flagged
    try {
        $rows = $pdo->query("SELECT r.id, r.order_id, r.stars, r.food_rating, r.courier_rating, r.tags, r.comment, r.rated_at,
                o.order_code, SUBSTRING_INDEX(TRIM(COALESCE(cu.name, '')), ' ', 1) AS customer, sg.business_name AS shop, COALESCE(du.name, c.courier_code) AS courier
            FROM order_ratings r JOIN orders o ON o.id = r.order_id
            LEFT JOIN users cu ON cu.id = r.customer_id
            LEFT JOIN order_assignments oa ON oa.order_id = r.order_id LEFT JOIN service_givers sg ON sg.id = oa.service_giver_id
            LEFT JOIN couriers c ON c.id = o.courier_id LEFT JOIN users du ON du.id = c.user_id
            WHERE $where ORDER BY r.rated_at DESC, r.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $r['low'] = etel_rating_is_low($r['stars'], $r['courier_rating'], $r['food_rating']);
            $r['tags'] = etel_rating_tag_list($r['tags']);
            $out['recent'][] = $r;
        }
    } catch (Exception $e) {}
    return $out;
}
