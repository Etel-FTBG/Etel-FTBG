<?php
// ============================================================
// ETEL OS Connector — Etel Pay
// Deploy as: etel-pay-root/etel-os.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('ETEL_API_KEY',    'CHANGE_THIS_SECRET');
define('PROJECT_ID',      'pay');
define('PROJECT_VERSION', '1.0.0');
// WP config path — adjust if needed
define('WP_ROOT', dirname(__DIR__, 3));

function ok($d)  { echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function auth()  { $k=$_SERVER['HTTP_X_ETEL_KEY']??$_GET['key']??''; if($k!==ETEL_API_KEY) err('Unauthorized',401); }

function db() {
    static $pdo=null;
    if($pdo) return $pdo;
    if(file_exists(WP_ROOT.'/wp-config.php')){
        $cfg=file_get_contents(WP_ROOT.'/wp-config.php');
        preg_match("/define\s*\(\s*['\"]DB_NAME['\"].*?['\"](.+?)['\"]/s",$cfg,$n);
        preg_match("/define\s*\(\s*['\"]DB_USER['\"].*?['\"](.+?)['\"]/s",$cfg,$u);
        preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"].*?['\"](.+?)['\"]/s",$cfg,$p);
        preg_match("/define\s*\(\s*['\"]DB_HOST['\"].*?['\"](.+?)['\"]/s",$cfg,$h);
        $pdo=new PDO("mysql:host=".($h[1]??'localhost').";dbname=".($n[1]??'').";charset=utf8mb4",$u[1]??'',$p[1]??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'status':
        ok(['project'=>'Etel Pay','id'=>PROJECT_ID,'version'=>PROJECT_VERSION,'status'=>'online','time'=>date('Y-m-d H:i:s')]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'active_wallets'       => (int)$db->query("SELECT COUNT(*) FROM etel_wallets WHERE status='active'")->fetchColumn(),
            'transactions_today'   => (int)$db->query("SELECT COUNT(*) FROM etel_transactions WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'total_volume_today'   => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM etel_transactions WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn(),
            'pending_transfers'    => (int)$db->query("SELECT COUNT(*) FROM etel_transactions WHERE status='pending'")->fetchColumn(),
        ]);

    case 'get_transactions':
        auth();
        $limit = (int)($_GET['limit'] ?? 20);
        $stmt = db()->prepare("SELECT id,sender_id,receiver_id,amount,type,status,created_at FROM etel_transactions ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        ok($stmt->fetchAll());

    case 'get_wallets':
        auth();
        $stmt = db()->prepare("SELECT id,user_id,balance,currency,status,created_at FROM etel_wallets ORDER BY balance DESC LIMIT 50");
        $stmt->execute();
        ok($stmt->fetchAll());

    default:
        err('Unknown action: '.$action, 404);
}
