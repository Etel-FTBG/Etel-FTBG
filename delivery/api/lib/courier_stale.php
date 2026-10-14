<?php
// Take an order back from a courier who never started it.
//
// Auto-dispatch hands an order to ONE courier ('assigned') and nothing took it back if that courier never reacted
// (phone off, app closed, courier asleep). Two live orders sat assigned for over 4 hours. With this on (default 25
// minutes, Admin > Couriers, 0 = off) an order that is still 'assigned' and not accepted after that time is released,
// the courier is freed, and the order goes to the next nearest courier who has not already had it (up to the same
// "couriers per order" limit as job offers). When nobody is left it stays in the open job list and the system
// health page flags it.
// Skipped: orders with an open job offer (courier_offers.php handles those), and orders the courier already accepted.
// Runs from the 5-minute cron and, cheaply, whenever a courier / shop / customer screen polls.

function etel_stale_minutes($pdo) {
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='courier_stale_minutes' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v === false || $v === null || $v === '') return 25;
        return etel_stale_clean($v);
    } catch (Exception $e) { return 25; }
}

// Pure: 0 (off) or 10..180 minutes.
function etel_stale_clean($m) {
    $m = (int) $m;
    if ($m <= 0) return 0;
    if ($m < 10) $m = 10;
    if ($m > 180) $m = 180;
    return $m;
}

function etel_stale_save($pdo, $minutes) {
    $m = etel_stale_clean($minutes);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_stale_minutes', ?, 'Minutes before an assigned, unaccepted delivery order is taken back and offered to another courier (0 = off)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $m));
    return $m;
}

// orders.courier_assigned_at: when the courier got the order. Returns true when the column exists.
function etel_stale_has_col($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'courier_assigned_at'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN courier_assigned_at DATETIME NULL DEFAULT NULL");
            $has = (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'courier_assigned_at'")->fetch();
        }
    } catch (Exception $e) { error_log('[ETEL] courier_assigned_at column: ' . $e->getMessage()); $has = false; }
    return $has;
}

// Couriers that already had this order (offers history and earlier stale releases).
function etel_stale_tried($pdo, $order_id) {
    try {
        $s = $pdo->prepare('SELECT DISTINCT courier_id FROM order_courier_offers WHERE order_id = ?');
        $s->execute(array((int) $order_id));
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return array(); }
}

function etel_stale_sweep($pdo, $force = false) {
    static $ran = false;
    if ($ran && !$force) return 0;
    $ran = true;
    $mins = etel_stale_minutes($pdo);
    if ($mins <= 0) return 0;
    $n = 0;
    try {
        require_once __DIR__ . '/courier_offers.php';
        require_once __DIR__ . '/order_dispatch.php';
        etel_offers_ensure_schema($pdo); // order_courier_offers + courier_accepted_at / courier_offer_expires_at
        $has = etel_stale_has_col($pdo);
        // Orders handed out before this feature (no timestamp) wait three times as long, measured from when they were placed.
        $assignedCond = $has
            ? "((courier_assigned_at IS NOT NULL AND courier_assigned_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)) OR (courier_assigned_at IS NULL AND placed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)))"
            : "(placed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND ? > 0)";
        $q = $pdo->prepare("SELECT id, courier_id, order_code FROM orders
            WHERE status = 'assigned' AND courier_id IS NOT NULL AND courier_accepted_at IS NULL AND courier_offer_expires_at IS NULL
              AND placed_at > DATE_SUB(NOW(), INTERVAL 2 DAY) AND $assignedCond
            ORDER BY id LIMIT 10");
        $q->execute($has ? array($mins, $mins * 3) : array($mins * 3, 1));
        $max = 3;
        $st = etel_offer_settings($pdo);
        if (!empty($st['max_attempts'])) $max = (int) $st['max_attempts'];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $id = (int) $o['id']; $cid = (int) $o['courier_id'];
            // Atomic: only if it is still assigned to this courier and not accepted.
            $set = $has ? ", courier_assigned_at = NULL" : "";
            $u = $pdo->prepare("UPDATE orders SET courier_id = NULL, status = 'pending'$set WHERE id = ? AND courier_id = ? AND status = 'assigned' AND courier_accepted_at IS NULL");
            $u->execute(array($id, $cid));
            if ($u->rowCount() !== 1) continue;
            $n++;
            try {
                $pdo->prepare("INSERT INTO order_courier_offers (order_id, courier_id, offered_at, expires_at, response, responded_at) VALUES (?, ?, NOW(), NOW(), 'stale', NOW())")->execute(array($id, $cid));
            } catch (Exception $e) {}
            error_log('[ETEL] stale assignment: order ' . $o['order_code'] . ' taken back from courier #' . $cid . ' after ' . $mins . ' min');
            etel_release_idle_couriers($pdo); // frees the courier (busy -> online/offline)
            $tried = etel_stale_tried($pdo, $id);
            if (count($tried) < $max) etel_dispatch_order($pdo, $id, $tried);
        }
    } catch (Exception $e) { error_log('[ETEL] etel_stale_sweep: ' . $e->getMessage()); }
      catch (Throwable $e) { error_log('[ETEL] etel_stale_sweep: ' . $e->getMessage()); }
    return $n;
}

// ---------------------------------------------------------------------------------------------------------------
// Old open jobs are hidden from the courier job list.
// An order nobody picked up for hours (a test order, a shop that never prepared it) kept showing as a job to
// every courier ("Waiting 846:49"). A courier cannot do a job that old, and showing it makes the app look broken.
// After this many minutes (default 120, 0 = never hide, else 30..1440) an unaccepted order no longer appears in the
// courier's list. It is NOT cancelled: it still shows in Admin as "needs attention" so staff can act on it.
// ---------------------------------------------------------------------------------------------------------------

// Pure: 0 (off) or 30..1440 minutes.
function etel_job_age_clean($m) {
    $m = (int) $m;
    if ($m <= 0) return 0;
    if ($m < 30) $m = 30;
    if ($m > 1440) $m = 1440;
    return $m;
}

function etel_job_age_minutes($pdo) {
    try {
        $s = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key='courier_job_max_age_minutes' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v === false || $v === null || $v === '') return 120;
        return etel_job_age_clean($v);
    } catch (Exception $e) { return 120; }
}

function etel_job_age_save($pdo, $minutes) {
    $m = etel_job_age_clean($minutes);
    $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_job_max_age_minutes', ?, 'Minutes after which an unaccepted order is no longer shown in the courier job list (0 = never hide)')
                   ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")->execute(array((string) $m));
    return $m;
}

// SQL fragment for the courier job list query (orders aliased o). Safe to concatenate: the number is cast to int.
function etel_job_age_filter($pdo) {
    $m = etel_job_age_minutes($pdo);
    if ($m <= 0) return '';
    return ' AND o.placed_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $m . ' MINUTE) ';
}

