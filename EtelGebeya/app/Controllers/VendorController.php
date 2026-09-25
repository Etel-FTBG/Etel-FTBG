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
            // 'pending', not 'active' - matches AuthController::register()'s
            // vendor path and AdminController::approveVendor()'s intended
            // flow. Storefront visibility already gates on status='active'
            // (see StorefrontController), so this doesn't block the vendor
            // from using their panel, only from being publicly listed
            // before an admin approves them.
            $this->db->insert('sellers', [
                'user_id'    => $this->auth->id(),
                'store_name' => $user['full_name'] ?? 'My Store',
                'store_slug' => $slug,
                'status'     => 'pending',
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

    /** Verifies the POSTed _token CSRF value, redirecting back with a flash error if it's missing or stale. */
    private function requireCsrf(string $redirectTo): void
    {
        if (!$this->auth->csrfCheck($_POST['_token'] ?? null)) {
            $this->auth->flash('error', 'Your session expired -- please try again.');
            redirect($redirectTo);
        }
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
                // The client-supplied extension alone was previously the
                // only check -- a renamed non-image file (e.g. a PHP
                // payload saved as "shell.php.jpg") would pass it and land
                // in a public, web-served uploads directory. getimagesize()
                // reads the file's real bytes, the same content-sniffing
                // pattern this codebase's own App\Core\Upload::image()
                // already uses for other upload paths.
                $info = @getimagesize($_FILES[$fk]['tmp_name']);
                $allowedTypes = [IMAGETYPE_JPEG => true, IMAGETYPE_PNG => true, IMAGETYPE_WEBP => true, IMAGETYPE_GIF => true];
                if ($info && isset($allowedTypes[$info[2]])
                    && in_array($ext, ['jpg','jpeg','png','webp','gif'], true)
                    && $_FILES[$fk]['size'] <= 5*1024*1024) {
                    $fname = 'p' . $productId . '_' . time() . '_' . $i . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fk]['tmp_name'], $uploadDir . $fname)) {
                        $slots[$i] = '/EtelGebeya/public/uploads/products/' . $fname;
                        continue;
                    }
                }
            }
            // Fallback: URL paste -- restrict to http(s) so a pasted
            // data:/javascript:/other scheme can never land in a stored
            // <img src> across storefront/vendor views.
            $url = trim($_POST['path_' . $i] ?? '');
            if ($url !== '' && strlen($url) <= 2048 && preg_match('#^https?://#i', $url)) {
                $slots[$i] = $url;
            }
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
        $suppliers  = \App\Models\Supplier::forSeller($this->db, $sid);

        $this->render('vendor/product-form', [
            'pageTitle'     => $product ? 'Edit Product' : 'Add Product',
            'seller'        => $seller,
            'product'       => $product,
            'productImages' => $productImages,
            'categories'    => $categories,
            'suppliers'     => $suppliers,
            'flash'         => $this->auth->takeFlash(),
        ]);
    }

    /* ?? POST /vendor-panel/products/save ?????????????????? */
    public function productSave(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/products');
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

        $isDropship   = !empty($_POST['is_dropship']) ? 1 : 0;
        $supplierId   = (int)($_POST['supplier_id'] ?? 0) ?: null;
        if ($isDropship && $supplierId) {
            // A supplier picked from the dropdown must actually belong to this seller.
            $ok = \App\Models\Supplier::find($this->db, $supplierId, $sid);
            if (!$ok) $supplierId = null;
        }
        $supplierSku  = trim($_POST['supplier_sku'] ?? '');
        $supplierUrl  = trim($_POST['supplier_product_url'] ?? '');
        $leadMin      = (int)($_POST['dropship_lead_days_min'] ?? 0) ?: null;
        $leadMax      = (int)($_POST['dropship_lead_days_max'] ?? 0) ?: null;
        if ($leadMin && $leadMax && $leadMax < $leadMin) $leadMax = $leadMin;

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

        \App\Models\Supplier::ensureSchema($this->db);
        $data['is_dropship']            = $isDropship;
        $data['supplier_id']            = $isDropship ? $supplierId : null;
        $data['supplier_sku']           = $isDropship && $supplierSku !== '' ? $supplierSku : null;
        $data['supplier_product_url']   = $isDropship && $supplierUrl !== '' ? $supplierUrl : null;
        $data['dropship_lead_days_min'] = $isDropship ? $leadMin : null;
        $data['dropship_lead_days_max'] = $isDropship ? $leadMax : null;

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
        $this->requireCsrf('/vendor-panel/products');
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

        \App\Models\Supplier::ensureSchema($this->db);

        try {
            // One row per order_item (not aggregated) -- each item is this
            // seller's own portion of a possibly multi-vendor order, and
            // each has its own status independent of other sellers' items
            // in the same order. Supplier fields come from the order_item's
            // own snapshot (is_dropship/supplier_id), not the live product --
            // a product's dropship setup can change after an order was placed.
            $items = $this->db->fetchAll(
                "SELECT oi.id AS item_id, oi.product_name, oi.quantity, oi.total_price, oi.status AS item_status,
                        oi.is_dropship, oi.forwarded_at, oi.forward_method,
                        oi.tracking_number, oi.courier_id, c.name AS courier_name,
                        s.id AS supplier_id, s.name AS supplier_name, s.contact_email AS supplier_email,
                        s.contact_phone AS supplier_phone, s.website AS supplier_website,
                        p.supplier_sku, p.supplier_product_url,
                        o.order_ref AS code, o.created_at, o.delivery_city AS city,
                        u.full_name as customer_name, u.phone as customer_phone
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   JOIN users u ON u.id = o.user_id
                   LEFT JOIN suppliers s ON s.id = oi.supplier_id
                   LEFT JOIN products p ON p.id = oi.product_id
                   LEFT JOIN couriers c ON c.id = oi.courier_id
                  WHERE oi.seller_id = ?
                  ORDER BY o.created_at DESC LIMIT 100",
                [$sid]
            );
        } catch (\Throwable $_) {}

        $couriers = [];
        try {
            $couriers = $this->db->fetchAll("SELECT id, name, phone FROM couriers ORDER BY name");
        } catch (\Throwable $_) {}

        $this->render('vendor/orders', [
            'pageTitle' => 'My Orders',
            'seller'    => $seller,
            'items'     => $items,
            'couriers'  => $couriers,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- POST /vendor-panel/orders/mark-forwarded -- */
    public function markOrderItemForwarded(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/orders');
        $sid    = (int)$seller['id'];
        $itemId = (int)($_POST['item_id'] ?? 0);

        $item = $this->db->fetch("SELECT id FROM order_items WHERE id = ? AND seller_id = ? AND is_dropship = 1", [$itemId, $sid]);
        if (!$item) {
            $this->auth->flash('error', 'Order item not found.');
            redirect('/vendor-panel/orders');
        }

        $this->db->update('order_items', [
            'forwarded_at'   => date('Y-m-d H:i:s'),
            'forwarded_by'   => $this->auth->id(),
            'forward_method' => 'manual',
        ], 'id = ?', [$itemId]);

        $this->auth->flash('success', 'Marked as forwarded to your supplier.');
        redirect('/vendor-panel/orders');
    }

    /* -- POST /vendor-panel/orders/email-supplier -- */
    public function emailSupplier(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/orders');
        $sid    = (int)$seller['id'];
        $itemId = (int)($_POST['item_id'] ?? 0);

        $item = $this->db->fetch(
            "SELECT oi.*, s.name AS supplier_name, s.contact_email AS supplier_email,
                    p.supplier_sku, p.supplier_product_url, o.order_ref, o.delivery_city, o.delivery_address
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN suppliers s ON s.id = oi.supplier_id
               LEFT JOIN products p ON p.id = oi.product_id
              WHERE oi.id = ? AND oi.seller_id = ? AND oi.is_dropship = 1",
            [$itemId, $sid]
        );
        if (!$item) {
            $this->auth->flash('error', 'Order item not found.');
            redirect('/vendor-panel/orders');
        }
        if (empty($item['supplier_email'])) {
            $this->auth->flash('error', 'This item\'s supplier has no email on file -- add one in Suppliers, or use "Mark as Forwarded" instead.');
            redirect('/vendor-panel/orders');
        }

        $subject = 'New order to fulfil -- ' . $item['order_ref'];
        $body = "Please ship the following item directly to the customer below.\n\n"
              . "Order: " . $item['order_ref'] . "\n"
              . "Product: " . $item['product_name'] . "\n"
              . (!empty($item['supplier_sku']) ? "Supplier SKU: " . $item['supplier_sku'] . "\n" : '')
              . (!empty($item['supplier_product_url']) ? "Product link: " . $item['supplier_product_url'] . "\n" : '')
              . "Quantity: " . (int)$item['quantity'] . "\n\n"
              . "Ship to:\n" . ($item['delivery_address'] ?? '') . "\n" . ($item['delivery_city'] ?? '') . "\n\n"
              . "-- Sent via " . ($seller['store_name'] ?? 'Etel Gebeya vendor') . " on Etel Gebeya";

        $sent = \App\Core\Mailer::send($item['supplier_email'], $subject, $body, $seller['store_name'] ?? 'Etel Gebeya');

        $this->db->update('order_items', [
            'forwarded_at'   => date('Y-m-d H:i:s'),
            'forwarded_by'   => $this->auth->id(),
            'forward_method' => 'email',
        ], 'id = ?', [$itemId]);

        $this->auth->flash($sent ? 'success' : 'error', $sent
            ? 'Order sheet emailed to ' . $item['supplier_name'] . '.'
            : 'Marked as forwarded, but the email could not be sent -- double-check the supplier\'s address.');
        redirect('/vendor-panel/orders');
    }

    /* -- GET /vendor-panel/suppliers -- */
    public function suppliers(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $rows   = \App\Models\Supplier::forSeller($this->db, $sid);

        $this->render('vendor/suppliers', [
            'pageTitle' => 'Suppliers',
            'seller'    => $seller,
            'suppliers' => $rows,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- POST /vendor-panel/suppliers/save -- */
    public function supplierSave(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/suppliers');
        $sid    = (int)$seller['id'];
        \App\Models\Supplier::ensureSchema($this->db);

        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');
        $phone = trim($_POST['contact_phone'] ?? '');
        $site  = trim($_POST['website'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            $this->auth->flash('error', 'Supplier name is required.');
            redirect('/vendor-panel/suppliers');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->auth->flash('error', 'That email address doesn\'t look valid.');
            redirect('/vendor-panel/suppliers');
        }

        $data = [
            'name'          => $name,
            'contact_email' => $email ?: null,
            'contact_phone' => $phone ?: null,
            'website'       => $site ?: null,
            'notes'         => $notes ?: null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            $existing = \App\Models\Supplier::find($this->db, $id, $sid);
            if (!$existing) { http_response_code(403); redirect('/vendor-panel/suppliers'); }
            $this->db->update('suppliers', $data, 'id = ?', [$id]);
            $this->auth->flash('success', 'Supplier updated.');
        } else {
            $data['seller_id']  = $sid;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('suppliers', $data);
            $this->auth->flash('success', 'Supplier added.');
        }

        redirect('/vendor-panel/suppliers');
    }

    /* -- POST /vendor-panel/suppliers/{id}/delete -- */
    public function supplierDelete(int $id): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/suppliers');
        $sid    = (int)$seller['id'];
        $existing = \App\Models\Supplier::find($this->db, $id, $sid);
        if ($existing) {
            // Detach rather than block deletion -- a vendor should be able to
            // remove a supplier they no longer use without it silently
            // failing because old products/orders still reference it.
            $this->db->query("UPDATE products SET supplier_id = NULL WHERE supplier_id = ? AND seller_id = ?", [$id, $sid]);
            $this->db->delete('suppliers', 'id = ?', [$id]);
            $this->auth->flash('success', 'Supplier removed.');
        }
        redirect('/vendor-panel/suppliers');
    }

    /* -- GET /vendor-panel/catalog (platform supplier catalog, phase 2) -- */
    public function catalog(): void
    {
        $seller = $this->requireVendor();
        $sid    = (int)$seller['id'];
        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $per    = 24;
        $offset = ($page - 1) * $per;

        $items    = \App\Models\CatalogItem::browse($this->db, $q, $per, $offset);
        $total    = \App\Models\CatalogItem::countActive($this->db, $q);
        $pages    = max(1, (int)ceil($total / $per));
        $imported = \App\Models\CatalogItem::importedMapForSeller($this->db, $sid);

        $this->render('vendor/catalog', [
            'pageTitle' => 'Supplier Catalog',
            'seller'    => $seller,
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'q'         => $q,
            'imported'  => $imported,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- POST /vendor-panel/catalog/import -- */
    public function catalogImport(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/catalog');
        $sid    = (int)$seller['id'];
        $itemId = (int)($_POST['catalog_item_id'] ?? 0);
        $price  = (float)($_POST['sale_price'] ?? 0);
        $stock  = (int)($_POST['stock_qty'] ?? 100);

        if ($itemId <= 0 || $price <= 0) {
            $this->auth->flash('error', 'Choose a sale price greater than zero.');
            redirect('/vendor-panel/catalog');
        }

        $productId = \App\Models\CatalogItem::importForSeller($this->db, $itemId, $sid, $price, max(1, $stock));
        if (!$productId) {
            $this->auth->flash('error', 'Could not import this item -- it may no longer be available.');
            redirect('/vendor-panel/catalog');
        }

        $this->auth->flash('success', 'Imported to your store as a dropship product.');
        redirect('/vendor-panel/products/' . $productId . '/edit');
    }

    /* ?? POST /vendor-panel/orders/item-status ?????????????? */
    public function updateOrderItemStatus(): void
    {
        $seller = $this->requireVendor();
        $this->requireCsrf('/vendor-panel/orders');
        $sid    = (int)$seller['id'];
        $itemId = (int)($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $allowed = ['confirmed', 'shipped', 'delivered', 'cancelled'];
        if (!$itemId || !in_array($status, $allowed, true)) {
            $this->auth->flash('error', 'Invalid status update.');
            redirect('/vendor-panel/orders');
        }

        $item = $this->db->fetch("SELECT id, order_id, status, product_id, quantity FROM order_items WHERE id = ? AND seller_id = ?", [$itemId, $sid]);
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
        if ($status === 'shipped') {
            // Both optional - a seller may not have a tracking number yet
            // (or the shipment doesn't have one at all), and shouldn't be
            // blocked from marking the item shipped either way.
            $tracking = trim((string)($_POST['tracking_number'] ?? ''));
            if ($tracking !== '') $update['tracking_number'] = substr($tracking, 0, 60);
            $courierId = (int)($_POST['courier_id'] ?? 0);
            if ($courierId > 0) $update['courier_id'] = $courierId;
        }
        if ($status === 'cancelled' && !empty($item['product_id'])) {
            // Give back the stock CheckoutController::place() reserved at
            // purchase time -- without this, every cancelled item would
            // permanently shrink stock_qty for a sale that never actually
            // happened.
            $this->db->query(
                "UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?",
                [(int)$item['quantity'], (int)$item['product_id']]
            );
        }
        $this->db->update('order_items', $update, 'id = ?', [$itemId]);
        \App\Core\OrderFulfillment::bubbleOrderStatus($this->db, (int)$item['order_id']);
        $this->auth->flash('success', 'Order item marked as ' . $status . '.');
        redirect('/vendor-panel/orders');
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
        $this->requireCsrf('/vendor-panel/payouts');
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
            error_log('[EG-Vendor] requestPayout: ' . $e->getMessage());
            $this->auth->flash('error', 'Could not request payout. Please try again.');
        }

        redirect('/vendor-panel/payouts');
    }

    /* ?? GET /vendor-panel/settings ???????????????????????? */
    public function settings(): void
    {
        $seller = $this->requireVendor();
        // saveSettings() below already self-heals these columns on save;
        // ensure them here too so a first-ever page view (before any save
        // has happened) doesn't render with them silently missing from
        // $seller -- the view itself is also null-safe as a second layer,
        // but keeping schema in sync here avoids the gap existing at all.
        \App\Core\SchemaGuard::ensureColumns($this->db, 'sellers', [
            'pickup_lat'     => 'pickup_lat DECIMAL(10,7) DEFAULT NULL',
            'pickup_lng'     => 'pickup_lng DECIMAL(10,7) DEFAULT NULL',
            'pickup_address' => 'pickup_address VARCHAR(255) DEFAULT NULL',
        ]);
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
        $this->requireCsrf('/vendor-panel/settings');
        $sid    = (int)$seller['id'];

        $storeName   = trim($_POST['store_name'] ?? '');
        $storeNameAm = trim($_POST['store_name_am'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $pickupAddr  = trim($_POST['pickup_address'] ?? '');
        $pickupLat   = isset($_POST['pickup_lat']) && is_numeric($_POST['pickup_lat']) ? (float)$_POST['pickup_lat'] : null;
        $pickupLng   = isset($_POST['pickup_lng']) && is_numeric($_POST['pickup_lng']) ? (float)$_POST['pickup_lng'] : null;

        if ($storeName === '') {
            $this->auth->flash('error', 'Store name is required.');
            redirect('/vendor-panel/settings');
        }

        \App\Core\SchemaGuard::ensureColumns($this->db, 'sellers', [
            'pickup_lat'     => 'pickup_lat DECIMAL(10,7) DEFAULT NULL',
            'pickup_lng'     => 'pickup_lng DECIMAL(10,7) DEFAULT NULL',
            'pickup_address' => 'pickup_address VARCHAR(255) DEFAULT NULL',
        ]);

        $this->db->update('sellers', [
            'store_name'     => $storeName,
            'store_name_am'  => $storeNameAm ?: $storeName,
            'description'    => $description,
            'phone'          => $phone,
            'pickup_address' => $pickupAddr,
            'pickup_lat'     => $pickupLat,
            'pickup_lng'     => $pickupLng,
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

            // 'pending', not 'active' - /sell used to activate sellers
            // immediately with no admin review, bypassing the same approval
            // gate AuthController::register()'s vendor path already
            // enforces. Storefront visibility gates on status='active', so
            // the new vendor can still use their panel and set up their
            // store while awaiting approval; they just aren't publicly
            // listed until an admin approves them.
            $this->db->insert('sellers', [
                'user_id'    => $userId,
                'store_name' => $store,
                'store_slug' => $slug,
                'phone'      => $phone,
                'status'     => 'pending',
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
