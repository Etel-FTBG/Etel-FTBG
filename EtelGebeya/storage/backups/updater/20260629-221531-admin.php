<?php
// ============================================================
//  ETEL GEBEYA -- Admin API  v1.0
//  https://ethelmarket.com/EtelGebeya/api/admin.php
// ============================================================
declare(strict_types=1);
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

switch ($action) {

// ?? AUTH ????????????????????????????????????????????????????
case 'login':
    $pass = $_POST['password'] ?? '';
    if ($pass === ADMIN_SECRET) ok(['token' => ADMIN_SECRET], 'Login OK');
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
    if (abs(time() - (int)$ts) > 90) err('Nonce expired — refresh the page and try again', 401);
    $expected = hash_hmac('sha256', $ts . ':' . $uid, ADMIN_SECRET);
    if (!hash_equals($expected, $sig)) err('Invalid nonce signature', 401);
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
    if (!$db) err('Database connection failed: '.($GLOBALS['__db_error']??'Check DB_PASS in api/config.php'), 503);
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

    $r = $safeRow("SELECT COUNT(*) c, COALESCE(SUM(status='pending'),0) pending,
        COALESCE(SUM(status='delivered'),0) delivered, COALESCE(SUM(status='cancelled'),0) cancelled
        FROM `".t('orders')."`");
    $stats['orders']    = (int)($r['c']??0);
    $stats['pending']   = (int)($r['pending']??0);
    $stats['delivered'] = (int)($r['delivered']??0);
    $stats['cancelled'] = (int)($r['cancelled']??0);
    $stats['revenue']       = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM `".t('transactions')."` WHERE status='paid'");
    $stats['revenue_today'] = (float)$safe("SELECT COALESCE(SUM(amount),0) FROM `".t('transactions')."` WHERE status='paid' AND DATE(created_at)=CURDATE()");

    $r = $safeRow("SELECT COUNT(*) total, COALESCE(SUM(status='pending'),0) pending,
        COALESCE(SUM(status='published'),0) published FROM `".t('products')."`");
    $stats['products']         = (int)($r['total']??0);
    $stats['products_pending'] = (int)($r['pending']??0);
    $stats['products_live']    = (int)($r['published']??0);

    $r = $safeRow("SELECT COUNT(*) total, COALESCE(SUM(status='pending'),0) pending,
        COALESCE(SUM(status='approved'),0) approved FROM `".t('sellers')."`");
    $stats['sellers']         = (int)($r['total']??0);
    $stats['sellers_pending'] = (int)($r['pending']??0);
    $stats['sellers_active']  = (int)($r['approved']??0);

    $stats['users']           = (int)$safe("SELECT COUNT(*) FROM `".t('users')."`");
    $stats['couriers']        = (int)$safe("SELECT COUNT(*) FROM `".t('couriers')."`");
    $stats['reviews_pending'] = (int)$safe("SELECT COUNT(*) FROM `".t('reviews')."` WHERE status='pending'");
    $stats['payouts_pending'] = (int)$safe("SELECT COUNT(*) FROM `".t('seller_payouts')."` WHERE status='pending'");
    $stats['recent_orders']   = $safeAll("SELECT o.*, u.full_name customer FROM `".t('orders')."` o
        LEFT JOIN `".t('users')."` u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 8");

    ok($stats);

// ?? REVENUE CHART (30 days) ?????????????????????????????????
case 'chart_revenue':
    auth();
    try {
        $rows = db()->query("SELECT DATE(created_at) d, SUM(amount) rev, COUNT(*) cnt
            FROM `".t('transactions')."` WHERE status='paid' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
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
    $allowed = ['published','rejected','draft','archived'];
    if (!in_array($status, $allowed)) err('Invalid status');
    db()->prepare("UPDATE ".t('products')." SET status=?, rejection_reason=?, updated_at=NOW() WHERE id=?")
        ->execute([$status, $reason, $id]);
    ok(['id'=>$id,'status'=>$status]);

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
    $allowed = ['approved','suspended','rejected','pending'];
    if (!in_array($status, $allowed)) err('Invalid status');
    $approved_at = $status === 'approved' ? 'NOW()' : 'NULL';
    db()->prepare("UPDATE ".t('sellers')." SET status=?, approved_at=IF(status='approved',NOW(),NULL), updated_at=NOW() WHERE id=?")
        ->execute([$status, $id]);
    ok(['id'=>$id,'status'=>$status]);

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
        $search = '%' . ($_GET['search'] ?? '') . '%';
        $status = $_GET['status'] ?? '';
        $where  = "WHERE (o.code LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)";
        $params = [$search, $search, $search];
        if ($status) { $where .= " AND o.status=?"; $params[] = $status; }

        $tc = $db->prepare("SELECT COUNT(*) FROM ".t('orders')." o LEFT JOIN ".t('users')." u ON u.id=o.user_id $where");
        $tc->execute($params);
        $totalCount = (int)$tc->fetchColumn();

        $sql = "SELECT o.*, u.full_name customer, u.phone customer_phone
                FROM ".t('orders')." o LEFT JOIN ".t('users')." u ON u.id=o.user_id
                $where ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Attach item count safely
        $hasItems = (bool)$db->query("SHOW TABLES LIKE '".t('order_items')."'")->fetchColumn();
        foreach ($rows as &$row) {
            if ($hasItems) {
                $ic = $db->prepare("SELECT COUNT(*) FROM ".t('order_items')." WHERE order_id=?");
                $ic->execute([(int)$row['id']]);
                $row['item_count'] = (int)$ic->fetchColumn();
            } else {
                $row['item_count'] = 0;
            }
        }
        ok(['total'=>$totalCount,'page'=>$page,'rows'=>$rows]);
    } catch(PDOException $e) { err('Orders query failed: '.$e->getMessage(), 500); }

case 'order_items':
    auth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('Missing id');
    $items = db()->prepare("SELECT oi.*, s.store_name vendor FROM ".t('order_items')." oi
        LEFT JOIN ".t('sellers')." s ON s.id=oi.seller_id WHERE oi.order_id=?");
    $items->execute([$id]);
    $order = db()->prepare("SELECT o.*, u.full_name, u.email, u.phone FROM ".t('orders')." o
        LEFT JOIN ".t('users')." u ON u.id=o.user_id WHERE o.id=?");
    $order->execute([$id]);
    ok(['order'=>$order->fetch(),'items'=>$items->fetchAll()]);

case 'order_status':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !$status) err('Missing data');
    db()->prepare("UPDATE ".t('orders')." SET status=?, updated_at=NOW() WHERE id=?")->execute([$status,$id]);
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
        $status = $_GET['status'] ?? '';
        // Support both possible table names: transactions or payments
        $txTbl = $db->query("SHOW TABLES LIKE '".t('transactions')."'")->fetchColumn()
               ? t('transactions')
               : (($db->query("SHOW TABLES LIKE '".t('payments')."'")->fetchColumn()) ? t('payments') : null);
        if (!$txTbl) ok(['total'=>0,'page'=>$page,'rows'=>[],'note'=>'No transactions table found']);
        $alias  = 't';
        $where  = $status ? "WHERE $alias.status=?" : "";
        $params = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM $txTbl $alias $where");
        $tc->execute($params);
        $sql = "SELECT $alias.*, o.code order_code, u.full_name customer
                FROM $txTbl $alias
                LEFT JOIN ".t('orders')." o ON o.id=$alias.order_id
                LEFT JOIN ".t('users')." u ON u.id=o.user_id
                $where ORDER BY $alias.created_at DESC LIMIT $limit OFFSET $offset";
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
        $venJoin = $venTbl ? "LEFT JOIN $venTbl s ON s.id=sp." . ($payTbl === t('vendor_payouts') ? 'vendor_id' : 'seller_id') : '';
        $venCol  = $venTbl ? "s.shop_name store_name," : "'—' store_name,";
        $venUser = $venTbl ? "LEFT JOIN ".t('users')." u ON u.id=s.user_id" : "";
        $sql = "SELECT sp.*, $venCol u.full_name, u.phone, u.email
                FROM $payTbl sp $venJoin $venUser
                $where ORDER BY sp.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Payouts query failed: '.$e->getMessage(), 500); }

case 'payout_action':
    auth();
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status,['approved','paid','rejected'])) err('Invalid');
    $processed = in_array($status,['approved','paid']) ? 'NOW()' : 'NULL';
    db()->prepare("UPDATE ".t('seller_payouts')." SET status=?,processed_at=IF(status IN('approved','paid'),NOW(),NULL) WHERE id=?")
        ->execute([$status,$id]);
    ok(['id'=>$id,'status'=>$status]);

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
    $status = $_GET['status'] ?? '';
    $where  = $status ? "WHERE r.status=?" : "";
    $params = $status ? [$status] : [];

    $total = db()->prepare("SELECT COUNT(*) FROM ".t('reviews')." r $where");
    $total->execute($params);

    $sql = "SELECT r.*, u.full_name reviewer, p.name_en product_name
            FROM ".t('reviews')." r
            LEFT JOIN ".t('users')." u ON u.id=r.user_id
            LEFT JOIN ".t('products')." p ON p.id=r.product_id
            $where ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = db()->prepare($sql); $stmt->execute($params);
    ok(['total'=>(int)$total->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);

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
        $hasTbl = (bool)$db->query("SHOW TABLES LIKE '".t('disputes')."'")->fetchColumn();
        if (!$hasTbl) ok(['total'=>0,'page'=>$page,'rows'=>[],'note'=>'Disputes table not yet created']);
        $status = $_GET['status'] ?? '';
        $where  = $status ? "WHERE d.status=?" : "";
        $params = $status ? [$status] : [];
        $tc = $db->prepare("SELECT COUNT(*) FROM ".t('disputes')." d $where");
        $tc->execute($params);
        $sql = "SELECT d.*, o.code order_code, u.full_name customer
                FROM ".t('disputes')." d
                LEFT JOIN ".t('orders')." o ON o.id=d.order_id
                LEFT JOIN ".t('users')." u ON u.id=d.user_id
                $where ORDER BY d.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        ok(['total'=>(int)$tc->fetchColumn(),'page'=>$page,'rows'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { err('Disputes query failed: '.$e->getMessage(), 500); }

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
        $hasTbl = (bool)$db->query("SHOW TABLES LIKE '".t('couriers')."'")->fetchColumn();
        if (!$hasTbl) ok(['rows'=>[],'note'=>'Couriers table not yet created']);
        $rows = $db->query("SELECT c.*, u.full_name, u.phone, u.email
            FROM ".t('couriers')." c LEFT JOIN ".t('users')." u ON u.id=c.user_id
            ORDER BY c.created_at DESC LIMIT 100")->fetchAll();
        ok(['rows'=>$rows]);
    } catch(PDOException $e) { err('Couriers query failed: '.$e->getMessage(), 500); }

// ?? QUICK COUNTS (badge refresh) ????????????????????????????
case 'badges':
    auth();
    $db = db();
    if (!$db) ok(['products_pending'=>0,'vendors_pending'=>0,'payouts_pending'=>0,'reviews_pending'=>0,'disputes_open'=>0,'db_error'=>$GLOBALS['__db_error']??'no db']);
    // Safe count — returns 0 if table doesn't exist
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
