<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

/**
 * A platform-run supplier catalog item -- admin-curated stock (sourced from
 * a platform-level Supplier, see Supplier::platformSuppliers()) that any
 * vendor can import as their own dropship product. Importing copies a
 * snapshot into `products` (own seller_id, own chosen sale price) rather
 * than vendors selling this row directly -- Etel Gebeya is never itself a
 * seller, per the agreed scope; a catalog item is a source vendors import
 * from, nothing more.
 */
final class CatalogItem
{
    /** @return array<int,array<string,mixed>> */
    public static function browse(Database $db, string $q = '', int $limit = 24, int $offset = 0): array
    {
        Supplier::ensureSchema($db);
        $where  = "ci.status = 'active'";
        $params = [];
        if ($q !== '') {
            $where .= " AND (ci.name_en LIKE ? OR ci.sku LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like;
        }
        try {
            return $db->fetchAll(
                "SELECT ci.*, s.name AS supplier_name
                   FROM catalog_items ci
                   JOIN suppliers s ON s.id = ci.supplier_id
                  WHERE $where
                  ORDER BY ci.created_at DESC LIMIT $limit OFFSET $offset",
                $params
            );
        } catch (\Throwable $_) { return []; }
    }

    public static function countActive(Database $db, string $q = ''): int
    {
        Supplier::ensureSchema($db);
        $where  = "status = 'active'";
        $params = [];
        if ($q !== '') {
            $where .= " AND (name_en LIKE ? OR sku LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like;
        }
        try {
            return (int)($db->fetchColumn("SELECT COUNT(*) FROM catalog_items WHERE $where", $params) ?: 0);
        } catch (\Throwable $_) { return 0; }
    }

    public static function find(Database $db, int $id): ?array
    {
        Supplier::ensureSchema($db);
        try {
            $row = $db->fetch(
                "SELECT ci.*, s.name AS supplier_name
                   FROM catalog_items ci JOIN suppliers s ON s.id = ci.supplier_id
                  WHERE ci.id = ?",
                [$id]
            );
        } catch (\Throwable $_) { $row = null; }
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAdmin(Database $db, int $limit = 25, int $offset = 0): array
    {
        Supplier::ensureSchema($db);
        try {
            return $db->fetchAll(
                "SELECT ci.*, s.name AS supplier_name
                   FROM catalog_items ci JOIN suppliers s ON s.id = ci.supplier_id
                  ORDER BY ci.created_at DESC LIMIT $limit OFFSET $offset"
            );
        } catch (\Throwable $_) { return []; }
    }

    public static function countAll(Database $db): int
    {
        Supplier::ensureSchema($db);
        try {
            return (int)($db->fetchColumn("SELECT COUNT(*) FROM catalog_items") ?: 0);
        } catch (\Throwable $_) { return 0; }
    }

    /** Catalog-item-ids this seller has already imported -> their resulting product_id. */
    public static function importedMapForSeller(Database $db, int $sellerId): array
    {
        Supplier::ensureSchema($db);
        try {
            $rows = $db->fetchAll(
                "SELECT catalog_item_id, product_id FROM catalog_item_imports WHERE seller_id = ?",
                [$sellerId]
            );
        } catch (\Throwable $_) { return []; }
        $map = [];
        foreach ($rows as $r) { $map[(int)$r['catalog_item_id']] = (int)$r['product_id']; }
        return $map;
    }

    /**
     * Imports one catalog item into a vendor's own store as a new dropship
     * product. Returns the new product id, or null if this seller already
     * imported it (see catalog_item_imports' unique key) or the item wasn't found.
     */
    public static function importForSeller(Database $db, int $catalogItemId, int $sellerId, float $salePrice, int $stockQty = 100): ?int
    {
        $item = self::find($db, $catalogItemId);
        if (!$item) return null;

        $existing = $db->fetch(
            "SELECT product_id FROM catalog_item_imports WHERE catalog_item_id = ? AND seller_id = ?",
            [$catalogItemId, $sellerId]
        );
        if ($existing) return (int)$existing['product_id'];

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($item['name_en'])) . '-' . substr(md5($item['name_en'] . $sellerId . microtime()), 0, 6);

        $productId = (int)$db->insert('products', [
            'seller_id'              => $sellerId,
            'category_id'            => $item['category_id'],
            'name_en'                => $item['name_en'],
            'name_am'                => $item['name_am'] ?: $item['name_en'],
            'slug'                   => $slug,
            'description_en'         => $item['description_en'],
            'sku'                    => $item['sku'],
            'price'                  => $salePrice,
            'cost_price'             => $item['cost_price'],
            'stock_qty'              => $stockQty,
            'status'                 => 'active',
            'is_dropship'            => 1,
            'supplier_id'            => $item['supplier_id'],
            'supplier_sku'           => $item['sku'],
            'dropship_lead_days_min' => $item['dropship_lead_days_min'],
            'dropship_lead_days_max' => $item['dropship_lead_days_max'],
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        if (!empty($item['image_url'])) {
            try {
                $db->insert('product_images', [
                    'product_id' => $productId,
                    'sort_order' => 0,
                    'path'       => $item['image_url'],
                ]);
                $sort = 1;
                foreach (explode(',', (string)($item['gallery_urls'] ?? '')) as $url) {
                    $url = trim($url);
                    if ($url === '') continue;
                    $db->insert('product_images', ['product_id' => $productId, 'sort_order' => $sort++, 'path' => $url]);
                }
            } catch (\Throwable $_) {}
        }

        $db->insert('catalog_item_imports', [
            'catalog_item_id' => $catalogItemId,
            'seller_id'       => $sellerId,
            'product_id'      => $productId,
            'imported_at'     => date('Y-m-d H:i:s'),
        ]);

        return $productId;
    }

    /**
     * Parses an uploaded CSV (header row required: name_en,sku,cost_price,
     * suggested_markup_pct,category_id,image_url,description_en,
     * lead_days_min,lead_days_max -- any subset works, unknown columns
     * ignored) and inserts one catalog_items row per data row for the given
     * supplier. Returns ['inserted' => int, 'errors' => string[]].
     */
    public static function importCsv(Database $db, int $supplierId, string $filePath): array
    {
        Supplier::ensureSchema($db);
        $inserted = 0;
        $errors   = [];

        $fh = @fopen($filePath, 'r');
        if (!$fh) return ['inserted' => 0, 'errors' => ['Could not read the uploaded file.']];

        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return ['inserted' => 0, 'errors' => ['File appears empty.']]; }
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

        $rowNum = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue; // blank line
            $data = [];
            foreach ($header as $i => $col) { $data[$col] = $row[$i] ?? null; }

            $nameEn = trim((string)($data['name_en'] ?? ''));
            $cost   = (float)($data['cost_price'] ?? 0);
            if ($nameEn === '' || $cost <= 0) {
                $errors[] = "Row $rowNum: name_en and a positive cost_price are required.";
                continue;
            }

            try {
                $db->insert('catalog_items', [
                    'supplier_id'            => $supplierId,
                    'category_id'            => (int)($data['category_id'] ?? 0) ?: null,
                    'name_en'                => $nameEn,
                    'name_am'                => trim((string)($data['name_am'] ?? '')) ?: null,
                    'description_en'         => trim((string)($data['description_en'] ?? '')) ?: null,
                    'sku'                    => trim((string)($data['sku'] ?? '')) ?: null,
                    'cost_price'             => $cost,
                    'suggested_markup_pct'   => (float)($data['suggested_markup_pct'] ?? 30),
                    'image_url'              => trim((string)($data['image_url'] ?? '')) ?: null,
                    'gallery_urls'           => trim((string)($data['gallery_urls'] ?? '')) ?: null,
                    'dropship_lead_days_min' => (int)($data['lead_days_min'] ?? 0) ?: null,
                    'dropship_lead_days_max' => (int)($data['lead_days_max'] ?? 0) ?: null,
                    'status'                 => 'active',
                    'created_at'             => date('Y-m-d H:i:s'),
                    'updated_at'             => date('Y-m-d H:i:s'),
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = "Row $rowNum: could not save (" . $e->getMessage() . ").";
            }
        }
        fclose($fh);

        return ['inserted' => $inserted, 'errors' => $errors];
    }
}
