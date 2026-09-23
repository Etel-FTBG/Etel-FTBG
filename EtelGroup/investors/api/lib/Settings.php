<?php
// ============================================================
// Investor Relations — settings key/value store (ir_settings)
// Backs the "Get Started" wizard: commission rate, the read-only
// Delivery & Ride DB connection, demo mode flag, and anything else
// single-valued. Self-healing table create, same convention as
// EtelGroup's own eg_ensure_companies_table().
// ============================================================
declare(strict_types=1);

function ir_ensure_core_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_settings (
        setting_key   VARCHAR(60) NOT NULL,
        setting_value TEXT NULL,
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_tranches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tranche_number TINYINT UNSIGNED NOT NULL,
        shares_total INT UNSIGNED NOT NULL,
        amount_total DECIMAL(14,2) NOT NULL,
        is_unlocked TINYINT(1) NOT NULL DEFAULT 0,
        unlocked_at DATETIME NULL,
        unlocked_note VARCHAR(500) NOT NULL DEFAULT '',
        unlocked_by VARCHAR(150) NOT NULL DEFAULT '',
        PRIMARY KEY (id), UNIQUE KEY uq_tranche_number (tranche_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO ir_tranches (tranche_number, shares_total, amount_total, is_unlocked)
        VALUES (1, 400, 400000.00, 1), (2, 600, 600000.00, 0)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_cost_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        monthly_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_cost_category_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED NOT NULL,
        old_amount DECIMAL(14,2) NOT NULL,
        new_amount DECIMAL(14,2) NOT NULL,
        changed_by VARCHAR(150) NOT NULL DEFAULT '',
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_category (category_id, changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_investors (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        contact_phone VARCHAR(30) NOT NULL DEFAULT '',
        login_identifier VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_login (login_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_share_purchases (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        investor_id INT UNSIGNED NOT NULL,
        purchase_date DATE NOT NULL,
        shares INT UNSIGNED NOT NULL,
        tranche_number TINYINT UNSIGNED NOT NULL,
        amount_paid DECIMAL(14,2) NOT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_investor (investor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_daily_trip_cache (
        trip_date DATE NOT NULL,
        trip_count INT UNSIGNED NOT NULL DEFAULT 0,
        gross_fare DECIMAL(14,2) NOT NULL DEFAULT 0,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (trip_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_distribution_periods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        commission_rate DECIMAL(6,4) NOT NULL,
        gross_fare_total DECIMAL(14,2) NOT NULL,
        commission_revenue DECIMAL(14,2) NOT NULL,
        total_costs DECIMAL(14,2) NOT NULL,
        net_profit DECIMAL(14,2) NOT NULL,
        pool_entitlement DECIMAL(14,2) NOT NULL,
        sold_shares INT UNSIGNED NOT NULL,
        unsold_shares INT UNSIGNED NOT NULL,
        unsold_bounce_theoretical DECIMAL(14,2) NOT NULL,
        unsold_bounce_to_investors DECIMAL(14,2) NOT NULL,
        unsold_bounce_to_founder DECIMAL(14,2) NOT NULL,
        total_investor_payout DECIMAL(14,2) NOT NULL,
        founder_amount DECIMAL(14,2) NOT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_by VARCHAR(150) NOT NULL DEFAULT '',
        PRIMARY KEY (id), KEY idx_period (period_start, period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_distribution_investor_lines (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        period_id INT UNSIGNED NOT NULL,
        investor_id INT UNSIGNED NOT NULL,
        shares_at_time INT UNSIGNED NOT NULL,
        invested_principal_at_time DECIMAL(14,2) NOT NULL,
        cumulative_received_before DECIMAL(14,2) NOT NULL,
        payback_phase TINYINT(1) NOT NULL,
        multiplier DECIMAL(4,2) NOT NULL,
        base_entitlement DECIMAL(14,2) NOT NULL,
        bounce_share DECIMAL(14,2) NOT NULL,
        total_payout DECIMAL(14,2) NOT NULL,
        cumulative_received_after DECIMAL(14,2) NOT NULL,
        PRIMARY KEY (id), KEY idx_period (period_id), KEY idx_investor (investor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_activity_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor VARCHAR(150) NOT NULL DEFAULT '',
        action VARCHAR(60) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ir_get_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    ir_ensure_core_tables($pdo);
    $s = $pdo->prepare('SELECT setting_value FROM ir_settings WHERE setting_key = ?');
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : $v;
}

function ir_set_setting(PDO $pdo, string $key, string $value): void {
    ir_ensure_core_tables($pdo);
    $pdo->prepare('INSERT INTO ir_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
}

function ir_commission_rate(PDO $pdo): float {
    $v = ir_get_setting($pdo, 'commission_rate', '0.05');
    return (float)$v;
}

function ir_is_demo_mode(PDO $pdo): bool {
    return ir_get_setting($pdo, 'demo_mode', '0') === '1';
}

function ir_log(PDO $pdo, string $actor, string $action, string $detail = ''): void {
    try {
        ir_ensure_core_tables($pdo);
        $pdo->prepare('INSERT INTO ir_activity_log (actor, action, detail) VALUES (?,?,?)')
            ->execute([$actor, $action, $detail]);
    } catch (Exception $e) {
        error_log('[InvestorRelations][activity_log] ' . $e->getMessage());
    }
}

// Moved here (from AdminController.php) so SetupController.php's demo
// seeder can reuse the exact same holdings/cumulative logic a real
// distribution run uses - boot.php only ever loads ONE controller file
// per request (routed by action prefix), so a helper only AdminController
// defined was unreachable from SetupController.
function ir_investor_holdings(PDO $pdo, bool $includeDemo, string $asOf = null): array {
    $asOf = $asOf ?? date('Y-m-d');
    $demoClause = $includeDemo ? '' : 'AND p.is_demo = 0';
    $stmt = $pdo->prepare(
        "SELECT i.id, i.name, i.contact_email, i.contact_phone, i.is_demo,
                COALESCE(SUM(p.shares), 0) AS shares_owned,
                COALESCE(SUM(p.amount_paid), 0) AS total_invested
         FROM ir_investors i
         LEFT JOIN ir_share_purchases p ON p.investor_id = i.id AND p.purchase_date <= ? $demoClause
         WHERE i.is_active = 1 " . ($includeDemo ? '' : 'AND i.is_demo = 0') . "
         GROUP BY i.id
         ORDER BY i.name"
    );
    $stmt->execute([$asOf]);
    return $stmt->fetchAll();
}

// Sums an investor's real total_payout across all PRIOR locked periods
// (period_end < the period being calculated), the "cumulative received
// before" the engine needs to determine payback phase.
function ir_cumulative_received_before(PDO $pdo, int $investorId, string $beforePeriodStart, bool $includeDemo): array {
    $demoClause = $includeDemo ? '' : 'AND dp.is_demo = 0';
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(l.total_payout), 0)
         FROM ir_distribution_investor_lines l
         JOIN ir_distribution_periods dp ON dp.id = l.period_id
         WHERE l.investor_id = ? AND dp.period_start < ? $demoClause"
    );
    $stmt->execute([$investorId, $beforePeriodStart]);
    return ['cumulative' => (float)$stmt->fetchColumn()];
}
