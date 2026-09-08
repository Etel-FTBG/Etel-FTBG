<?php
// ============================================================
// ETEL OS Connector — Etel Delivery (delivery.zip / delivery project)
// Deploy as: delivery/api/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'delivery');
define('PROJECT_VERSION', '1.0.0');

function ok($d)  { echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function err($m, $c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function auth()  { $k=$_SERVER['HTTP_X_ETEL_KEY']??$_GET['key']??''; if($k!==ETEL_API_KEY) err('Unauthorized',401); }

function db() {
    static $pdo=null;
    if($pdo) return $pdo;
    // Load config from existing delivery project config
    $cfg = file_exists(__DIR__.'/.env') ? parse_ini_file(__DIR__.'/.env') : [];
    $host = $cfg['DB_HOST'] ?? 'localhost';
    $name = $cfg['DB_NAME'] ?? 'delivery';
    $user = $cfg['DB_USER'] ?? 'root';
    $pass = $cfg['DB_PASS'] ?? '';
    $pdo  = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'status':
        ok(['project'=>'Etel Delivery','id'=>PROJECT_ID,'version'=>PROJECT_VERSION,'status'=>'online','time'=>date('Y-m-d H:i:s')]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'orders_today'    => (int)$db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'pending_orders'  => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'active_couriers' => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='service_giver' AND is_online=1")->fetchColumn(),
            'completed_today' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='completed' AND DATE(updated_at)=CURDATE()")->fetchColumn(),
        ]);

    case 'get_orders':
        auth();
        $db = db();
        $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 20);
        $status = $body['status'] ?? $_GET['status'] ?? null;
        $sql = "SELECT id,customer_name,pickup_location,dropoff_location,status,created_at FROM orders";
        if ($status) $sql .= " WHERE status=?";
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $stmt = $db->prepare($sql);
        $status ? $stmt->execute([$status, $limit]) : $stmt->execute([$limit]);
        ok($stmt->fetchAll());

    case 'get_couriers':
        auth();
        $db = db();
        $stmt = $db->prepare("SELECT id,full_name,phone,is_online,rating,total_deliveries FROM users WHERE role='service_giver' ORDER BY is_online DESC, full_name ASC LIMIT 50");
        $stmt->execute();
        ok($stmt->fetchAll());

    case 'update_order_status':
        auth();
        $id     = (int)($body['order_id'] ?? 0);
        $status = $body['status'] ?? '';
        if (!$id || !$status) err('order_id and status required');
        db()->prepare("UPDATE orders SET status=?,updated_at=NOW() WHERE id=?")->execute([$status,$id]);
        ok(['updated'=>true]);

    default:
        err('Unknown action: '.$action, 404);
}
