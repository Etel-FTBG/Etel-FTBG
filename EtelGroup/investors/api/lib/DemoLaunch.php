<?php
// ============================================================
// Investor Relations - demo data for the cost lines and the launch budget.
// Used only by setup_seed_demo / setup_clear_demo, so the investor screens look "live".
// Everything it adds is marked (DEMO) or recorded in a setting, and is removed by Clear demo.
// Real cost lines and a real launch switch are never touched.
// ASCII only.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/Launch.php';

// Call BEFORE the demo transaction starts (creating tables commits any open transaction).
function ir_demo_launch_seed(PDO $pdo, string $actor): void {
    ir_launch_ensure($pdo);

    // Cost lines: only when none exist yet.
    $n = (int)$pdo->query('SELECT COUNT(*) FROM ir_cost_categories')->fetchColumn();
    if ($n === 0) {
        $rows = [
            ['Office rent (DEMO)', 6000], ['Customer service (DEMO)', 10000],
            ['Yearly fixed cost, 20,000 / 12 (DEMO)', 1667], ['Hosting, maps and SMS - assumed (DEMO)', 4000],
            ['Payment gateway fees - assumed (DEMO)', 1500], ['Licence and taxes - assumed (DEMO)', 2500],
            ['Contingency 10% (DEMO)', 2567],
        ];
        $ins = $pdo->prepare('INSERT INTO ir_cost_categories (name, monthly_amount, sort_order) VALUES (?, ?, ?)');
        $ids = [];
        foreach ($rows as $i => $r) { $ins->execute([$r[0], $r[1], $i]); $ids[] = (int)$pdo->lastInsertId(); }
        ir_set_setting($pdo, 'demo_cost_ids', implode(',', $ids));
    }

    // Launch period: only when the admin has not switched one on.
    if (ir_get_setting($pdo, 'launch_active', '0') !== '1') {
        ir_set_setting($pdo, 'launch_active', '1');
        // Started 30 days ago; the end date follows the rule (90 days, or 150 trips a day for 4 weeks).
        ir_set_setting($pdo, 'launch_started_on', date('Y-m-d', strtotime('-30 days')));
        ir_set_setting($pdo, 'launch_until', '');
        ir_set_setting($pdo, 'launch_note', 'DEMO: new drivers pay no commission for 60 days, then 2% for 30 days. Launch bonuses, referral rewards and rider discounts are paid from the raise. No investor payouts until the launch period ends.');
        ir_set_setting($pdo, 'demo_launch_set', '1');
    }

    // Sample spending history (hand-entered lines), spread over the last 5 months.
    $pdo->exec("DELETE FROM ir_launch_spend WHERE created_by = '(DEMO)'");
    $lines = [
        [-150, 'material',  8000,  'Driver uniforms and stickers'],
        [-140, 'marketing', 12000, 'Posters and flyers, Hawassa'],
        [-120, 'material',  7000,  'Printed driver guides and ID cards'],
        [-100, 'marketing', 9000,  'Facebook and Telegram ads'],
        [-80,  'promotion', 6500,  'First-ride discounts'],
        [-70,  'bonus',     4800,  'Driver launch bonuses'],
        [-60,  'marketing', 7000,  'Radio spot'],
        [-45,  'referral',  2500,  'Driver referral rewards'],
        [-35,  'promotion', 5500,  'Weekend discount week'],
        [-25,  'material',  6000,  'Branded helmets and bags for couriers'],
        [-20,  'bonus',     5200,  'Daily trip bonuses'],
        [-12,  'marketing', 10000, 'University campus campaign'],
        [-8,   'referral',  3500,  'Referral rewards'],
        [-4,   'promotion', 5500,  'Rider discount codes'],
    ];
    $ins = $pdo->prepare("INSERT INTO ir_launch_spend (spent_on, category, amount, note, created_by) VALUES (?, ?, ?, ?, '(DEMO)')");
    foreach ($lines as $l) $ins->execute([date('Y-m-d', strtotime($l[0] . ' days')), $l[1], $l[2], 'DEMO: ' . $l[3]]);
}

function ir_demo_launch_clear(PDO $pdo): void {
    ir_launch_ensure($pdo);
    $pdo->exec("DELETE FROM ir_launch_spend WHERE created_by = '(DEMO)'");
    $ids = array_filter(array_map('intval', explode(',', (string)ir_get_setting($pdo, 'demo_cost_ids', ''))));
    if ($ids) $pdo->exec('DELETE FROM ir_cost_categories WHERE id IN (' . implode(',', $ids) . ')');
    ir_set_setting($pdo, 'demo_cost_ids', '');
    if (ir_get_setting($pdo, 'demo_launch_set', '0') === '1') {
        ir_set_setting($pdo, 'launch_active', '0');
        ir_set_setting($pdo, 'launch_until', '');
        ir_set_setting($pdo, 'launch_started_on', '');
        ir_set_setting($pdo, 'launch_note', '');
        ir_set_setting($pdo, 'demo_launch_set', '0');
    }
}
