<?php
// Etel Group — Investor & Equity Control Center.
// Every action here requires the 'equity.view' or 'equity.manage'
// capability (see boot.php's require_admin()) - this is real founder
// financial/ownership data, not general admin data, so it is never
// reachable by a plain 'admin' role without an explicit grant in the
// Roles panel, unlike most AdminController.php actions.
switch ($action) {

case 'equity_get_settings': {
    $pdo = db();
    require_admin($pdo, 'equity.view');
    try {
        $s = $pdo->query('SELECT target_amount, investor_equity_pct, total_shares, founder_capital_contributed FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load round settings - has migration 048_founder_capital.sql been applied? (api/migrate.php --apply)', 500);
    }
    if (!$s) $s = array('target_amount' => 500000, 'investor_equity_pct' => 20, 'total_shares' => 10000, 'founder_capital_contributed' => 0);
    ok(array('settings' => $s));
    break;
}

case 'equity_update_settings': {
    $pdo = db();
    $u = require_admin($pdo, 'equity.manage');
    $target = isset($body['target_amount']) ? etel_validate_amount($body['target_amount'], true) : false;
    if ($target === false || $target <= 0) fail('target_amount must be a positive amount', 400);
    $pct = isset($body['investor_equity_pct']) ? floatval($body['investor_equity_pct']) : false;
    if ($pct === false || $pct <= 0 || $pct > 100) fail('investor_equity_pct must be between 0 and 100', 400);
    $shares = isset($body['total_shares']) ? intval($body['total_shares']) : false;
    if ($shares === false || $shares <= 0) fail('total_shares must be a positive whole number', 400);
    // Optional - a plain record-keeping figure (e.g. cash the founder
    // has personally put into the company). Never required, never
    // validated against target_amount/pct, and never fed into any of
    // the ownership/price-per-share formulas below - it is deliberately
    // outside the investor cap-table math, not a second pool.
    $founderCapital = isset($body['founder_capital_contributed']) ? etel_validate_amount($body['founder_capital_contributed'], true) : 0;
    if ($founderCapital === false) $founderCapital = 0;
    try {
        $s = $pdo->prepare('UPDATE equity_round_settings SET target_amount=?, investor_equity_pct=?, total_shares=?, founder_capital_contributed=?, updated_by=? WHERE id=1');
        $s->execute(array($target, $pct, $shares, $founderCapital, $u['id']));
        ok(array('message' => 'Round settings updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}

case 'equity_list_investors': {
    $pdo = db();
    require_admin($pdo, 'equity.view');
    try {
        $rows = $pdo->query("SELECT ei.id, ei.investor_code, ei.name, ei.contact, ei.amount_invested, ei.payment_status, ei.agreement_status, ei.receipt_number, ei.invested_at, ei.notes, ei.access_token, ei.can_view_captable, ei.can_view_distributions, ei.can_view_growth_planner, ei.user_id, u.name AS linked_user_name, u.phone AS linked_user_phone
            FROM equity_investors ei LEFT JOIN users u ON u.id = ei.user_id ORDER BY ei.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load investors - has migration 036_equity_investors.sql been applied?', 500);
    }
    ok(array('investors' => $rows));
    break;
}

case 'equity_link_investor_user': {
    $pdo = db();
    require_admin($pdo, 'equity.manage');
    $investorId = isset($body['investor_id']) ? intval($body['investor_id']) : 0;
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    if ($investorId <= 0) fail('investor_id is required', 400);
    if ($phone === '') fail('phone is required', 400);
    try {
        $u = $pdo->prepare('SELECT id, name, phone FROM users WHERE phone=?');
        $u->execute(array($phone));
        $user = $u->fetch();
        if (!$user) fail('No EtelID account found with that phone number', 404);
        $pdo->prepare('UPDATE equity_investors SET user_id=? WHERE id=?')->execute(array($user['id'], $investorId));
        ok(array('message' => 'Linked to ' . $user['name'] . '\'s EtelID', 'user_id' => $user['id'], 'user_name' => $user['name']));
    } catch (Exception $e) {
        fail_ex($e, 'Could not link - has migration 039 been applied?', 500);
    }
    break;
}

case 'equity_unlink_investor_user': {
    $pdo = db();
    require_admin($pdo, 'equity.manage');
    $investorId = isset($body['investor_id']) ? intval($body['investor_id']) : 0;
    if ($investorId <= 0) fail('investor_id is required', 400);
    try {
        $pdo->prepare('UPDATE equity_investors SET user_id=NULL WHERE id=?')->execute(array($investorId));
        ok(array('message' => 'EtelID unlinked'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not unlink', 500);
    }
    break;
}

case 'investor_portal_am_i_investor': {
    // Cheap, called on every app's load to decide whether to show the
    // floating investor button at all - deliberately returns nothing
    // sensitive, just a yes/no plus a display name.
    $pdo = db();
    $u = require_auth($pdo);
    try {
        $s = $pdo->prepare('SELECT COUNT(*) AS n FROM equity_investors WHERE user_id=?');
        $s->execute(array($u['id']));
        $n = intval($s->fetch()['n']);
    } catch (Exception $e) {
        ok(array('is_investor' => false)); // migration not applied yet - fail safe, don't show the button
        break;
    }
    ok(array('is_investor' => $n > 0));
    break;
}

case 'investor_portal_get_my_view': {
    // Same shape of response as the token-based investor_portal_get_view
    // in InvestorPortalController.php, but identified via the person's
    // own EtelID session instead of a link token. Aggregates ALL
    // equity_investors rows linked to this user_id, since a real
    // investor can have more than one investment record (seen in
    // practice - the same person investing at two different times).
    $pdo = db();
    $u = require_auth($pdo);
    try {
        $s = $pdo->prepare('SELECT * FROM equity_investors WHERE user_id=?');
        $s->execute(array($u['id']));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load your investor view - has migration 039 been applied?', 500);
    }
    if (empty($rows)) fail('Your EtelID is not linked to an investor record. Contact the founder.', 403);

    try {
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct, total_shares FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load round settings', 500);
    }
    $target = $settings ? floatval($settings['target_amount']) : 0;
    $pct = $settings ? floatval($settings['investor_equity_pct']) : 0;
    $totalAmount = array_sum(array_map(function ($r) { return floatval($r['amount_invested']); }, $rows));
    $ownership = $target > 0 ? ($totalAmount / $target) * $pct : 0;
    $pricePerShare = ($settings && $settings['total_shares'] > 0 && $pct > 0)
        ? $target / ($settings['total_shares'] * ($pct / 100)) : 0;
    $shares = $pricePerShare > 0 ? $totalAmount / $pricePerShare : 0;

    // Permissions: if ANY of this person's investor rows grants a
    // view, they get it - a founder who later adds a second, more
    // permissive investment record for someone shouldn't have that
    // shadowed by an older, more restrictive row.
    $canCaptable = false; $canDistributions = false; $canGrowth = false;
    $investorIds = array();
    foreach ($rows as $r) {
        if (!empty($r['can_view_captable'])) $canCaptable = true;
        if (!empty($r['can_view_distributions'])) $canDistributions = true;
        if (!empty($r['can_view_growth_planner'])) $canGrowth = true;
        $investorIds[] = $r['id'];
    }

    $response = array(
        'investor' => array(
            'name' => $rows[0]['name'], 'investor_code' => count($rows) > 1 ? implode(', ', array_column($rows, 'investor_code')) : $rows[0]['investor_code'],
            'amount_invested' => $totalAmount, 'ownership_pct' => round($ownership, 4), 'shares' => round($shares, 2),
            'payment_status' => $rows[0]['payment_status'], 'agreement_status' => $rows[0]['agreement_status'],
            'invested_at' => $rows[0]['invested_at'], 'investment_count' => count($rows),
        ),
        'permissions' => array(
            'can_view_captable' => $canCaptable, 'can_view_distributions' => $canDistributions, 'can_view_growth_planner' => $canGrowth,
        ),
    );

    if ($canCaptable) {
        try {
            $others = $pdo->query('SELECT name, amount_invested FROM equity_investors ORDER BY amount_invested DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $others = array(); }
        $capTable = array();
        foreach ($others as $o) {
            $oAmt = floatval($o['amount_invested']);
            $capTable[] = array('name' => $o['name'], 'ownership_pct' => round($target > 0 ? ($oAmt / $target) * $pct : 0, 4));
        }
        $response['cap_table'] = $capTable;
        $response['settings'] = array('target_amount' => $target, 'investor_equity_pct' => $pct);
    }

    if ($canDistributions) {
        try {
            $placeholders = implode(',', array_fill(0, count($investorIds), '?'));
            $d = $pdo->prepare("SELECT epd.created_at, epdi.profit_share, epdi.ownership_pct
                FROM equity_profit_distribution_items epdi
                JOIN equity_profit_distributions epd ON epd.id = epdi.distribution_id
                WHERE epdi.investor_id IN ($placeholders) ORDER BY epd.created_at DESC");
            $d->execute($investorIds);
            $response['my_distributions'] = $d->fetchAll(PDO::FETCH_ASSOC);
            $response['total_received'] = array_sum(array_map(function ($r) { return floatval($r['profit_share']); }, $response['my_distributions']));
        } catch (Exception $e) {
            $response['my_distributions'] = array();
            $response['total_received'] = 0;
        }
    }

    ok($response);
    break;
}

case 'investor_portal_get_my_growth_planner_data': {
    $pdo = db();
    $u = require_auth($pdo);
    try {
        $s = $pdo->prepare('SELECT can_view_growth_planner FROM equity_investors WHERE user_id=? AND can_view_growth_planner=1 LIMIT 1');
        $s->execute(array($u['id']));
        $hasAccess = (bool) $s->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not check access', 500);
    }
    if (!$hasAccess) fail('You do not have access to this view', 403);
    try {
        $costs = $pdo->query('SELECT * FROM platform_cost_assumptions WHERE id=1')->fetch();
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load data', 500);
    }
    ok(array('costs' => $costs, 'settings' => $settings));
    break;
}

case 'equity_add_investor': {
    $pdo = db();
    $u = require_admin($pdo, 'equity.manage');
    $name = isset($body['name']) ? trim($body['name']) : '';
    if ($name === '') fail('Investor name is required', 400);
    $amount = isset($body['amount_invested']) ? etel_validate_amount($body['amount_invested'], true) : false;
    if ($amount === false || $amount <= 0) fail('amount_invested must be a positive amount', 400);
    $contact = isset($body['contact']) ? trim($body['contact']) : '';
    $paymentStatus = isset($body['payment_status']) && in_array($body['payment_status'], array('unpaid','pending','paid'), true) ? $body['payment_status'] : 'pending';
    $agreementStatus = isset($body['agreement_status']) && in_array($body['agreement_status'], array('draft','sent','signed'), true) ? $body['agreement_status'] : 'draft';
    $receipt = isset($body['receipt_number']) ? trim($body['receipt_number']) : '';
    if ($receipt === '') $receipt = 'ETL-' . substr((string) time(), -6);
    $investedAt = isset($body['invested_at']) && $body['invested_at'] !== '' ? $body['invested_at'] : date('Y-m-d');
    $notes = isset($body['notes']) ? trim($body['notes']) : '';
    $code = 'INV-' . str_pad((string) (mt_rand(0, 999)), 3, '0', STR_PAD_LEFT) . '-' . substr((string) time(), -4);
    $accessToken = bin2hex(random_bytes(24));
    try {
        $s = $pdo->prepare('INSERT INTO equity_investors (investor_code, name, contact, amount_invested, payment_status, agreement_status, receipt_number, invested_at, notes, access_token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute(array($code, $name, $contact, $amount, $paymentStatus, $agreementStatus, $receipt, $investedAt, $notes, $accessToken, $u['id']));
        ok(array('message' => 'Investor added', 'id' => $pdo->lastInsertId(), 'investor_code' => $code, 'access_token' => $accessToken));
    } catch (Exception $e) {
        fail_ex($e, 'Could not add investor', 500);
    }
    break;
}

case 'equity_update_investor': {
    $pdo = db();
    require_admin($pdo, 'equity.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    $name = isset($body['name']) ? trim($body['name']) : '';
    if ($name === '') fail('Investor name is required', 400);
    $amount = isset($body['amount_invested']) ? etel_validate_amount($body['amount_invested'], true) : false;
    if ($amount === false || $amount <= 0) fail('amount_invested must be a positive amount', 400);
    $contact = isset($body['contact']) ? trim($body['contact']) : '';
    $paymentStatus = isset($body['payment_status']) && in_array($body['payment_status'], array('unpaid','pending','paid'), true) ? $body['payment_status'] : 'pending';
    $agreementStatus = isset($body['agreement_status']) && in_array($body['agreement_status'], array('draft','sent','signed'), true) ? $body['agreement_status'] : 'draft';
    $receipt = isset($body['receipt_number']) ? trim($body['receipt_number']) : '';
    $notes = isset($body['notes']) ? trim($body['notes']) : '';
    $canCaptable = !empty($body['can_view_captable']) ? 1 : 0;
    $canDistributions = !empty($body['can_view_distributions']) ? 1 : 0;
    $canGrowthPlanner = !empty($body['can_view_growth_planner']) ? 1 : 0;
    try {
        $s = $pdo->prepare('UPDATE equity_investors SET name=?, contact=?, amount_invested=?, payment_status=?, agreement_status=?, receipt_number=?, notes=?, can_view_captable=?, can_view_distributions=?, can_view_growth_planner=? WHERE id=?');
        $s->execute(array($name, $contact, $amount, $paymentStatus, $agreementStatus, $receipt, $notes, $canCaptable, $canDistributions, $canGrowthPlanner, $id));
        ok(array('message' => 'Investor updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not update investor', 500);
    }
    break;
}

case 'equity_regenerate_investor_token': {
    // Instantly invalidates any previously shared portal link for
    // this investor - use if a link was shared with the wrong person
    // or the founder just wants to rotate it periodically.
    $pdo = db();
    require_admin($pdo, 'equity.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    $newToken = bin2hex(random_bytes(24));
    try {
        $pdo->prepare('UPDATE equity_investors SET access_token=? WHERE id=?')->execute(array($newToken, $id));
        ok(array('message' => 'Access link regenerated', 'access_token' => $newToken));
    } catch (Exception $e) {
        fail_ex($e, 'Could not regenerate token', 500);
    }
    break;
}

case 'equity_delete_investor': {
    $pdo = db();
    require_admin($pdo, 'equity.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    try {
        $pdo->prepare('DELETE FROM equity_investors WHERE id=?')->execute(array($id));
        ok(array('message' => 'Investor removed'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete investor', 500);
    }
    break;
}

case 'equity_get_dashboard': {
    $pdo = db();
    require_admin($pdo, 'equity.view');
    try {
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct, total_shares, founder_capital_contributed FROM equity_round_settings WHERE id=1')->fetch();
        $investors = $pdo->query('SELECT id, name, amount_invested FROM equity_investors ORDER BY amount_invested DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load dashboard - has migration 048_founder_capital.sql been applied?', 500);
    }
    if (!$settings) $settings = array('target_amount' => 500000, 'investor_equity_pct' => 20, 'total_shares' => 10000, 'founder_capital_contributed' => 0);
    $target = floatval($settings['target_amount']);
    $pct = floatval($settings['investor_equity_pct']);
    $committed = 0.0;
    foreach ($investors as $inv) $committed += floatval($inv['amount_invested']);
    // Same formulas as the standalone control-center tool: ownership% of
    // an investor is (their amount / target) * the pool's overall %,
    // and price-per-share comes from spreading the raise target across
    // just the investor-owned share count (shares * pct/100) - founder
    // shares are never for sale so they're excluded from the pricing math.
    $investorSharePool = $settings['total_shares'] * ($pct / 100);
    $pricePerShare = $investorSharePool > 0 ? $target / $investorSharePool : 0;
    $cappedEquityIssued = $target > 0 ? min(($committed / $target) * $pct, $pct) : 0;
    $capTable = array();
    foreach ($investors as $inv) {
        $amt = floatval($inv['amount_invested']);
        $ownership = $target > 0 ? ($amt / $target) * $pct : 0;
        $shares = $pricePerShare > 0 ? $amt / $pricePerShare : 0;
        $capTable[] = array(
            'id' => $inv['id'], 'name' => $inv['name'], 'amount_invested' => $amt,
            'ownership_pct' => round($ownership, 4), 'shares' => round($shares, 2),
        );
    }
    ok(array(
        'settings' => $settings,
        'total_committed' => $committed,
        'remaining_to_raise' => max($target - $committed, 0),
        'equity_issued_pct' => round($cappedEquityIssued, 4),
        'price_per_share' => round($pricePerShare, 4),
        'investor_count' => count($investors),
        'cap_table' => $capTable,
    ));
    break;
}

case 'equity_preview_distribution': {
    // GET-style preview: shows the split for a given profit figure
    // without saving anything, so the founder can experiment with
    // numbers before committing a real distribution record.
    $pdo = db();
    require_admin($pdo, 'equity.view');
    $profit = isset($body['profit_amount']) ? etel_validate_amount($body['profit_amount'], true) : false;
    if ($profit === false || $profit < 0) fail('profit_amount must be a non-negative amount', 400);
    try {
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct FROM equity_round_settings WHERE id=1')->fetch();
        $investors = $pdo->query('SELECT id, name, amount_invested FROM equity_investors ORDER BY amount_invested DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load equity data', 500);
    }
    $target = $settings ? floatval($settings['target_amount']) : 500000;
    $pct = $settings ? floatval($settings['investor_equity_pct']) : 20;
    ok(equity_compute_distribution($profit, $target, $pct, $investors));
    break;
}

case 'equity_distribute_profit': {
    // POST: actually records a distribution, snapshotting each
    // investor's current amount/ownership at this moment in time so a
    // later change to the cap table never rewrites history.
    $pdo = db();
    $u = require_admin($pdo, 'equity.manage');
    $profit = isset($body['profit_amount']) ? etel_validate_amount($body['profit_amount'], true) : false;
    if ($profit === false || $profit < 0) fail('profit_amount must be a non-negative amount', 400);
    $note = isset($body['note']) ? trim($body['note']) : '';
    try {
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct FROM equity_round_settings WHERE id=1')->fetch();
        $investors = $pdo->query('SELECT id, name, amount_invested FROM equity_investors ORDER BY amount_invested DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load equity data', 500);
    }
    $target = $settings ? floatval($settings['target_amount']) : 500000;
    $pct = $settings ? floatval($settings['investor_equity_pct']) : 20;
    $calc = equity_compute_distribution($profit, $target, $pct, $investors);
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('INSERT INTO equity_profit_distributions (profit_amount, investor_pool, founder_pool, company_amount, charity_amount, personal_amount, note, created_by) VALUES (?,?,?,?,?,?,?,?)');
        $s->execute(array($profit, $calc['investor_pool'], $calc['founder_pool'], $calc['company_amount'], $calc['charity_amount'], $calc['personal_amount'], $note, $u['id']));
        $distId = $pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO equity_profit_distribution_items (distribution_id, investor_id, investor_name, amount_invested, ownership_pct, profit_share) VALUES (?,?,?,?,?,?)');
        foreach ($calc['investor_shares'] as $row) {
            $item->execute(array($distId, $row['id'], $row['name'], $row['amount_invested'], $row['ownership_pct'], $row['profit_share']));
        }
        $pdo->commit();
        ok(array('message' => 'Profit distribution recorded', 'id' => $distId) + $calc);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not record distribution', 500);
    }
    break;
}

case 'equity_list_distributions': {
    $pdo = db();
    require_admin($pdo, 'equity.view');
    try {
        $rows = $pdo->query('SELECT id, profit_amount, investor_pool, founder_pool, company_amount, charity_amount, personal_amount, note, created_at FROM equity_profit_distributions ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load distribution history', 500);
    }
    ok(array('distributions' => $rows));
    break;
}

case 'equity_get_distribution': {
    $pdo = db();
    require_admin($pdo, 'equity.view');
    $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($body['id']) ? intval($body['id']) : 0);
    if ($id <= 0) fail('id is required', 400);
    try {
        $s = $pdo->prepare('SELECT id, profit_amount, investor_pool, founder_pool, company_amount, charity_amount, personal_amount, note, created_at FROM equity_profit_distributions WHERE id=?');
        $s->execute(array($id));
        $dist = $s->fetch();
        if (!$dist) fail('Distribution not found', 404);
        $i = $pdo->prepare('SELECT investor_name, amount_invested, ownership_pct, profit_share FROM equity_profit_distribution_items WHERE distribution_id=? ORDER BY profit_share DESC');
        $i->execute(array($id));
        $items = $i->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load distribution', 500);
    }
    ok(array('distribution' => $dist, 'items' => $items));
    break;
}

case 'equity_export_investors_csv': {
    // Exported as a real file download (not JSON) same pattern as
    // admin_export_messages_csv on the EtelGroup side - token comes from
    // the query string since this is opened via window.open(), not fetch().
    $pdo = db();
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    require_admin($pdo, 'equity.view');
    try {
        $rows = $pdo->query('SELECT investor_code, name, contact, amount_invested, payment_status, agreement_status, invested_at, receipt_number FROM equity_investors ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not export', 500);
    }
    $target = $settings ? floatval($settings['target_amount']) : 500000;
    $pct = $settings ? floatval($settings['investor_equity_pct']) : 20;
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="etel-group-investors.csv"');
    echo "Code,Name,Contact,Amount(ETB),Ownership%,Payment,Agreement,Date,Receipt\n";
    foreach ($rows as $r) {
        $own = $target > 0 ? round((floatval($r['amount_invested']) / $target) * $pct, 2) : 0;
        echo implode(',', array(
            $r['investor_code'],
            '"' . str_replace('"', '""', $r['name']) . '"',
            '"' . str_replace('"', '""', $r['contact']) . '"',
            $r['amount_invested'], $own, $r['payment_status'], $r['agreement_status'],
            $r['invested_at'], $r['receipt_number'],
        )) . "\n";
    }
    exit;
}

}

// Shared calculation used by both the preview and the real save action,
// so the number the founder previews is guaranteed to be exactly what
// gets recorded - no drift between a "preview" formula and a "commit"
// formula living in two different places.
function equity_compute_distribution($profit, $target, $pct, $investors) {
    $investorPool = $profit * ($pct / 100);
    $founderPool = $profit - $investorPool;
    // Founder's personal allocation rule (of the remaining 80%): 30%
    // reinvested into the company, 10% given (charity/God), 40% kept
    // personally. The other 20% of that 80% (i.e. 16% of total profit)
    // isn't allocated here - it mirrors the standalone control-center
    // tool exactly, which only tracks these three named buckets.
    $company = $founderPool * 0.30;
    $charity = $founderPool * 0.10;
    $personal = $founderPool * 0.40;
    $investorShares = array();
    foreach ($investors as $inv) {
        $amt = floatval($inv['amount_invested']);
        $ownership = $target > 0 ? ($amt / $target) * $pct : 0;
        $shareOfPool = $target > 0 ? $amt / $target : 0;
        $profitShare = $investorPool * $shareOfPool;
        $investorShares[] = array(
            'id' => $inv['id'], 'name' => $inv['name'], 'amount_invested' => $amt,
            'ownership_pct' => round($ownership, 4), 'profit_share' => round($profitShare, 2),
        );
    }
    return array(
        'profit_amount' => $profit,
        'investor_pool' => round($investorPool, 2),
        'founder_pool' => round($founderPool, 2),
        'company_amount' => round($company, 2),
        'charity_amount' => round($charity, 2),
        'personal_amount' => round($personal, 2),
        'investor_shares' => $investorShares,
    );
}
