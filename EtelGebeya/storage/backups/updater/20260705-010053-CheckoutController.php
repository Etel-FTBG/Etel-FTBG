<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

/**
 * CheckoutController -- order placement + payment gateway redirect.
 */
final class CheckoutController
{
    private Auth $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    /* ?? GET /checkout ????????????????????????????????????? */
    public function index(): void
    {
        $cart = CartController::getCart();
        if (empty($cart)) {
            redirect('/cart');
        }
        if (!$this->auth->check()) {
            $_SESSION['_intended'] = '/checkout';
            redirect('/login');
        }

        $total    = CartController::cartTotal($cart);
        $shipping = $this->shippingFee($cart);
        $flash    = $this->auth->takeFlash();
        $user     = $this->auth->user();
        $locale   = $GLOBALS['__i18n']->locale();
        $addresses = [];
        try {
            $addresses = $this->db->fetchAll(
                "SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC LIMIT 5",
                [$this->auth->id()]
            );
        } catch (\Throwable $_) {}

        echo View::capture('storefront/checkout', [
            'pageTitle' => 'Checkout -- Etel Gebeya',
            'cart'      => $cart,
            'total'     => $total,
            'shipping'  => $shipping,
            'flash'     => $flash,
            'user'      => $user,
            'addresses' => $addresses,
            'locale'    => $locale,
            'csrfToken' => $this->auth->csrfToken(),
        ]);
    }

    /* ?? POST /checkout/place ?????????????????????????????? */
    public function place(): void
    {
        if (!$this->auth->check()) {
            redirect('/login');
        }
        if (!$this->auth->csrfCheck($_POST['_token'] ?? '')) {
            $this->auth->flash('error', 'Invalid form token.');
            redirect('/checkout');
        }

        $cart = CartController::getCart();
        if (empty($cart)) {
            redirect('/cart');
        }
        if (!$this->db->isConnected()) {
            $this->auth->flash('error', 'Database unavailable. Please try again.');
            redirect('/checkout');
        }

        $total    = CartController::cartTotal($cart);
        $shipping = $this->shippingFee($cart);
        $grand    = $total + $shipping;

        // Shipping address
        $name    = trim($_POST['full_name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        // Map form values to DB ENUM values
        $paymentMap = ['chapa'=>'bank_transfer','telebirr'=>'telebirr','cbe'=>'cbe_birr','cod'=>'cash'];
        $rawPayment = $_POST['payment'] ?? 'cod';
        $payment    = $paymentMap[$rawPayment] ?? 'cash';

        if ($name === '' || $city === '' || $address === '') {
            $this->auth->flash('error', 'Please fill in all required shipping fields.');
            redirect('/checkout');
        }

        $now  = date('Y-m-d H:i:s');
        $code = 'EG-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        try {
            $this->db->pdo()->beginTransaction();

            $orderId = $this->db->insert('orders', [
                'user_id'          => $this->auth->id(),
                'order_ref'        => $code,
                'status'           => 'new',
                'payment_method'   => $payment,
                'payment_status'   => 'pending',
                'subtotal'         => $total,
                'delivery_fee'     => $shipping,
                'discount_amount'  => 0,
                'total_amount'     => $grand,
                'platform_fee'     => 0,
                'coupon_discount'  => 0,
                'cashback_points'  => 0,
                'delivery_name'    => $name,
                'delivery_phone'   => $phone,
                'delivery_city'    => $city,
                'delivery_address' => $address,
                'delivery_notes'   => trim($_POST['notes'] ?? ''),
                'delivery_type'    => 'standard',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            // Pre-fetch seller_ids for all cart products
            $pids      = array_unique(array_column(array_values($cart), 'id'));
            $plist     = implode(',', array_map('intval', $pids));
            $sellerMap = [];
            if ($plist !== '') {
                foreach ($this->db->fetchAll("SELECT id, seller_id FROM products WHERE id IN ($plist)") as $row) {
                    $sellerMap[(int)$row['id']] = (int)$row['seller_id'];
                }
            }

            foreach ($cart as $item) {
                $pid       = (int)$item['id'];
                $sellerId  = $sellerMap[$pid] ?? null;
                if (!$sellerId) continue; // skip if product has no seller
                $lineTotal = round((float)$item['price'] * (int)$item['qty'], 2);
                $this->db->insert('order_items', [
                    'order_id'      => $orderId,
                    'product_id'    => $pid,
                    'seller_id'     => $sellerId,
                    'product_name'  => $item['name_en'],
                    'unit_price'    => (float)$item['price'],
                    'quantity'      => (int)$item['qty'],
                    'total_price'   => $lineTotal,
                    'platform_fee'  => 0,
                    'seller_payout' => $lineTotal,
                    'created_at'    => $now,
                ]);
            }

            $this->db->pdo()->commit();

        } catch (\Throwable $e) {
            try { $this->db->pdo()->rollBack(); } catch (\Throwable $_) {}
            error_log('[EG-Checkout] ' . $e->getMessage());
            $this->auth->flash('error', 'Order failed: ' . $e->getMessage());
            redirect('/checkout');
        }

        // Clear cart
        unset($_SESSION['cart']);

        redirect('/checkout/success/' . $code);
    }

    /* ?? GET /checkout/success/{code} ????????????????????? */
    public function success(string $code = ''): void
    {
        $order = null;
        $items = [];

        if ($this->db->isConnected() && $code !== '') {
            $order = $this->db->fetch(
                'SELECT *, order_ref AS code, total_amount AS total, delivery_fee AS shipping_fee,
                         delivery_name AS full_name, delivery_phone AS phone, delivery_city AS city
                   FROM orders WHERE order_ref = ? LIMIT 1', [$code]
            );
            if ($order) {
                $items = $this->db->fetchAll(
                    'SELECT *, product_name AS name_en, unit_price AS price, quantity AS qty, total_price AS subtotal
                       FROM order_items WHERE order_id = ?', [(int)$order['id']]
                );
            }
        }

        $locale = $GLOBALS['__i18n']->locale();
        echo View::capture('storefront/checkout_success', [
            'pageTitle' => 'Order Confirmed -- Etel Gebeya',
            'order'     => $order,
            'items'     => $items,
            'code'      => $code,
            'locale'    => $locale,
        ]);
    }

    /* ?? helpers ??????????????????????????????????????????? */
    private function shippingFee(array $cart): float
    {
        if (empty($cart)) return 0.0;
        $cfg = $GLOBALS['__config']['courier'] ?? [];
        return (float)($cfg['fee_flat_etb'] ?? 30);
    }
}
