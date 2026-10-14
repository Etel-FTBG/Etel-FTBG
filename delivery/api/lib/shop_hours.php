<?php
// Shop opening hours and "sold out today".
//   service_givers.opens_at / closes_at  (TIME, NULL = no hours set). A window that ends before it starts (22:00 -> 02:00) runs overnight.
//   items.sold_out_date                  (DATE). The item is sold out while this equals today's date, so it comes back by itself tomorrow.
// A shop is open when its switch is ON and (no hours are set OR now is inside the hours). The switch always wins: switching off
// closes the shop whatever the hours say. Never throws: a hours problem must not break browsing or ordering.

function etel_hours_ensure_columns($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $sg = $pdo->query("SHOW COLUMNS FROM service_givers LIKE 'opens_at'")->fetch();
        if (!$sg) {
            $pdo->exec("ALTER TABLE service_givers ADD COLUMN opens_at TIME NULL DEFAULT NULL, ADD COLUMN closes_at TIME NULL DEFAULT NULL");
        }
        $it = $pdo->query("SHOW COLUMNS FROM items LIKE 'sold_out_date'")->fetch();
        if (!$it) {
            $pdo->exec("ALTER TABLE items ADD COLUMN sold_out_date DATE NULL DEFAULT NULL");
        }
    } catch (Exception $e) { error_log('[ETEL] shop hours columns: ' . $e->getMessage()); }
}

// 'HH:MM' or 'HH:MM:SS' -> 'HH:MM', or null when it is not a real time.
function etel_hours_clean($t) {
    $t = trim((string) $t);
    if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $t, $m)) return null;
    return sprintf('%02d:%s', intval($m[1]), $m[2]);
}

// Is $now ('HH:MM') inside the window? No hours (either side empty, or the same time) = always inside.
function etel_hours_within($opens, $closes, $now) {
    $o = etel_hours_clean($opens); $c = etel_hours_clean($closes); $n = etel_hours_clean($now);
    if ($o === null || $c === null || $n === null || $o === $c) return true;
    if ($o < $c) return ($n >= $o && $n < $c);
    return ($n >= $o || $n < $c);   // overnight
}

// Returns array(open => bool, reason => ''|'switch'|'hours', opens_at => 'HH:MM'|null, closes_at => 'HH:MM'|null).
function etel_shop_state($is_online, $opens, $closes, $now) {
    $o = etel_hours_clean($opens); $c = etel_hours_clean($closes);
    $hasHours = ($o !== null && $c !== null && $o !== $c);
    $state = array('open' => true, 'reason' => '', 'opens_at' => $hasHours ? $o : null, 'closes_at' => $hasHours ? $c : null);
    if (!$is_online) { $state['open'] = false; $state['reason'] = 'switch'; return $state; }
    if ($hasHours && !etel_hours_within($o, $c, $now)) { $state['open'] = false; $state['reason'] = 'hours'; }
    return $state;
}

function etel_item_sold_out($sold_out_date, $today) {
    return $sold_out_date !== null && $sold_out_date !== '' && substr((string) $sold_out_date, 0, 10) === $today;
}

// Customer feed rows: make is_online mean "open right now" (hours included), mark sold-out items, and say why a shop is closed.
function etel_shop_feed_apply($rows, $now, $today) {
    foreach ($rows as $i => $r) {
        $st = etel_shop_state(!empty($r['is_online']), isset($r['opens_at']) ? $r['opens_at'] : null, isset($r['closes_at']) ? $r['closes_at'] : null, $now);
        $rows[$i]['is_online'] = $st['open'] ? 1 : 0;
        $rows[$i]['closed_reason'] = $st['reason'];
        $rows[$i]['opens_at'] = $st['opens_at'];
        $rows[$i]['closes_at'] = $st['closes_at'];
        $sold = etel_item_sold_out(isset($r['sold_out_date']) ? $r['sold_out_date'] : null, $today);
        $rows[$i]['sold_out'] = $sold ? 1 : 0;
        if ($sold) $rows[$i]['is_available'] = 0;
        unset($rows[$i]['sold_out_date']);
    }
    return $rows;
}

// Order-time check. Returns '' when every line can be ordered, or the message to show the customer.
// Hidden / sold-out items are always refused. A shop is refused only when it has HOURS set and is outside them
// (a shop that merely has its switch off keeps the old behaviour: the customer was warned and may still order).
function etel_order_blockers($pdo, $item_ids, $now = null, $today = null) {
    $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
    if (!$item_ids) return '';
    if ($now === null) $now = date('H:i');
    if ($today === null) $today = date('Y-m-d');
    try {
        etel_hours_ensure_columns($pdo);
        $ph = implode(',', array_fill(0, count($item_ids), '?'));
        $q = $pdo->prepare("SELECT i.id, i.name, COALESCE(i.is_available, 1) AS is_available, i.sold_out_date, sg.business_name, sg.opens_at, sg.closes_at
            FROM items i JOIN service_givers sg ON i.service_giver_id = sg.id WHERE i.id IN ($ph)");
        $q->execute($item_ids);
        foreach ($q->fetchAll() as $r) {
            if (intval($r['is_available']) !== 1) return '"' . $r['name'] . '" is not available right now. Please remove it from your cart.';
            if (etel_item_sold_out($r['sold_out_date'], $today)) return '"' . $r['name'] . '" is sold out for today. Please remove it from your cart.';
            $o = etel_hours_clean($r['opens_at']); $c = etel_hours_clean($r['closes_at']);
            if ($o !== null && $c !== null && $o !== $c && !etel_hours_within($o, $c, $now)) {
                return $r['business_name'] . ' is closed right now. It is open ' . $o . ' to ' . $c . '.';
            }
        }
    } catch (Exception $e) { error_log('[ETEL] order blockers: ' . $e->getMessage()); }
    return '';
}
