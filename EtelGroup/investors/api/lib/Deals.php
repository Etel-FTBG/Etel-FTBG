<?php
// ============================================================
// Investor Relations - digital Investor Deal Sheet (helpers)
// One row per signed sheet in ir_deal_sheets. Self-healing table
// create, same convention as the rest of this module.
// ASCII only (this host's WAF has corrupted non-ASCII PHP before).
// ============================================================
declare(strict_types=1);

function ir_deal_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ir_deal_sheets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_code VARCHAR(24) NOT NULL,
        lang CHAR(2) NOT NULL DEFAULT 'en',
        status VARCHAR(12) NOT NULL DEFAULT 'submitted',
        investor_name VARCHAR(150) NOT NULL,
        id_number VARCHAR(80) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        units INT UNSIGNED NOT NULL,
        unit_price DECIMAL(14,2) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        pool_share_pct DECIMAL(8,4) NOT NULL,
        payout_period VARCHAR(12) NOT NULL DEFAULT 'monthly',
        payout_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        closing_date DATE NULL,
        bank_account VARCHAR(190) NOT NULL DEFAULT '',
        signature_name VARCHAR(150) NOT NULL,
        signature_png MEDIUMTEXT NOT NULL,
        signed_at DATETIME NOT NULL,
        doc_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL DEFAULT '',
        user_agent VARCHAR(190) NOT NULL DEFAULT '',
        reviewed_by VARCHAR(150) NOT NULL DEFAULT '',
        reviewed_at DATETIME NULL,
        review_note VARCHAR(500) NOT NULL DEFAULT '',
        investor_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_ref (ref_code), KEY idx_status (status, created_at), KEY idx_ip (ip_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$pdo->query("SHOW COLUMNS FROM ir_deal_sheets LIKE 'stage'")->fetch()) {
        $pdo->exec("ALTER TABLE ir_deal_sheets ADD COLUMN stage TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status");
    }
}

// The stages of the raise with how many real (non-demo) shares each has sold.
function ir_stages(PDO $pdo): array {
    ir_ensure_core_tables($pdo);
    $sold = [];
    foreach ($pdo->query('SELECT tranche_number, COALESCE(SUM(shares),0) s FROM ir_share_purchases WHERE is_demo = 0 GROUP BY tranche_number')->fetchAll() as $r) {
        $sold[(int)$r['tranche_number']] = (int)$r['s'];
    }
    $out = [];
    foreach ($pdo->query('SELECT * FROM ir_tranches ORDER BY tranche_number')->fetchAll() as $t) {
        $n = (int)$t['tranche_number'];
        $total = (int)$t['shares_total'];
        $price = (float)$t['price_per_share'];
        $s = $sold[$n] ?? 0;
        $out[] = [
            'stage' => $n, 'shares_total' => $total, 'price' => $price,
            'raise' => round($total * $price, 2), 'unlock_trips' => (int)$t['unlock_trips'],
            'unlocked' => (bool)$t['is_unlocked'], 'sold' => $s, 'remaining' => max(0, $total - $s),
            'company_pct' => round($total / IR_TOTAL_SHARES * 100, 2),
        ];
    }
    return $out;
}

// The stage investors can buy in right now: the first unlocked stage that still has shares.
function ir_current_stage(PDO $pdo): ?array {
    foreach (ir_stages($pdo) as $st) {
        if ($st['unlocked'] && $st['remaining'] > 0) return $st;
    }
    return null;
}

// Units still open for sale in the current stage (never more than the 2,000-share pool).
function ir_deal_available_units(PDO $pdo): int {
    $st = ir_current_stage($pdo);
    return $st ? $st['remaining'] : 0;
}

function ir_deal_config(PDO $pdo): array {
    $st = ir_current_stage($pdo);
    return [
        'unit_price'   => $st ? $st['price'] : (float)IR_SHARE_PRICE,
        'stage'        => $st ? $st['stage'] : 0,
        'stages'       => ir_stages($pdo),
        'demo_mode'    => ir_is_demo_mode($pdo),
        'pool_units'   => IR_INVESTOR_POOL_SHARES,
        'pool_percent' => (IR_INVESTOR_POOL_SHARES / IR_TOTAL_SHARES) * 100,
        'boost'        => IR_BOOSTED_MULTIPLIER,
        'available'    => $st ? $st['remaining'] : 0,
        'companies'    => ir_group_companies($pdo),
        'launch'       => (function () use ($pdo) { require_once __DIR__ . '/Launch.php'; $l = ir_launch_state($pdo); return ['active' => $l['active'], 'until' => $l['until']]; })(),
    ];
}

// Other Etel Group companies (name, line, live or coming soon) for the "also in the group" card.
function ir_group_companies(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT name, tagline, status FROM companies WHERE slug NOT IN ('etel-delivery','luxline') ORDER BY (status = 'live') DESC, sort_order, id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['name' => (string)$r['name'], 'tagline' => (string)$r['tagline'], 'live' => $r['status'] === 'live'];
    }
    return $out;
}

function ir_deal_new_ref(PDO $pdo): string {
    for ($i = 0; $i < 8; $i++) {
        $ref = 'EG-INV-' . date('y') . '-' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $s = $pdo->prepare('SELECT 1 FROM ir_deal_sheets WHERE ref_code = ?');
        $s->execute([$ref]);
        if (!$s->fetchColumn()) return $ref;
    }
    throw new Exception('Could not allocate a reference code');
}

function ir_deal_ip_hash(): string {
    return hash('sha256', 'ir-deal|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

// Returns the decoded PNG bytes length if $dataUrl is a plausible PNG data URL, else 0.
function ir_deal_png_size(string $dataUrl): int {
    $prefix = 'data:image/png;base64,';
    if (strncmp($dataUrl, $prefix, strlen($prefix)) !== 0) return 0;
    $bin = base64_decode(substr($dataUrl, strlen($prefix)), true);
    if ($bin === false || strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) return 0;
    return strlen($bin);
}

function ir_deal_random_password(int $len = 10): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}
