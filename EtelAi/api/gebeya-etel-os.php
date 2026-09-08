<?php
// ============================================================
// ETEL OS Connector — EtelGebeya (marketplace)
// Deploy as: EtelGebeya/api/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'gebeya');
define('PROJECT_VERSION', '1.0.0');

function ok($d)  { echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function auth()  { $k=$_SERVER['HTTP_X_ETEL_KEY']??$_GET['key']??''; if($k!==ETEL_API_KEY) err('Unauthorized',401); }

function db() {
    static $pdo=null;
    if($pdo) return $pdo;
    $cfg = require __DIR__.'/../config/config.php'; // EtelGebeya config structure
    $pdo=new PDO("mysql:host=".($cfg['db_host']??'localhost').";dbname=".($cfg['db_name']??'').";charset=utf8mb4",$cfg['db_user']??'',$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'status':
        ok(['project'=>'Etel Gebeya','id'=>PROJECT_ID,'version'=>PROJECT_VERSION,'status'=>'online','time'=>date('Y-m-d H:i:s')]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'orders_today'    => (int)$db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'total_products'  => (int)$db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
            'active_sellers'  => (int)$db->query("SELECT COUNT(DISTINCT seller_id) FROM products WHERE status='active'")->fetchColumn(),
            'revenue_today'   => (float)$db->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn(),
        ]);

    case 'get_orders':
        auth();
        $limit = (int)($_GET['limit'] ?? 20);
        $stmt = db()->prepare("SELECT id,buyer_id,seller_id,total_price,status,created_at FROM orders ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        ok($stmt->fetchAll());

    case 'get_products':
        auth();
        $limit = (int)($_GET['limit'] ?? 20);
        $stmt = db()->prepare("SELECT id,name,price,stock,seller_id,status,created_at FROM products ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        ok($stmt->fetchAll());

    case 'approve_seller':
        auth();
        $id = (int)($body['seller_id'] ?? 0);
        if (!$id) err('seller_id required');
        db()->prepare("UPDATE sellers SET status='approved',approved_at=NOW() WHERE id=?")->execute([$id]);
        ok(['approved'=>true]);

    default:
        err('Unknown action: '.$action, 404);
}
