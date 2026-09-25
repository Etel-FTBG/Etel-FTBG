<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

/**
 * AdminController -- EtelGebeya platform administration.
 * Routes registered in public/index.php under /admin/*.
 * All PHP in this file is pure ASCII (WAF-safe).
 */
final class AdminController
{
    private Auth $auth;
    private Database $db;
    private int $perPage = 20;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
        $this->guard();
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function guard(): void
    {
        if (!$this->auth->check()) {
            $_SESSION['_intended'] = '/admin';
            redirect('/login');
        }
        if (!$this->auth->isAdmin()) {
            redirect('/');
        }
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

    private function page(): int
    {
        return max(1, (int)($_GET['page'] ?? 1));
    }

    private function offset(int $page): int
    {
        return ($page - 1) * $this->perPage;
    }

    // -------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------

    public function dashboard(): void
    {
        $db = $this->db;

        $stats = [
            'orders_total'     => (int)($db->fetchColumn("SELECT COUNT(*) FROM orders") ?? 0),
            'orders_new'       => (int)($db->fetchColumn("SELECT COUNT(*) FROM orders WHERE status='new'") ?? 0),
            'revenue'          => (float)($db->fetchColumn("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status='paid'") ?? 0),
            'products_total'   => (int)($db->fetchColumn("SELECT COUNT(*) FROM products") ?? 0),
            'products_pending' => (int)($db->fetchColumn("SELECT COUNT(*) FROM products WHERE status='pending'") ?? 0),
            'sellers_total'    => (int)($db->fetchColumn("SELECT COUNT(*) FROM sellers") ?? 0),
            'sellers_pending'  => (int)($db->fetchColumn("SELECT COUNT(*) FROM sellers WHERE status='pending'") ?? 0),
            'users_total'      => (int)($db->fetchColumn("SELECT COUNT(*) FROM users") ?? 0),
        ];

        $recent_orders = $db->fetchAll(
            "SELECT order_ref AS code, total_amount AS total, status,
                    payment_status, delivery_name AS full_name, created_at
             FROM orders ORDER BY created_at DESC LIMIT 8"
        );

        $pending_products = $db->fetchAll(
            "SELECT p.id, p.name_en, p.price, s.store_name
             FROM products p LEFT JOIN sellers s ON p.seller_id=s.id
             WHERE p.status='pending' ORDER BY p.id DESC LIMIT 6"
        );

        $pending_sellers = $db->fetchAll(
            "SELECT s.id, s.store_name, s.phone, u.full_name, u.email
             FROM sellers s LEFT JOIN users u ON s.user_id=u.id
             WHERE s.status='pending' ORDER BY s.id DESC LIMIT 6"
        );

        $this->render('admin-panel/dashboard', compact(
            'stats', 'recent_orders', 'pending_products', 'pending_sellers'
        ));
    }

    // -------------------------------------------------------
    // Vendors (= Sellers table)
    // -------------------------------------------------------

    public function vendors(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['q'] ?? '');
        $page   = $this->page();

        $where  = '1=1';
        $params = [];
        if ($filter !== 'all') {
            $where .= ' AND s.status=?';
            $params[] = $filter;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (s.store_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM sellers s LEFT JOIN users u ON s.user_id=u.id WHERE $where",
            $params) ?? 0);

        $sellers = $this->db->fetchAll(
            "SELECT s.id, s.store_name, s.store_name_am, s.store_slug, s.status, s.phone,
                    u.full_name, u.email,
                    (SELECT COUNT(*) FROM products WHERE seller_id=s.id) AS product_count,
                    (SELECT COUNT(*) FROM products WHERE seller_id=s.id AND status='pending') AS pending_count
             FROM sellers s LEFT JOIN users u ON s.user_id=u.id
             WHERE $where ORDER BY s.id DESC
             LIMIT {$this->perPage} OFFSET {$this->offset($page)}",
            $params);

        $flash = $this->auth->takeFlash();
        $this->render('admin-panel/sellers',
            compact('sellers', 'filter', 'search', 'page', 'total', 'flash'));
    }

    public function approveVendor($id): void
    {
        $this->requireCsrf('/admin/vendors');
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('sellers', ['status' => 'active'], 'id=?', [$id]);
            $this->auth->flash('success', 'Seller approved and activated.');
        }
        redirect('/admin/vendors');
    }

    public function suspendVendor($id): void
    {
        $this->requireCsrf('/admin/vendors');
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('sellers', ['status' => 'suspended'], 'id=?', [$id]);
            $this->auth->flash('error', 'Seller suspended.');
        }
        redirect('/admin/vendors');
    }

    // -------------------------------------------------------
    // Products
    // -------------------------------------------------------

    public function products(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['q'] ?? '');
        $page   = $this->page();

        $where  = '1=1';
        $params = [];
        if ($filter !== 'all') {
            $where .= ' AND p.status=?';
            $params[] = $filter;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (p.name_en LIKE ? OR p.name_am LIKE ? OR s.store_name LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM products p
             LEFT JOIN sellers s ON p.seller_id=s.id WHERE $where",
            $params) ?? 0);

        $products = $this->db->fetchAll(
            "SELECT p.id, p.name_en, p.name_am, p.price, p.compare_price,
                    p.stock_qty, p.sold_count, p.status,
                    s.store_name, s.store_slug,
                    (SELECT path FROM product_images WHERE product_id=p.id ORDER BY sort_order LIMIT 1) AS thumb
             FROM products p LEFT JOIN sellers s ON p.seller_id=s.id
             WHERE $where ORDER BY p.id DESC
             LIMIT {$this->perPage} OFFSET {$this->offset($page)}",
            $params);

        $flash = $this->auth->takeFlash();
        $this->render('admin-panel/products',
            compact('products', 'filter', 'search', 'page', 'total', 'flash'));
    }

    public function approveProduct($id): void
    {
        $this->requireCsrf('/admin/products');
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('products', ['status' => 'active'], 'id=?', [$id]);
            $this->auth->flash('success', 'Product approved and published.');
        }
        redirect('/admin/products');
    }

    public function rejectProduct($id): void
    {
        $this->requireCsrf('/admin/products');
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('products', ['status' => 'flagged'], 'id=?', [$id]);
            $this->auth->flash('error', 'Product rejected.');
        }
        redirect('/admin/products');
    }

    // -------------------------------------------------------
    // Orders
    // -------------------------------------------------------

    public function orders(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['q'] ?? '');
        $page   = $this->page();

        $where  = '1=1';
        $params = [];
        if ($filter !== 'all') {
            $where .= ' AND o.status=?';
            $params[] = $filter;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (o.order_ref LIKE ? OR o.delivery_name LIKE ? OR o.delivery_phone LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM orders o WHERE $where", $params) ?? 0);

        $orders = $this->db->fetchAll(
            "SELECT o.id, o.order_ref AS code, o.status, o.payment_status,
                    o.payment_method, o.total_amount AS total, o.delivery_fee,
                    o.delivery_name AS full_name, o.delivery_city AS city, o.created_at,
                    u.email AS user_email
             FROM orders o LEFT JOIN users u ON o.user_id=u.id
             WHERE $where ORDER BY o.created_at DESC
             LIMIT {$this->perPage} OFFSET {$this->offset($page)}",
            $params);

        $flash = $this->auth->takeFlash();
        $this->render('admin-panel/orders',
            compact('orders', 'filter', 'search', 'page', 'total', 'flash'));
    }

    // -------------------------------------------------------
    // Payments
    // -------------------------------------------------------

    public function payments(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        $page   = $this->page();

        $where  = '1=1';
        $params = [];
        if ($filter === 'paid') {
            $where .= " AND payment_status='paid'";
        } elseif ($filter === 'pending') {
            $where .= " AND payment_status='pending'";
        } elseif ($filter !== 'all') {
            $where .= " AND payment_method=?";
            $params[] = $filter;
        }

        $total = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM orders WHERE $where", $params) ?? 0);

        $orders = $this->db->fetchAll(
            "SELECT o.id, o.order_ref AS code, o.status, o.payment_status,
                    o.payment_method, o.total_amount AS total,
                    o.delivery_name AS full_name, o.created_at
             FROM orders o WHERE $where ORDER BY o.created_at DESC
             LIMIT {$this->perPage} OFFSET {$this->offset($page)}",
            $params);

        $summary = $this->db->fetch(
            "SELECT
               COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) AS paid_total,
               COALESCE(SUM(CASE WHEN payment_status='pending' THEN total_amount ELSE 0 END),0) AS pending_total,
               COUNT(CASE WHEN payment_status='paid' THEN 1 END) AS paid_count,
               COUNT(CASE WHEN payment_status='pending' THEN 1 END) AS pending_count
             FROM orders"
        );

        $this->render('admin-panel/payments',
            compact('orders', 'filter', 'page', 'total', 'summary'));
    }

    /**
     * Telebirr/CBE/bank-transfer checkout is intentionally manual by
     * design (see config/app.php's payments.telebirr/cbe comments): the
     * customer sees pay-by-reference instructions and "an admin will
     * confirm your payment" (checkout_success.php's own copy) -- this is
     * the missing other half of that promise, since nothing previously
     * existed anywhere in the app to actually record that confirmation.
     */
    public function markPaymentPaid(): void
    {
        $this->requireCsrf('/admin/payments');
        $orderId = (int)($_POST['order_id'] ?? 0);
        $filter  = $_POST['filter'] ?? 'all';
        $page    = (int)($_POST['page'] ?? 1);
        $back    = '/admin/payments?filter=' . urlencode($filter) . '&page=' . $page;

        $order = $this->db->fetch("SELECT id, payment_method, payment_status FROM orders WHERE id = ?", [$orderId]);
        if (!$order) {
            $this->auth->flash('error', 'Order not found.');
            redirect($back);
        }
        if ($order['payment_method'] === 'etel_pay') {
            $this->auth->flash('error', 'ኢE Pay orders are verified automatically and cannot be marked paid manually.');
            redirect($back);
        }
        $updated = $this->db->update(
            'orders',
            ['payment_status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')],
            'id = ? AND payment_status != ?',
            [$orderId, 'paid']
        );
        $this->auth->flash($updated > 0 ? 'success' : 'error',
            $updated > 0 ? 'Payment marked as confirmed.' : 'This order was already marked paid.');
        redirect($back);
    }

    // -------------------------------------------------------
    // Users
    // -------------------------------------------------------

    public function users(): void
    {
        $search = trim($_GET['q'] ?? '');
        $page   = $this->page();

        $where  = '1=1';
        $params = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE $where", $params) ?? 0);

        $users = $this->db->fetchAll(
            "SELECT id, full_name, email, phone, role, created_at
             FROM users WHERE $where ORDER BY id DESC
             LIMIT {$this->perPage} OFFSET {$this->offset($page)}",
            $params);

        $this->render('admin-panel/users',
            compact('users', 'search', 'page', 'total'));
    }

    // -------------------------------------------------------
    // Categories
    // -------------------------------------------------------

    public function categories(): void
    {
        $categories = $this->db->fetchAll(
            "SELECT c.*, COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN products p ON p.category_id=c.id
             GROUP BY c.id ORDER BY c.sort_order, c.name_en"
        );
        $flash = $this->auth->takeFlash();
        $this->render('admin-panel/categories', compact('categories', 'flash'));
    }

    public function saveCategory(): void
    {
        $this->requireCsrf('/admin/categories');
        $id       = (int)($_POST['id'] ?? 0);
        $name_en  = trim($_POST['name_en'] ?? '');
        $name_am  = trim($_POST['name_am'] ?? '');
        $slug     = trim($_POST['slug'] ?? '');
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if (!$name_en) {
            $this->auth->flash('error', 'Category name is required.');
            redirect('/admin/categories');
        }

        if (!$slug) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name_en));
        }

        $data = [
            'name_en'    => $name_en,
            'name_am'    => $name_am,
            'slug'       => $slug,
            'sort_order' => $sort,
        ];

        if ($id > 0) {
            $this->db->update('categories', $data, 'id=?', [$id]);
            $this->auth->flash('success', 'Category updated.');
        } else {
            $this->db->insert('categories', $data);
            $this->auth->flash('success', 'Category created.');
        }
        redirect('/admin/categories');
    }

    // -------------------------------------------------------
    // Supplier Catalog (platform-run dropship catalog, phase 2)
    // -------------------------------------------------------

    public function catalog(): void
    {
        \App\Models\Supplier::ensureSchema($this->db);
        $page   = $this->page();
        $items  = \App\Models\CatalogItem::forAdmin($this->db, $this->perPage, $this->offset($page));
        $total  = \App\Models\CatalogItem::countAll($this->db);
        $pages  = max(1, (int)ceil($total / $this->perPage));
        $suppliers  = \App\Models\Supplier::platformSuppliers($this->db);
        $categories = $this->db->fetchAll("SELECT id, name_en FROM categories ORDER BY name_en");
        $flash  = $this->auth->takeFlash();

        $this->render('admin-panel/catalog', compact('items', 'total', 'page', 'pages', 'suppliers', 'categories', 'flash'));
    }

    public function saveCatalogItem(): void
    {
        $this->requireCsrf('/admin/catalog');
        \App\Models\Supplier::ensureSchema($this->db);

        $id          = (int)($_POST['id'] ?? 0);
        $supplierId  = (int)($_POST['supplier_id'] ?? 0);
        $nameEn      = trim($_POST['name_en'] ?? '');
        $nameAm      = trim($_POST['name_am'] ?? '');
        $descEn      = trim($_POST['description_en'] ?? '');
        $sku         = trim($_POST['sku'] ?? '');
        $costPrice   = (float)($_POST['cost_price'] ?? 0);
        $markupPct   = (float)($_POST['suggested_markup_pct'] ?? 30);
        $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $imageUrl    = trim($_POST['image_url'] ?? '');
        $galleryUrls = trim($_POST['gallery_urls'] ?? '');
        $leadMin     = (int)($_POST['dropship_lead_days_min'] ?? 0) ?: null;
        $leadMax     = (int)($_POST['dropship_lead_days_max'] ?? 0) ?: null;
        $status      = in_array($_POST['status'] ?? '', ['active', 'archived'], true) ? $_POST['status'] : 'active';

        if ($nameEn === '' || $costPrice <= 0 || $supplierId <= 0) {
            $this->auth->flash('error', 'Name, a positive cost price, and a supplier are required.');
            redirect('/admin/catalog');
        }
        if (!\App\Models\Supplier::findPlatform($this->db, $supplierId)) {
            $this->auth->flash('error', 'Choose a valid platform supplier (or add one below first).');
            redirect('/admin/catalog');
        }

        $data = [
            'supplier_id'            => $supplierId,
            'category_id'            => $categoryId,
            'name_en'                => $nameEn,
            'name_am'                => $nameAm ?: null,
            'description_en'         => $descEn ?: null,
            'sku'                    => $sku ?: null,
            'cost_price'             => $costPrice,
            'suggested_markup_pct'   => $markupPct,
            'image_url'              => $imageUrl ?: null,
            'gallery_urls'           => $galleryUrls ?: null,
            'dropship_lead_days_min' => $leadMin,
            'dropship_lead_days_max' => $leadMax,
            'status'                 => $status,
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            $this->db->update('catalog_items', $data, 'id = ?', [$id]);
            $this->auth->flash('success', 'Catalog item updated.');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('catalog_items', $data);
            $this->auth->flash('success', 'Catalog item added.');
        }
        redirect('/admin/catalog');
    }

    public function deleteCatalogItem($id): void
    {
        $this->requireCsrf('/admin/catalog');
        \App\Models\Supplier::ensureSchema($this->db);
        $this->db->delete('catalog_items', 'id = ?', [(int)$id]);
        // Vendor products already imported from this item stay untouched --
        // they're independent rows by design (see CatalogItem::importForSeller).
        $this->auth->flash('success', 'Catalog item removed.');
        redirect('/admin/catalog');
    }

    /** Quick-add a platform supplier (seller_id NULL) from the catalog page. */
    public function saveCatalogSupplier(): void
    {
        $this->requireCsrf('/admin/catalog');
        \App\Models\Supplier::ensureSchema($this->db);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->auth->flash('error', 'Supplier name is required.');
            redirect('/admin/catalog');
        }
        $this->db->insert('suppliers', [
            'seller_id'     => null,
            'name'          => $name,
            'contact_email' => trim($_POST['contact_email'] ?? '') ?: null,
            'contact_phone' => trim($_POST['contact_phone'] ?? '') ?: null,
            'website'       => trim($_POST['website'] ?? '') ?: null,
            'notes'         => trim($_POST['notes'] ?? '') ?: null,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->auth->flash('success', 'Platform supplier added.');
        redirect('/admin/catalog');
    }

    public function importCatalogCsv(): void
    {
        $this->requireCsrf('/admin/catalog');
        \App\Models\Supplier::ensureSchema($this->db);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($supplierId <= 0 || !\App\Models\Supplier::findPlatform($this->db, $supplierId)) {
            $this->auth->flash('error', 'Choose a valid platform supplier for this import.');
            redirect('/admin/catalog');
        }
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $this->auth->flash('error', 'Choose a CSV file to upload.');
            redirect('/admin/catalog');
        }

        $result = \App\Models\CatalogItem::importCsv($this->db, $supplierId, $_FILES['csv']['tmp_name']);
        $msg = $result['inserted'] . ' item(s) imported.';
        if ($result['errors']) {
            $msg .= ' ' . count($result['errors']) . ' row(s) skipped: ' . implode(' ', array_slice($result['errors'], 0, 5));
        }
        $this->auth->flash($result['inserted'] > 0 ? 'success' : 'error', $msg);
        redirect('/admin/catalog');
    }

    // -------------------------------------------------------
    // Settings
    // -------------------------------------------------------

    public function settings(): void
    {
        $cfg   = $GLOBALS['__config'] ?? [];
        $flash = $this->auth->takeFlash();
        $this->render('admin-panel/settings', compact('cfg', 'flash'));
    }

    public function saveSettings(): void
    {
        $this->requireCsrf('/admin/settings');
        // Placeholder -- settings are stored in config/app.php (file-based).
        // Extend this to write to a DB-backed settings table as needed.
        $this->auth->flash('success', 'Settings saved (restart may be required).');
        redirect('/admin/settings');
    }

    // -------------------------------------------------------
    // Code audit (one-click, server-side)
    // -------------------------------------------------------

    public function runAudit(): void
    {
        // Replaces the manual "zip the project, upload it to
        // EtelGebeya/audit, download the JSON, paste it into Claude" loop
        // with one button: this reads the project's own files off disk
        // and calls the same shared AI-audit backend the manual browser
        // tool uses. See audit_agent.php at the project root for the
        // actual scan/build/call logic -- kept outside app/ so it has no
        // dependency on this framework's autoloader and is a clean
        // copy-paste template for other Etel projects.
        header('Content-Type: application/json');
        if (!$this->auth->csrfCheck($_POST['_token'] ?? null)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Your session expired -- please refresh and try again.']);
            return;
        }
        require_once __DIR__ . '/../../audit_agent.php';
        $provider = $_POST['provider'] ?? 'gemini';
        $root = dirname(__DIR__, 2); // Controllers -> app -> project root
        $report = \etel_run_self_audit($root, 'EtelGebeya', $provider, $this->db->pdo());
        if (isset($report['error'])) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'message' => $report['error']]);
            return;
        }
        echo json_encode(['ok' => true, 'report' => $report]);
    }

    // Audit API settings -- which Gemini/OpenRouter key (and OpenRouter
    // model) "Run Audit Now" actually calls, direct to each provider's
    // real API. Previously lived in hand-edited config.php files on the
    // server (audit/config.php, public/config.php), which repeatedly led
    // to edits landing in the wrong one of two near-identical files or
    // not persisting at all. Now editable from this panel and stored in
    // the DB, same as delivery/api/controllers/AdminController.php; see
    // etel_audit_get_config() in audit_agent.php for the fallback-to-env
    // behavior if this table is empty/unreachable.
    public function auditSettingsGet(): void
    {
        header('Content-Type: application/json');
        require_once __DIR__ . '/../../audit_agent.php';
        $cfg = \etel_audit_get_config($this->db->pdo());
        echo json_encode(['ok' => true] + $cfg);
    }

    public function auditSettingsSave(): void
    {
        header('Content-Type: application/json');
        if (!$this->auth->csrfCheck($_POST['_token'] ?? null)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Your session expired -- please refresh and try again.']);
            return;
        }
        require_once __DIR__ . '/../../audit_agent.php';
        $geminiKey       = trim($_POST['gemini_key'] ?? '');
        $openrouterKey   = trim($_POST['openrouter_key'] ?? '');
        $openrouterModel = trim($_POST['openrouter_model'] ?? '');
        $provider        = trim($_POST['default_provider'] ?? 'gemini');
        if (!in_array($provider, ['gemini', 'openrouter'], true)) $provider = 'gemini';
        if ($provider === 'gemini' && !$geminiKey) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Gemini API key is required when Gemini is the default provider']);
            return;
        }
        if ($provider === 'openrouter' && !$openrouterKey) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'OpenRouter API key is required when OpenRouter is the default provider']);
            return;
        }
        if (!$openrouterModel) $openrouterModel = 'anthropic/claude-3.5-sonnet';

        $pdo = $this->db->pdo();
        \etel_audit_ensure_settings_table($pdo);
        try {
            $pdo->prepare("INSERT INTO audit_settings (id, gemini_key, openrouter_key, openrouter_model, default_provider) VALUES (1, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE gemini_key=VALUES(gemini_key), openrouter_key=VALUES(openrouter_key), openrouter_model=VALUES(openrouter_model), default_provider=VALUES(default_provider)")
                ->execute([$geminiKey, $openrouterKey, $openrouterModel, $provider]);
            echo json_encode(['ok' => true, 'message' => 'Audit settings saved']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Could not save audit settings']);
        }
    }
}
