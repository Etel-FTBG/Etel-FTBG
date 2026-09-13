<?php
// Investor self-service portal. NOT gated by require_admin() - these
// are real investors, not staff, and have no admin login. Instead
// every action here requires the investor's own access_token, a long
// random string only they and the founder have. Whatever an investor
// can see beyond their own basic stake (cap table, distribution
// history, growth planner) is controlled per-investor by the founder
// via can_view_captable/can_view_distributions/can_view_growth_planner
// flags set in the admin Equity tab - this file only ever reads those
// flags, it never grants access beyond what's already stored there.
switch ($action) {

case 'investor_portal_get_view': {
    $pdo = db();
    $token = isset($body['token']) ? trim($body['token']) : '';
    if ($token === '') fail('Access token is required', 400);
    $s = $pdo->prepare('SELECT * FROM equity_investors WHERE access_token = ?');
    $s->execute(array($token));
    $inv = $s->fetch(PDO::FETCH_ASSOC);
    if (!$inv) fail('Invalid or expired access link. Contact the founder for a new one.', 403);

    try {
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct, total_shares FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load round settings', 500);
    }
    $target = $settings ? floatval($settings['target_amount']) : 0;
    $pct = $settings ? floatval($settings['investor_equity_pct']) : 0;
    $amt = floatval($inv['amount_invested']);
    $ownership = $target > 0 ? ($amt / $target) * $pct : 0;
    $pricePerShare = ($settings && $settings['total_shares'] > 0 && $pct > 0)
        ? $target / ($settings['total_shares'] * ($pct / 100)) : 0;
    $shares = $pricePerShare > 0 ? $amt / $pricePerShare : 0;

    $response = array(
        'investor' => array(
            'name' => $inv['name'], 'investor_code' => $inv['investor_code'],
            'amount_invested' => $amt, 'ownership_pct' => round($ownership, 4), 'shares' => round($shares, 2),
            'payment_status' => $inv['payment_status'], 'agreement_status' => $inv['agreement_status'],
            'invested_at' => $inv['invested_at'],
        ),
        'permissions' => array(
            'can_view_captable' => (bool) $inv['can_view_captable'],
            'can_view_distributions' => (bool) $inv['can_view_distributions'],
            'can_view_growth_planner' => (bool) $inv['can_view_growth_planner'],
        ),
    );

    if ($inv['can_view_captable']) {
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

    if ($inv['can_view_distributions']) {
        try {
            $d = $pdo->prepare('SELECT epd.created_at, epdi.profit_share, epdi.ownership_pct
                FROM equity_profit_distribution_items epdi
                JOIN equity_profit_distributions epd ON epd.id = epdi.distribution_id
                WHERE epdi.investor_id = ? ORDER BY epd.created_at DESC');
            $d->execute(array($inv['id']));
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

case 'investor_portal_get_growth_planner_data': {
    // Separate action (not folded into the main view) since this is
    // the most business-sensitive optional permission - keeping it as
    // its own token-checked call means a future change to what data
    // it returns doesn't accidentally widen what the main view leaks.
    $pdo = db();
    $token = isset($body['token']) ? trim($body['token']) : '';
    if ($token === '') fail('Access token is required', 400);
    $s = $pdo->prepare('SELECT id, can_view_growth_planner FROM equity_investors WHERE access_token = ?');
    $s->execute(array($token));
    $inv = $s->fetch(PDO::FETCH_ASSOC);
    if (!$inv) fail('Invalid or expired access link', 403);
    if (!$inv['can_view_growth_planner']) fail('You do not have access to this view', 403);
    try {
        $costs = $pdo->query('SELECT * FROM platform_cost_assumptions WHERE id=1')->fetch();
        $settings = $pdo->query('SELECT target_amount, investor_equity_pct FROM equity_round_settings WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load data', 500);
    }
    ok(array('costs' => $costs, 'settings' => $settings));
    break;
}

}
