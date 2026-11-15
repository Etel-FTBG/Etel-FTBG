<?php
// ============================================================
// ETEL TAXI - Admin audit log  v1.0
// One row per admin action that changes something (verify/reject a payment,
// register a driver, edit a bank account, send a reminder ...). The table is
// created on first use, so no manual SQL is needed. Logging never breaks the
// action it records: any database problem here is swallowed.
// ============================================================

if (!function_exists('taxi_audit_ensure')) {

function taxi_audit_ensure($pdo) {
    static $made = false;
    if ($made) return;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . tbl('taxi_admin_audit') . ' (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            admin_id    INT UNSIGNED NULL,
            admin_label VARCHAR(120) NULL,
            action      VARCHAR(60)  NOT NULL,
            target_type VARCHAR(30)  NULL,
            target_id   INT UNSIGNED NULL,
            detail      VARCHAR(500) NULL,
            ip          VARCHAR(45)  NULL,
            created_at  DATETIME     NOT NULL,
            KEY idx_action (action),
            KEY idx_target (target_type, target_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $made = true;
    } catch (Exception $e) { /* logging is best effort */ }
}

/* $admin: the row require_admin_auth() returned. $detail: short plain text (cut to 500 chars). */
function taxi_audit($pdo, $admin, $action, $targetType = null, $targetId = null, $detail = '') {
    try {
        taxi_audit_ensure($pdo);
        $label = '';
        if (is_array($admin)) {
            foreach (array('full_name', 'name', 'username', 'phone', 'email') as $k) {
                if (!empty($admin[$k])) { $label = (string)$admin[$k]; break; }
            }
        }
        $detail = (string)$detail;
        if (strlen($detail) > 500) $detail = substr($detail, 0, 500);
        $pdo->prepare('INSERT INTO ' . tbl('taxi_admin_audit') . ' (admin_id, admin_label, action, target_type, target_id, detail, ip, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute(array(
            is_array($admin) && isset($admin['id']) ? intval($admin['id']) : null,
            $label !== '' ? substr($label, 0, 120) : null,
            substr((string)$action, 0, 60),
            $targetType,
            $targetId === null ? null : intval($targetId),
            $detail !== '' ? $detail : null,
            isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ));
    } catch (Exception $e) { /* never break the real action */ }
}

}
