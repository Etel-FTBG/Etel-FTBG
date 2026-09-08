<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Core\Database;

/**
 * CartController -- session-based cart (no DB required until checkout).
 *
 * Session cart structure:
 *   $_SESSION['cart'] = [
 *     'product_id' => [
 *       'id', 'name_en', 'name_am', 'price', 'image', 'slug', 'vendor', 'qty', 'stock'
 *     ]
 *   ]
 */
final class CartController
{
    /* ?? GET /cart ????????????????????????????????????????? */
    public function index(): void
    {
        $cart   = $this->getCart();
        $total  = $this->cartTotal($cart);
        $locale = $GLOBALS['__i18n']->locale();
        echo View::capture('storefront/cart', [
            'pageTitle' => 'Shopping Cart -- Etel Gebeya',
            'cart'      => $cart,
            'total'     => $total,
            'locale'    => $locale,
        ]);
    }

    /* ?? POST /cart/add ???????????????????????????????????? */
    public function add(): void
    {
        $id  = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));

        if ($id < 1) {
            $this->jsonErr('Invalid product');
        }

        $db = Database::getInstance();
        if (!$db->isConnected()) {
            $this->jsonErr('Database unavailable');
        }

        $p = $db->fetch(
            "SELECT id, name_en, name_am, price, compare_price, stock_qty, slug, seller_id,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id=products.id ORDER BY pi.sort_order LIMIT 1) AS image,
                    (SELECT s.store_name FROM sellers s WHERE s.id=products.seller_id LIMIT 1) AS vendor_name
             FROM products WHERE id = ? AND status IN ('published','publish','active') LIMIT 1",
            [$id]
        );

        if (!$p) {
            $this->jsonErr('Product not found or unavailable');
        }

        // This schema has no per-product "track stock" toggle -- stock_qty
        // alone is the source of truth, and 0 always means out of stock.
        $stockQty = (int)($p['stock_qty'] ?? 0);
        if ($stockQty <= 0) {
            $this->jsonErr('This product is out of stock');
        }

        $cart = $this->getCart();
        $key  = (string)$id;

        if (isset($cart[$key])) {
            $newQty = (int)$cart[$key]['qty'] + $qty;
            $cart[$key]['qty'] = min($newQty, $stockQty);
        } else {
            $cart[$key] = [
                'id'        => $p['id'],
                'seller_id' => (int)($p['seller_id'] ?? 0),
                'name_en'   => $p['name_en'],
                'name_am'   => $p['name_am'] ?? $p['name_en'],
                'price'     => (float)$p['price'],
                'image'     => $p['image'] ?? '',
                'slug'      => $p['slug'],
                'vendor'    => $p['vendor_name'] ?? '',
                'stock'     => (int)($p['stock_qty'] ?? 99),
                'qty'       => $qty,
            ];
        }

        $this->saveCart($cart);
        $this->jsonOk([
            'count' => $this->cartCount($cart),
            'total' => $this->cartTotal($cart),
            'msg'   => htmlspecialchars($p['name_en']) . ' added to cart',
        ]);
    }

    /* ?? POST /cart/update ????????????????????????????????? */
    public function update(): void
    {
        $id  = (string)(int)($_POST['product_id'] ?? 0);
        $qty = max(0, (int)($_POST['qty'] ?? 0));

        $cart = $this->getCart();
        if ($qty === 0) {
            unset($cart[$id]);
        } elseif (isset($cart[$id])) {
            $cart[$id]['qty'] = min($qty, $cart[$id]['stock']);
        }

        $this->saveCart($cart);
        $this->jsonOk([
            'count'    => $this->cartCount($cart),
            'total'    => $this->cartTotal($cart),
            'subtotal' => isset($cart[$id]) ? $cart[$id]['price'] * $cart[$id]['qty'] : 0,
        ]);
    }

    /* ?? POST /cart/remove ????????????????????????????????? */
    public function remove(): void
    {
        $id   = (string)(int)($_POST['product_id'] ?? 0);
        $cart = $this->getCart();
        unset($cart[$id]);
        $this->saveCart($cart);
        $this->jsonOk(['count' => $this->cartCount($cart), 'total' => $this->cartTotal($cart)]);
    }

    /* ?? static helpers ???????????????????????????????????? */
    public static function getCart(): array
    {
        return $_SESSION['cart'] ?? [];
    }

    public static function cartCount(array $cart): int
    {
        return array_sum(array_column($cart, 'qty'));
    }

    public static function cartTotal(array $cart): float
    {
        $t = 0.0;
        foreach ($cart as $item) {
            $t += (float)$item['price'] * (int)$item['qty'];
        }
        return $t;
    }

    private function saveCart(array $cart): void
    {
        $_SESSION['cart'] = $cart;
    }

    private function jsonOk(array $data): never
    {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, ...$data]);
        exit;
    }

    private function jsonErr(string $msg, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}
