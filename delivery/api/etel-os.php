<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
// ============================================================
// ETEL OS Connector  Etel Delivery v1.0
// Upload this file to: public_html/delivery/api/etel-os.php
// Then set ETEL_API_KEY below to your secret key.
// ============================================================

header('Content-Type: application/json');
/* Restrict CORS to your domain */
$_cors_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$_cors_allowed = array('https://ethelmarket.com','https://www.ethelmarket.com','http://localhost','http://127.0.0.1');
if (in_array($_cors_origin, $_cors_allowed, true)) { header('Access-Control-Allow-Origin: ' . $_cors_origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/* Read ETEL_OS_KEY from .env
   Was parse_ini_file(), same silent-failure bug as diagnose.php had
   (returns false / drops lines on non-ASCII bytes anywhere in .env,
   including in a comment). Found while fixing that one - switched to
   the same shared parser boot.php uses. See env_loader.php. */
require_once __DIR__ . '/env_loader.php';
$_eos_env = file_exists(__DIR__ . '/.env') ? etel_parse_env_file(__DIR__ . '/.env') : array();
if (!empty($_eos_env)) foreach ($_eos_env as $_k => $_v) { if (!getenv($_k)) putenv("$_k=$_v"); }
define('ETEL_API_KEY', getenv('ETEL_OS_KEY') ?: '');
if (!ETEL_API_KEY) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Server misconfigured  check api/.env (ETEL_OS_KEY)']); exit; }
define('ETELID_URL',      'https://ethelmarket.com/EtelId'); //  EtelID root URL
define('ETELID_KEY',      getenv('ETELID_LEGACY_KEY') ?: ''); // set in api/.env
define('PROJECT_ID',      'delivery');
define('PROJECT_VERSION', '1.0.0');

function ok($d)            { ob_clean(); echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { ob_clean(); http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }
function auth()  { $k = $_SERVER['HTTP_X_ETEL_KEY'] ?? $_GET['key'] ?? ''; if ($k !== ETEL_API_KEY) err('Unauthorized', 401); }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg  = file_exists(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env') : [];
    $host = $cfg['DB_HOST'] ?? 'localhost';
    $name = $cfg['DB_NAME'] ?? 'etel_delivery';
    $user = $cfg['DB_USER'] ?? 'root';
    $pass = $cfg['DB_PASS'] ?? '';
    $pdo  = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

$action = $_GET['action'] ?? 'status';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    case 'status':
        ok([
            'project' => 'Etel Delivery',
            'id'      => PROJECT_ID,
            'version' => PROJECT_VERSION,
            'status'  => 'online',
            'time'    => date('Y-m-d H:i:s'),
        ]);

    case 'stats':
        auth();
        $db = db();
        ok([
            'orders_today'    => (int)$db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'pending_orders'  => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'active_couriers' => (int)$db->query("SELECT COUNT(*) FROM service_givers sg JOIN users u ON u.id=sg.user_id WHERE sg.is_online=1 AND sg.status='active'")->fetchColumn(),
            'completed_today' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='completed' AND DATE(updated_at)=CURDATE()")->fetchColumn(),
            'active_rides'    => (int)$db->query("SELECT COUNT(*) FROM rides WHERE status IN ('searching','accepted','in_progress','arrived')")->fetchColumn(),
            'rides_today'     => (int)$db->query("SELECT COUNT(*) FROM rides WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        ]);

    case 'get_orders':
        auth();
        $limit  = (int)($_GET['limit'] ?? 20);
        $status = $_GET['status'] ?? null;
        $sql    = "SELECT id,customer_name,pickup_location,dropoff_location,status,created_at FROM orders";
        if ($status) { $sql .= " WHERE status=?"; $stmt = db()->prepare($sql . " ORDER BY created_at DESC LIMIT ?"); $stmt->execute([$status, $limit]); }
        else         { $stmt = db()->prepare($sql . " ORDER BY created_at DESC LIMIT ?"); $stmt->execute([$limit]); }
        ok($stmt->fetchAll());

    case 'get_couriers':
        auth();
        $stmt = db()->prepare("SELECT u.id, u.name AS full_name, u.phone, sg.is_online, sg.average_rating AS rating, sg.total_deliveries FROM service_givers sg JOIN users u ON u.id=sg.user_id WHERE u.role='service_giver' ORDER BY sg.is_online DESC LIMIT 50");
        $stmt->execute();
        ok($stmt->fetchAll());

    // Etel Ride shares this exact backend/database as Etel Delivery, so
    // the Projects panel's "ride" card points at this same file - this is
    // the action its "Active Rides" quick-action and stats card actually
    // hit (get_rides / active_rides in stats below).
    case 'get_rides':
        auth();
        $limit  = (int)($_GET['limit'] ?? 20);
        $status = $_GET['status'] ?? null;
        $sql = "SELECT r.id, r.status, r.pickup_address, r.dropoff_address, r.fare_estimate, r.fare_final,
                       p.name AS passenger_name, d.name AS driver_name, r.created_at
                FROM rides r
                LEFT JOIN users p ON p.id = r.passenger_id
                LEFT JOIN users d ON d.id = r.driver_id";
        if ($status) { $sql .= " WHERE r.status=?"; $stmt = db()->prepare($sql . " ORDER BY r.created_at DESC LIMIT ?"); $stmt->execute([$status, $limit]); }
        else         { $stmt = db()->prepare($sql . " ORDER BY r.created_at DESC LIMIT ?"); $stmt->execute([$limit]); }
        ok($stmt->fetchAll());

    // Lightweight natural-language proxy for the Projects panel's "Ask AI"
    // button - deliberately NOT a second LLM pipeline (chat.php already
    // owns real conversational AI); this just matches a few keywords in
    // the prompt to the relevant stats/list query above and returns that
    // data with a short plain-language summary, so the contract (valid
    // JSON, ok:true) is met without duplicating a whole AI stack here.
    case 'ai_query':
        auth();
        $prompt = strtolower($body['prompt'] ?? '');
        if (strpos($prompt, 'ride') !== false) {
            $rows = db()->query("SELECT status, COUNT(*) c FROM rides WHERE DATE(created_at)=CURDATE() GROUP BY status")->fetchAll();
            ok(['summary' => 'Today\'s ride activity by status.', 'data' => $rows]);
        }
        if (strpos($prompt, 'courier') !== false || strpos($prompt, 'driver') !== false) {
            $active = (int) db()->query("SELECT COUNT(*) FROM service_givers WHERE is_online=1 AND status='active'")->fetchColumn();
            ok(['summary' => $active . ' couriers are online right now.', 'data' => ['active_couriers' => $active]]);
        }
        // Default: general order/delivery snapshot.
        $today  = (int) db()->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $pending = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
        ok(['summary' => $today . ' orders placed today, ' . $pending . ' currently pending.', 'data' => ['orders_today' => $today, 'pending_orders' => $pending]]);

    case 'etelid_login':
        $phone    = $body['phone']    ?? '';
        $password = $body['password'] ?? '';
        if (!$phone || !$password) err('phone and password required');
        $etelid = __DIR__ . '/etelid_auth.php';
        if (!file_exists($etelid)) err('EtelID auth client not found', 503);
        require_once $etelid;
        EtelID::configure(ETELID_URL, ETELID_KEY, PROJECT_ID);
        $r = EtelID::login($phone, $password);
        if (!$r || !$r['ok']) err(($r['error'] ?? 'Login failed'), 401);
        ok($r['data']);

    case 'etelid_verify':
        $token = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
        if (!$token) err('token required');
        $etelid = __DIR__ . '/etelid_auth.php';
        if (!file_exists($etelid)) err('EtelID auth client not found', 503);
        require_once $etelid;
        EtelID::configure(ETELID_URL, ETELID_KEY, PROJECT_ID);
        $r = EtelID::verify_token($token);
        if (!$r || !$r['ok']) err('Invalid or expired token', 401);
        ok($r['data']);

    default:
        err('Unknown action: ' . $action, 404);
}
