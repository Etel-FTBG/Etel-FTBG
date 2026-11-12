<?php
// ============================================================
// Investor Relations - launch period, launch budget tracker and
// delivery-order income.
//   Launch period: while on, no distribution can be locked (no profit is paid).
//   Launch budget: what the raise is spent on (marketing, bonuses ...), shown to investors.
//   Order income: delivered Delivery orders counted next to ride commission.
// ASCII only (this host's WAF has corrupted non-ASCII PHP before).
// ============================================================
declare(strict_types=1);

function ir_launch_cats(): array {
    return [
        'reserve'   => 'Reserve (6 months of running costs)',
        'marketing' => 'Marketing',
        'promotion' => 'Promotion (customer discounts)',
        'bonus'     => 'Bonuses (drivers and couriers)',
        'referral'  => 'Referral rewards',
        'material'  => 'Materials',
    ];
}

function ir_launch_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ir_ensure_core_tables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_launch_budget (
        category VARCHAR(30) NOT NULL,
        budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_launch_spend (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        spent_on DATE NOT NULL,
        category VARCHAR(30) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        note VARCHAR(300) NOT NULL DEFAULT '',
        created_by VARCHAR(150) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_cat (category), KEY idx_date (spent_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // First-time budget: the proposed Stage 1 split (26 / 25 / 15 / 12 / 10 / 12 %).
    $raise = (float)$pdo->query('SELECT COALESCE(shares_total * price_per_share, 0) FROM ir_tranches WHERE tranche_number = 1')->fetchColumn();
    if ($raise <= 0) $raise = 400000.0;
    $split = ['reserve' => 26, 'marketing' => 25, 'promotion' => 15, 'bonus' => 12, 'referral' => 10, 'material' => 12];
    $ins = $pdo->prepare('INSERT IGNORE INTO ir_launch_budget (category, budget_amount) VALUES (?, ?)');
    foreach ($split as $cat => $pc) $ins->execute([$cat, round($raise * $pc / 100, 2)]);
}

// The launch period is on while the switch is on and the end date (if any) has not passed.
function ir_launch_state(PDO $pdo): array {
    $flag = ir_get_setting($pdo, 'launch_active', '0') === '1';
    $until = (string)ir_get_setting($pdo, 'launch_until', '');
    $note = (string)ir_get_setting($pdo, 'launch_note', '');
    $active = $flag && ($until === '' || date('Y-m-d') <= $until);
    return ['active' => $active, 'switch_on' => $flag, 'until' => $until, 'note' => $note, 'auto_from' => (string)ir_get_setting($pdo, 'launch_auto_from', '')];
}

function ir_launch_summary(PDO $pdo, int $recent = 40): array {
    ir_launch_ensure($pdo);
    $spent = [];
    foreach ($pdo->query('SELECT category, SUM(amount) s FROM ir_launch_spend GROUP BY category')->fetchAll() as $r) $spent[$r['category']] = (float)$r['s'];
    $cats = [];
    $tb = 0.0; $ts = 0.0;
    $budgets = [];
    foreach ($pdo->query('SELECT category, budget_amount FROM ir_launch_budget')->fetchAll() as $r) $budgets[$r['category']] = (float)$r['budget_amount'];
    foreach (ir_launch_cats() as $k => $label) {
        $b = $budgets[$k] ?? 0.0; $s = $spent[$k] ?? 0.0;
        $cats[] = ['category' => $k, 'label' => $label, 'budget' => $b, 'spent' => $s, 'left' => $b - $s];
        $tb += $b; $ts += $s;
    }
    // Promotion, bonus and referral spending is also counted automatically from Delivery & Ride, from the "count from" date on.
    $autoFrom = (string)ir_get_setting($pdo, 'launch_auto_from', '');
    $auto = ['from' => $autoFrom, 'ok' => false, 'error' => '', 'amounts' => []];
    if ($autoFrom !== '') {
        try {
            $auto['amounts'] = ir_delivery_incentives($pdo, $autoFrom, date('Y-m-d'));
            $auto['ok'] = true;
        } catch (Exception $e) {
            $auto['error'] = 'Could not read the platform spending: ' . $e->getMessage();
        }
    }
    foreach ($cats as $i => $c) {
        $a = ($auto['ok'] && isset($auto['amounts'][$c['category']])) ? (float)$auto['amounts'][$c['category']] : 0.0;
        $cats[$i]['spent_manual'] = $c['spent'];
        $cats[$i]['spent_auto'] = $a;
        $cats[$i]['spent'] = $c['spent'] + $a;
        $cats[$i]['left'] = $c['budget'] - $c['spent'] - $a;
        $ts += $a;
    }
    $list = $pdo->prepare('SELECT id, spent_on, category, amount, note FROM ir_launch_spend ORDER BY spent_on DESC, id DESC LIMIT ' . (int)$recent);
    $list->execute();
    return ['categories' => $cats, 'total_budget' => $tb, 'total_spent' => $ts, 'total_left' => $tb - $ts, 'auto' => $auto, 'spend' => $list->fetchAll()];
}

// Delivered Delivery orders in [from, to] counted as extra company income.
// Off by default; throws (never returns made-up zeros) if it is on but cannot be read.
function ir_orders_income(PDO $pdo, string $from, string $to): array {
    $on = ir_get_setting($pdo, 'include_orders', '0') === '1';
    $rate = ir_get_setting($pdo, 'order_commission_rate', '');
    $rate = ($rate === null || $rate === '') ? ir_commission_rate($pdo) : (float)$rate;
    $out = ['enabled' => $on, 'rate' => $rate, 'count' => 0, 'gross' => 0.0, 'commission' => 0.0];
    if (!$on) return $out;
    $d = ir_delivery_connect($pdo);
    $st = $d->prepare("SELECT COUNT(*) c, COALESCE(SUM(fare_total), 0) g FROM orders WHERE status = 'delivered' AND delivered_at IS NOT NULL AND DATE(delivered_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $r = $st->fetch();
    $out['count'] = (int)$r['c'];
    $out['gross'] = round((float)$r['g'], 2);
    $out['commission'] = round($out['gross'] * $rate, 2);
    return $out;
}
