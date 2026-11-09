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
}

// Units still open for sale: shares in UNLOCKED tranches minus real (non-demo) shares already bought.
function ir_deal_available_units(PDO $pdo): int {
    ir_ensure_core_tables($pdo);
    $open = (int)$pdo->query('SELECT COALESCE(SUM(shares_total),0) FROM ir_tranches WHERE is_unlocked = 1')->fetchColumn();
    $sold = (int)$pdo->query('SELECT COALESCE(SUM(shares),0) FROM ir_share_purchases WHERE is_demo = 0')->fetchColumn();
    return max(0, min($open - $sold, IR_INVESTOR_POOL_SHARES - $sold));
}

function ir_deal_config(PDO $pdo): array {
    return [
        'unit_price'   => IR_SHARE_PRICE,
        'pool_units'   => IR_INVESTOR_POOL_SHARES,
        'pool_percent' => (IR_INVESTOR_POOL_SHARES / IR_TOTAL_SHARES) * 100,
        'boost'        => IR_BOOSTED_MULTIPLIER,
        'available'    => ir_deal_available_units($pdo),
    ];
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
