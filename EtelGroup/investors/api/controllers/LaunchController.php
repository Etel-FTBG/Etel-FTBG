<?php
// ============================================================
// Investor Relations - launch period, launch budget, order income
//   admin_launch_get / admin_launch_save / admin_launch_budget_save
//   admin_launch_spend_add / admin_launch_spend_delete / admin_orders_income_save   (EtelGroup admin)
//   investor_launch   (an investor's own portal: read only)
// ASCII only.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../lib/Launch.php';

function ir_launch_payload(PDO $pdo): array {
    return [
        'state' => ir_launch_state($pdo),
        'summary' => ir_launch_summary($pdo),
        'orders' => [
            'include_orders' => ir_get_setting($pdo, 'include_orders', '0') === '1',
            'order_commission_rate' => (string)ir_get_setting($pdo, 'order_commission_rate', ''),
            'ride_commission_rate' => ir_commission_rate($pdo),
        ],
    ];
}

switch ($action) {

case 'admin_launch_get': {
    eg_require_admin();
    $pdo = eg_db();
    ok(ir_launch_payload($pdo));
    break;
}

case 'admin_launch_save': {
    eg_require_admin();
    $pdo = eg_db();
    ir_launch_ensure($pdo);
    $on = !empty($body['active']);
    $until = trim((string)($body['until'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    $autoFrom = trim((string)($body['auto_from'] ?? ''));
    if ($autoFrom !== '') {
        $d0 = DateTime::createFromFormat('Y-m-d', $autoFrom);
        if (!$d0 || $d0->format('Y-m-d') !== $autoFrom) fail('The date to count platform spending from must be a valid date.', 400);
    }
    if ($until !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $until);
        if (!$d || $d->format('Y-m-d') !== $until) fail('The end date must be a valid date.', 400);
    }
    if (mb_strlen($note) > 500) fail('The note is too long (500 characters at most).', 400);
    ir_set_setting($pdo, 'launch_active', $on ? '1' : '0');
    ir_set_setting($pdo, 'launch_until', $until);
    ir_set_setting($pdo, 'launch_note', $note);
    ir_set_setting($pdo, 'launch_auto_from', $autoFrom);
    ir_log($pdo, eg_current_actor()['full_name'], 'launch_period_' . ($on ? 'on' : 'off'), $until !== '' ? ('until ' . $until) : 'no end date');
    ok(ir_launch_payload($pdo));
    break;
}

case 'admin_launch_budget_save': {
    eg_require_admin();
    $pdo = eg_db();
    ir_launch_ensure($pdo);
    $cat = (string)($body['category'] ?? '');
    $amount = (float)($body['budget'] ?? -1);
    if (!isset(ir_launch_cats()[$cat])) fail('Unknown budget line.', 400);
    if ($amount < 0 || $amount > 1000000000) fail('Enter a budget of 0 or more.', 400);
    $pdo->prepare('UPDATE ir_launch_budget SET budget_amount = ? WHERE category = ?')->execute([round($amount, 2), $cat]);
    ir_log($pdo, eg_current_actor()['full_name'], 'launch_budget_saved', $cat . ' = ' . $amount);
    ok(ir_launch_payload($pdo));
    break;
}

case 'admin_launch_spend_add': {
    eg_require_admin();
    $pdo = eg_db();
    ir_launch_ensure($pdo);
    $cat = (string)($body['category'] ?? '');
    $amount = (float)($body['amount'] ?? 0);
    $date = trim((string)($body['spent_on'] ?? date('Y-m-d')));
    $note = trim((string)($body['note'] ?? ''));
    if (!isset(ir_launch_cats()[$cat])) fail('Choose a budget line.', 400);
    if ($amount <= 0 || $amount > 1000000000) fail('Enter the amount spent (more than 0).', 400);
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) fail('The date must be a valid date.', 400);
    if (mb_strlen($note) > 300) fail('The note is too long (300 characters at most).', 400);
    $actor = eg_current_actor()['full_name'];
    $pdo->prepare('INSERT INTO ir_launch_spend (spent_on, category, amount, note, created_by) VALUES (?,?,?,?,?)')
        ->execute([$date, $cat, round($amount, 2), $note, $actor]);
    ir_log($pdo, $actor, 'launch_spend_added', $cat . ' ' . $amount);
    ok(ir_launch_payload($pdo));
    break;
}

case 'admin_launch_spend_delete': {
    eg_require_admin();
    $pdo = eg_db();
    ir_launch_ensure($pdo);
    $id = (int)($body['id'] ?? 0);
    if ($id < 1) fail('Missing spend id.', 400);
    $pdo->prepare('DELETE FROM ir_launch_spend WHERE id = ?')->execute([$id]);
    ir_log($pdo, eg_current_actor()['full_name'], 'launch_spend_deleted', '#' . $id);
    ok(ir_launch_payload($pdo));
    break;
}

case 'admin_orders_income_save': {
    eg_require_admin();
    $pdo = eg_db();
    $on = !empty($body['include_orders']);
    $rate = trim((string)($body['order_commission_rate'] ?? ''));
    if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 1)) fail('The order commission rate must be between 0 and 1 (0.05 = 5 %), or empty to use the ride rate.', 400);
    ir_set_setting($pdo, 'include_orders', $on ? '1' : '0');
    ir_set_setting($pdo, 'order_commission_rate', $rate);
    ir_log($pdo, eg_current_actor()['full_name'], 'orders_income_' . ($on ? 'on' : 'off'), $rate === '' ? 'ride rate' : $rate);
    ok(ir_launch_payload($pdo));
    break;
}

case 'investor_launch': {
    ir_require_investor();
    $pdo = eg_db();
    $p = ir_launch_payload($pdo);
    // an investor sees whether the automatic counting works, never the technical reason
    $p['summary']['auto'] = ['from' => $p['summary']['auto']['from'], 'ok' => $p['summary']['auto']['ok']];
    ok(['state' => ['active' => $p['state']['active'], 'until' => $p['state']['until'], 'note' => $p['state']['note']], 'summary' => $p['summary']]);
    break;
}

}
