<?php
// ============================================================
// Investor Relations — Admin controller (admin_* actions)
// Every action requires EtelGroup's own admin auth (eg_require_admin()).
// ============================================================
declare(strict_types=1);

// Sums a given investor's real, non-demo shares and amount invested
// from ir_share_purchases as of a given date (defaults to today).
// Demo purchases are included ONLY when $includeDemo is true (used for
// the demo distribution flow) - real distribution runs never see them.
switch ($action) {

// ---------- dashboard ----------
case 'admin_dashboard_summary': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);

    $delivery = ['connected' => ir_delivery_is_configured($pdo)];
    if ($delivery['connected']) {
        try {
            $milestone = ir_delivery_rolling_4week_avg($pdo);
            $delivery['milestone'] = $milestone;
            $delivery['error'] = null;
        } catch (Exception $e) {
            $delivery['error'] = $e->getMessage();
        }
    }

    $costTotal = (float)$pdo->query('SELECT COALESCE(SUM(monthly_amount),0) FROM ir_cost_categories WHERE is_active = 1')->fetchColumn();
    // Must match admin_list_investors' own demo-mode check below - this
    // was hardcoded to false, so the stat cards (Investors, Shares Sold,
    // Total Invested) always excluded demo investors even with Demo Mode
    // on, while the Investors table beneath them (which does check
    // ir_is_demo_mode()) correctly included them - two different counts
    // on the same screen.
    $includeDemo = ir_is_demo_mode($pdo);
    $holdings = ir_investor_holdings($pdo, $includeDemo);
    $soldShares = 0; $investedTotal = 0.0;
    foreach ($holdings as $h) { $soldShares += (int)$h['shares_owned']; $investedTotal += (float)$h['total_invested']; }

    $tranches = $pdo->query('SELECT * FROM ir_tranches ORDER BY tranche_number')->fetchAll();
    $periodSql = 'SELECT * FROM ir_distribution_periods';
    if (!$includeDemo) $periodSql .= ' WHERE is_demo = 0';
    $periodSql .= ' ORDER BY period_end DESC LIMIT 1';
    $lastPeriod = $pdo->query($periodSql)->fetch();

    ok([
        'delivery' => $delivery,
        'commission_rate' => ir_commission_rate($pdo),
        'monthly_cost_total' => $costTotal,
        'investor_count' => count($holdings),
        'sold_shares' => $soldShares,
        'unsold_shares' => IR_INVESTOR_POOL_SHARES - $soldShares,
        'invested_total' => $investedTotal,
        'tranches' => $tranches,
        'last_locked_period' => $lastPeriod ?: null,
        'demo_mode' => ir_is_demo_mode($pdo),
    ]);
    break;
}

case 'admin_get_milestone_progress': {
    eg_require_admin();
    $pdo = eg_db();
    try {
        $m = ir_delivery_rolling_4week_avg($pdo);
        ok([
            'connected' => true,
            'avg_per_day' => $m['avg_per_day'],
            'target' => 400,
            'progress_pct' => min(100, round(($m['avg_per_day'] / 400) * 100, 1)),
            'window' => ['from' => $m['from'], 'to' => $m['to']],
            'daily' => $m['daily'],
        ]);
    } catch (Exception $e) {
        ok(['connected' => false, 'error' => $e->getMessage()]);
    }
    break;
}

// ---------- cost categories ----------
case 'admin_list_cost_categories': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ok($pdo->query('SELECT * FROM ir_cost_categories ORDER BY sort_order, id')->fetchAll());
    break;
}

case 'admin_save_cost_category': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $amount = (float)($body['monthly_amount'] ?? -1);
    $isActive = !empty($body['is_active']) ? 1 : 0;
    if ($name === '' || $amount < 0) fail('Name and a non-negative monthly amount are required.', 400);
    $actor = eg_current_actor()['full_name'];

    if ($id > 0) {
        $old = $pdo->prepare('SELECT monthly_amount FROM ir_cost_categories WHERE id = ?');
        $old->execute([$id]);
        $oldAmount = $old->fetchColumn();
        if ($oldAmount === false) fail('Cost category not found', 404);
        $pdo->prepare('UPDATE ir_cost_categories SET name=?, monthly_amount=?, is_active=? WHERE id=?')
            ->execute([$name, $amount, $isActive, $id]);
        if ((float)$oldAmount !== $amount) {
            $pdo->prepare('INSERT INTO ir_cost_category_history (category_id, old_amount, new_amount, changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $oldAmount, $amount, $actor]);
        }
        ir_log($pdo, $actor, 'cost_category_updated', "$name -> $amount");
    } else {
        $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM ir_cost_categories')->fetchColumn();
        $pdo->prepare('INSERT INTO ir_cost_categories (name, monthly_amount, is_active, sort_order) VALUES (?,?,?,?)')
            ->execute([$name, $amount, $isActive, $maxSort + 1]);
        $id = (int)$pdo->lastInsertId();
        ir_log($pdo, $actor, 'cost_category_created', "$name = $amount");
    }
    ok(['id' => $id]);
    break;
}

case 'admin_get_cost_category_history': {
    eg_require_admin();
    $pdo = eg_db();
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) fail('Missing category id', 400);
    $s = $pdo->prepare('SELECT * FROM ir_cost_category_history WHERE category_id = ? ORDER BY changed_at DESC');
    $s->execute([$id]);
    ok($s->fetchAll());
    break;
}

case 'admin_delete_cost_category': {
    // Hard delete, unlike the is_active toggle in admin_save_cost_category -
    // for cleaning up genuine mistakes (e.g. an accidentally duplicated
    // category), not for the normal "stop counting this cost" workflow,
    // which should still use is_active so its history stays attached to
    // something visible. Leaves that category's ir_cost_category_history
    // rows in place (no FK cascade) - they simply become unreachable from
    // the UI once the category itself is gone, which is fine for this use.
    eg_require_admin();
    $pdo = eg_db();
    $id = (int)($body['id'] ?? 0);
    if (!$id) fail('Missing category id', 400);
    $s = $pdo->prepare('SELECT name FROM ir_cost_categories WHERE id = ?');
    $s->execute([$id]);
    $name = $s->fetchColumn();
    if ($name === false) fail('Cost category not found', 404);
    $pdo->prepare('DELETE FROM ir_cost_categories WHERE id = ?')->execute([$id]);
    ir_log($pdo, eg_current_actor()['full_name'], 'cost_category_deleted', "$name (#$id)");
    ok(['deleted' => true]);
    break;
}

// ---------- investors ----------
case 'admin_list_investors': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $includeDemo = ir_is_demo_mode($pdo);
    $holdings = ir_investor_holdings($pdo, $includeDemo);
    $out = [];
    foreach ($holdings as $h) {
        $cum = ir_cumulative_received_before($pdo, (int)$h['id'], date('Y-m-d', strtotime('+1 day')), $includeDemo);
        $paybackPhase = $cum['cumulative'] < (float)$h['total_invested'];
        $out[] = $h + [
            'cumulative_received' => $cum['cumulative'],
            'remaining_principal' => max(0, (float)$h['total_invested'] - $cum['cumulative']),
            'payback_phase' => $paybackPhase,
            'current_multiplier' => $paybackPhase ? IR_BOOSTED_MULTIPLIER : IR_STANDARD_MULTIPLIER,
        ];
    }
    ok($out);
    break;
}

case 'admin_create_investor': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['contact_email'] ?? ''));
    $phone = trim((string)($body['contact_phone'] ?? ''));
    $identifier = trim((string)($body['login_identifier'] ?? $email));
    $password = (string)($body['password'] ?? '');
    if ($name === '' || $identifier === '' || strlen($password) < 6) {
        fail('Name, a login identifier, and a password (min 6 chars) are required.', 400);
    }
    try {
        $pdo->prepare('INSERT INTO ir_investors (name, contact_email, contact_phone, login_identifier, password_hash) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, $phone, $identifier, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('That login identifier is already in use by another investor.', 409);
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    ir_log($pdo, eg_current_actor()['full_name'], 'investor_created', "$name (#$id)");
    ok(['id' => $id]);
    break;
}

case 'admin_update_investor': {
    eg_require_admin();
    $pdo = eg_db();
    $id = (int)($body['id'] ?? 0);
    if (!$id) fail('Missing investor id', 400);
    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['contact_email'] ?? ''));
    $phone = trim((string)($body['contact_phone'] ?? ''));
    $isActive = array_key_exists('is_active', $body) ? (!empty($body['is_active']) ? 1 : 0) : 1;
    if ($name === '') fail('Name is required.', 400);
    $pdo->prepare('UPDATE ir_investors SET name=?, contact_email=?, contact_phone=?, is_active=? WHERE id=?')
        ->execute([$name, $email, $phone, $isActive, $id]);
    if (!empty($body['password'])) {
        if (strlen((string)$body['password']) < 6) fail('New password must be at least 6 characters.', 400);
        $pdo->prepare('UPDATE ir_investors SET password_hash=? WHERE id=?')
            ->execute([password_hash((string)$body['password'], PASSWORD_DEFAULT), $id]);
    }
    ir_log($pdo, eg_current_actor()['full_name'], 'investor_updated', "#$id");
    ok(['id' => $id]);
    break;
}

case 'admin_add_share_purchase': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $investorId = (int)($body['investor_id'] ?? 0);
    $shares = (int)($body['shares'] ?? 0);
    $tranche = (int)($body['tranche_number'] ?? 0);
    $amount = (float)($body['amount_paid'] ?? -1);
    $date = trim((string)($body['purchase_date'] ?? date('Y-m-d')));
    if (!$investorId || $shares <= 0 || !in_array($tranche, [1, 2], true) || $amount < 0) {
        fail('Investor, a positive share count, tranche (1 or 2), and amount are required.', 400);
    }
    $tr = $pdo->prepare('SELECT is_unlocked FROM ir_tranches WHERE tranche_number = ?');
    $tr->execute([$tranche]);
    $unlocked = $tr->fetchColumn();
    if ($tranche === 2 && !$unlocked) fail('Tranche 2 is not unlocked yet - unlock it first from Milestones.', 409);

    $pdo->prepare('INSERT INTO ir_share_purchases (investor_id, purchase_date, shares, tranche_number, amount_paid) VALUES (?,?,?,?,?)')
        ->execute([$investorId, $date, $shares, $tranche, $amount]);
    ir_log($pdo, eg_current_actor()['full_name'], 'share_purchase_added', "investor #$investorId: $shares shares, tranche $tranche, $amount ETB");
    ok(['id' => (int)$pdo->lastInsertId()]);
    break;
}

case 'admin_list_investor_purchases': {
    eg_require_admin();
    $pdo = eg_db();
    $investorId = (int)($_GET['investor_id'] ?? $body['investor_id'] ?? 0);
    if (!$investorId) fail('Missing investor_id', 400);
    $s = $pdo->prepare('SELECT * FROM ir_share_purchases WHERE investor_id = ? ORDER BY purchase_date DESC');
    $s->execute([$investorId]);
    ok($s->fetchAll());
    break;
}

// ---------- tranches ----------
case 'admin_get_tranches': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    ok($pdo->query('SELECT * FROM ir_tranches ORDER BY tranche_number')->fetchAll());
    break;
}

case 'admin_unlock_tranche2': {
    eg_require_admin();
    $pdo = eg_db();
    $note = trim((string)($body['note'] ?? ''));
    if ($note === '') fail('A note explaining why Tranche 2 is being unlocked is required.', 400);
    $actor = eg_current_actor()['full_name'];
    $pdo->prepare('UPDATE ir_tranches SET is_unlocked=1, unlocked_at=NOW(), unlocked_note=?, unlocked_by=? WHERE tranche_number=2')
        ->execute([$note, $actor]);
    ir_log($pdo, $actor, 'tranche2_unlocked', $note);
    ok(['unlocked' => true]);
    break;
}

// ---------- distributions ----------
case 'admin_preview_distribution':
case 'admin_run_distribution': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $periodStart = trim((string)($body['period_start'] ?? ''));
    $periodEnd = trim((string)($body['period_end'] ?? ''));
    if (!$periodStart || !$periodEnd || $periodEnd < $periodStart) {
        fail('A valid period_start and period_end (period_end on or after period_start) are required.', 400);
    }
    $lock = $action === 'admin_run_distribution';
    $includeDemo = ir_is_demo_mode($pdo);

    if ($lock) {
        $overlap = $pdo->prepare(
            'SELECT COUNT(*) FROM ir_distribution_periods
             WHERE is_demo = 0 AND NOT (period_end < ? OR period_start > ?)'
        );
        $overlap->execute([$periodStart, $periodEnd]);
        if ((int)$overlap->fetchColumn() > 0) {
            fail('This period overlaps an already-locked distribution period.', 409);
        }
    }

    if (!ir_delivery_is_configured($pdo)) {
        fail('Delivery & Ride read-only connection is not configured - finish the setup wizard first.', 409);
    }
    try {
        $days = ir_delivery_fetch_daily($pdo, $periodStart, $periodEnd);
    } catch (Exception $e) {
        fail('Could not read real trip data from Delivery & Ride: ' . $e->getMessage(), 502);
    }
    $grossFareTotal = 0.0;
    foreach ($days as $d) $grossFareTotal += (float)$d['gross_fare'];

    $costCats = $pdo->query('SELECT monthly_amount FROM ir_cost_categories WHERE is_active = 1')->fetchAll();

    $holdings = ir_investor_holdings($pdo, $includeDemo, $periodEnd);
    $investorsForEngine = [];
    foreach ($holdings as $h) {
        $cum = ir_cumulative_received_before($pdo, (int)$h['id'], $periodStart, $includeDemo);
        $investorsForEngine[] = [
            'id' => (int)$h['id'],
            'shares_owned' => (int)$h['shares_owned'],
            'total_invested' => (float)$h['total_invested'],
            'cumulative_received_before' => $cum['cumulative'],
        ];
    }

    $result = ir_run_distribution(
        $grossFareTotal, ir_commission_rate($pdo), $costCats, $periodStart, $periodEnd, $investorsForEngine
    );

    if (!$lock) {
        ok($result);
        break;
    }

    $actor = eg_current_actor()['full_name'];
    $pdo->beginTransaction();
    try {
        $insPeriod = $pdo->prepare(
            'INSERT INTO ir_distribution_periods
             (period_start, period_end, commission_rate, gross_fare_total, commission_revenue, total_costs, net_profit,
              pool_entitlement, sold_shares, unsold_shares, unsold_bounce_theoretical, unsold_bounce_to_investors,
              unsold_bounce_to_founder, total_investor_payout, founder_amount, is_demo, locked_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insPeriod->execute([
            $result['period_start'], $result['period_end'], $result['commission_rate'], $result['gross_fare_total'],
            $result['commission_revenue'], $result['total_costs'], $result['net_profit'], $result['pool_entitlement'],
            $result['sold_shares'], $result['unsold_shares'], $result['unsold_bounce_theoretical'],
            $result['unsold_bounce_to_investors'], $result['unsold_bounce_to_founder'], $result['total_investor_payout'],
            $result['founder_amount'], $includeDemo ? 1 : 0, $actor,
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
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail('Could not save the distribution period: ' . $e->getMessage(), 500);
    }

    ir_log($pdo, $actor, 'distribution_run', "$periodStart to $periodEnd, net profit {$result['net_profit']}");
    $result['id'] = $periodId;
    ok($result);
    break;
}

case 'admin_list_distribution_periods': {
    eg_require_admin();
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $includeDemo = ir_is_demo_mode($pdo);
    $sql = 'SELECT * FROM ir_distribution_periods';
    if (!$includeDemo) $sql .= ' WHERE is_demo = 0';
    $sql .= ' ORDER BY period_start DESC';
    ok($pdo->query($sql)->fetchAll());
    break;
}

case 'admin_get_distribution_period': {
    eg_require_admin();
    $pdo = eg_db();
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) fail('Missing period id', 400);
    $p = $pdo->prepare('SELECT * FROM ir_distribution_periods WHERE id = ?');
    $p->execute([$id]);
    $period = $p->fetch();
    if (!$period) fail('Period not found', 404);
    $l = $pdo->prepare(
        'SELECT l.*, i.name AS investor_name
         FROM ir_distribution_investor_lines l
         JOIN ir_investors i ON i.id = l.investor_id
         WHERE l.period_id = ?
         ORDER BY l.total_payout DESC'
    );
    $l->execute([$id]);
    $period['lines'] = $l->fetchAll();
    ok($period);
    break;
}

}
