<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Models\Category;
use App\Models\Product;

/**
 * StorefrontController -- all public-facing pages.
 */
final class StorefrontController
{
    public function home(): void
    {
        $locale = $GLOBALS['__i18n']->locale();
        $db = $GLOBALS['__db'] ?? null;

        // If DB isn't connected yet, show a minimal "coming soon" page
        if ($db === null || !$db->isConnected()) {
            View::render('storefront/coming-soon', ['locale' => $locale]);
            return;
        }

        $featured = Product::featured(8);
        $recent   = Product::all(12);
        $cats     = Category::topLevel();
        $displayCats = array_slice($cats, 0, 8);

        View::render('storefront/home', [
            'locale'     => $locale,
            'featured'   => $featured,
            'recent'     => $recent,
            'categories' => $displayCats,
        ]);
    }

    public function search(): void
    {
        $q      = trim($_GET['q'] ?? '');
        $locale = $GLOBALS['__i18n']->locale();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $sort   = $_GET['sort'] ?? 'newest';
        $per    = 20;
        $offset = ($page - 1) * $per;

        $db = $GLOBALS['__db'] ?? null;
        $statusClause = "status IN ('published','publish','active')";

        $orderMap = [
            'newest'     => 'created_at DESC',
            'price_asc'  => 'price ASC',
            'price_desc' => 'price DESC',
            'rating'     => 'avg_rating DESC',
            'popular'    => 'sales_count DESC',
            'relevant'   => 'created_at DESC',
        ];
        $orderBy = $orderMap[$sort] ?? 'created_at DESC';

        [$minPrice, $maxPrice, $minRating] = self::parsePriceRatingFilters();

        $filterSqlParts = [];
        $filterParams   = [];
        if ($minPrice !== null) { $filterSqlParts[] = 'price >= ?'; $filterParams[] = $minPrice; }
        if ($maxPrice !== null) { $filterSqlParts[] = 'price <= ?'; $filterParams[] = $maxPrice; }
        if ($minRating !== null) { $filterSqlParts[] = 'avg_rating >= ?'; $filterParams[] = $minRating; }
        $filterSql = $filterSqlParts ? (' AND ' . implode(' AND ', $filterSqlParts)) : '';

        if ($q) {
            $like = '%' . $q . '%';
            $products = Product::search($q, $per, $offset, $orderBy, $minPrice, $maxPrice, $minRating);
            $total = 0;
            if ($db && $db->isConnected()) {
                try {
                    $total = (int)$db->fetchColumn(
                        "SELECT COUNT(*) FROM products WHERE $statusClause AND (name_en LIKE ? OR name_am LIKE ? OR description_en LIKE ? OR description_am LIKE ?)$filterSql",
                        array_merge([$like, $like, $like, $like], $filterParams)
                    );
                } catch (\Throwable $_) { $total = count($products); }
            }
        } else {
            $products = Product::all($per, $offset, $orderBy, $minPrice, $maxPrice, $minRating);
            $total = 0;
            if ($db && $db->isConnected()) {
                try {
                    $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM products WHERE $statusClause$filterSql", $filterParams);
                } catch (\Throwable $_) { $total = 0; }
            }
        }

        View::render('storefront/search', [
            'q'         => $q,
            'locale'    => $locale,
            'products'  => $products,
            'page'      => $page,
            'per'       => $per,
            'total'     => $total,
        ]);
    }

    public function category(string $slug): void
    {
        $locale = $GLOBALS['__i18n']->locale();
        $cat    = Category::bySlug($slug);
        if (!$cat) { http_response_code(404); View::render('errors/404', ['path' => $slug]); return; }

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $per     = 20;
        $offset  = ($page - 1) * $per;

        [$minPrice, $maxPrice, $minRating] = self::parsePriceRatingFilters();
        $sort    = $_GET['sort'] ?? 'newest';
        $orderMap = [
            'newest' => 'created_at DESC', 'price_asc' => 'price ASC', 'price_desc' => 'price DESC',
            'rating' => 'avg_rating DESC', 'popular' => 'sales_count DESC',
        ];
        $orderBy = $orderMap[$sort] ?? 'created_at DESC';

        $products = Product::byCategory($cat->id, $per, $offset, $orderBy, $minPrice, $maxPrice, $minRating);

        View::render('storefront/category', [
            'locale'   => $locale,
            'category' => $cat,
            'products' => $products,
            'page'     => $page,
            'per'      => $per,
        ]);
    }

    /** Shared "price=0-500|500-2000|10000+" and "rating=4" query-param parsing. */
    private static function parsePriceRatingFilters(): array
    {
        $minPrice = null; $maxPrice = null;
        $priceParam = trim($_GET['price'] ?? '');
        if ($priceParam !== '') {
            if (str_ends_with($priceParam, '+')) {
                $minPrice = (float)rtrim($priceParam, '+');
            } elseif (str_contains($priceParam, '-')) {
                [$lo, $hi] = explode('-', $priceParam, 2);
                $minPrice = (float)$lo;
                $maxPrice = (float)$hi;
            }
        }
        $minRating = null;
        $ratingParam = trim($_GET['rating'] ?? '');
        if ($ratingParam !== '' && is_numeric($ratingParam)) {
            $minRating = (float)$ratingParam;
        }
        return [$minPrice, $maxPrice, $minRating];
    }

    public function product(string $slug): void
    {
        $locale  = $GLOBALS['__i18n']->locale();
        $product = Product::bySlug($slug);
        if (!$product) { http_response_code(404); View::render('errors/404', ['path' => $slug]); return; }

        // Increment view count (fire-and-forget, ignore if column missing)
        try { $GLOBALS['__db']->query("UPDATE products SET view_count = view_count + 1 WHERE id = ?", [$product->id]); } catch (\Throwable $_) {}

        View::render('storefront/product', [
            'locale'  => $locale,
            'product' => $product,
        ]);
    }

    public function vendor(string $slug): void
    {
        $locale = $GLOBALS['__i18n']->locale();
        $db     = $GLOBALS['__db'];

        // Try by store_slug first, then by store_slug-{id} suffix pattern
        $seller = $db->fetch(
            "SELECT s.*, u.full_name FROM sellers s JOIN users u ON u.id = s.user_id
              WHERE s.store_slug = ? AND s.status = 'approved' LIMIT 1",
            [$slug]
        );
        // Fallback: slug may have a -hex suffix added at registration time
        if (!$seller) {
            $seller = $db->fetch(
                "SELECT s.*, u.full_name FROM sellers s JOIN users u ON u.id = s.user_id
                  WHERE s.status = 'approved'
                    AND (s.store_slug = ? OR CONCAT(s.store_slug,'-',LOWER(HEX(s.id))) = ?) LIMIT 1",
                [$slug, $slug]
            );
        }
        if (!$seller) { http_response_code(404); View::render('errors/404', ['path' => $slug]); return; }

        // Alias to 'vendor' keys the view expects
        $vendor = array_merge($seller, [
            'id'        => $seller['id'],
            'shop_name' => $seller['store_name'],
            'shop_slug' => $seller['store_slug'],
            'tagline_en'=> $seller['store_name'],
            'tagline_am'=> $seller['store_name_am'] ?? $seller['store_name'],
        ]);

        $sid      = (int)$seller['id'];
        $products = $db->fetchAll(
            "SELECT p.*,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) AS image
               FROM products p
              WHERE p.seller_id = ? AND p.status IN ('published','publish','active')
              ORDER BY p.created_at DESC LIMIT 24",
            [$sid]
        );

        View::render('storefront/vendor', [
            'locale'   => $locale,
            'vendor'   => $vendor,
            'seller'   => $seller,
            'products' => $products,
        ]);
    }
}
