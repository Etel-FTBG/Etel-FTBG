<?php
// ============================================================
//  ETEL GEBEYA -- Admin API  v1.0
//  https://ethelmarket.com/EtelGebeya/api/admin.php
// ============================================================
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/etelpay_bridge.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

// Returns array of column names for a table (empty if table doesn't exist)
function cols(string $tbl): array {
    $db = db();
    if (!$db) return [];
    try { return $db->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN); }
    catch(PDOException $e) { return []; }
}
function hasCol(string $tbl, string $col): bool { return in_array($col, cols($tbl)); }

switch ($action) {

// ?? AUTH ????????????????????????????????????????????????????
case 'login':
    $pass = $_POST['password'] ?? '';
    if (!empty(ADMIN_SECRET) && hash_equals(ADMIN_SECRET, $pass)) ok(['token' => ADMIN_SECRET], 'Login OK');
    err('Wrong password', 401);

// EtelID SSO bridge: verify a short-lived HMAC nonce issued by the PHP template
// Nonce format (base64): "<timestamp>:<uid>:<sha256_hmac>"
// Valid for 90 seconds. No session sharing needed.
case 'nonce_login': {
    $raw = base64_decode($_POST['nonce'] ?? '', true);
    if (!$raw) err('Missing or invalid nonce', 400);
    $parts = explode(':', $raw, 3);
    if (count($parts) !== 3) err('Malformed nonce', 400);
    [$ts, $uid, $sig] = $parts;
    if (abs(time() - (int)$ts) > 90) err('Nonce expired -- refresh the page and try again', 401);
    $expected = hash_hmac('sha256', $ts . ':' . $uid, ADMIN_SECRET);
    if (!hash_equals($expected, $sig)) err('Invalid nonce signature', 401);

    // Single-use enforcement: reject if this exact signature was already redeemed
    $spentDir = dirname(__DIR__) . '/storage/spent_nonces';
    if (!is_dir($spentDir)) mkdir($spentDir, 0755, true);
    $spentFile = $spentDir . '/' . hash('sha256', $sig) . '.used';
    if (is_file($spentFile)) err('Nonce already used', 401);
    touch($spentFile);

    // Opportunistic cleanup of expired markers (older than the nonce window)
    foreach (glob($spentDir . '/*.used') ?: [] as $f) {
        if (time() - filemtime($f) > 90) @unlink($f);
    }

    ok(['token' => ADMIN_SECRET, 'uid' => (int)$uid, 'is_admin' => true], 'Admin access granted');
}

// Grant admin role to a user by phone (one-time setup, requires admin password)
case 'grant_admin': {
    auth();
    $phone = trim($_POST['phone'] ?? '');
    if (!$phone) err('phone required');
    $db = db();
    if (!$db) err('DB unavailable', 503);
    $stmt = $db->prepare('SELECT id FROM ' . t('users') . ' WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    if (!$user) err('No EtelGebeya account found for phone: ' . $phone, 404);
    $uid = (int)$user['id'];
    // Ensure all three roles exist
    foreach (['customer', 'vendor', 'admin'] as $role) {
        $exists = $db->prepare('SELECT 1 FROM ' . t('user_roles') . ' WHERE user_id=? AND role=? LIMIT 1');
        $exists->execute([$uid, $role]);
        if (!$exists->fetchColumn()) {
            $db->prepare('INSERT INTO ' . t('user_roles') . ' (user_id, role) VALUES (?,?)')->execute([$uid, $role]);
        }
    }
    ok(['uid' => $uid, 'phone' => $phone], 'Admin role granted. Next EtelID login will detect it.');
}

// ?? DASHBOARD KPIs ?????????????????????????????????????????
case 'stats':
    auth();
    $db = db();
    if (!$db) { error_log('[EG-Admin] DB error: ' . ($GLOBALS['__db_error'] ?? 'unknown')); err('Database connection failed. Please contact the administrator.', 503); }
    // Helper: safe query that returns 0 if table missing
    $safe = function(string $sql, mixed $default=0) use ($db) {
        try { $r=$db->query($sql); return $r ? $r->fetchColumn() : $default; }
        catch(PDOException $e) { return $default; }
    };
    $safeRow = function(string $sql) use ($db): array {
        try { $r=$db->query($sql); return $r ? ($r->fetch()?:[]) : []; }
        catch(PDOException $e) { return []; }
    };
    $safeAll = function(string $sql) use ($db): array {
        try { $r=$db->query($sql); return $r ? $r->fetchAll() : []; }
        catch(PDOException $e) { return []; }
    };

    // Check if schema is installed
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasOrders   = in_array(t('orders'),   $tables);
    $hasProducts = in_array(t('products'), $tables);
    if (!$hasOrders && !$hasProducts) {
        err('Database tables not found. Please run database/schema.sql in phpMyAdmin to create the tables. Found tables: '.implode(', ', array_slice($tables,0,10)), 503);
    }

    // Real orders.status ENUM starts at 'new' (no 'pending' value), and
    // 'delivered'/'cancelled' are valid as-is.
    $r = $safeRow("SELECT COUNT(*) c, COALESCE(SUM(status='new'),0) pending,
        COALESCE(SUM(status='delivered'),0) delivered, COALESCE(SUM(status='cancelled'),0) cancelled
        FROM `".t('orders')."`");
    $stats['orders']    = (int)($r['c']??0);
    $stats['pending']   = (int)($r['pending']??0);
    $stats['delivered'] = (int)($r['delivered']??0);
    $stats['cancelled'] = (int)($r['cancelled']??0);
    // Real transactions.status ENUM is pending/completed/failed/reversed
    // (no 'paid' value) -- 'completed' is what a settled payment is.
    $stats['revenue']       = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM `".t('transactions')."` WHERE status='completed'");
    $stats['revenue_today'] = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM `".t('transactions')."` WHERE status='completed' AND DATE(created_at)=CURDATE()");

    // Real products.status ENUM is active/pending/flagged/removed/draft
    // (no 'published' value).
    $r = $safeRow("SELECT COUNT(*) total, COALESCE(SUM(status='pending'),0) pending,
        COALESCE(SUM(status='active'),0) published FROM `".t('products')."`");
    $stats['products']         = (int)($r['total']??0);
    $stats['products_pending'] = (int)($r['pending']??0);
    $stats['products_live']    = (int)($r['published']??0);

    // Real sellers.status ENUM is pending/active/under_review/suspended/banned
    // (no 'approved' value).
    $r = $safeRow("SELECT COUNT(*) total, COALESCE(SUM(status='pending'),0) pending,
        COALESCE(SUM(status='active'),0) approved FROM `".t('sellers')."`");
    $stats['sellers']         = (int)($r['total']??0);
    $stats['sellers_pending'] = (int)($r['pending']??0);
    $stats['sellers_active']  = (int)($r['approved']??0);

    $stats['users']           = (int)$safe("SELECT COUNT(*) FROM `".t('users')."`");
    $stats['couriers']        = (int)$safe("SELECT COUNT(*) FROM `".t('couriers')."`");
    $stats['reviews_pending'] = (int)$safe("SELECT COUNT(*) FROM `".t('reviews')."` WHERE status='pending'");
    $stats['payouts_pending'] = (int)$safe("SELECT COUNT(*) FROM `".t('seller_payouts')."` WHERE status='pending'");
    $stats['recent_orders']   = $safeAll("SELECT o.*, o.order_ref code, o.total_amount total, u.full_name customer
        FROM `".t('orders')."` o
        LEFT JOIN `".t('users')."` u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 8");

    ok($stats);

// ?? REVENUE CHART (30 days) ?????????????????????????????????
case 'chart_revenue':
    auth();
    try {
        $rows = db()->query("SELECT DATE(created_at) d, SUM(amount) rev, COUNT(*) cnt
            FROM `".t('transactions')."` WHERE status='completed' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
            GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll();
    } catch(PDOException $e) { $rows = []; }
    ok($rows);

// ?? ORDER STATUS CHART ?????????????????????????????????????
case 'chart_orders':
    auth();
    try { $rows = db()->query("SELECT status, COUNT(*) cnt FROM `".t('orders')."` GROUP BY status")->fetchAll(); }
    catch(PDOException $e) { $rows = []; }
    ok($rows);

// ?? TOP CATEGORIES CHART ????????????????????????????????????
case 'chart_categories':
    auth();
    try {
        $rows = db()->query("SELECT c.name_en cat, COUNT(p.id) cnt
            FROM `".t('categories')."` c LEFT JOIN `".t('products')."` p ON p.category_id=c.id
            GROUP BY c.id ORDER BY cnt DESC LIMIT 8")->fetchAll();
    } catch(PDOException $e) { $rows = []; }
    ok($rows);

// ?? PRODUCTS ????????????????????????????????????????????????
case 'products':
    auth();
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $status = $_GET['status'] ?? '';
    // Same 'published'/'rejected'/'archived' -> real enum mapping as
    // product_action -- the status filter dropdown sends the same
    // human-readable values, which never matched any real row before.
    $statusFilterMap = ['published' => 'active', 'rejected' => 'flagged', 'draft' => 'draft', 'archived' => 'removed', 'pending' => 'pending'];
    if ($status && isset($statusFilterMap[$status])) $status = $statusFilterMap[$status];
    $where  = "WHERE (p.name_en LIKE ? OR p.sku LIKE ?)";
    $params = [$search, $search];
    if ($status) { $where .= " AND p.status=?"; $params[] = $status; }

    $total = db()->prepare("SELECT COUNT(*) FROM ".t('products')." p $where");
    $total->execute($params);

    $sql = "SELECT p.*, c.name_en cat_name, s.store_name vendor
            FROM ".t('products')." p
            LEFT JOIN ".t('categories')." c ON c.id=p.category_id
            LEFT JOIN ".t('sellers')." s ON s.id=p.seller_id
            $where ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = db()->prepare($sql); $stmt->execute($params);
    ok(['total'=>(int)$total->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);

case 'product_action':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $reason = $_POST['reason'] ?? '';
    if (!$id) err('Missing id');
    // Frontend sends human-readable intents; products.status is actually
    // enum('active','pending','flagged','removed','draft') -- 'published',
    // 'rejected' and 'archived' were never valid values, so every approve/
    // reject click threw a DB error. Map to the real enum here instead of
    // touching every call site.
    $statusMap = ['published' => 'active', 'rejected' => 'flagged', 'draft' => 'draft', 'archived' => 'removed'];
    if (!isset($statusMap[$status])) err('Invalid status');
    $dbStatus = $statusMap[$status];

    $db = db();
    if (!$db) err('Database unavailable', 503);
    if (!hasCol(t('products'), 'rejection_reason')) {
        try { $db->exec("ALTER TABLE " . t('products') . " ADD COLUMN rejection_reason VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}
    }
    $db->prepare("UPDATE ".t('products')." SET status=?, rejection_reason=?, updated_at=NOW() WHERE id=?")
        ->execute([$dbStatus, $reason ?: null, $id]);
    ok(['id'=>$id,'status'=>$dbStatus]);

case 'delete_product':
    auth();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) err('Missing id');
    db()->prepare("DELETE FROM ".t('products')." WHERE id=?")->execute([$id]);
    ok(['deleted'=>$id]);

case 'product_featured':
    auth();
    $id  = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['featured'] ?? 0);
    db()->prepare("UPDATE ".t('products')." SET is_featured=? WHERE id=?")->execute([$val,$id]);
    ok(['id'=>$id,'featured'=>$val]);

// ?? VENDORS / SELLERS ???????????????????????????????????????
case 'vendors':
    auth();
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $status = $_GET['status'] ?? '';
    // Same 'approved'/'rejected' -> real enum mapping as vendor_action.
    $vendorStatusMap = ['approved' => 'active', 'rejected' => 'banned', 'suspended' => 'suspended', 'pending' => 'pending'];
    if ($status && isset($vendorStatusMap[$status])) $status = $vendorStatusMap[$status];
    $where  = "WHERE (s.store_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params = [$search, $search, $search];
    if ($status) { $where .= " AND s.status=?"; $params[] = $status; }

    $total = db()->prepare("SELECT COUNT(*) FROM ".t('sellers')." s LEFT JOIN ".t('users')." u ON u.id=s.user_id $where");
    $total->execute($params);

    $sql = "SELECT s.*, u.full_name, u.email, u.phone, u.created_at joined
            FROM ".t('sellers')." s LEFT JOIN ".t('users')." u ON u.id=s.user_id
            $where ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = db()->prepare($sql); $stmt->execute($params);
    ok(['total'=>(int)$total->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);

case 'vendor_action':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !$status) err('Missing data');
    // Frontend sends 'approved'/'rejected'; sellers.status is actually
    // enum('pending','active','under_review','suspended','banned') --
    // 'approved' isn't a real value (approving always failed at the DB),
    // and the old query also wrote to 'approved_at', a column that
    // doesn't exist at all (the real column is 'verified_at').
    $statusMap = ['approved' => 'active', 'suspended' => 'suspended', 'rejected' => 'banned', 'pending' => 'pending'];
    if (!isset($statusMap[$status])) err('Invalid status');
    $dbStatus = $statusMap[$status];
    $verifiedAt = $dbStatus === 'active' ? date('Y-m-d H:i:s') : null;
    db()->prepare("UPDATE ".t('sellers')." SET status=?, verified_at=?, updated_at=NOW() WHERE id=?")
        ->execute([$dbStatus, $verifiedAt, $id]);
    ok(['id'=>$id,'status'=>$dbStatus]);

case 'vendor_commission':
    auth();
    $id  = (int)($_POST['id'] ?? 0);
    $pct = (float)($_POST['pct'] ?? 10);
    db()->prepare("UPDATE ".t('sellers')." SET commission_pct=? WHERE id=?")->execute([$pct,$id]);
    ok(['id'=>$id,'commission_pct'=>$pct]);

// ?? ORDERS ??????????????????????????????????????????????????
case 'orders':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        $oTbl   = t('orders');
        $uTbl   = t('users');
        // Detect actual FK column name (customer_id or user_id)
        $fkCol  = hasCol($oTbl, 'customer_id') ? 'customer_id' : 'user_id';
        // Real column is order_ref (not 'code') -- confirmed against schema.
        $searchExpr = "(o.order_ref LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)";
        $search  = '%' . ($_GET['search'] ?? '') . '%';
        $status  = $_GET['status'] ?? '';
        $params  = [$search, $search, $search];
        $where   = "WHERE $searchExpr";
        if ($status) { $where .= " AND o.status=?"; $params[] = $status; }

        $joinOn = "u.id=o.$fkCol";
        $tc = $db->prepare("SELECT COUNT(*) FROM `$oTbl` o LEFT JOIN `$uTbl` u ON $joinOn $where");
        $tc->execute($params);
        $totalCount = (int)$tc->fetchColumn();

        $hasPlacedAt = hasCol($oTbl, 'placed_at');
        $dateCol = $hasPlacedAt ? 'o.placed_at' : 'o.created_at';
        $sql = "SELECT o.*, o.order_ref code, o.total_amount total, u.full_name customer, u.phone customer_phone
                FROM `$oTbl` o LEFT JOIN `$uTbl` u ON $joinOn
                $where ORDER BY $dateCol DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Item count
        $hasItemsTbl = (bool)$db->query("SHOW TABLES LIKE '$oTbl'")->fetchColumn()
                    && (bool)$db->query("SHOW TABLES LIKE '".t('order_items')."'")->fetchColumn();
        foreach ($rows as &$row) {
            $row['item_count'] = 0;
            if ($hasItemsTbl) {
                $ic = $db->prepare("SELECT COUNT(*) FROM `".t('order_items')."` WHERE order_id=?");
                $ic->execute([(int)$row['id']]);
                $row['item_count'] = (int)$ic->fetchColumn();
            }
        }
        ok(['total'=>$totalCount,'page'=>$page,'rows'=>$rows]);
    } catch(PDOException $e) { err('Orders query failed: '.$e->getMessage(), 500); }

case 'order_items':
    auth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('Missing id');
    $items = db()->prepare("SELECT oi.*, oi.product_name name, oi.quantity qty, oi.unit_price price,
            oi.total_price line_total, s.store_name vendor
        FROM ".t('order_items')." oi
        LEFT JOIN ".t('sellers')." s ON s.id=oi.seller_id WHERE oi.order_id=?");
    $items->execute([$id]);
    $order = db()->prepare("SELECT o.*, o.order_ref code, o.total_amount total, u.full_name, u.email, u.phone
        FROM ".t('orders')." o
        LEFT JOIN ".t('users')." u ON u.id=o.user_id WHERE o.id=?");
    $order->execute([$id]);
    ok(['order'=>$order->fetch(),'items'=>$items->fetchAll()]);

case 'order_status':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowedOrderStatus = ['new','confirmed','processing','packed','shipped','out_for_delivery','delivered','cancelled','returned','disputed'];
    if (!$id || !in_array($status, $allowedOrderStatus, true)) err('Missing or invalid status');
    db()->prepare("UPDATE ".t('orders')." SET status=?, updated_at=NOW() WHERE id=?")->execute([$status,$id]);
    // Escrow release reads order_items.status/delivered_at, not this
    // whole-order status -- setting the order to "delivered" here without
    // also cascading down would leave the customer's "Confirm Received"
    // button (and the seller's payout) stuck forever. Mirror it onto
    // every still-active item so an admin override actually unlocks escrow.
    if ($status === 'delivered') {
        if (!hasCol(t('order_items'), 'delivered_at')) {
            try { db()->exec("ALTER TABLE ".t('order_items')." ADD COLUMN delivered_at DATETIME DEFAULT NULL"); } catch (PDOException $e) {}
        }
        db()->prepare(
            "UPDATE ".t('order_items')." SET status='delivered', delivered_at=NOW()
             WHERE order_id=? AND status NOT IN ('delivered','cancelled','returned')"
        )->execute([$id]);
    }
    ok(['id'=>$id,'status'=>$status]);

// ?? USERS ???????????????????????????????????????????????????
case 'users':
    auth();
    $db = db();
    if (!$db) err('Database unavailable: '.($GLOBALS['__db_error']??'check config.php'), 503);
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $where  = "WHERE (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params = [$search, $search, $search];
    try {
        $total = $db->prepare("SELECT COUNT(*) FROM `".t('users')."` $where");
        $total->execute($params);
        $sql = "SELECT id, full_name, email, phone, status, locale, created_at
                FROM `".t('users')."` $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$total->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Users query failed: '.$e->getMessage(), 503); }

case 'user_status':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['active','banned','pending'])) err('Invalid');
    try { db()->prepare("UPDATE `".t('users')."` SET status=? WHERE id=?")->execute([$status,$id]); }
    catch(PDOException $e) { err('Update failed: '.$e->getMessage(), 503); }
    ok(['id'=>$id,'status'=>$status]);

// ?? CATEGORIES ??????????????????????????????????????????????
case 'categories':
    auth();
    $rows = db()->query("SELECT c.*, p.name_en parent_name,
        (SELECT COUNT(*) FROM ".t('products')." WHERE category_id=c.id) product_count
        FROM ".t('categories')." c LEFT JOIN ".t('categories')." p ON p.id=c.parent_id
        ORDER BY c.sort_order, c.id")->fetchAll();
    ok($rows);

case 'save_category':
    auth();
    $id      = (int)($_POST['id'] ?? 0);
    $name_en = trim($_POST['name_en'] ?? '');
    $name_am = trim($_POST['name_am'] ?? '');
    $slug    = trim($_POST['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/','-',$name_en)));
    $icon    = trim($_POST['icon'] ?? '');
    $parent  = $_POST['parent_id'] ? (int)$_POST['parent_id'] : null;
    $sort    = (int)($_POST['sort_order'] ?? 0);
    $active  = (int)($_POST['is_active'] ?? 1);
    if (!$name_en) err('Name required');
    if ($id) {
        db()->prepare("UPDATE ".t('categories')." SET name_en=?,name_am=?,slug=?,icon=?,parent_id=?,sort_order=?,is_active=? WHERE id=?")
            ->execute([$name_en,$name_am,$slug,$icon,$parent,$sort,$active,$id]);
        ok(['id'=>$id,'action'=>'updated']);
    } else {
        db()->prepare("INSERT INTO ".t('categories')."(name_en,name_am,slug,icon,parent_id,sort_order,is_active) VALUES(?,?,?,?,?,?,?)")
            ->execute([$name_en,$name_am,$slug,$icon,$parent,$sort,$active]);
        ok(['id'=>db()->lastInsertId(),'action'=>'created']);
    }

case 'delete_category':
    auth();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) err('Missing id');
    db()->prepare("DELETE FROM ".t('categories')." WHERE id=?")->execute([$id]);
    ok(['deleted'=>$id]);

// ?? TRANSACTIONS ????????????????????????????????????????????
case 'transactions':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        $txTbl = $db->query("SHOW TABLES LIKE '".t('transactions')."'")->fetchColumn() ? t('transactions')
               : ($db->query("SHOW TABLES LIKE '".t('payments')."'")->fetchColumn()    ? t('payments') : null);
        if (!$txTbl) ok(['total'=>0,'page'=>$page,'rows'=>[],'note'=>'No transactions/payments table found']);
        $oTbl    = t('orders');
        $uTbl    = t('users');
        $fkCol   = hasCol($oTbl, 'customer_id') ? 'customer_id' : 'user_id';
        $codeExpr = 'o.order_ref order_code';
        // Detect if transactions table has order_id column
        $hasTxOrderId = hasCol($txTbl, 'order_id');
        $status  = $_GET['status'] ?? '';
        $where   = $status ? "WHERE t.status=?" : "";
        $params  = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM `$txTbl` t $where");
        $tc->execute($params);
        $txDateCol = hasCol($txTbl, 'created_at') ? 't.created_at'
                   : (hasCol($txTbl, 'paid_at')    ? 't.paid_at'
                   : (hasCol($txTbl, 'date')        ? 't.date' : 't.id'));
        if ($hasTxOrderId) {
            $sql = "SELECT t.*, $codeExpr, u.full_name customer
                    FROM `$txTbl` t
                    LEFT JOIN `$oTbl` o ON o.id=t.order_id
                    LEFT JOIN `$uTbl` u ON u.id=o.$fkCol
                    $where ORDER BY $txDateCol DESC LIMIT $limit OFFSET $offset";
        } else {
            $sql = "SELECT t.* FROM `$txTbl` t $where ORDER BY $txDateCol DESC LIMIT $limit OFFSET $offset";
        }
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Transactions query failed: '.$e->getMessage(), 500); }

// ?? SELLER PAYOUTS ??????????????????????????????????????????
case 'payouts':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        // Detect payout table name
        $payTbl = $db->query("SHOW TABLES LIKE '".t('seller_payouts')."'")->fetchColumn() ? t('seller_payouts')
                : ($db->query("SHOW TABLES LIKE '".t('vendor_payouts')."'")->fetchColumn()  ? t('vendor_payouts') : null);
        if (!$payTbl) ok(['total'=>0,'page'=>$page,'rows'=>[],'note'=>'No payouts table found']);
        // Detect vendor table name
        $venTbl = $db->query("SHOW TABLES LIKE '".t('sellers')."'")->fetchColumn() ? t('sellers')
                : ($db->query("SHOW TABLES LIKE '".t('vendors')."'")->fetchColumn()  ? t('vendors') : null);
        $status = $_GET['status'] ?? '';
        $where  = $status ? "WHERE sp.status=?" : "";
        $params = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM $payTbl sp $where");
        $tc->execute($params);
        // Detect actual name column in vendor/seller table
        $venNameCol = 'store_name';
        if ($venTbl) {
            $vCols = cols($venTbl);
            $venNameCol = in_array('shop_name', $vCols) ? 'shop_name'
                        : (in_array('store_name', $vCols) ? 'store_name'
                        : (in_array('name', $vCols) ? 'name' : null));
        }
        // Detect FK column in payouts table
        $payFk  = hasCol($payTbl, 'vendor_id') ? 'vendor_id' : 'seller_id';
        $venJoin = $venTbl ? "LEFT JOIN `$venTbl` s ON s.id=sp.$payFk" : '';
        $venCol  = ($venTbl && $venNameCol) ? "s.$venNameCol store_name," : "'--' store_name,";
        $venUser = ($venTbl && $venNameCol) ? "LEFT JOIN `".t('users')."` u ON u.id=s.user_id" : "";
        // Detect date column in payouts table
        $payDateCol = hasCol($payTbl, 'requested_at') ? 'sp.requested_at'
                    : (hasCol($payTbl, 'created_at')  ? 'sp.created_at' : 'sp.id');
        $userCols   = ($venTbl && $venNameCol) ? "u.full_name, u.phone, u.email," : "'--' full_name,'--' phone,'--' email,";
        $sql = "SELECT sp.*, $venCol $userCols '' dummy
                FROM `$payTbl` sp $venJoin $venUser
                $where ORDER BY $payDateCol DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Payouts query failed: '.$e->getMessage(), 500); }

case 'payout_action': {
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['approved', 'paid', 'rejected'])) err('Invalid');

    $db = db();
    if (!$db) err('Database unavailable', 503);

    // 'paid' isn't a real seller_payouts.status ENUM value (only pending/
    // approved/processing/completed/rejected) -- this is where the button
    // used to silently fail. It's also the exact moment we should
    // actually pay the seller for real via Etel Pay, not just flip a flag.
    if ($status === 'paid') {
        $payout = $db->prepare(
            "SELECT sp.*, s.phone AS seller_phone FROM " . t('seller_payouts') . " sp
             JOIN " . t('sellers') . " s ON s.id = sp.seller_id
             WHERE sp.id = ? LIMIT 1"
        );
        $payout->execute([$id]);
        $row = $payout->fetch();
        if (!$row) err('Payout not found', 404);
        if ($row['status'] === 'completed') err('Already paid', 409);
        if (!$row['seller_phone']) err('This seller has no phone on file to pay out to', 422);

        $result = etelpay_bridge_partner_credit(
            $row['seller_phone'],
            (float)$row['amount'],
            'eg_payout_' . $id,
            'EtelGebeya payout #' . $id
        );
        if (!$result['ok']) err('Etel Pay payout failed: ' . $result['message'], 502);

        $db->prepare("UPDATE " . t('seller_payouts') . " SET status='completed', txn_ref=?, processed_at=NOW() WHERE id=?")
            ->execute([$result['tx_ref'], $id]);
        ok(['id' => $id, 'status' => 'completed', 'tx_ref' => $result['tx_ref']]);
    }

    $db->prepare("UPDATE " . t('seller_payouts') . " SET status=?, processed_at=IF(?='approved',NOW(),NULL) WHERE id=?")
        ->execute([$status, $status, $id]);
    ok(['id' => $id, 'status' => $status]);
}

// ?? COUPONS ?????????????????????????????????????????????????
case 'coupons':
    auth();
    $rows = db()->query("SELECT c.*,
        (SELECT COUNT(*) FROM ".t('orders')." WHERE coupon_code=c.code) used_real
        FROM ".t('coupons')." c ORDER BY c.created_at DESC")->fetchAll();
    ok($rows);

case 'save_coupon':
    auth();
    $id      = (int)($_POST['id'] ?? 0);
    $code    = strtoupper(trim($_POST['code'] ?? ''));
    $type    = $_POST['type'] ?? 'percent';
    $value   = (float)($_POST['value'] ?? 0);
    $min_ord = (float)($_POST['min_order'] ?? 0);
    $max_use = (int)($_POST['max_uses'] ?? 100);
    $expires = $_POST['expires_at'] ?? null;
    $active  = (int)($_POST['is_active'] ?? 1);
    if (!$code || !$value) err('Code and value required');
    if ($id) {
        db()->prepare("UPDATE ".t('coupons')." SET code=?,type=?,value=?,min_order=?,max_uses=?,expires_at=?,is_active=? WHERE id=?")
            ->execute([$code,$type,$value,$min_ord,$max_use,$expires,$active,$id]);
        ok(['id'=>$id,'action'=>'updated']);
    } else {
        db()->prepare("INSERT INTO ".t('coupons')."(code,type,value,min_order,max_uses,expires_at,is_active) VALUES(?,?,?,?,?,?,?)")
            ->execute([$code,$type,$value,$min_ord,$max_use,$expires,$active]);
        ok(['id'=>db()->lastInsertId(),'action'=>'created']);
    }

case 'delete_coupon':
    auth();
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare("DELETE FROM ".t('coupons')." WHERE id=?")->execute([$id]);
    ok(['deleted'=>$id]);

// ?? REVIEWS ?????????????????????????????????????????????????
case 'reviews':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        $rTbl   = t('reviews');
        $rFkCol = hasCol($rTbl, 'customer_id') ? 'customer_id' : 'user_id';
        $status = $_GET['status'] ?? '';
        $where  = $status ? "WHERE r.status=?" : "";
        $params = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM `$rTbl` r $where");
        $tc->execute($params);
        $sql = "SELECT r.*, u.full_name reviewer, p.name_en product_name
                FROM `$rTbl` r
                LEFT JOIN `".t('users')."` u ON u.id=r.$rFkCol
                LEFT JOIN `".t('products')."` p ON p.id=r.product_id
                $where ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Reviews query failed: '.$e->getMessage(), 500); }

case 'review_action':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status,['approved','rejected','pending'])) err('Invalid');
    db()->prepare("UPDATE ".t('reviews')." SET status=? WHERE id=?")->execute([$status,$id]);
    ok(['id'=>$id,'status'=>$status]);

// ?? DISPUTES ????????????????????????????????????????????????
case 'disputes':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        $dTbl = t('disputes');
        if (!$db->query("SHOW TABLES LIKE '$dTbl'")->fetchColumn())
            ok(['total'=>0,'page'=>$page,'rows'=>[],'note'=>'Disputes table not yet created']);
        $oTbl     = t('orders');
        $uTbl     = t('users');
        $fkCol    = hasCol($oTbl, 'customer_id') ? 'customer_id' : 'user_id';
        $codeExpr = 'o.order_ref order_code';
        // Detect customer FK in disputes table
        $dUserCol = hasCol($dTbl, 'user_id') ? 'user_id' : (hasCol($dTbl, 'customer_id') ? 'customer_id' : null);
        $status   = $_GET['status'] ?? '';
        $where    = $status ? "WHERE d.status=?" : "";
        $params   = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM `$dTbl` d $where");
        $tc->execute($params);
        $userJoin   = $dUserCol ? "LEFT JOIN `$uTbl` u ON u.id=d.$dUserCol" : "";
        $userSel    = $dUserCol ? "u.full_name customer," : "'--' customer,";
        // Detect date column in disputes table
        $dCols      = cols($dTbl);
        $dDateCol   = in_array('opened_at',    $dCols) ? 'd.opened_at'
                    : (in_array('created_at',   $dCols) ? 'd.created_at'
                    : (in_array('submitted_at', $dCols) ? 'd.submitted_at'
                    : (in_array('date',         $dCols) ? 'd.date' : 'd.id')));
        // Detect order FK column in disputes
        $dOrderCol  = hasCol($dTbl, 'order_id') ? 'order_id' : null;
        $orderJoin  = $dOrderCol ? "LEFT JOIN `$oTbl` o ON o.id=d.$dOrderCol" : "";
        $orderSel   = $dOrderCol ? "$codeExpr," : "'' order_code,";
        $sql = "SELECT d.*, $orderSel $userSel '' dummy
                FROM `$dTbl` d
                $orderJoin
                $userJoin
                $where ORDER BY $dDateCol DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Disputes query failed: '.$e->getMessage(), 500); }

// Resolve a customer-filed dispute. Escrow stays held on any order with
// an open dispute (see EscrowService::hasOpenDispute) -- this is the only
// way those funds move once a dispute exists; the 5-day auto-release
// cron and the customer's own "Confirm Received" button both back off.
case 'dispute_resolve':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    $id            = (int)($_POST['id'] ?? 0);
    $resolution    = $_POST['resolution'] ?? '';
    $resolutionNote = trim($_POST['resolution_note'] ?? '');
    if (!$id || !in_array($resolution, ['refund_buyer','no_refund'], true)) {
        err('id and a valid resolution (refund_buyer or no_refund) are required');
    }
    try {
        $dispute = $db->prepare("SELECT * FROM ".t('disputes')." WHERE id=? LIMIT 1");
        $dispute->execute([$id]);
        $d = $dispute->fetch();
        if (!$d) err('Dispute not found', 404);
        if ($d['status'] === 'resolved' || $d['status'] === 'closed') err('Dispute already resolved');

        $order = $db->prepare("SELECT o.* FROM ".t('orders')." o WHERE o.id=?");
        $order->execute([(int)$d['order_id']]);
        $ord = $order->fetch();
        if (!$ord) err('Order not found for this dispute', 404);

        $holds = $db->prepare("SELECT * FROM ".t('order_seller_escrow')." WHERE order_id=? AND status='held'");
        $holds->execute([$ord['id']]);
        $holdRows = $holds->fetchAll();
        if (!$holdRows) err('No held escrow funds on this order to resolve', 422);

        $etelPayAction = ($resolution === 'refund_buyer') ? 'ep_escrow_reverse' : 'ep_escrow_release';
        $txRef = null;
        $anyOk = false;
        foreach ($holdRows as $hold) {
            $r = etelpay_bridge_escrow_resolve($etelPayAction, $hold['tx_ref']);
            if ($r['ok']) {
                $newStatus = ($resolution === 'refund_buyer') ? 'refunded' : 'released';
                $db->prepare("UPDATE ".t('order_seller_escrow')." SET status=?, released_at=NOW() WHERE id=?")->execute([$newStatus, $hold['id']]);
                $db->prepare("UPDATE ".t('order_items')." SET escrow_status=? WHERE order_id=? AND seller_id=?")->execute([$newStatus, $ord['id'], $hold['seller_id']]);
                $txRef = $hold['tx_ref'];
                $anyOk = true;
            }
        }
        if (!$anyOk) err('Etel Pay resolve failed for all held funds on this order', 500);

        if ($resolution === 'refund_buyer') {
            $db->prepare("UPDATE ".t('orders')." SET payment_status='refunded' WHERE id=?")->execute([$ord['id']]);
            $db->prepare("UPDATE ".t('transactions')." SET status='reversed' WHERE order_id=? AND type='payment'")->execute([$ord['id']]);
        } else {
            $db->prepare("UPDATE ".t('transactions')." SET status='completed' WHERE order_id=? AND type='payment'")->execute([$ord['id']]);
        }

        $db->prepare("UPDATE ".t('disputes')." SET status='resolved', resolution=?, resolution_note=?, resolved_at=NOW() WHERE id=?")
           ->execute([$resolution, $resolutionNote, $id]);
        ok(['id'=>$id, 'resolution'=>$resolution, 'tx_ref'=>$txRef]);
    } catch (PDOException $e) { err('Dispute resolution failed: '.$e->getMessage(), 500); }

// ?? NOTIFICATIONS ???????????????????????????????????????????
case 'notifications':
    auth();
    $rows = db()->query("SELECT * FROM ".t('notifications')." ORDER BY created_at DESC LIMIT 50")->fetchAll();
    ok($rows);

// ?? AUDIT LOG ??????????????????????????????????????????????
case 'audit_log':
    auth();
    $rows = db()->query("SELECT al.*, a.email admin_email FROM ".t('audit_log')." al
        LEFT JOIN ".t('admins')." a ON a.id=al.admin_id
        ORDER BY al.created_at DESC LIMIT 100")->fetchAll();
    ok($rows);

// ?? SETTINGS ????????????????????????????????????????????????
case 'settings':
    auth();
    $rows = db()->query("SELECT key_name, value FROM ".t('settings'))->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($rows);

case 'save_settings':
    auth();
    $data = $_POST;
    unset($data['action'], $data['auth']);
    $db = db();
    foreach ($data as $k => $v) {
        $db->prepare("INSERT INTO ".t('settings')."(key_name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=?,updated_at=NOW()")
            ->execute([$k,$v,$v]);
    }
    ok(['saved' => count($data)]);

// ?? PROMOTIONS ?????????????????????????????????????????????
case 'promotions':
    auth();
    $rows = db()->query("SELECT * FROM ".t('promotions')." ORDER BY created_at DESC")->fetchAll();
    ok($rows);

// ?? COURIERS ???????????????????????????????????????????????
case 'couriers':
    auth();
    $db = db();
    if (!$db) err('Database unavailable', 503);
    try {
        $cTbl = t('couriers');
        if (!$db->query("SHOW TABLES LIKE '$cTbl'")->fetchColumn())
            ok(['rows'=>[],'note'=>'Couriers table not yet created']);
        // Detect what columns the couriers table actually has
        $cCols      = cols($cTbl);
        $hasUserId  = in_array('user_id',  $cCols);
        $hasCreated = in_array('created_at', $cCols);
        $orderBy    = $hasCreated ? "c.created_at DESC" : "c.id DESC";
        if ($hasUserId) {
            $sql  = "SELECT c.*, u.full_name, u.phone, u.email
                     FROM `$cTbl` c LEFT JOIN `".t('users')."` u ON u.id=c.user_id
                     ORDER BY $orderBy LIMIT 100";
        } else {
            $sql  = "SELECT c.* FROM `$cTbl` c ORDER BY $orderBy LIMIT 100";
        }
        $rows = $db->query($sql)->fetchAll();
        ok(['rows'=>$rows]);
    } catch(PDOException $e) { err('Couriers query failed: '.$e->getMessage(), 500); }

// ?? QUICK COUNTS (badge refresh) ????????????????????????????
case 'badges':
    auth();
    $db = db();
    if (!$db) ok(['products_pending'=>0,'vendors_pending'=>0,'payouts_pending'=>0,'reviews_pending'=>0,'disputes_open'=>0,'db_error'=>$GLOBALS['__db_error']??'no db']);
    // Safe count -- returns 0 if table doesn't exist
    $safeQ = function(string $tbl, string $where) use ($db): int {
        try {
            $r = $db->query("SELECT COUNT(*) FROM `$tbl` WHERE $where");
            return $r ? (int)$r->fetchColumn() : 0;
        } catch(PDOException $e) { return 0; }
    };
    // Detect actual table names on this server
    $venTbl  = $db->query("SHOW TABLES LIKE '".t('sellers')."'")->fetchColumn()      ? t('sellers')       : t('vendors');
    $payTbl  = $db->query("SHOW TABLES LIKE '".t('seller_payouts')."'")->fetchColumn() ? t('seller_payouts') : t('vendor_payouts');
    ok([
        'products_pending' => $safeQ(t('products'), "status='pending'"),
        'vendors_pending'  => $safeQ($venTbl,        "status='pending'"),
        'payouts_pending'  => $safeQ($payTbl,         "status='pending'"),
        'reviews_pending'  => $safeQ(t('reviews'),   "status='pending'"),
        'disputes_open'    => $safeQ(t('disputes'),  "status='open'"),
    ]);

default:
    err("Unknown action: $action", 404);
}
