<?php
// Courier approval helpers (shared by boot.php and the dispatch libraries; no dependency on boot.php).

/* Courier approval: a courier who signs up on their own waits for an admin to approve them before they can go online or
   take any job. The column is added on first use; every courier that already exists counts as approved. */
function etel_courier_approval_ready($pdo) {
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM couriers LIKE 'approval_status'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE couriers ADD COLUMN approval_status VARCHAR(10) NOT NULL DEFAULT 'approved', ADD COLUMN approval_note VARCHAR(200) NULL DEFAULT NULL, ADD COLUMN approval_at DATETIME NULL DEFAULT NULL");
        }
        $ready = true;
    } catch (Exception $e) { error_log('[ETEL] courier approval column: ' . $e->getMessage()); }
    return $ready;
}
/* ' AND c.approval_status = \'approved\'' for dispatch queries, or '' when the column is not there yet. */
function etel_courier_approved_sql($pdo, $alias = 'c') {
    return etel_courier_approval_ready($pdo) ? " AND $alias.approval_status = 'approved'" : '';
}
/* True when new self-registered couriers must be approved first (pricing_rules.courier_require_approval, default on). */
function etel_courier_requires_approval($pdo) {
    try {
        $q = $pdo->prepare("SELECT rule_value FROM pricing_rules WHERE rule_key = 'courier_require_approval' LIMIT 1");
        $q->execute();
        $v = $q->fetchColumn();
        return $v === false ? true : intval($v) !== 0;
    } catch (Exception $e) { return true; }
}
