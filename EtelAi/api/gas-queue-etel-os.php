<?php
// ============================================================
// ETEL OS Connector — Madeya Gas Queue
// Deploy as: madeya-gas-queue/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'gas-queue');
define('PROJECT_VERSION', '6.7.0');

function ok($d)  { echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function auth()  { $k=$_SERVER['HTTP_X_ETEL_KEY']??$_GET['key']??''; if($k!==ETEL_API_KEY) err('Unauthorized',401); }

function db() {
    static $pdo=null;
    if($pdo) return $pdo;
    $cfg = require __DIR__.'/includes/config.php';
    $pdo=new PDO("mysql:host=".($cfg['db_host']??'localhost').";dbname=".($cfg['db_name']??'').";charset=utf8mb4",$cfg['db_user']??'',$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'status':
        ok(['project'=>'Madeya Gas Queue','id'=>PROJECT_ID,'version'=>PROJECT_VERSION,'status'=>'online','time'=>date('Y-m-d H:i:s')]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'queue_length'  => (int)$db->query("SELECT COUNT(*) FROM queue WHERE status='waiting'")->fetchColumn(),
            'served_today'  => (int)$db->query("SELECT COUNT(*) FROM queue WHERE status='served' AND DATE(served_at)=CURDATE()")->fetchColumn(),
            'avg_wait_min'  => round((float)$db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,served_at)) FROM queue WHERE status='served' AND DATE(created_at)=CURDATE()")->fetchColumn(), 1),
            'total_in_queue'=> (int)$db->query("SELECT COUNT(*) FROM queue WHERE status IN ('waiting','called')")->fetchColumn(),
        ]);

    case 'get_queue':
        auth();
        $stmt = db()->prepare("SELECT id,ticket_number,plate_number,fuel_type,status,created_at FROM queue WHERE status IN ('waiting','called') ORDER BY created_at ASC LIMIT 50");
        $stmt->execute();
        ok($stmt->fetchAll());

    case 'call_next':
        auth();
        $stmt = db()->prepare("SELECT id FROM queue WHERE status='waiting' ORDER BY created_at ASC LIMIT 1");
        $stmt->execute();
        $next = $stmt->fetch();
        if (!$next) err('Queue is empty');
        db()->prepare("UPDATE queue SET status='called',called_at=NOW() WHERE id=?")->execute([$next['id']]);
        ok(['called_id'=>$next['id']]);

    case 'serve':
        auth();
        $id = (int)($body['queue_id'] ?? 0);
        if (!$id) err('queue_id required');
        db()->prepare("UPDATE queue SET status='served',served_at=NOW() WHERE id=?")->execute([$id]);
        ok(['served'=>true]);

    default:
        err('Unknown action: '.$action, 404);
}
