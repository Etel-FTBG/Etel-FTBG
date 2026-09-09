<?php
declare(strict_types=1);
namespace App\Models;

/**
 * Product -- for fetching product listings.
 */
final class Product
{
    public readonly int $id;
    public readonly int $vendorId;
    public readonly ?int $categoryId;
    public readonly string $nameEn;
    public readonly string $nameAm;
    public readonly string $slug;
    public readonly string $descriptionEn;
    public readonly string $descriptionAm;
    public readonly float $price;
    public readonly ?float $comparePrice;
    public readonly string $currency;
    public readonly ?string $sku;
    public readonly int $stockQty;
    public readonly int $lowStockAt;
    public readonly bool $trackStock;
    public readonly ?float $weightKg;
    public readonly string $status;
    public readonly bool $isFeatured;
    public readonly float $ratingAvg;
    public readonly int $ratingCount;
    public readonly int $soldCount;
    public readonly int $viewCount;
    public readonly string $createdAt;

    /** @var string[] */
    private array $images = [];

    public function __construct(array $row)
    {
        $this->id           = (int)($row['id'] ?? 0);
        $this->vendorId     = (int)($row['seller_id'] ?? $row['vendor_id'] ?? 0);
        $this->categoryId   = isset($row['category_id']) && $row['category_id'] !== null ? (int)$row['category_id'] : null;
        $this->nameEn       = (string)($row['name_en'] ?? '');
        $this->nameAm       = (string)($row['name_am'] ?? $row['name_en'] ?? '');
        $this->slug         = (string)($row['slug'] ?? '');
        $this->descriptionEn= (string)($row['description_en'] ?? '');
        $this->descriptionAm= (string)($row['description_am'] ?? $row['description_en'] ?? '');
        $this->price        = (float)($row['price'] ?? 0);
        $this->comparePrice = isset($row['compare_price']) && $row['compare_price'] !== null ? (float)$row['compare_price'] : null;
        $this->currency     = (string)($row['currency'] ?? 'ETB');
        $this->sku          = isset($row['sku']) && $row['sku'] !== null ? (string)$row['sku'] : null;
        $this->stockQty     = (int)($row['stock_qty'] ?? 0);
        $this->lowStockAt   = (int)($row['low_stock_alert'] ?? $row['low_stock_at'] ?? 5);
        $this->trackStock   = !empty($row['track_stock']);
        $this->weightKg     = isset($row['weight_grams']) && $row['weight_grams'] !== null ? (float)$row['weight_grams'] / 1000 : null;
        $this->status       = (string)($row['status'] ?? 'active');
        $this->isFeatured   = !empty($row['is_featured']);
        $this->ratingAvg    = (float)($row['avg_rating'] ?? $row['rating_avg'] ?? 0);
        $this->ratingCount  = (int)($row['review_count'] ?? $row['rating_count'] ?? 0);
        $this->soldCount    = (int)($row['sales_count'] ?? $row['sold_count'] ?? 0);
        $this->viewCount    = (int)($row['view_count'] ?? 0);
        $this->createdAt    = (string)($row['created_at'] ?? '');
    }

    public function name(string $locale = 'en'): string
    {
        $n = $locale === 'am' ? ($this->nameAm ?: $this->nameEn) : $this->nameEn;
        return $n ?: $this->nameEn;
    }

    public function shortDesc(string $locale = 'en', int $len = 100): string
    {
        $d = $locale === 'am' ? ($this->descriptionAm ?: $this->descriptionEn) : ($this->descriptionEn ?: $this->descriptionAm);
        $d = strip_tags((string)$d);
        return mb_strlen($d) > $len ? mb_substr($d, 0, $len, 'UTF-8') . '...' : $d;
    }

    public function primaryImage(): string
    {
        if (!empty($this->images)) {
            $img = $this->images[0];
            if (str_starts_with($img, 'http')) return $img;
            // Uploads are saved with an already-site-absolute path
            // (see VendorController's $uploadDir) -- don't re-prefix those,
            // only bare filenames that never got a path stored.
            if (str_starts_with($img, '/')) return $img;
            return '/EtelGebeya/public/uploads/products/' . $img;
        }
        return "https://picsum.photos/seed/{$this->id}/400/400";
    }

    public function hasDiscount(): bool
    {
        return $this->comparePrice !== null && $this->comparePrice > $this->price;
    }

    public function discountPct(): int
    {
        if (!$this->hasDiscount()) return 0;
        return (int)round(100 - ($this->price / $this->comparePrice * 100));
    }

    // NOTE: this schema has no per-product "track stock" toggle column,
    // so trackStock is always false and can't gate these checks (it did
    // before, which silently made inStock() always return true and the
    // storefront's out-of-stock badge a no-op). stock_qty alone is the
    // real source of truth here -- 0 always means out of stock.
    public function inStock(): bool
    {
        return $this->stockQty > 0;
    }

    public function lowStock(): bool
    {
        return $this->stockQty > 0 && $this->stockQty <= $this->lowStockAt;
    }

    // ---- static fetchers ----

    /** Status values accepted as "published" (handles WP 'publish' and our 'published') */
    private static function statusClause(): string
    {
        return "status IN ('published','publish','active')";
    }

    public static function all(int $limit = 20, int $offset = 0, string $orderBy = 'created_at DESC', ?float $minPrice = null, ?float $maxPrice = null, ?float $minRating = null): array
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $order  = self::safeOrder($orderBy);
        [$filterSql, $params] = self::filterClause($minPrice, $maxPrice, $minRating);
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM products WHERE " . self::statusClause() . $filterSql . " ORDER BY $order LIMIT $limit OFFSET $offset",
                $params
            );
        } catch (\Throwable $_) { $rows = []; }
        return array_values(array_filter(array_map(function($r) {
            try { return self::fromRow($r); } catch (\Throwable $_) { return null; }
        }, $rows)));
    }

    /** Builds " AND price >= ? AND price <= ? AND avg_rating >= ?"-style SQL + bound params. */
    private static function filterClause(?float $minPrice, ?float $maxPrice, ?float $minRating): array
    {
        $sql = ''; $params = [];
        if ($minPrice !== null) { $sql .= ' AND price >= ?'; $params[] = $minPrice; }
        if ($maxPrice !== null) { $sql .= ' AND price <= ?'; $params[] = $maxPrice; }
        if ($minRating !== null) { $sql .= ' AND avg_rating >= ?'; $params[] = $minRating; }
        return [$sql, $params];
    }

    public static function featured(int $limit = 8): array
    {
        $limit = max(1, $limit);
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM products WHERE " . self::statusClause() . " AND is_featured = 1 ORDER BY created_at DESC LIMIT $limit"
            );
            if (empty($rows)) {
                $rows = $GLOBALS['__db']->fetchAll(
                    "SELECT * FROM products WHERE " . self::statusClause() . " ORDER BY created_at DESC LIMIT $limit"
                );
            }
        } catch (\Throwable $_) {
            try {
                $rows = $GLOBALS['__db']->fetchAll(
                    "SELECT * FROM products WHERE " . self::statusClause() . " ORDER BY created_at DESC LIMIT $limit"
                );
            } catch (\Throwable $_) { $rows = []; }
        }
        return array_values(array_filter(array_map(function($r) {
            try { return self::fromRow($r); } catch (\Throwable $_) { return null; }
        }, $rows)));
    }

    public static function byCategory(int $categoryId, int $limit = 20, int $offset = 0, string $orderBy = 'created_at DESC', ?float $minPrice = null, ?float $maxPrice = null, ?float $minRating = null): array
    {
        $limit = max(1, $limit); $offset = max(0, $offset);
        $order = self::safeOrder($orderBy);
        [$filterSql, $filterParams] = self::filterClause($minPrice, $maxPrice, $minRating);
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM products WHERE " . self::statusClause() . " AND category_id = ?" . $filterSql . " ORDER BY $order LIMIT $limit OFFSET $offset",
                array_merge([$categoryId], $filterParams)
            );
        } catch (\Throwable $_) { $rows = []; }
        return array_values(array_filter(array_map(function($r) {
            try { return self::fromRow($r); } catch (\Throwable $_) { return null; }
        }, $rows)));
    }

    public static function bySlug(string $slug): ?self
    {
        try {
            $row = $GLOBALS['__db']->fetch(
                "SELECT * FROM products WHERE slug = ? AND " . self::statusClause(), [$slug]
            );
        } catch (\Throwable $_) { $row = null; }
        return $row ? self::fromRow($row) : null;
    }

    public static function search(string $q, int $limit = 20, int $offset = 0, string $orderBy = 'created_at DESC', ?float $minPrice = null, ?float $maxPrice = null, ?float $minRating = null): array
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $order  = self::safeOrder($orderBy);
        $like   = '%' . $q . '%';
        [$filterSql, $filterParams] = self::filterClause($minPrice, $maxPrice, $minRating);
        try {
            $rows = $GLOBALS['__db']->fetchAll(
                "SELECT * FROM products WHERE " . self::statusClause() .
                " AND (name_en LIKE ? OR name_am LIKE ? OR description_en LIKE ? OR description_am LIKE ?)" . $filterSql . "
                 ORDER BY $order LIMIT $limit OFFSET $offset",
                array_merge([$like, $like, $like, $like], $filterParams)
            );
        } catch (\Throwable $_) { $rows = []; }
        return array_values(array_filter(array_map(function($r) {
            try { return self::fromRow($r); } catch (\Throwable $_) { return null; }
        }, $rows)));
    }

    private static function safeOrder(string $orderBy): string
    {
        $allowed = [
            'created_at DESC', 'created_at ASC',
            'price ASC', 'price DESC',
            'avg_rating DESC', 'sales_count DESC',
        ];
        return in_array($orderBy, $allowed, true) ? $orderBy : 'created_at DESC';
    }

    private static function fromRow(array $row): self
    {
        $id = (int)($row['id'] ?? 0);
        if ($id) {
            try {
                $imgRows = $GLOBALS['__db']->fetchAll(
                    "SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order",
                    [$id]
                );
                $row['_images'] = array_column($imgRows, 'path');
            } catch (\Throwable $_) {
                $row['_images'] = [];
            }
        }
        $p = new self($row);
        if (!empty($row['_images'])) {
            $p->images = $row['_images'];
        }
        return $p;
    }

    public function loadImages(): void
    {
        if (!empty($this->images)) return;
        $imgRows = $GLOBALS['__db']->fetchAll(
            "SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order",
            [$this->id]
        );
        $this->images = array_column($imgRows, 'path');
    }
}
