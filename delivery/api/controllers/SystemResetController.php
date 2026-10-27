<?php
// ============================================================
// DANGER ZONE — Full System Reset
// This is the single most destructive action in the entire admin
// system: it can permanently delete real orders, rides, ratings, and
// user accounts. Every action here requires the 'system.reset'
// capability, which is NOT included in a generic Owner grant by
// default and must be added explicitly in the Roles panel - a
// deliberate extra step, on top of the normal capability system.
//
// The three actions below are designed to only work in sequence:
//   1) system_reset_preview  - read-only, shows exactly what would
//      be deleted/kept, no side effects.
//   2) system_reset_backup   - exports every affected row to a
//      downloadable CSV bundle and stamps a short-lived backup
//      token in the DB.
//   3) system_reset_execute  - requires a *recent, valid* backup
//      token from step 2 AND an exact-match confirmation phrase.
//      Refuses to run without both, so it is structurally
//      impossible to skip straight to deleting without a backup
//      already having been taken in this session.
// ============================================================

// Tables holding real transactional/user data - these are what get
// wiped (rows deleted, table structure kept). Verified 2026-08-25
// against a real database dump (etel_delivery.sql), not just grepped
// from PHP source - the grep-based version of this list had missed
// `drivers`, `ride_requests`, and `service_giver_ratings` (they exist
// in the schema but weren't referenced in the controller files that
// were checked), and had wrongly guessed a non-existent `ride_ratings`
// table (the real one is `service_giver_ratings`).
//
// Deliberately EXCLUDED from the wipe, and must stay excluded:
//   - schema_migrations   : migration-runner state - deleting this
//     would desync migrate.php from what's actually applied
//   - patch_log, updater_logs : operational/audit history, not user data
//   - audit_settings      : config (holds the code-audit proxy secret)
//   - app_canary_testers, app_release_channels : canary/release config
//   - bank_accounts, driver_goal_tiers, pricing_rules, ride_fare_rules, delivery_zones/tiers, promo codes, items/menu
//     and the other admin-set configuration: that is how the business is set up, not demo activity. They were
//     wiped before, which silently removed the company's own receiving bank accounts and the driver goals.
//     tests/ci/check_reset_coverage.py lists every table as wiped or kept, so a new table must be classified.
//   - geocode_cache       : just a lookup cache, safe either way but
//     not user data, so left alone
//   - rides_legacy_202606120827 : an old renamed backup table from a
//     prior migration, already empty (0 rows) as of this writing - an
//     archival snapshot, not live data. Left untouched on purpose;
//     don't add legacy_* tables here without checking each one first.
//
// Order matters: children before parents, to respect foreign keys
// even with FK checks temporarily relaxed as a safety net. Verified
// real FK constraints (from the dump): service_givers.user_id,
// service_giver_ratings.{service_giver_id,order_id,customer_id},
// order_assignments.{order_id,service_giver_id}, order_items.{order_id,item_id},
// equity_profit_distribution_items.distribution_id - all ON DELETE CASCADE.
// Most other tables (drivers, rides, ride_requests, orders) have NO
// DB-level FK constraint at all; the app manages those relationships
// in PHP, which is exactly why explicit per-table deletes (not just
// relying on cascade) are the correct approach here.
function equity_reset_table_plan() {
    return array(
        // Ride-related children first
        'ride_messages', 'ride_typing', 'ride_path_points', 'ride_issues',
        'ride_favorite_routes', 'ride_drivers', 'ride_requests', 'order_ratings',
        'service_giver_ratings', 'ride_counter_offers', 'ride_driver_exclusions', 'ride_offer_log',
        'ride_pin_fails', 'ride_tips', 'ride_promo_redemptions', 'ride_monitor_alerts', 'sos_alerts',
        // Order-related children
        'order_items', 'order_confirmations', 'order_assignments', 'scheduled_orders',
        'order_admin_actions', 'order_courier_offers', 'order_issues', 'order_message_reads', 'order_messages',
        'order_pay_log', 'order_payment_log', 'order_push_log', 'order_refunds', 'package_pin_tries',
        'promo_redemptions', 'loyalty_points', 'offline_requests',
        // Driver/service-giver related
        'driver_announcement_reads', 'driver_vehicle_change_requests',
        'driver_etel_money_topups', 'driver_payouts', 'driver_compliance_requests',
        'driver_shifts', 'driver_fuel_logs', 'courier_shifts', 'courier_fuel_logs', 'courier_cash_remittances',
        'sg_staff', 'sg_payout_change_requests', 'sg_cash_days', 'sg_expenses', 'sg_pos_sale_items',
        'sg_pos_sales', 'sg_stock_events', 'sg_business_audit', 'drivers',
        // Customer related
        'customer_addresses', 'customer_favorites', 'customer_messages', 'customer_referral_rewards',
        'equb_contributions', 'equb_payouts', 'equb_members', 'equb_participants',
        // Financial records
        'etelpay_user_overrides', 'wallet_transactions', 'user_wallets',
        'payment_retries', 'payment_claims', 'telebirr_payments', 'bank_payments', 'chapa_payments',
        // Push/session/security noise
        'push_subscriptions', 'push_tokens', 'login_attempts', 'password_resets', 'tg_chat_ids',
        'notifications', 'client_errors', 'csp_reports', 'user_display_prefs',
        // Parents last
        'rides', 'orders', 'service_givers', 'couriers',
        // users handled specially (partial delete, not full truncate)
    );
}

// Setup values that were never changed from the install seed. The reset leaves bank_accounts alone, so a placeholder
// number must be replaced by hand before real customers are told to pay into it. Shown in the preview.
function equity_reset_placeholder_warnings($pdo) {
    $w = array();
    try {
        $rows = $pdo->query("SELECT display_name, account_number FROM bank_accounts WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $n = preg_replace('/\D/', '', (string) $r['account_number']);
            if ($n === '' || $n === '1234567890' || $n === '9876543210') {
                $w[] = 'Bank account "' . $r['display_name'] . '" still has a placeholder number (' . ($r['account_number'] === '' ? 'empty' : $r['account_number']) . '). Enter the real one in Pricing > Bank accounts before launch.';
            }
        }
    } catch (Exception $e) { /* no bank_accounts table on this install */ }
    try {
        $demo = $pdo->query("SELECT COUNT(*) FROM users WHERE phone IN ('0900000000','+251911000001','+251911000002') OR name LIKE '%(System Test)%'")->fetchColumn();
        if ((int) $demo > 0) $w[] = $demo . ' demo or system-test account(s) exist (for example the seed admin 0900000000). Do not list their phones under "keep", and change the password of any seed login you keep.';
    } catch (Exception $e) { }
    return $w;
}

switch ($action) {

case 'system_reset_preview': {
    $pdo = db();
    require_admin($pdo, 'system.reset');
    $keepPhones = array();
    if (isset($body['keep_phones']) && is_array($body['keep_phones'])) {
        foreach ($body['keep_phones'] as $p) {
            $clean = preg_replace('/[^0-9+]/', '', trim((string) $p));
            if ($clean !== '') $keepPhones[] = $clean;
        }
    }
    $counts = array();
    foreach (equity_reset_table_plan() as $t) {
        try {
            $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        } catch (Exception $e) {
            $counts[$t] = null; // table doesn't exist on this install - skip silently in execute too
        }
    }
    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $keptUsers = array();
    if (!empty($keepPhones)) {
        $placeholders = implode(',', array_fill(0, count($keepPhones), '?'));
        $s = $pdo->prepare("SELECT id, name, phone, role FROM users WHERE phone IN ($placeholders)");
        $s->execute($keepPhones);
        $keptUsers = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $notMatched = array_values(array_diff($keepPhones, array_map(function ($u) { return $u['phone']; }, $keptUsers)));
    ok(array(
        'table_counts' => $counts,
        'total_users' => $totalUsers,
        'users_to_keep' => $keptUsers,
        'users_to_delete' => $totalUsers - count($keptUsers),
        'phones_not_found' => $notMatched,
        'setup_warnings' => equity_reset_placeholder_warnings($pdo),
        'admins_kept' => count(array_filter($keptUsers, function ($u) { return $u['role'] === 'admin'; })),
        'warning' => 'This is a preview only. Nothing has been deleted or backed up yet.',
    ));
    break;
}

case 'system_reset_backup': {
    $pdo = db();
    $u = require_admin($pdo, 'system.reset');
    while (ob_get_level()) { @ob_end_clean(); }
    $tmpZip = sys_get_temp_dir() . '/etel-reset-backup-' . time() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) fail('Could not create backup archive', 500);
    $tables = array_merge(equity_reset_table_plan(), array('users'));
    foreach ($tables as $t) {
        try {
            $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            continue; // table doesn't exist on this install
        }
        $csv = '';
        if (!empty($rows)) {
            $csv .= implode(',', array_keys($rows[0])) . "\n";
            foreach ($rows as $r) {
                $csv .= implode(',', array_map(function ($v) {
                    return '"' . str_replace('"', '""', (string) $v) . '"';
                }, $r)) . "\n";
            }
        }
        $zip->addFromString($t . '.csv', $csv);
    }
    $zip->close();
    // Stamp a short-lived backup token (valid 60 minutes) so
    // system_reset_execute can verify a real backup was just taken in
    // this session before it will run - this is the mechanism that
    // makes it structurally impossible to skip straight to delete.
    $token = bin2hex(random_bytes(24));
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_reset_tokens (token VARCHAR(64) PRIMARY KEY, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->prepare('INSERT INTO system_reset_tokens (token, created_by) VALUES (?,?)')->execute(array($token, $u['id']));
    // Stream the zip, then append the token as a trailing response
    // header (can't mix binary body + JSON) - the frontend reads it
    // from this custom header.
    header('X-Backup-Token: ' . $token);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="etel-full-backup-' . date('Y-m-d-His') . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

case 'system_reset_execute': {
    $pdo = db();
    $u = require_admin($pdo, 'system.reset');
    $confirmPhrase = isset($body['confirm_phrase']) ? trim($body['confirm_phrase']) : '';
    if ($confirmPhrase !== 'DELETE EVERYTHING') {
        fail('Confirmation phrase does not match. Type exactly: DELETE EVERYTHING', 400);
    }
    $backupToken = isset($body['backup_token']) ? trim($body['backup_token']) : '';
    if ($backupToken === '') fail('A valid recent backup token is required. Run the Backup step first.', 400);
    try {
        $s = $pdo->prepare('SELECT created_at FROM system_reset_tokens WHERE token=? AND created_by=?');
        $s->execute(array($backupToken, $u['id']));
        $row = $s->fetch();
    } catch (Exception $e) {
        fail('Backup token table missing - run the Backup step first', 400);
    }
    if (!$row) fail('Backup token not found or does not belong to your session. Run the Backup step again.', 400);
    if (strtotime($row['created_at']) < time() - 3600) {
        fail('That backup is more than an hour old - please take a fresh backup immediately before resetting, in case anything changed since.', 400);
    }
    $keepPhones = array();
    if (isset($body['keep_phones']) && is_array($body['keep_phones'])) {
        foreach ($body['keep_phones'] as $p) {
            $clean = preg_replace('/[^0-9+]/', '', trim((string) $p));
            if ($clean !== '') $keepPhones[] = $clean;
        }
    }
    // Never lock everyone out: at least one admin account must be among the phones to keep.
    $keepAdmins = 0;
    if (!empty($keepPhones)) {
        $ph = implode(',', array_fill(0, count($keepPhones), '?'));
        $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND phone IN ($ph)");
        $q->execute($keepPhones);
        $keepAdmins = (int) $q->fetchColumn();
    }
    if ($keepAdmins < 1) {
        fail('The phone numbers to keep must include at least one admin account, otherwise nobody could sign in to the admin after the reset. Nothing was deleted.', 400);
    }
    $log = array();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->beginTransaction();
        foreach (equity_reset_table_plan() as $t) {
            try {
                $n = $pdo->exec("DELETE FROM `$t`");
                $log[$t] = $n === false ? 'skipped (error)' : $n . ' rows deleted';
            } catch (Exception $e) {
                $log[$t] = 'skipped (table not present)';
            }
        }
        if (empty($keepPhones)) {
            $n = $pdo->exec('DELETE FROM users');
            $log['users'] = $n . ' rows deleted (no phones were given to preserve)';
        } else {
            $placeholders = implode(',', array_fill(0, count($keepPhones), '?'));
            $s = $pdo->prepare("DELETE FROM users WHERE phone NOT IN ($placeholders)");
            $s->execute($keepPhones);
            $log['users'] = $s->rowCount() . ' rows deleted, ' . count($keepPhones) . ' phone(s) preserved';
        }
        $pdo->exec('DELETE FROM system_reset_tokens WHERE token=' . $pdo->quote($backupToken));
        $pdo->commit();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        ok(array('message' => 'Full system reset complete', 'log' => $log));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        fail_ex($e, 'Reset failed partway through - check the log and your backup before retrying', 500);
    }
    break;
}

}
