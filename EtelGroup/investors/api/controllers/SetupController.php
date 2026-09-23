<?php
// ============================================================
// Investor Relations — Setup wizard controller (setup_* actions)
// Every action here requires EtelGroup's own admin auth
// (eg_require_admin() - the same login as the rest of EtelGroup admin).
// ============================================================
declare(strict_types=1);

switch ($action) {

case 'setup_get_status': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $configured = ir_delivery_is_configured($pdo);
    $catCount = (int)$pdo->query('SELECT COUNT(*) FROM ir_cost_categories WHERE is_active = 1')->fetchColumn();
    ok([
        'delivery_connected' => $configured,
        'delivery_host' => ir_get_setting($pdo, 'delivery_db_host', ''),
        'delivery_db_name' => ir_get_setting($pdo, 'delivery_db_name', ''),
        'commission_rate' => ir_commission_rate($pdo),
        'cost_category_count' => $catCount,
        'demo_mode' => ir_is_demo_mode($pdo),
    ]);
    break;
}

case 'setup_save_delivery_connection': {
    eg_require_admin();
    $pdo = eg_db();
    $host = trim((string)($body['host'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    $user = trim((string)($body['user'] ?? ''));
    $pass = (string)($body['password'] ?? '');
    if ($host === '' || $name === '' || $user === '') {
        fail('Host, database name, and username are required.', 400);
    }
    ir_set_setting($pdo, 'delivery_db_host', $host);
    ir_set_setting($pdo, 'delivery_db_name', $name);
    ir_set_setting($pdo, 'delivery_db_user', $user);
    if ($pass !== '') {
        ir_set_setting($pdo, 'delivery_db_pass_enc', ir_encrypt($pass));
    }
    ir_log($pdo, eg_current_actor()['full_name'], 'setup_delivery_connection', "host=$host db=$name user=$user");
    ok(['saved' => true]);
    break;
}

case 'setup_test_delivery_connection': {
    eg_require_admin();
    $pdo = eg_db();
    try {
        $result = ir_delivery_test_connection($pdo);
        ok($result);
    } catch (Exception $e) {
        fail('Connection failed: ' . $e->getMessage(), 502);
    }
    break;
}

case 'setup_save_commission_rate': {
    eg_require_admin();
    $pdo = eg_db();
    $rate = (float)($body['rate'] ?? -1);
    if ($rate < 0 || $rate > 1) fail('Commission rate must be between 0 and 1 (e.g. 0.05 for 5%).', 400);
    ir_set_setting($pdo, 'commission_rate', (string)$rate);
    ir_log($pdo, eg_current_actor()['full_name'], 'setup_commission_rate', "rate=$rate");
    ok(['rate' => $rate]);
    break;
}

case 'setup_save_cost_categories': {
    // Bulk-create initial cost categories during the wizard. Later
    // edits go through admin_save_cost_category (which also logs
    // history) - this action is only for first-time seeding, and is
    // now guarded to run at most once: without this check, an admin
    // who has to retry an earlier wizard step (e.g. fixing the Delivery
    // connection) and lands back on this step re-submits it, appending
    // a full duplicate set of categories every time - exactly what
    // happened in practice before this guard existed.
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $existing = (int)$pdo->query('SELECT COUNT(*) FROM ir_cost_categories')->fetchColumn();
    if ($existing > 0) {
        fail('Cost categories already exist - edit them from the dashboard instead of re-running setup.', 409);
    }
    $cats = $body['categories'] ?? [];
    if (!is_array($cats) || !count($cats)) fail('Provide at least one cost category.', 400);
    $stmt = $pdo->prepare('INSERT INTO ir_cost_categories (name, monthly_amount, sort_order) VALUES (?, ?, ?)');
    $i = 0;
    foreach ($cats as $cat) {
        $name = trim((string)($cat['name'] ?? ''));
        $amount = (float)($cat['monthly_amount'] ?? 0);
        if ($name === '') continue;
        $stmt->execute([$name, $amount, $i++]);
    }
    ir_log($pdo, eg_current_actor()['full_name'], 'setup_cost_categories', "$i categories seeded");
    ok(['created' => $i]);
    break;
}

case 'setup_seed_demo': {
    // Seeds realistic sample investors + purchases + one locked sample
    // distribution period, all flagged is_demo=1, purely for showing
    // prospective investors how the system works. Never mixed into
    // real math - every query elsewhere filters is_demo appropriately.
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);

    $demoInvestors = [
        ['name' => 'Yonas Bekele (DEMO)', 'email' => 'demo.yonas@example.com', 'phone' => '+251911000001', 'shares' => 400, 'tranche' => 1, 'amount' => 400000],
        ['name' => 'Selamawit Girma (DEMO)', 'email' => 'demo.selam@example.com', 'phone' => '+251911000002', 'shares' => 150, 'tranche' => 2, 'amount' => 150000],
        ['name' => 'Abel Tesfaye (DEMO)', 'email' => 'demo.abel@example.com', 'phone' => '+251911000003', 'shares' => 100, 'tranche' => 2, 'amount' => 100000],
    ];

    $pdo->beginTransaction();
    try {
        $insInv = $pdo->prepare(
            'INSERT INTO ir_investors (name, contact_email, contact_phone, login_identifier, password_hash, is_demo)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $insPurchase = $pdo->prepare(
            'INSERT INTO ir_share_purchases (investor_id, purchase_date, shares, tranche_number, amount_paid, is_demo)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $investorIds = [];
        foreach ($demoInvestors as $d) {
            $hash = password_hash('demo1234', PASSWORD_DEFAULT);
            $insInv->execute([$d['name'], $d['email'], $d['phone'], $d['email'], $hash]);
            $id = (int)$pdo->lastInsertId();
            $investorIds[] = $id;
            $insPurchase->execute([$id, date('Y-m-d', strtotime('-90 days')), $d['shares'], $d['tranche'], $d['amount']]);
        }

        // One sample locked period so the demo has payout history to show.
        $investorsForEngine = [];
        foreach ($demoInvestors as $i => $d) {
            $investorsForEngine[] = [
                'id' => $investorIds[$i],
                'shares_owned' => $d['shares'],
                'total_invested' => $d['amount'],
                'cumulative_received_before' => 0,
            ];
        }
        $periodStart = date('Y-m-01', strtotime('-1 month'));
        $periodEnd = date('Y-m-t', strtotime('-1 month'));
        $result = ir_run_distribution(
            180000.0, // sample gross fares for the demo month
            ir_commission_rate($pdo),
            [], // no real cost categories deducted in the demo snapshot, keeps it simple/illustrative
            $periodStart, $periodEnd, $investorsForEngine
        );

        $insPeriod = $pdo->prepare(
            'INSERT INTO ir_distribution_periods
             (period_start, period_end, commission_rate, gross_fare_total, commission_revenue, total_costs, net_profit,
              pool_entitlement, sold_shares, unsold_shares, unsold_bounce_theoretical, unsold_bounce_to_investors,
              unsold_bounce_to_founder, total_investor_payout, founder_amount, is_demo, locked_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)'
        );
        $insPeriod->execute([
            $result['period_start'], $result['period_end'], $result['commission_rate'], $result['gross_fare_total'],
            $result['commission_revenue'], $result['total_costs'], $result['net_profit'], $result['pool_entitlement'],
            $result['sold_shares'], $result['unsold_shares'], $result['unsold_bounce_theoretical'],
            $result['unsold_bounce_to_investors'], $result['unsold_bounce_to_founder'], $result['total_investor_payout'],
            $result['founder_amount'], eg_current_actor()['full_name'] . ' (DEMO)',
        ]);
        $periodId = (int)$pdo->lastInsertId();

        $insLine = $pdo->prepare(
            'INSERT INTO ir_distribution_investor_lines
             (period_id, investor_id, shares_at_time, invested_principal_at_time, cumulative_received_before,
              payback_phase, multiplier, base_entitlement, bounce_share, total_payout, cumulative_received_after)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($result['lines'] as $line) {
            $insLine->execute([
                $periodId, $line['investor_id'], $line['shares_at_time'], $line['invested_principal_at_time'],
                $line['cumulative_received_before'], $line['payback_phase'] ? 1 : 0, $line['multiplier'],
                $line['base_entitlement'], $line['bounce_share'], $line['total_payout'], $line['cumulative_received_after'],
            ]);
        }

        ir_set_setting($pdo, 'demo_mode', '1');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail('Could not seed demo data: ' . $e->getMessage(), 500);
    }

    ir_log($pdo, eg_current_actor()['full_name'], 'setup_seed_demo', count($demoInvestors) . ' demo investors + 1 sample period');
    ok(['seeded_investors' => count($demoInvestors)]);
    break;
}

case 'setup_clear_demo': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $pdo->beginTransaction();
    try {
        $demoPeriodIds = $pdo->query('SELECT id FROM ir_distribution_periods WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
        if ($demoPeriodIds) {
            $in = implode(',', array_map('intval', $demoPeriodIds));
            $pdo->exec("DELETE FROM ir_distribution_investor_lines WHERE period_id IN ($in)");
            $pdo->exec("DELETE FROM ir_distribution_periods WHERE id IN ($in)");
        }
        $pdo->exec('DELETE FROM ir_share_purchases WHERE is_demo = 1');
        $pdo->exec('DELETE FROM ir_investors WHERE is_demo = 1');
        ir_set_setting($pdo, 'demo_mode', '0');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail('Could not clear demo data: ' . $e->getMessage(), 500);
    }
    ir_log($pdo, eg_current_actor()['full_name'], 'setup_clear_demo', '');
    ok(['cleared' => true]);
    break;
}

}
