<?php
// ============================================================
// ETEL OS — Universal Project Connector v1.0
// Drop this file into any Etel project as: etel-os.php
// Then configure the constants below per project.
//
// INSTRUCTIONS:
//  1. Copy this file to your project root as "etel-os.php"
//  2. Set ETEL_API_KEY to a strong secret (same key you enter in etel-os)
//  3. Set PROJECT_ID, PROJECT_NAME, and DB constants
//  4. Implement get_project_stats() with real DB queries
//  5. Add any extra actions your project needs
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CONFIGURATION (edit these) ──
define('ETEL_API_KEY',    'CHANGE_THIS_TO_A_STRONG_SECRET');
define('PROJECT_ID',      'my-project');
define('PROJECT_NAME',    'My Etel Project');
define('PROJECT_VERSION', '1.0.0');

// DB — adjust to your project's credentials
define('DB_HOST',    'localhost');
define('DB_NAME',    'your_database');
define('DB_USER',    'your_user');
define('DB_PASS',    'your_password');

// ── HELPERS ──
function ok($data)           { echo json_encode(['ok' => true,  'data' => $data]); exit; }
function err($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

function auth() {
    $key = $_SERVER['HTTP_X_ETEL_KEY'] ?? $_GET['key'] ?? '';
    if ($key !== ETEL_API_KEY) err('Unauthorized', 401);
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// ── ROUTER ──
$action = $_GET['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // Public — no auth needed (etel-os pings this to check connectivity)
    case 'status':
        ok([
            'project' => PROJECT_NAME,
            'id'      => PROJECT_ID,
            'version' => PROJECT_VERSION,
            'status'  => 'online',
            'time'    => date('Y-m-d H:i:s'),
        ]);

    // Protected — requires API key
    case 'stats':
        auth();
        ok(get_project_stats());

    case 'get_orders':
        auth();
        ok(get_recent_orders());

    case 'get_users':
        auth();
        ok(get_recent_users());

    case 'alert':
        auth();
        // Called by etel-os to trigger an alert in this project
        $msg = $body['message'] ?? '';
        // Store or process the alert (implement per project)
        ok(['received' => true, 'message' => $msg]);

    default:
        err('Unknown action: ' . $action, 404);
}

// ── IMPLEMENT THESE PER PROJECT ──

function get_project_stats() {
    // Return key metrics for the etel-os dashboard card
    // Example — replace with real queries:
    // $pdo = db();
    // return [
    //   'orders_today'    => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    //   'active_couriers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='courier' AND is_online=1")->fetchColumn(),
    //   'pending_orders'  => $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
    //   'revenue_today'   => $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn(),
    // ];
    return ['message' => 'Implement get_project_stats() with real DB queries'];
}

function get_recent_orders() {
    // Return recent orders — implement with your DB schema
    return [];
}

function get_recent_users() {
    // Return recent users — implement with your DB schema
    return [];
}
