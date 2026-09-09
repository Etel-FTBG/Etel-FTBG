<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

/**
 * A vendor's own external dropship supplier -- who a vendor forwards an
 * order to when they sell a product they don't stock themselves.
 *
 * seller_id is nullable on purpose: every supplier created today belongs
 * to one vendor (seller_id set), but a future platform-run supplier
 * catalog (suppliers onboarded directly by Etel Gebeya, sellable by any
 * vendor) can reuse this same table with seller_id left NULL, rather than
 * needing a second table and a schema migration later.
 */
final class Supplier
{
    /** Creates the table / adds columns this feature needs if missing -- no manual SQL step on deploy. */
    public static function ensureSchema(Database $db): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        if (!$db->isConnected()) return;

        try {
            $db->pdo()->exec(
                "CREATE TABLE IF NOT EXISTS suppliers (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    seller_id INT UNSIGNED DEFAULT NULL,
                    name VARCHAR(160) NOT NULL,
                    contact_email VARCHAR(190) DEFAULT NULL,
                    contact_phone VARCHAR(32) DEFAULT NULL,
                    website VARCHAR(255) DEFAULT NULL,
                    notes VARCHAR(500) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_seller (seller_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {}

        \App\Core\SchemaGuard::ensureColumns($db, 'products', [
            'is_dropship'            => "is_dropship TINYINT(1) NOT NULL DEFAULT 0",
            'supplier_id'            => "supplier_id INT UNSIGNED DEFAULT NULL",
            'supplier_sku'           => "supplier_sku VARCHAR(120) DEFAULT NULL",
            'supplier_product_url'   => "supplier_product_url VARCHAR(500) DEFAULT NULL",
            'dropship_lead_days_min' => "dropship_lead_days_min SMALLINT UNSIGNED DEFAULT NULL",
            'dropship_lead_days_max' => "dropship_lead_days_max SMALLINT UNSIGNED DEFAULT NULL",
        ]);

        \App\Core\SchemaGuard::ensureColumns($db, 'order_items', [
            'is_dropship'    => "is_dropship TINYINT(1) NOT NULL DEFAULT 0",
            'supplier_id'    => "supplier_id INT UNSIGNED DEFAULT NULL",
            'forwarded_at'   => "forwarded_at DATETIME DEFAULT NULL",
            'forwarded_by'   => "forwarded_by INT UNSIGNED DEFAULT NULL",
            'forward_method' => "forward_method VARCHAR(20) DEFAULT NULL",
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forSeller(Database $db, int $sellerId): array
    {
        self::ensureSchema($db);
        try {
            return $db->fetchAll(
                "SELECT * FROM suppliers WHERE seller_id = ? ORDER BY name ASC",
                [$sellerId]
            );
        } catch (\Throwable $_) { return []; }
    }

    public static function find(Database $db, int $id, int $sellerId): ?array
    {
        self::ensureSchema($db);
        try {
            $row = $db->fetch(
                "SELECT * FROM suppliers WHERE id = ? AND seller_id = ?",
                [$id, $sellerId]
            );
        } catch (\Throwable $_) { $row = null; }
        return $row ?: null;
    }
}
