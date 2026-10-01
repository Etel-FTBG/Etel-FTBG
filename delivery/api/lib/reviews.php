<?php
// Ratings & reviews helpers.
//  - Quick "tags" a customer can tick on a review (whitelisted keys; labels live in the apps).
//  - Extra columns on order_ratings: tags, shop_reply, shop_replied_at (created on first use).
//  - Keeps service_givers.average_rating / rating_count in step with the real ratings. Before
//    this, nothing ever updated those columns, so shop cards always showed no rating.

define('ETEL_REVIEW_TAGS', 'fast_delivery,great_taste,well_packed,friendly_courier,good_value,late,cold,wrong_item');

function etel_ensure_review_columns($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = etel_table_columns($pdo, 'order_ratings');
        if (!$cols) return;
        if (!in_array('tags', $cols, true))            $pdo->exec("ALTER TABLE order_ratings ADD COLUMN tags VARCHAR(255) NULL DEFAULT NULL");
        if (!in_array('shop_reply', $cols, true))      $pdo->exec("ALTER TABLE order_ratings ADD COLUMN shop_reply VARCHAR(500) NULL DEFAULT NULL");
        if (!in_array('shop_replied_at', $cols, true)) $pdo->exec("ALTER TABLE order_ratings ADD COLUMN shop_replied_at DATETIME NULL DEFAULT NULL");
    } catch (Exception $e) {
        error_log('[ETEL] etel_ensure_review_columns: ' . $e->getMessage());
    }
}

// Pure: keep only known tag keys (max 6, no duplicates) and return them comma-separated ('' if none).
function etel_review_clean_tags($tags) {
    if (is_string($tags)) $tags = explode(',', $tags);
    if (!is_array($tags)) return '';
    $allowed = explode(',', ETEL_REVIEW_TAGS);
    $out = array();
    foreach ($tags as $t) {
        $t = trim((string)$t);
        if ($t !== '' && in_array($t, $allowed, true) && !in_array($t, $out, true)) $out[] = $t;
        if (count($out) >= 6) break;
    }
    return implode(',', $out);
}

// Pure: 1..5 or null.
function etel_review_star($v) {
    if ($v === null || $v === '') return null;
    $n = intval($v);
    return ($n >= 1 && $n <= 5) ? $n : null;
}

// Recompute the shop's stored average/count from its ratings (shop found via the order's assignment).
function etel_review_update_shop_aggregate($pdo, $order_id) {
    try {
        $s = $pdo->prepare('SELECT service_giver_id FROM order_assignments WHERE order_id = ? LIMIT 1');
        $s->execute(array(intval($order_id)));
        $sg = intval($s->fetchColumn());
        if ($sg <= 0) return false;
        $a = $pdo->prepare('SELECT AVG(r.stars) AS avg, COUNT(*) AS cnt FROM order_ratings r JOIN order_assignments oa ON oa.order_id = r.order_id WHERE oa.service_giver_id = ?');
        $a->execute(array($sg));
        $row = $a->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('UPDATE service_givers SET average_rating = ?, rating_count = ? WHERE id = ?')
            ->execute(array(round(floatval($row['avg']), 2), intval($row['cnt']), $sg));
        return true;
    } catch (Exception $e) { error_log('[ETEL] review aggregate failed (non-fatal): ' . $e->getMessage()); return false; }
}
