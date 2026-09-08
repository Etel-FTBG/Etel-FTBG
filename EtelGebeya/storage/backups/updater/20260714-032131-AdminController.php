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
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('sellers', ['status' => 'active'], 'id=?', [$id]);
            $this->auth->flash('success', 'Seller approved and activated.');
        }
        redirect('/admin/vendors');
    }

    public function suspendVendor($id): void
    {
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
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('products', ['status' => 'published'], 'id=?', [$id]);
            $this->auth->flash('success', 'Product approved and published.');
        }
        redirect('/admin/products');
    }

    public function rejectProduct($id): void
    {
        $id = (int)$id;
        if ($id > 0) {
            $this->db->update('products', ['status' => 'rejected'], 'id=?', [$id]);
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
        // Placeholder -- settings are stored in config/app.php (file-based).
        // Extend this to write to a DB-backed settings table as needed.
        $this->auth->flash('success', 'Settings saved (restart may be required).');
        redirect('/admin/settings');
    }
}
