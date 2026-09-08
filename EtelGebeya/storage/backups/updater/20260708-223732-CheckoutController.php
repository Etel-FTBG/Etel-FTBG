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

        $etelPayMerchantPhone = (new \App\Core\EtelPayClient())->merchantPhone();

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
            'etelPayMerchantPhone' => $etelPayMerchantPhone,
        ]);
    }

    /* ?? POST /checkout/apply-coupon (AJAX preview) ???????? */
    public function applyCoupon(): void
    {
        header('Content-Type: application/json');
        if (!$this->auth->check()) { echo json_encode(['ok' => false, 'message' => 'Please sign in.']); return; }

        $cart = CartController::getCart();
        if (empty($cart)) { echo json_encode(['ok' => false, 'message' => 'Your cart is empty.']); return; }

        $code  = trim($_POST['code'] ?? '');
        $total = CartController::cartTotal($cart);
        $result = $this->validateCoupon($code, $cart, $total);

        echo json_encode([
            'ok'            => $result['ok'],
            'message'       => $result['message'],
            'discount'      => $result['discount'],
            'free_shipping' => $result['free_shipping'],
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

        // Coupon: re-validated here from scratch, server-side -- the
        // client's displayed discount is only a preview, never trusted
        // for the actual charge (same principle as the wallet payment
        // verification below).
        $couponCode = trim($_POST['coupon_code'] ?? '');
        $discount = 0.0;
        $couponRow = null;
        if ($couponCode !== '') {
            $couponResult = $this->validateCoupon($couponCode, $cart, $total);
            if ($couponResult['ok']) {
                $discount = $couponResult['discount'];
                if ($couponResult['free_shipping']) $shipping = 0.0;
                $couponRow = $couponResult['coupon'];
            }
            // If the coupon became invalid between preview and submit
            // (expired, used up, etc.) we simply don't apply it rather
            // than blocking the whole checkout.
        }

        $grand = max(0, $total + $shipping - $discount);

        // Shipping address
        $name    = trim($_POST['full_name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        // Map form values to DB ENUM values
        $paymentMap = ['chapa'=>'bank_transfer','telebirr'=>'telebirr','cbe'=>'cbe_birr','cod'=>'cash','etel_pay'=>'etel_pay'];
        $rawPayment = $_POST['payment'] ?? 'cod';
        $payment    = $paymentMap[$rawPayment] ?? 'cash';

        if ($name === '' || $city === '' || $address === '') {
            $this->auth->flash('error', 'Please fill in all required shipping fields.');
            redirect('/checkout');
        }

        // ኢE Pay wallet: the browser already completed a real transfer
        // (login + send happened client-side, this backend never saw the
        // PIN) -- independently verify the resulting tx_ref with Etel Pay
        // before trusting it. Never mark an order paid on the client's
        // say-so alone.
        $walletPaidAt = null;
        if ($payment === 'etel_pay') {
            $txRef = trim($_POST['wallet_tx_ref'] ?? '');
            if (!$txRef) {
                $this->auth->flash('error', 'ኢE Pay payment did not complete. Please try again.');
                redirect('/checkout');
            }
            $dupe = $this->db->fetch("SELECT id FROM orders WHERE payment_ref = ? LIMIT 1", [$txRef]);
            if ($dupe) {
                $this->auth->flash('error', 'This payment has already been used for another order.');
                redirect('/checkout');
            }
            $tx = (new \App\Core\EtelPayClient())->verifyTx($txRef, $this->auth->user()['phone'] ?? '', $grand);
            if (!$tx) {
                $this->auth->flash('error', 'Could not verify your ኢE Pay payment. If money left your account, contact support with reference ' . $txRef);
                redirect('/checkout');
            }
            $walletPaidAt = date('Y-m-d H:i:s');
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
                'payment_status'   => $walletPaidAt ? 'paid' : 'pending',
                'payment_ref'      => $walletPaidAt ? ($txRef ?? null) : null,
                'paid_at'          => $walletPaidAt,
                'subtotal'         => $total,
                'delivery_fee'     => $shipping,
                'discount_amount'  => $discount,
                'total_amount'     => $grand,
                'platform_fee'     => 0,
                'coupon_code'      => $couponRow ? $couponRow['code'] : null,
                'coupon_discount'  => $discount,
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

            if ($couponRow) {
                $this->db->query("UPDATE coupons SET total_uses = total_uses + 1 WHERE id = ?", [$couponRow['id']]);
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

    /**
     * Validates a coupon code against this cart from scratch and computes
     * the real discount amount server-side. Never trust a client-supplied
     * discount figure -- this is the single source of truth, called both
     * for the AJAX "Apply" preview and again inside place() before the
     * order (and any wallet payment amount) is finalized.
     */
    private function validateCoupon(string $code, array $cart, float $subtotal): array
    {
        $fail = ['ok' => false, 'discount' => 0.0, 'free_shipping' => false, 'coupon' => null];
        if ($code === '') return $fail + ['message' => 'Enter a code.'];

        $coupon = $this->db->fetch(
            "SELECT * FROM coupons WHERE code = ? AND is_active = 1 LIMIT 1",
            [strtoupper($code)]
        );
        if (!$coupon) return $fail + ['message' => 'Invalid or inactive coupon code.'];
        if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < time()) {
            return $fail + ['message' => 'This coupon has expired.'];
        }
        if ($coupon['max_uses'] !== null && (int)$coupon['total_uses'] >= (int)$coupon['max_uses']) {
            return $fail + ['message' => 'This coupon has reached its usage limit.'];
        }
        if ((int)$coupon['per_user_limit'] > 0 && $this->auth->check()) {
            $usedByMe = (int)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM orders WHERE user_id = ? AND coupon_code = ?",
                [$this->auth->id(), $coupon['code']]
            ) ?: 0);
            if ($usedByMe >= (int)$coupon['per_user_limit']) {
                return $fail + ['message' => 'You have already used this coupon.'];
            }
        }

        // Eligible subtotal: whole cart, or just the portion that matches
        // a seller/category-scoped coupon.
        $eligibleSubtotal = $subtotal;
        if (!empty($coupon['seller_id'])) {
            $eligibleSubtotal = 0.0;
            foreach ($cart as $item) {
                if ((int)($item['seller_id'] ?? 0) === (int)$coupon['seller_id']) {
                    $eligibleSubtotal += (float)$item['price'] * (int)$item['qty'];
                }
            }
            if ($eligibleSubtotal <= 0) return $fail + ['message' => 'This coupon only applies to a specific seller\'s products, which aren\'t in your cart.'];
        } elseif (!empty($coupon['category_id'])) {
            $pids = array_unique(array_column(array_values($cart), 'id'));
            $plist = implode(',', array_map('intval', $pids));
            $inCategory = [];
            if ($plist !== '') {
                foreach ($this->db->fetchAll("SELECT id FROM products WHERE id IN ($plist) AND category_id = ?", [$coupon['category_id']]) as $row) {
                    $inCategory[(int)$row['id']] = true;
                }
            }
            $eligibleSubtotal = 0.0;
            foreach ($cart as $item) {
                if (isset($inCategory[(int)$item['id']])) $eligibleSubtotal += (float)$item['price'] * (int)$item['qty'];
            }
            if ($eligibleSubtotal <= 0) return $fail + ['message' => 'This coupon only applies to a specific category, which isn\'t in your cart.'];
        }

        if ((float)$coupon['min_order'] > 0 && $eligibleSubtotal < (float)$coupon['min_order']) {
            return $fail + ['message' => 'Minimum order of Br ' . number_format((float)$coupon['min_order'], 2) . ' required for this coupon.'];
        }

        $discount = 0.0;
        $freeShipping = false;
        if ($coupon['type'] === 'percent') {
            $discount = round($eligibleSubtotal * ((float)$coupon['value'] / 100), 2);
            if (!empty($coupon['max_discount'])) $discount = min($discount, (float)$coupon['max_discount']);
        } elseif ($coupon['type'] === 'fixed') {
            $discount = min((float)$coupon['value'], $eligibleSubtotal);
        } elseif ($coupon['type'] === 'free_delivery') {
            $freeShipping = true;
        }

        return [
            'ok' => true, 'discount' => $discount, 'free_shipping' => $freeShipping,
            'coupon' => $coupon, 'message' => 'Coupon applied!',
        ];
    }
}
