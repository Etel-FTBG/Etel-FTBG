<?php
// ============================================================
// Investor Relations — investor portal controller (investor_* actions)
// investor_login is public; every other action requires a valid
// investor bearer token (ir_require_investor()) and ONLY ever returns
// that one investor's own data - never another investor's, never the
// admin-wide view.
// ============================================================
declare(strict_types=1);

switch ($action) {

case 'investor_login': {
    $pdo = eg_db();
    ir_ensure_core_tables($pdo);
    $identifier = trim((string)($body['identifier'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($identifier === '' || $password === '') fail('Enter your login identifier and password.', 400);

    $s = $pdo->prepare('SELECT * FROM ir_investors WHERE login_identifier = ? AND is_active = 1');
    $s->execute([$identifier]);
    $inv = $s->fetch();
    if (!$inv || !password_verify($password, $inv['password_hash'])) {
        fail('Incorrect login identifier or password.', 401);
    }
    $token = ir_issue_investor_token((int)$inv['id'], $inv['name']);
    ok([
        'token' => $token,
        'investor' => ['id' => (int)$inv['id'], 'name' => $inv['name'], 'is_demo' => (bool)$inv['is_demo']],
    ]);
    break;
}

case 'investor_me': {
    $auth = ir_require_investor();
    $pdo = eg_db();
    $id = $auth['investor_id'];

    $s = $pdo->prepare('SELECT * FROM ir_investors WHERE id = ? AND is_active = 1');
    $s->execute([$id]);
    $inv = $s->fetch();
    if (!$inv) fail('Investor not found', 404);

    $holdShares = $pdo->prepare(
        "SELECT COALESCE(SUM(shares),0) AS shares, COALESCE(SUM(amount_paid),0) AS invested
         FROM ir_share_purchases WHERE investor_id = ?"
    );
    $holdShares->execute([$id]);
    $holding = $holdShares->fetch();

    $cumStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total_payout),0) FROM ir_distribution_investor_lines WHERE investor_id = ?'
    );
    $cumStmt->execute([$id]);
    $cumulative = (float)$cumStmt->fetchColumn();
    $invested = (float)$holding['invested'];
    $paybackPhase = $cumulative < $invested;

    ok([
        'id' => (int)$inv['id'],
        'name' => $inv['name'],
        'contact_email' => $inv['contact_email'],
        'contact_phone' => $inv['contact_phone'],
        'is_demo' => (bool)$inv['is_demo'],
        'shares_owned' => (int)$holding['shares'],
        'total_invested' => $invested,
        'cumulative_received' => $cumulative,
        'remaining_principal' => max(0, $invested - $cumulative),
        'payback_phase' => $paybackPhase,
        'current_multiplier' => $paybackPhase ? IR_BOOSTED_MULTIPLIER : IR_STANDARD_MULTIPLIER,
        'total_shares_in_company' => IR_TOTAL_SHARES,
        'investor_pool_shares' => IR_INVESTOR_POOL_SHARES,
    ]);
    break;
}

case 'investor_payout_history': {
    $auth = ir_require_investor();
    $pdo = eg_db();
    $s = $pdo->prepare(
        'SELECT l.*, p.period_start, p.period_end, p.locked_at, p.is_demo
         FROM ir_distribution_investor_lines l
         JOIN ir_distribution_periods p ON p.id = l.period_id
         WHERE l.investor_id = ?
         ORDER BY p.period_start DESC'
    );
    $s->execute([$auth['investor_id']]);
    ok($s->fetchAll());
    break;
}

case 'investor_purchase_history': {
    $auth = ir_require_investor();
    $pdo = eg_db();
    $s = $pdo->prepare('SELECT * FROM ir_share_purchases WHERE investor_id = ? ORDER BY purchase_date DESC');
    $s->execute([$auth['investor_id']]);
    ok($s->fetchAll());
    break;
}

}
