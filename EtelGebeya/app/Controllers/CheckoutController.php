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
        $sellerBreakdown      = $this->sellerBreakdown($cart);
        // Wallet checkout needs every seller to have a resolvable Etel Pay
        // phone AND the platform merchant phone (for shipping) -- if any
        // seller in the cart can't be paid, don't offer the wallet option
        // at all rather than letting checkout fail after the customer's
        // money is already held.
        $walletUsable = $etelPayMerchantPhone !== null;
        foreach ($sellerBreakdown as $s) {
            if (empty($s['phone'])) { $walletUsable = false; break; }
        }

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
            'etelPayMerchantPhone' => $walletUsable ? $etelPayMerchantPhone : null,
            'sellerBreakdown'      => $sellerBreakdown,
        ]);
    }

    /**
     * Groups the cart by seller_id with each seller's subtotal and their
     * resolved Etel Pay destination phone. Used both to render the
     * checkout page (client needs to know who to open escrow holds with)
     * and, re-derived independently, to verify what place() receives --
     * client-supplied amounts/phones are never trusted on their own.
     */
    private function sellerBreakdown(array $cart): array
    {
        $bySeller = [];
        foreach ($cart as $item) {
            $sid = (int)($item['seller_id'] ?? 0);
            if (!$sid) continue;
            $bySeller[$sid] = ($bySeller[$sid] ?? 0) + ((float)$item['price'] * (int)$item['qty']);
        }
        if (!$bySeller) return [];

        $ids = implode(',', array_map('intval', array_keys($bySeller)));
        $sellers = $this->db->fetchAll("SELECT id, phone, payout_method, payout_account FROM sellers WHERE id IN ($ids)");
        $phoneById = [];
        foreach ($sellers as $s) {
            $phoneById[(int)$s['id']] = ($s['payout_method'] === 'etel_pay' && !empty($s['payout_account']))
                ? $s['payout_account'] : $s['phone'];
        }

        $out = [];
        ksort($bySeller); // deterministic order -- must match the client-side split
        foreach ($bySeller as $sid => $subtotal) {
            $out[] = ['seller_id' => $sid, 'subtotal' => round($subtotal, 2), 'phone' => $phoneById[$sid] ?? null];
        }
        return $out;
    }

    /**
     * Splits a total discount proportionally across sellers by subtotal
     * share, giving the last seller (in the same deterministic order used
     * above) any rounding remainder so the parts always sum exactly to
     * subtotal-discount. Mirrored in checkout.php's client JS so the
     * amounts it requests from Etel Pay match what this server expects.
     */
    private function splitDiscount(array $sellerBreakdown, float $totalSubtotal, float $discount): array
    {
        if ($totalSubtotal <= 0 || $discount <= 0) {
            $out = [];
            foreach ($sellerBreakdown as $s) { $out[$s['seller_id']] = round($s['subtotal'], 2); }
            return $out;
        }
        $out = [];
        $remaining = $discount;
        $n = count($sellerBreakdown);
        foreach ($sellerBreakdown as $i => $s) {
            if ($i === $n - 1) {
                $share = $remaining;
            } else {
                $share = round($discount * $s['subtotal'] / $totalSubtotal, 2);
                $remaining -= $share;
            }
            $out[$s['seller_id']] = round($s['subtotal'] - $share, 2);
        }
        return $out;
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

        $total = CartController::cartTotal($cart);
        // Delivery fee is re-derived here from the pin the customer set --
        // never trust the shipping figure the page happened to display,
        // same principle as the wallet/coupon verification below.
        $deliveryLat = isset($_POST['delivery_lat']) && is_numeric($_POST['delivery_lat']) ? (float)$_POST['delivery_lat'] : null;
        $deliveryLng = isset($_POST['delivery_lng']) && is_numeric($_POST['delivery_lng']) ? (float)$_POST['delivery_lng'] : null;
        $shipping = $this->shippingFee($cart, $deliveryLat, $deliveryLng);

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

        // ኢE Pay wallet escrow: the browser already opened a pending hold
        // per seller (ep_escrow_send) plus one normal completed transfer
        // for shipping -- this backend never saw the PIN, only tx_refs.
        // Every ref is independently re-verified against amounts THIS
        // server computes itself (never the client's), before an order is
        // ever created. $sellerHolds ends up [ ['seller_id','tx_ref','amount'], ... ].
        // Instantiated up front (before ANY query touches order_seller_escrow,
        // including the dupe-check below) -- its constructor is what
        // creates that table on a fresh deploy.
        $escrow = new \App\Core\EscrowService();
        $walletPaidAt = null;
        $sellerHolds  = [];
        $shippingTxRef = null;
        if ($payment === 'etel_pay') {
            $pay = new \App\Core\EtelPayClient();
            $customerPhone = $this->auth->user()['phone'] ?? '';
            $sellerBreakdown = $this->sellerBreakdown($cart);
            $sellerAmounts = $this->splitDiscount($sellerBreakdown, $total, $discount);

            $rawHolds = json_decode($_POST['wallet_escrow_refs'] ?? '[]', true);
            if (!is_array($rawHolds)) $rawHolds = [];
            $holdBySeller = [];
            foreach ($rawHolds as $h) {
                if (isset($h['seller_id'], $h['tx_ref'])) $holdBySeller[(int)$h['seller_id']] = trim((string)$h['tx_ref']);
            }

            foreach ($sellerBreakdown as $s) {
                $sid = $s['seller_id'];
                $expectedAmount = $sellerAmounts[$sid] ?? 0;
                if ($expectedAmount <= 0) continue; // fully discounted away
                $txRef = $holdBySeller[$sid] ?? '';
                if (!$txRef || empty($s['phone'])) {
                    $this->auth->flash('error', 'ኢE Pay payment did not complete for one of the sellers in your cart. Please try again.');
                    redirect('/checkout');
                }
                $dupe = $this->db->fetch("SELECT id FROM order_seller_escrow WHERE tx_ref = ? LIMIT 1", [$txRef]);
                if ($dupe) {
                    $this->auth->flash('error', 'This payment has already been used for another order.');
                    redirect('/checkout');
                }
                $tx = $pay->verifyEscrowTx($txRef, $customerPhone, $s['phone'], $expectedAmount);
                if (!$tx) {
                    $this->auth->flash('error', 'Could not verify your ኢE Pay payment. If money left your account, contact support with reference ' . $txRef);
                    redirect('/checkout');
                }
                $sellerHolds[] = ['seller_id' => $sid, 'tx_ref' => $txRef, 'amount' => $expectedAmount];
            }

            if ($shipping > 0) {
                $shippingTxRef = trim($_POST['wallet_shipping_tx_ref'] ?? '');
                if (!$shippingTxRef) {
                    $this->auth->flash('error', 'ኢE Pay payment did not complete. Please try again.');
                    redirect('/checkout');
                }
                $dupe = $this->db->fetch("SELECT id FROM orders WHERE payment_ref = ? LIMIT 1", [$shippingTxRef]);
                if ($dupe) {
                    $this->auth->flash('error', 'This payment has already been used for another order.');
                    redirect('/checkout');
                }
                $shipTx = $pay->verifyTx($shippingTxRef, $customerPhone, $shipping);
                if (!$shipTx) {
                    $this->auth->flash('error', 'Could not verify your ኢE Pay shipping payment. If money left your account, contact support with reference ' . $shippingTxRef);
                    redirect('/checkout');
                }
            }

            $walletPaidAt = date('Y-m-d H:i:s');
        }

        $now  = date('Y-m-d H:i:s');
        $code = 'EG-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        // Schema self-heal must happen before the transaction (DDL causes
        // an implicit commit in MySQL) -- same reasoning as EscrowService.
        \App\Core\SchemaGuard::ensureColumns($this->db, 'orders', [
            'delivery_lat' => 'delivery_lat DECIMAL(10,7) DEFAULT NULL',
            'delivery_lng' => 'delivery_lng DECIMAL(10,7) DEFAULT NULL',
        ]);

        try {
            $this->db->pdo()->beginTransaction();

            $orderId = $this->db->insert('orders', [
                'user_id'          => $this->auth->id(),
                'order_ref'        => $code,
                'status'           => 'new',
                'payment_method'   => $payment,
                'payment_status'   => $walletPaidAt ? 'paid' : 'pending',
                'payment_ref'      => $walletPaidAt ? ($shippingTxRef ?? null) : null,
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
                'delivery_lat'     => $deliveryLat,
                'delivery_lng'     => $deliveryLng,
                'delivery_notes'   => trim($_POST['notes'] ?? ''),
                'delivery_type'    => 'standard',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            // A legacy DB trigger (trg_order_ref) overwrites order_ref on
            // insert with its own randomly generated value, ignoring the
            // one we just sent. Always re-read the value MySQL actually
            // stored -- using our own guess here would redirect the
            // customer to a success/lookup URL for a code that was never
            // saved, showing "Order Not Found" even though payment and
            // the order both succeeded.
            $storedRef = $this->db->fetchColumn("SELECT order_ref FROM orders WHERE id = ?", [$orderId]);
            if ($storedRef) {
                $code = (string)$storedRef;
            }

            // Pre-fetch seller_ids (+ dropship info, snapshotted onto the
            // order_item so a later change to the product's dropship setup
            // never rewrites history on an already-placed order) for all
            // cart products.
            \App\Models\Supplier::ensureSchema($this->db);
            $pids      = array_unique(array_column(array_values($cart), 'id'));
            $plist     = implode(',', array_map('intval', $pids));
            $sellerMap = [];
            if ($plist !== '') {
                foreach ($this->db->fetchAll("SELECT id, seller_id, is_dropship, supplier_id FROM products WHERE id IN ($plist)") as $row) {
                    $sellerMap[(int)$row['id']] = $row;
                }
            }

            foreach ($cart as $item) {
                $pid       = (int)$item['id'];
                $prow      = $sellerMap[$pid] ?? null;
                $sellerId  = $prow ? (int)$prow['seller_id'] : null;
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
                    'is_dropship'   => !empty($prow['is_dropship']) ? 1 : 0,
                    'supplier_id'   => !empty($prow['is_dropship']) ? ($prow['supplier_id'] ?: null) : null,
                    'created_at'    => $now,
                ]);
            }

            foreach ($sellerHolds as $hold) {
                $escrow->recordHold($orderId, $hold['seller_id'], $hold['tx_ref'], $hold['amount']);
            }

            if ($couponRow) {
                $this->db->query("UPDATE coupons SET total_uses = total_uses + 1 WHERE id = ?", [$couponRow['id']]);
            }

            // Record the payment in `transactions` so the admin Transactions
            // tab reflects it -- the order row alone (payment_status/payment_ref)
            // was never enough, since that dashboard reads from this table.
            // For wallet orders this is EtelGebeya's own bookkeeping entry
            // (distinct from the per-seller holds tracked in
            // order_seller_escrow) -- status "pending" here means "held in
            // escrow", not that anything failed.
            $firstHoldRef = $sellerHolds[0]['tx_ref'] ?? null;
            $this->db->insert('transactions', [
                'order_id'     => $orderId,
                'user_id'      => $this->auth->id(),
                'type'         => 'payment',
                'method'       => $payment,
                'amount'       => $grand,
                'currency'     => 'ETB',
                'status'       => 'pending',
                'gateway_ref'  => $walletPaidAt ? ($shippingTxRef ?? $firstHoldRef) : null,
                'notes'        => 'Etel Gebeya order ' . $code,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $this->db->pdo()->commit();

        } catch (\Throwable $e) {
            try { $this->db->pdo()->rollBack(); } catch (\Throwable $_) {}
            error_log('[EG-Checkout] ' . $e->getMessage());
            $this->auth->flash('error', 'Order failed. Please try again or contact support if the problem continues.');
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
    /**
     * Distance-based delivery fee: Br 50 flat within 2km of the seller's
     * pickup point, +Br 15/km beyond that (any started km counts as a
     * full km). Computed PER SELLER in the cart and summed, since a
     * multi-vendor order ships from multiple pickup points that could be
     * different distances away. Falls back to the flat courier config fee
     * when the customer hasn't pinned a location yet, or a seller hasn't
     * set a pickup point -- never blocks checkout on missing map data.
     */
    private function shippingFee(array $cart, ?float $lat = null, ?float $lng = null): float
    {
        if (empty($cart)) return 0.0;
        $flat = (float)($GLOBALS['__config']['courier']['fee_flat_etb'] ?? 30);
        if ($lat === null || $lng === null) return $flat;

        $sellerBreakdown = $this->sellerBreakdown($cart);
        if (!$sellerBreakdown) return $flat;

        \App\Core\SchemaGuard::ensureColumns($this->db, 'sellers', [
            'pickup_lat'     => 'pickup_lat DECIMAL(10,7) DEFAULT NULL',
            'pickup_lng'     => 'pickup_lng DECIMAL(10,7) DEFAULT NULL',
            'pickup_address' => 'pickup_address VARCHAR(255) DEFAULT NULL',
        ]);

        $ids = implode(',', array_map(fn($s) => (int)$s['seller_id'], $sellerBreakdown));
        $pickups = $this->db->fetchAll("SELECT id, pickup_lat, pickup_lng FROM sellers WHERE id IN ($ids)");
        $pickupById = [];
        foreach ($pickups as $p) { $pickupById[(int)$p['id']] = $p; }

        $total = 0.0;
        foreach ($sellerBreakdown as $s) {
            $p = $pickupById[$s['seller_id']] ?? null;
            if (!$p || $p['pickup_lat'] === null || $p['pickup_lng'] === null) {
                $total += $flat; // this seller hasn't pinned a pickup point yet
                continue;
            }
            $km = self::haversineKm((float)$p['pickup_lat'], (float)$p['pickup_lng'], $lat, $lng);
            $total += self::feeForDistance($km);
        }
        return round($total, 2);
    }

    private static function feeForDistance(float $km): float
    {
        if ($km <= 2.0) return 50.0;
        return 50.0 + ceil($km - 2.0) * 15.0;
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0; // Earth radius, km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /* ── POST /checkout/quote-shipping (AJAX, live re-price as the
       customer moves the map pin) ─────────────────────────────── */
    public function quoteShipping(): void
    {
        header('Content-Type: application/json');
        $cart = CartController::getCart();
        if (empty($cart)) { echo json_encode(['ok' => false, 'message' => 'Cart empty']); return; }

        $lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float)$_POST['lng'] : null;
        if ($lat === null || $lng === null) { echo json_encode(['ok' => false, 'message' => 'Invalid location']); return; }

        $shipping = $this->shippingFee($cart, $lat, $lng);
        echo json_encode([
            'ok'       => true,
            'shipping' => $shipping,
            'note'     => 'Br 50 within 2km of each seller, +Br 15/km beyond',
        ]);
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
