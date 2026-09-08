<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

final class VendorController
{
    private Auth $auth;
    private \App\Core\Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = \App\Core\Database::getInstance();
    }

    /* ?? helpers ???????????????????????????????????????????? */

    private function requireVendor(): array
    {
        if (!$this->auth->check()) {
            $_SESSION['_intended'] = '/vendor-panel';
            redirect('/login');
        }
        if (!$this->auth->isVendor()) {
            redirect('/sell');
        }
        $seller = $this->db->fetch(
            "SELECT * FROM sellers WHERE user_id = ? LIMIT 1",
            [$this->auth->id()]
        );
        if (!$seller) {
            $user = $this->auth->user();
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($user['full_name'] ?? 'vendor')) . '-' . $this->auth->id();
            $this->db->insert('sellers', [
                'user_id'    => $this->auth->id(),
                'store_name' => $user['full_name'] ?? 'My Store',
                'store_slug' => $slug,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $seller = $this->db->fetch("SELECT * FROM sellers WHERE user_id = ? LIMIT 1", [$this->auth->id()]);
        }
        return $seller;
    }

    private function render(string $view, array $data = []): void
    {
        View::render($view, array_merge(['auth' => $this->auth], $data));
    }

    private function handleImageUploads(int $productId): void
    {
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $slots = []; // slot index => path

        for ($i = 0; $i < 6; $i++) {
            // File upload takes priority
            $fk = 'file_' . $i;
            if (!empty($_FILES[$fk]['tmp_name']) && $_FILES[$fk]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$fk]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true) && $_FILES[$fk]['size'] <= 5*1024*1024) {
                    $fname = 'p' . $productId . '_' . time() . '_' . $i . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fk]['tmp_name'], $uploadDir . $fname)) {
                        $slots[$i] = '/EtelGebeya/public/uploads/products/' . $fname;
                        continue;
                    }
                }
            }
            // Fallback: URL paste
            $url = trim($_POST['path_' . $i] ?? '');
            if ($url) $slots[$i] = $url;
        }

        if (!$slots) return;

        try { $this->db->delete('product_images', 'product_id = ?', [$productId]); } catch (\Throwable $_) {}

        foreach ($slots as $sort => $path) {
            try {
                $this->db->insert('product_images', [
                    'product_id' => $productId,
                    'sort_order' => $sort,
                    'path'       => $path,
                ]);
            } catch (\Throwable $_) {}
        }
    }

    /* ?? GET /vendor-panel ?????????????????????????????????? */
    public function dashboard(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];

        $productCount = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM products WHERE seller_id = ?", [$sid]) ?: 0);
        $activeCount  = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status='active'", [$sid]) ?: 0);
        $orderCount   = 0;
        $revenue      = 0.0;
        $recentOrders = [];

        try {
            $orderCount = (int)($this->db->fetchColumn(
                "SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.seller_id=?",
                [$sid]
            ) ?: 0);
            $revenue = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(oi.total_price),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.seller_id=? AND o.status NOT IN ('cancelled','returned')",
                [$sid]
            ) ?: 0);
            $recentOrders = $this->db->fetchAll(
                "SELECT o.order_ref AS code, o.status, o.created_at, SUM(oi.total_price) as total
                   FROM orders o JOIN order_items oi ON oi.order_id=o.id
                  WHERE oi.seller_id=? GROUP BY o.id ORDER BY o.created_at DESC LIMIT 5",
                [$sid]
            );
        } catch (\Throwable $_) {}

        $recentProducts = $this->db->fetchAll(
            "SELECT p.id, p.name_en, p.price, p.status, p.stock_qty, p.is_featured,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) as thumb
               FROM products p WHERE p.seller_id=? ORDER BY p.created_at DESC LIMIT 6",
            [$sid]
        );

        $this->render('vendor/dashboard', [
            'pageTitle'      => 'Vendor Dashboard',
            'seller'         => $seller,
            'productCount'   => $productCount,
            'activeCount'    => $activeCount,
            'orderCount'     => $orderCount,
            'revenue'        => $revenue,
            'recentOrders'   => $recentOrders,
            'recentProducts' => $recentProducts,
        ]);
    }

    /* ?? GET /vendor-panel/products ???????????????????????? */
    public function products(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $per    = 20;
        $offset = ($page - 1) * $per;
        $status = $_GET['status'] ?? '';
        $q      = trim($_GET['q'] ?? '');

        $where = "p.seller_id = $sid";
        if ($status) $where .= " AND p.status = " . $this->db->pdo()->quote($status);
        if ($q)      $where .= " AND (p.name_en LIKE " . $this->db->pdo()->quote('%'.$q.'%') . " OR p.sku LIKE " . $this->db->pdo()->quote('%'.$q.'%') . ")";

        $total = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM products p WHERE $where") ?: 0);
        $rows  = $this->db->fetchAll(
            "SELECT p.*, (SELECT pi.path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) as thumb
               FROM products p WHERE $where ORDER BY p.created_at DESC LIMIT $per OFFSET $offset"
        );

        $this->render('vendor/products', [
            'pageTitle' => 'My Products',
            'seller'    => $seller,
            'products'  => $rows,
            'total'     => $total,
            'page'      => $page,
            'per'       => $per,
            'status'    => $status,
            'q'         => $q,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* ?? GET /vendor-panel/products/new
           GET /vendor-panel/products/{id}/edit ??????????? */
    public function productForm(int $id = 0): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $product = null;
        $productImages = [];

        if ($id > 0) {
            $product = $this->db->fetch("SELECT * FROM products WHERE id = ? AND seller_id = ?", [$id, $sid]);
            if (!$product) { http_response_code(403); redirect('/vendor-panel/products'); }
            try {
                $productImages = $this->db->fetchAll(
                    "SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC",
                    [$id]
                );
            } catch (\Throwable $_) {}
        }

        $categories = $this->db->fetchAll("SELECT id, name_en FROM categories WHERE is_active = 1 ORDER BY name_en");

        $this->render('vendor/product-form', [
            'pageTitle'     => $product ? 'Edit Product' : 'Add Product',
            'seller'        => $seller,
            'product'       => $product,
            'productImages' => $productImages,
            'categories'    => $categories,
            'flash'         => $this->auth->takeFlash(),
        ]);
    }

    /* ?? POST /vendor-panel/products/save ?????????????????? */
    public function productSave(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];

        $id           = (int)($_POST['id'] ?? 0);
        $nameEn       = trim($_POST['name_en'] ?? '');
        $nameAm       = trim($_POST['name_am'] ?? '');
        $descEn       = trim($_POST['description_en'] ?? '');
        $descAm       = trim($_POST['description_am'] ?? '');
        $price        = (float)($_POST['price'] ?? 0);
        $comparePrice = (float)($_POST['compare_price'] ?? 0) ?: null;
        $catId        = (int)($_POST['category_id'] ?? 0) ?: null;
        $stockQty     = (int)($_POST['stock_qty'] ?? 0);
        $lowStock     = (int)($_POST['low_stock_alert'] ?? 5);
        $sku          = trim($_POST['sku'] ?? '');
        $weightGrams  = (int)($_POST['weight_grams'] ?? 0) ?: null;
        $status       = in_array($_POST['status'] ?? '', ['active','draft'], true) ? $_POST['status'] : 'draft';
        $isFeatured   = !empty($_POST['is_featured']) ? 1 : 0;

        if ($nameEn === '' || $price <= 0) {
            $this->auth->flash('error', 'Product name and price are required.');
            redirect($id ? "/vendor-panel/products/$id/edit" : '/vendor-panel/products/new');
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($nameEn)) . '-' . substr(md5($nameEn.$sid), 0, 6);

        $data = [
            'name_en'         => $nameEn,
            'name_am'         => $nameAm ?: $nameEn,
            'slug'            => $slug,
            'price'           => $price,
            'category_id'     => $catId,
            'description_en'  => $descEn,
            'stock_qty'       => $stockQty,
            'status'          => $status,
            'is_featured'     => $isFeatured,
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        // Optional columns -- add only if they exist
        if ($sku !== '')         $data['sku']              = $sku;
        if ($weightGrams)        $data['weight_grams']     = $weightGrams;
        if ($lowStock > 0)       $data['low_stock_alert']  = $lowStock;
        if ($descAm !== '')      { try { $data['description_am'] = $descAm; } catch (\Throwable $_) {} }
        if ($comparePrice)       { try { $data['compare_price']  = $comparePrice; } catch (\Throwable $_) {} }

        if ($id > 0) {
            $existing = $this->db->fetch("SELECT id FROM products WHERE id = ? AND seller_id = ?", [$id, $sid]);
            if (!$existing) { http_response_code(403); redirect('/vendor-panel/products'); }
            $this->db->update('products', $data, 'id = ?', [$id]);
            $this->handleImageUploads($id);
            $this->auth->flash('success', 'Product updated successfully.');
        } else {
            $data['seller_id']  = $sid;
            $data['created_at'] = date('Y-m-d H:i:s');
            $newId = $this->db->insert('products', $data);
            $this->handleImageUploads((int)$newId);
            $this->auth->flash('success', 'Product published successfully.');
        }

        redirect('/vendor-panel/products');
    }

    /* ?? POST /vendor-panel/products/{id}/delete ??????????? */
    public function productDelete(int $id): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $existing = $this->db->fetch("SELECT id FROM products WHERE id = ? AND seller_id = ?", [$id, $sid]);
        if ($existing) {
            try { $this->db->delete('product_images', 'product_id = ?', [$id]); } catch (\Throwable $_) {}
            $this->db->delete('products', 'id = ?', [$id]);
            $this->auth->flash('success', 'Product deleted.');
        }
        redirect('/vendor-panel/products');
    }

    /* ?? GET /vendor-panel/orders ?????????????????????????? */
    public function orders(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $items  = [];

        try {
            // One row per order_item (not aggregated) -- each item is this
            // seller's own portion of a possibly multi-vendor order, and
            // each has its own status independent of other sellers' items
            // in the same order.
            $items = $this->db->fetchAll(
                "SELECT oi.id AS item_id, oi.product_name, oi.quantity, oi.total_price, oi.status AS item_status,
                        o.order_ref AS code, o.created_at, o.delivery_city AS city,
                        u.full_name as customer_name, u.phone as customer_phone
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   JOIN users u ON u.id = o.user_id
                  WHERE oi.seller_id = ?
                  ORDER BY o.created_at DESC LIMIT 100",
                [$sid]
            );
        } catch (\Throwable $_) {}

        $this->render('vendor/orders', [
            'pageTitle' => 'My Orders',
            'seller'    => $seller,
            'items'     => $items,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* ?? POST /vendor-panel/orders/item-status ?????????????? */
    public function updateOrderItemStatus(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $itemId = (int)($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $allowed = ['confirmed', 'shipped', 'delivered', 'cancelled'];
        if (!$itemId || !in_array($status, $allowed, true)) {
            $this->auth->flash('error', 'Invalid status update.');
            redirect('/vendor-panel/orders');
        }

        $item = $this->db->fetch("SELECT id, order_id, status FROM order_items WHERE id = ? AND seller_id = ?", [$itemId, $sid]);
        if (!$item) {
            $this->auth->flash('error', 'Order item not found.');
            redirect('/vendor-panel/orders');
        }

        // Forward-only transitions (except cancel, allowed unless already delivered)
        $order = ['pending', 'confirmed', 'shipped', 'delivered'];
        $fromIdx = array_search($item['status'], $order, true);
        $toIdx   = array_search($status, $order, true);
        $isForward = $toIdx !== false && $fromIdx !== false && $toIdx === $fromIdx + 1;
        $isCancel  = $status === 'cancelled' && $item['status'] !== 'delivered';

        if (!$isForward && !$isCancel) {
            $this->auth->flash('error', 'Cannot change status from "' . $item['status'] . '" to "' . $status . '".');
            redirect('/vendor-panel/orders');
        }

        $update = ['status' => $status];
        if ($status === 'delivered') {
            new \App\Core\EscrowService(); // ensures delivered_at/escrow columns exist
            $update['delivered_at'] = date('Y-m-d H:i:s');
        }
        $this->db->update('order_items', $update, 'id = ?', [$itemId]);
        $this->bubbleOrderStatus((int)$item['order_id']);
        $this->auth->flash('success', 'Order item marked as ' . $status . '.');
        redirect('/vendor-panel/orders');
    }

    /**
     * Keeps the customer-facing orders.status in sync with what sellers
     * actually report at the item level -- it must never be the only
     * thing that says "delivered" (escrow release depends on
     * order_items.status, not this), but leaving it stuck on "new"
     * forever while every item ships is equally wrong. Whole-order
     * status = the weakest stage among its still-active items.
     */
    private function bubbleOrderStatus(int $orderId): void
    {
        $items = $this->db->fetchAll("SELECT status FROM order_items WHERE order_id = ?", [$orderId]);
        if (!$items) return;

        // order_items.status -> orders.status, in ascending fulfillment order.
        $stageOrder  = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3];
        $itemToOrder = ['pending' => 'new', 'confirmed' => 'confirmed', 'shipped' => 'shipped', 'delivered' => 'delivered'];
        $active = array_filter($items, fn($it) => !in_array($it['status'], ['cancelled', 'returned'], true));
        if (!$active) return; // everything on this order was cancelled -- leave orders.status alone

        $minStage  = min(array_map(fn($it) => $stageOrder[$it['status']] ?? 0, $active));
        $itemStage = array_search($minStage, $stageOrder, true);
        $newStatus = $itemToOrder[$itemStage] ?? 'new';

        $orderRank = ['new'=>0,'confirmed'=>1,'processing'=>2,'packed'=>3,'shipped'=>4,'out_for_delivery'=>5,'delivered'=>6];
        $current = $this->db->fetchColumn("SELECT status FROM orders WHERE id = ?", [$orderId]);
        // Never touch a cancelled/disputed/etc. order, and never move it
        // backward (item-level tracking is coarser than orders.status --
        // e.g. it has no notion of "packed" -- so it should only ever
        // push the order FORWARD to a stage it can actually represent).
        if (!isset($orderRank[$current])) return;
        if (($orderRank[$newStatus] ?? 0) > $orderRank[$current]) {
            $this->db->query("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?", [$newStatus, $orderId]);
        }
    }

    /* ?? GET /vendor-panel/payouts ????????????????????????? */
    public function payouts(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];

        $earned = 0.0;
        $paidOrReserved = 0.0;
        $history = [];
        try {
            // Per-item status, not the parent order's overall status -- in a
            // multi-vendor order, this seller's own items can be delivered
            // while other sellers' items in the same order are still in
            // transit, and they shouldn't have to wait on those.
            $earned = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(seller_payout),0) FROM order_items
                  WHERE seller_id = ? AND status = 'delivered'",
                [$sid]
            ) ?: 0);
            $paidOrReserved = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount),0) FROM seller_payouts
                  WHERE seller_id = ? AND status IN ('pending','approved','processing','completed')",
                [$sid]
            ) ?: 0);
            $history = $this->db->fetchAll(
                "SELECT * FROM seller_payouts WHERE seller_id = ? ORDER BY requested_at DESC LIMIT 20",
                [$sid]
            );
        } catch (\Throwable $_) {}

        $available = max(0, $earned - $paidOrReserved);

        $this->render('vendor/payouts', [
            'pageTitle' => 'Payouts',
            'seller'    => $seller,
            'earned'    => $earned,
            'available' => $available,
            'history'   => $history,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* ?? POST /vendor-panel/payouts/request ??????????????????? */
    public function requestPayout(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $amount = (float)($_POST['amount'] ?? 0);

        if ($amount <= 0) {
            $this->auth->flash('error', 'Enter an amount to withdraw.');
            redirect('/vendor-panel/payouts');
        }
        if (empty($seller['phone'])) {
            $this->auth->flash('error', 'Add a phone number in Store Settings before requesting a payout.');
            redirect('/vendor-panel/payouts');
        }

        try {
            // Per-item status, not the parent order's overall status -- in a
            // multi-vendor order, this seller's own items can be delivered
            // while other sellers' items in the same order are still in
            // transit, and they shouldn't have to wait on those.
            $earned = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(seller_payout),0) FROM order_items
                  WHERE seller_id = ? AND status = 'delivered'",
                [$sid]
            ) ?: 0);
            $reserved = (float)($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount),0) FROM seller_payouts
                  WHERE seller_id = ? AND status IN ('pending','approved','processing','completed')",
                [$sid]
            ) ?: 0);
            $available = max(0, $earned - $reserved);

            if ($amount > $available) {
                $this->auth->flash('error', 'You can withdraw at most Br ' . number_format($available, 2) . '.');
                redirect('/vendor-panel/payouts');
            }

            $this->db->insert('seller_payouts', [
                'seller_id'    => $sid,
                'amount'       => $amount,
                'method'       => 'etel_pay',
                'account'      => $seller['phone'],
                'status'       => 'pending',
                'requested_at' => date('Y-m-d H:i:s'),
            ]);
            $this->auth->flash('success', 'Payout requested. An admin will review and release it via ኢE Pay.');
        } catch (\Throwable $e) {
            $this->auth->flash('error', 'Could not request payout: ' . $e->getMessage());
        }

        redirect('/vendor-panel/payouts');
    }

    /* ?? GET /vendor-panel/settings ???????????????????????? */
    public function settings(): void
    {
        $seller = $this->requireVendor();
        $this->render('vendor/settings', [
            'pageTitle' => 'Store Settings',
            'seller'    => $seller,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* ?? POST /vendor-panel/settings ??????????????????????? */
    public function saveSettings(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];

        $storeName   = trim($_POST['store_name'] ?? '');
        $storeNameAm = trim($_POST['store_name_am'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');

        if ($storeName === '') {
            $this->auth->flash('error', 'Store name is required.');
            redirect('/vendor-panel/settings');
        }

        $this->db->update('sellers', [
            'store_name'    => $storeName,
            'store_name_am' => $storeNameAm ?: $storeName,
            'description'   => $description,
            'phone'         => $phone,
        ], 'id = ?', [$sid]);

        $this->auth->flash('success', 'Store settings saved.');
        redirect('/vendor-panel/settings');
    }

    /* ?? GET|POST /sell ???????????????????????????????????? */
    public function signup(): void
    {
        if ($this->auth->check() && $this->auth->isVendor()) {
            redirect('/vendor-panel');
        }

        $flash = $this->auth->takeFlash();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name     = trim($_POST['name'] ?? '');
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $store    = trim($_POST['store_name'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');

            if (!$name || !$email || !$password || !$store) {
                $this->auth->flash('error', 'All fields are required.');
                redirect('/sell');
            }

            $existing = $this->db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
            if ($existing) {
                $this->auth->flash('error', 'This email is already registered. Please log in.');
                redirect('/sell');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($store)) . '-' . substr(md5($store.$email), 0, 6);

            $userId = $this->db->insert('users', [
                'full_name'     => $name,
                'email'         => $email,
                'password_hash' => $hash,
                'phone'         => $phone,
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            try { $this->db->insert('user_roles', ['user_id' => $userId, 'role' => 'vendor']); } catch (\Throwable $_) {}

            $this->db->insert('sellers', [
                'user_id'    => $userId,
                'store_name' => $store,
                'store_slug' => $slug,
                'phone'      => $phone,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->auth->loginUser($userId);
            redirect('/vendor-panel');
        }

        View::render('storefront/sell', [
            'pageTitle' => 'Become a Vendor',
            'flash'     => $flash,
            'auth'      => $this->auth,
        ]);
    }
}
