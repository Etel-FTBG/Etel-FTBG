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
    // Builds a 6-month GROWTH STORY, not one flat sample month - the
    // platform hasn't launched yet, so there's no real trip history to
    // seed from; a single static month doesn't tell an investor anything
    // about trajectory. Illustrates, end to end: early unprofitable
    // months turning profitable as trips/day ramps up, investors joining
    // at different points, Tranche 2 unlocking mid-story the same real
    // way the admin dashboard's own milestone trigger works (first month
    // trips/day crosses 400), an early investor completing payback and
    // dropping to the standard 1.0x rate, and unsold pool shares left
    // over (room for new investors, not a hard sell-out) - all clearly
    // tagged DEMO and fully separate from real data throughout.
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $actor = eg_current_actor()['full_name'];

    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[] = date('Y-m-01', strtotime("-$i months", strtotime(date('Y-m-01'))));
    }
    // Illustrative trips/day per month (there's no real data yet) and a
    // rough average fare/trip, used only to derive a plausible gross
    // fare total per month (trips/day * days in month * avg fare).
    $tripsPerDayByMonth = [60, 140, 230, 320, 410, 480];
    $avgFarePerTrip = 150.0;

    $investorsPlan = [
        ['month_index' => 0, 'name' => 'Yonas Bekele (DEMO)', 'email' => 'demo.yonas@example.com', 'phone' => '+251911000001', 'shares' => 300, 'tranche' => 1, 'amount' => 300000],
        ['month_index' => 2, 'name' => 'Selamawit Girma (DEMO)', 'email' => 'demo.selam@example.com', 'phone' => '+251911000002', 'shares' => 100, 'tranche' => 1, 'amount' => 100000],
        ['month_index' => 4, 'name' => 'Abel Tesfaye (DEMO)', 'email' => 'demo.abel@example.com', 'phone' => '+251911000003', 'shares' => 250, 'tranche' => 2, 'amount' => 250000],
        ['month_index' => 5, 'name' => 'Mekdes Alemu (DEMO)', 'email' => 'demo.mekdes@example.com', 'phone' => '+251911000004', 'shares' => 150, 'tranche' => 2, 'amount' => 150000],
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
        foreach ($investorsPlan as $inv) {
            $hash = password_hash('demo1234', PASSWORD_DEFAULT);
            $insInv->execute([$inv['name'], $inv['email'], $inv['phone'], $inv['email'], $hash]);
            $id = (int)$pdo->lastInsertId();
            $investorIds[] = $id;
            $joinDate = date('Y-m-05', strtotime($months[$inv['month_index']]));
            $insPurchase->execute([$id, $joinDate, $inv['shares'], $inv['tranche'], $inv['amount']]);
        }

        $commissionRate = ir_commission_rate($pdo);
        // Reuses whatever REAL active cost categories are currently
        // configured (prorated per demo month, same as a real run) so
        // the demo's cost side matches the admin's actual entered
        // numbers rather than an unrelated illustrative figure - falls
        // back to zero costs if none are configured yet.
        $costCats = $pdo->query('SELECT monthly_amount FROM ir_cost_categories WHERE is_active = 1')->fetchAll();

        $insPeriod = $pdo->prepare(
            'INSERT INTO ir_distribution_periods
             (period_start, period_end, commission_rate, gross_fare_total, commission_revenue, total_costs, net_profit,
              pool_entitlement, sold_shares, unsold_shares, unsold_bounce_theoretical, unsold_bounce_to_investors,
              unsold_bounce_to_founder, total_investor_payout, founder_amount, is_demo, locked_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)'
        );
        $insLine = $pdo->prepare(
            'INSERT INTO ir_distribution_investor_lines
             (period_id, investor_id, shares_at_time, invested_principal_at_time, cumulative_received_before,
              payback_phase, multiplier, base_entitlement, bounce_share, total_payout, cumulative_received_after)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        $tranche2UnlockedByDemo = false;
        foreach ($months as $mi => $monthStart) {
            $periodStart = $monthStart;
            $periodEnd = date('Y-m-t', strtotime($monthStart));
            $tripsPerDay = $tripsPerDayByMonth[$mi];
            $daysInMonth = (int)date('t', strtotime($monthStart));
            $grossFare = $tripsPerDay * $daysInMonth * $avgFarePerTrip;

            // Same 400 trips/day trigger the real milestone panel uses -
            // only touches Tranche 2 if it isn't already really unlocked.
            if ($tripsPerDay >= 400 && !$tranche2UnlockedByDemo) {
                $already = (bool)$pdo->query('SELECT is_unlocked FROM ir_tranches WHERE tranche_number = 2')->fetchColumn();
                if (!$already) {
                    $pdo->prepare(
                        "UPDATE ir_tranches SET is_unlocked=1, unlocked_at=?, unlocked_note=?, unlocked_by='(DEMO)' WHERE tranche_number=2"
                    )->execute([$periodEnd . ' 00:00:00', "DEMO: hit $tripsPerDay trips/day in " . date('F Y', strtotime($monthStart))]);
                }
                $tranche2UnlockedByDemo = true;
            }

            $holdings = ir_investor_holdings($pdo, true, $periodEnd);
            $investorsForEngine = [];
            foreach ($holdings as $h) {
                $cum = ir_cumulative_received_before($pdo, (int)$h['id'], $periodStart, true);
                $investorsForEngine[] = [
                    'id' => (int)$h['id'],
                    'shares_owned' => (int)$h['shares_owned'],
                    'total_invested' => (float)$h['total_invested'],
                    'cumulative_received_before' => $cum['cumulative'],
                ];
            }

            $result = ir_run_distribution($grossFare, $commissionRate, $costCats, $periodStart, $periodEnd, $investorsForEngine);

            $insPeriod->execute([
                $result['period_start'], $result['period_end'], $result['commission_rate'], $result['gross_fare_total'],
                $result['commission_revenue'], $result['total_costs'], $result['net_profit'], $result['pool_entitlement'],
                $result['sold_shares'], $result['unsold_shares'], $result['unsold_bounce_theoretical'],
                $result['unsold_bounce_to_investors'], $result['unsold_bounce_to_founder'], $result['total_investor_payout'],
                $result['founder_amount'], $actor . ' (DEMO)',
            ]);
            $periodId = (int)$pdo->lastInsertId();
            foreach ($result['lines'] as $line) {
                $insLine->execute([
                    $periodId, $line['investor_id'], $line['shares_at_time'], $line['invested_principal_at_time'],
                    $line['cumulative_received_before'], $line['payback_phase'] ? 1 : 0, $line['multiplier'],
                    $line['base_entitlement'], $line['bounce_share'], $line['total_payout'], $line['cumulative_received_after'],
                ]);
            }
        }

        ir_set_setting($pdo, 'demo_mode', '1');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail('Could not seed demo data: ' . $e->getMessage(), 500);
    }

    ir_log($pdo, $actor, 'setup_seed_demo', count($investorsPlan) . ' demo investors across ' . count($months) . ' months');
    ok(['seeded_investors' => count($investorsPlan), 'seeded_periods' => count($months)]);
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
        // Revert a Tranche 2 unlock ONLY if the demo itself performed it
        // (marked unlocked_by='(DEMO)') - never touches a real admin unlock.
        $pdo->exec("UPDATE ir_tranches SET is_unlocked=0, unlocked_at=NULL, unlocked_note='', unlocked_by=''
                     WHERE tranche_number=2 AND unlocked_by='(DEMO)'");
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
