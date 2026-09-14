<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

final class CustomerController
{
    private Auth $auth;
    private \App\Core\Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = \App\Core\Database::getInstance();
    }

    private function requireLogin(): void
    {
        if (!$this->auth->check()) {
            $_SESSION['_intended'] = '/account';
            redirect('/login');
        }
    }

    /* ?? GET /account ??????????????????????????????????????? */
    public function dashboard(): void
    {
        $this->requireLogin();
        $user   = $this->auth->user();
        $uid    = $this->auth->id();
        $locale = $GLOBALS['__i18n']->locale();

        $orders = [];
        $orderCount = 0;
        $totalSpent = 0.0;

        try {
            $orders = $this->db->fetchAll(
                "SELECT *, order_ref AS code, total_amount AS total, delivery_fee AS shipping_fee,
                         delivery_name AS full_name, delivery_phone AS phone, delivery_city AS city,
                         CONCAT_WS(', ',delivery_city,delivery_address) AS shipping_address
                   FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
                [$uid]
            );
            $orderCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM orders WHERE user_id = ?", [$uid]) ?: 0);
            $totalSpent = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE user_id = ? AND status NOT IN ('cancelled','returned')",
                [$uid]
            ) ?: 0);
        } catch (\Throwable $_) {}

        View::render('customer/account', [
            'pageTitle'  => 'My Account',
            'locale'     => $locale,
            'user'       => $user,
            'orders'     => $orders,
            'orderCount' => $orderCount,
            'totalSpent' => $totalSpent,
            'flash'      => $this->auth->takeFlash(),
            'auth'       => $this->auth,
        ]);
    }

    /* ?? GET /account/orders ???????????????????????????????? */
    public function orders(): void
    {
        $this->requireLogin();
        $uid    = $this->auth->id();
        $locale = $GLOBALS['__i18n']->locale();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $per    = 10;
        $offset = ($page - 1) * $per;
        $orders = [];
        $total  = 0;

        try {
            $total  = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM orders WHERE user_id = ?", [$uid]) ?: 0);
            $orders = $this->db->fetchAll(
                "SELECT *, order_ref AS code, total_amount AS total, delivery_fee AS shipping_fee,
                         delivery_name AS full_name, delivery_phone AS phone, delivery_city AS city
                   FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT $per OFFSET $offset",
                [$uid]
            );
        } catch (\Throwable $_) {}

        View::render('customer/orders', [
            'pageTitle' => 'My Orders',
            'locale'    => $locale,
            'user'      => $this->auth->user(),
            'orders'    => $orders,
            'total'     => $total,
            'page'      => $page,
            'per'       => $per,
            'auth'      => $this->auth,
        ]);
    }

    /* ?? GET /account/orders/{code} ???????????????????????? */
    public function order(string $code): void
    {
        $this->requireLogin();
        $uid    = $this->auth->id();
        $locale = $GLOBALS['__i18n']->locale();
        $order  = null;
        $items  = [];

        try {
            $order = $this->db->fetch(
                "SELECT *, order_ref AS code, total_amount AS total, delivery_fee AS shipping_fee,
                         delivery_name AS full_name, delivery_phone AS phone, delivery_city AS city,
                         CONCAT_WS(', ',delivery_city,delivery_address) AS shipping_address
                   FROM orders WHERE order_ref = ? AND user_id = ?",
                [$code, $uid]
            );
            if (!$order) { http_response_code(404); redirect('/account/orders'); }
            $items = $this->db->fetchAll(
                "SELECT oi.*, p.name_en, p.name_am, p.slug,
                        oi.unit_price AS price, oi.quantity AS qty, oi.total_price AS subtotal,
                        c.name AS courier_name, c.phone AS courier_phone,
                        (SELECT pi.path FROM product_images pi WHERE pi.product_id=oi.product_id ORDER BY pi.sort_order LIMIT 1) as thumb
                   FROM order_items oi
                   JOIN products p ON p.id = oi.product_id
                   LEFT JOIN couriers c ON c.id = oi.courier_id
                  WHERE oi.order_id = ?",
                [$order['id']]
            );
        } catch (\Throwable $_) {
            if (!$order) redirect('/account/orders');
        }

        $hasDispute = false;
        $canConfirm = false;
        if ($order) {
            try {
                $svc = new \App\Core\EscrowService();
                $hasDispute = $svc->hasOpenDispute((int)$order['id']);
                $canConfirm = !$hasDispute && $svc->hasReleasableHold((int)$order['id']);
            } catch (\Throwable $_) {}
        }

        View::render('customer/order-detail', [
            'pageTitle'  => 'Order #' . $code,
            'locale'     => $locale,
            'user'       => $this->auth->user(),
            'order'      => $order,
            'items'      => $items,
            'flash'      => $this->auth->takeFlash(),
            'auth'       => $this->auth,
            'hasDispute' => $hasDispute,
            'canConfirm' => $canConfirm,
        ]);
    }

    /* ?? POST /account/orders/{code}/cancel ??????????????????
       Customers can only self-cancel while the seller hasn't started
       fulfilling it yet (still 'new' or 'confirmed'). Anything past
       that (processing/packed/shipped/...) requires contacting support. */
    public function cancelOrder(string $code): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        try {
            $order = $this->db->fetch(
                "SELECT id, status FROM orders WHERE order_ref = ? AND user_id = ? LIMIT 1",
                [$code, $uid]
            );
            if (!$order) {
                $this->auth->flash('error', 'Order not found.');
                redirect('/account/orders');
            }
            if (!in_array($order['status'], ['new', 'confirmed'], true)) {
                $this->auth->flash('error', 'This order can no longer be cancelled -- it is already being processed. Please contact support.');
                redirect('/account/orders/' . $code);
            }

            $this->db->query(
                "UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id=?",
                [$order['id']]
            );

            // If this was paid via the ኢE Pay wallet, the money is sitting
            // in escrow (the platform's own wallet) -- reverse it back to
            // the customer now. Cash/Telebirr/CBE orders never put money
            // in our custody, so there's nothing to reverse for those.
            $refunded = (new \App\Core\EscrowService())->refundOrder((int)$order['id']);
            $this->auth->flash('success', $refunded
                ? 'Order cancelled and your payment has been refunded to your ኢE Pay wallet.'
                : 'Order cancelled.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not cancel order: ' . $e->getMessage());
        }

        redirect('/account/orders/' . $code);
    }

    /* ── POST /account/orders/{code}/confirm ──────────────────────
       Customer confirms they received the order -- releases escrowed
       funds to the seller(s) for every item currently marked delivered.
       If the customer never clicks this, a daily cron auto-releases
       after 5 days (see cron/escrow_autorelease.php) unless a dispute
       is open. */
    public function confirmReceived(string $code): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        try {
            $order = $this->db->fetch(
                "SELECT id FROM orders WHERE order_ref = ? AND user_id = ? LIMIT 1",
                [$code, $uid]
            );
            if (!$order) {
                $this->auth->flash('error', 'Order not found.');
                redirect('/account/orders');
            }

            $svc = new \App\Core\EscrowService();
            if ($svc->hasOpenDispute((int)$order['id'])) {
                $this->auth->flash('error', 'This order has an open dispute -- funds will be released once it is resolved.');
                redirect('/account/orders/' . $code);
            }

            $released = $svc->releaseOrder((int)$order['id']);
            $this->auth->flash('success', $released > 0
                ? 'Thanks for confirming! Payment has been released to the seller.'
                : 'Nothing to confirm yet -- no items are marked delivered.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not confirm receipt: ' . $e->getMessage());
        }

        redirect('/account/orders/' . $code);
    }

    /* ── POST /account/orders/{code}/dispute ───────────────────────
       Files a dispute (item not delivered, wrong/defective item, etc).
       This pauses the auto-release timer and holds the escrow until an
       admin reviews it -- see api/admin.php's dispute_resolve action. */
    public function fileDispute(string $code): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        $issueType   = $_POST['issue_type'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $allowedTypes = ['not_delivered', 'not_as_described', 'defective', 'wrong_item', 'other'];
        if (!in_array($issueType, $allowedTypes, true)) $issueType = 'other';

        if ($description === '') {
            $this->auth->flash('error', 'Please describe the problem.');
            redirect('/account/orders/' . $code);
        }

        try {
            $order = $this->db->fetch(
                "SELECT id, total_amount FROM orders WHERE order_ref = ? AND user_id = ? LIMIT 1",
                [$code, $uid]
            );
            if (!$order) {
                $this->auth->flash('error', 'Order not found.');
                redirect('/account/orders');
            }
            // Use the first seller on the order for the dispute record --
            // a customer with a problem contacts support, who can see all
            // items via the order itself in the admin panel.
            $sellerId = (int)($this->db->fetchColumn(
                "SELECT seller_id FROM order_items WHERE order_id = ? LIMIT 1", [$order['id']]
            ) ?: 0);

            $this->db->insert('disputes', [
                'dispute_ref'  => 'DP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'order_id'     => $order['id'],
                'user_id'      => $uid,
                'seller_id'    => $sellerId,
                'issue_type'   => $issueType,
                'description'  => $description,
                'amount'       => $order['total_amount'],
                'resolution'   => 'pending',
                'status'       => 'open',
                'opened_at'    => date('Y-m-d H:i:s'),
            ]);
            $this->auth->flash('success', 'Your report has been submitted -- our team will review it and your payment stays held until it\'s resolved.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not submit report: ' . $e->getMessage());
        }

        redirect('/account/orders/' . $code);
    }

    /* ?? GET /account/addresses ???????????????????????????? */
    public function addresses(): void
    {
        $this->requireLogin();
        $uid    = $this->auth->id();
        $locale = $GLOBALS['__i18n']->locale();
        $addresses = [];

        try {
            $addresses = $this->db->fetchAll(
                "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC",
                [$uid]
            );
        } catch (\Throwable $_) {}

        View::render('customer/addresses', [
            'pageTitle' => 'My Addresses',
            'locale'    => $locale,
            'user'      => $this->auth->user(),
            'addresses' => $addresses,
            'flash'     => $this->auth->takeFlash(),
            'auth'      => $this->auth,
        ]);
    }

    /* ?? POST /account/addresses ??????????????????????????? */
    public function saveAddress(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        $label    = trim($_POST['label'] ?? 'Home');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $city     = trim($_POST['city'] ?? '');
        $subCity  = trim($_POST['sub_city'] ?? '');
        $wereda   = trim($_POST['wereda'] ?? '');
        $detail   = trim($_POST['address_detail'] ?? '');

        if (!$fullName || !$phone || !$city) {
            $this->auth->flash('error', 'Name, phone and city are required.');
            redirect('/account/addresses');
        }

        try {
            // First address a user ever saves becomes their default automatically.
            $existingCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?", [$uid]) ?: 0);
            $isDefault = $existingCount === 0 ? 1 : 0;

            $this->db->query(
                "INSERT INTO user_addresses (user_id,label,full_name,phone,city,sub_city,wereda,address_detail,is_default,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                [$uid,$label,$fullName,$phone,$city,$subCity,$wereda,$detail,$isDefault]
            );
            $this->auth->flash('success', 'Address saved.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not save address: '.$e->getMessage());
        }

        redirect('/account/addresses');
    }

    /* ?? POST /account/addresses/delete ????????????????????? */
    public function deleteAddress(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();
        $id  = (int)($_POST['id'] ?? 0);

        try {
            $addr = $this->db->fetch("SELECT id, is_default FROM user_addresses WHERE id = ? AND user_id = ?", [$id, $uid]);
            if (!$addr) {
                $this->auth->flash('error', 'Address not found.');
                redirect('/account/addresses');
            }
            $this->db->query("DELETE FROM user_addresses WHERE id = ? AND user_id = ?", [$id, $uid]);

            // If we just deleted the default, promote the next-oldest one.
            if ($addr['is_default']) {
                $next = $this->db->fetchColumn("SELECT id FROM user_addresses WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
                if ($next) $this->db->query("UPDATE user_addresses SET is_default = 1 WHERE id = ?", [$next]);
            }
            $this->auth->flash('success', 'Address removed.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not remove address: ' . $e->getMessage());
        }

        redirect('/account/addresses');
    }

    /* ?? POST /account/addresses/default ????????????????????? */
    public function setDefaultAddress(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();
        $id  = (int)($_POST['id'] ?? 0);

        try {
            $addr = $this->db->fetch("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?", [$id, $uid]);
            if (!$addr) {
                $this->auth->flash('error', 'Address not found.');
                redirect('/account/addresses');
            }
            $this->db->query("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?", [$uid]);
            $this->db->query("UPDATE user_addresses SET is_default = 1 WHERE id = ?", [$id]);
            $this->auth->flash('success', 'Default address updated.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not update default address: ' . $e->getMessage());
        }

        redirect('/account/addresses');
    }

    /* ?? POST /account/profile ????????????????????????????? */
    public function saveProfile(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $newPass  = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$fullName) {
            $this->auth->flash('error', 'Name is required.');
            redirect('/account');
        }

        $update = ['full_name' => $fullName, 'phone' => $phone];

        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                $this->auth->flash('error', 'Password must be at least 8 characters.');
                redirect('/account');
            }
            if ($newPass !== $confirm) {
                $this->auth->flash('error', 'Passwords do not match.');
                redirect('/account');
            }
            $update['password_hash'] = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        try {
            $this->db->update('users', $update, 'id = ?', [$uid]);
            $this->auth->flash('success', 'Profile updated.');
        } catch (\Throwable $_) {
            $this->auth->flash('error', 'Could not update profile.');
        }

        redirect('/account');
    }

    /* ?? GET /account/wishlist ????????????????????????????? */
    public function wishlist(): void
    {
        $this->requireLogin();
        $uid    = $this->auth->id();
        $locale = $GLOBALS['__i18n']->locale();
        $items  = [];

        try {
            $items = $this->db->fetchAll(
                "SELECT w.id as wish_id, p.*, p.id as product_id,
                        (SELECT pi.path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) as thumb
                   FROM wishlist w
                   JOIN products p ON p.id = w.product_id
                  WHERE w.user_id = ?
                  ORDER BY w.id DESC",
                [$uid]
            );
        } catch (\Throwable $_) {}

        View::render('customer/wishlist', [
            'pageTitle' => 'My Wishlist',
            'locale'    => $locale,
            'user'      => $this->auth->user(),
            'items'     => $items,
            'flash'     => $this->auth->takeFlash(),
            'auth'      => $this->auth,
        ]);
    }

    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /* ?? POST /account/wishlist/add ???????????????????????? */
    public function wishlistAdd(): void
    {
        if (!$this->auth->check()) {
            if ($this->isAjax()) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'login_required' => true]); return; }
            $_SESSION['_intended'] = '/account';
            redirect('/login');
        }
        $uid = $this->auth->id();
        $pid = (int)($_POST['product_id'] ?? 0);
        $ok  = false;
        if ($pid > 0) {
            try {
                $this->db->query(
                    "INSERT IGNORE INTO wishlist (user_id, product_id, created_at) VALUES (?,?,NOW())",
                    [$uid, $pid]
                );
                $ok = true;
            } catch (\Throwable $_) {}
        }
        if ($this->isAjax()) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok]); return; }
        $ref = $_SERVER['HTTP_REFERER'] ?? '/account/wishlist';
        redirect($ref);
    }

    /* ?? POST /account/wishlist/remove ???????????????????? */
    public function wishlistRemove(): void
    {
        if (!$this->auth->check()) {
            if ($this->isAjax()) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'login_required' => true]); return; }
            $_SESSION['_intended'] = '/account';
            redirect('/login');
        }
        $uid = $this->auth->id();
        $pid = (int)($_POST['product_id'] ?? 0);
        $ok  = false;
        if ($pid > 0) {
            try {
                $this->db->query("DELETE FROM wishlist WHERE user_id=? AND product_id=?", [$uid, $pid]);
                $ok = true;
            } catch (\Throwable $_) {}
        }
        if ($this->isAjax()) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok]); return; }
        redirect('/account/wishlist');
    }

    /* ?? POST /product/{slug}/review ??????????????????????
       Customers submit a rating + written review. Reviews from a
       verified purchase (a delivered order containing this product)
       are auto-approved and flagged is_verified; everything else goes
       to 'pending' for admin moderation, matching the existing
       reviews.status enum the storefront already reads from. */
    public function submitReview(string $slug): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();

        $product = \App\Models\Product::bySlug($slug);
        if (!$product) { http_response_code(404); redirect('/'); }

        $rating = (int)($_POST['rating'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['body'] ?? '');

        if ($rating < 1 || $rating > 5) {
            $this->auth->flash('error', 'Please choose a star rating from 1 to 5.');
            redirect('/product/' . $slug);
        }
        if ($body === '') {
            $this->auth->flash('error', 'Please write a short review.');
            redirect('/product/' . $slug);
        }

        try {
            $existing = $this->db->fetch(
                "SELECT id FROM reviews WHERE product_id=? AND user_id=? LIMIT 1",
                [$product->id, $uid]
            );
            if ($existing) {
                $this->auth->flash('error', 'You already reviewed this product.');
                redirect('/product/' . $slug);
            }

            $orderItem = $this->db->fetch(
                "SELECT oi.id FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                  WHERE o.user_id = ? AND oi.product_id = ? AND o.status IN ('delivered','completed')
                  ORDER BY oi.id DESC LIMIT 1",
                [$uid, $product->id]
            );
            $isVerified = $orderItem ? 1 : 0;
            $status     = $isVerified ? 'approved' : 'pending';

            $this->db->query(
                "INSERT INTO reviews (product_id, user_id, order_item_id, rating, title, body, is_verified, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$product->id, $uid, $orderItem['id'] ?? null, $rating, $title ?: null, $body, $isVerified, $status]
            );

            if ($status === 'approved') {
                $this->db->query(
                    "UPDATE products SET
                        avg_rating   = (SELECT AVG(rating) FROM reviews WHERE product_id=? AND status='approved'),
                        rating_count = (SELECT COUNT(*)     FROM reviews WHERE product_id=? AND status='approved')
                     WHERE id=?",
                    [$product->id, $product->id, $product->id]
                );
                $this->auth->flash('success', 'Thanks! Your review is live.');
            } else {
                $this->auth->flash('success', 'Thanks! Your review will appear once approved.');
            }
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not submit review: ' . $e->getMessage());
        }

        redirect('/product/' . $slug);
    }
}
