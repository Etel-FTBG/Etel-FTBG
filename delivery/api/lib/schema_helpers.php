<?php
/**
 * ETEL Delivery - shared schema helpers
 *
 * Functions here are used by more than one controller. boot.php only
 * requires the ONE controller file needed for the current action, so a
 * helper defined inside a controller is invisible to every other
 * controller - see the 2026-08-19 postmortem in RideController.php's
 * git history (admin_topup_etel_money / admin_get_pending_etel_topups
 * threw "Call to undefined function rides_add_missing_cols" because
 * AdminController.php never loads RideController.php).
 *
 * Anything defined here must be required directly by every controller
 * that calls it - do not rely on another controller having pulled it
 * in first, since load order depends on which action was dispatched.
 */

if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

if (!function_exists('rides_add_missing_cols')) {
    function rides_add_missing_cols($pdo, $table, $cols) {
        /* Adds any missing columns to a pre-existing table (e.g. an older
           rides table left over from a previous ETEL Ride build). */
        $existing = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $c) {
            $existing[strtolower($c['Field'])] = true;
        }
        foreach ($cols as $name => $ddl) {
            if (!isset($existing[strtolower($name)])) {
                try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl"); }
                catch (Exception $e) { error_log('[ETEL][rides] add col ' . $name . ': ' . $e->getMessage()); }
            }
        }
    }
}
