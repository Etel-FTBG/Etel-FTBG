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
                        (SELECT pi.path FROM product_images pi WHERE pi.product_id=oi.product_id ORDER BY pi.sort_order LIMIT 1) as thumb
                   FROM order_items oi
                   JOIN products p ON p.id = oi.product_id
                  WHERE oi.order_id = ?",
                [$order['id']]
            );
        } catch (\Throwable $_) {
            if (!$order) redirect('/account/orders');
        }

        View::render('customer/order-detail', [
            'pageTitle' => 'Order #' . $code,
            'locale'    => $locale,
            'user'      => $this->auth->user(),
            'order'     => $order,
            'items'     => $items,
            'auth'      => $this->auth,
        ]);
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
            $this->db->query(
                "INSERT INTO user_addresses (user_id,label,full_name,phone,city,sub_city,wereda,address_detail,is_default,created_at)
                 VALUES (?,?,?,?,?,?,?,?,0,NOW())",
                [$uid,$label,$fullName,$phone,$city,$subCity,$wereda,$detail]
            );
            $this->auth->flash('success', 'Address saved.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not save address: '.$e->getMessage());
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

    /* ?? POST /account/wishlist/add ???????????????????????? */
    public function wishlistAdd(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            try {
                $this->db->query(
                    "INSERT IGNORE INTO wishlist (user_id, product_id, created_at) VALUES (?,?,NOW())",
                    [$uid, $pid]
                );
            } catch (\Throwable $_) {}
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '/account/wishlist';
        redirect($ref);
    }

    /* ?? POST /account/wishlist/remove ???????????????????? */
    public function wishlistRemove(): void
    {
        $this->requireLogin();
        $uid = $this->auth->id();
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            try {
                $this->db->query("DELETE FROM wishlist WHERE user_id=? AND product_id=?", [$uid, $pid]);
            } catch (\Throwable $_) {}
        }
        redirect('/account/wishlist');
    }
}
