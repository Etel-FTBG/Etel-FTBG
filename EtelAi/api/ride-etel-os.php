<?php
// ============================================================
// ETEL OS Connector — Etel Ride
// Deploy as: etel-ride/api/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'ride');
define('PROJECT_VERSION', '4.0.0');

function ok($d)  { echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function auth()  { $k=$_SERVER['HTTP_X_ETEL_KEY']??$_GET['key']??''; if($k!==ETEL_API_KEY) err('Unauthorized',401); }

function db() {
    static $pdo=null;
    if($pdo) return $pdo;
    // Load from etel-ride's own config
    $cfg = require __DIR__.'/config.php';
    $pdo=new PDO("mysql:host=".($cfg['db_host']??'localhost').";dbname=".($cfg['db_name']??'').";charset=utf8mb4",$cfg['db_user']??'',$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'status':
        ok(['project'=>'Etel Ride','id'=>PROJECT_ID,'version'=>PROJECT_VERSION,'status'=>'online','time'=>date('Y-m-d H:i:s')]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'rides_today'     => (int)$db->query("SELECT COUNT(*) FROM rides WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'active_rides'    => (int)$db->query("SELECT COUNT(*) FROM rides WHERE status IN ('accepted','in_progress')")->fetchColumn(),
            'active_drivers'  => (int)$db->query("SELECT COUNT(*) FROM drivers WHERE is_online=1")->fetchColumn(),
            'revenue_today'   => (float)$db->query("SELECT COALESCE(SUM(fare),0) FROM rides WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn(),
        ]);

    case 'get_rides':
        auth();
        $limit = (int)($_GET['limit'] ?? 20);
        $stmt = db()->prepare("SELECT id,rider_id,driver_id,pickup,dropoff,fare,status,created_at FROM rides ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        ok($stmt->fetchAll());

    case 'get_drivers':
        auth();
        $stmt = db()->prepare("SELECT id,name,phone,is_online,rating,total_rides FROM drivers ORDER BY is_online DESC, rating DESC LIMIT 50");
        $stmt->execute();
        ok($stmt->fetchAll());

    case 'cancel_ride':
        auth();
        $id = (int)($body['ride_id'] ?? 0);
        if (!$id) err('ride_id required');
        db()->prepare("UPDATE rides SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$id]);
        ok(['cancelled'=>true]);

    default:
        err('Unknown action: '.$action, 404);
}
