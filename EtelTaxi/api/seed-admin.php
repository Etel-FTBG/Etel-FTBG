<?php
// ============================================================
// ETEL TAXI - Seed the first super admin  v1.0
// Visit once: /api/seed-admin.php?key=YOUR_TAXI_ADMIN_KEY&phone=...&pin=...&name=...
// Creates a super_admin account if none exists yet. Delete this file
// (or at least don't leave it easily guessable) once you've created
// your first admin - it's a one-time bootstrap tool, not something
// that should stay reachable indefinitely.
// ============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!defined('TAXI_ADMIN_KEY') || TAXI_ADMIN_KEY === '' || !hash_equals(TAXI_ADMIN_KEY, $key)) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Unauthorized - set TAXI_ADMIN_KEY in api/.env first.'));
    exit;
}

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$pin   = isset($_GET['pin']) ? trim($_GET['pin']) : '';
$name  = isset($_GET['name']) ? trim($_GET['name']) : 'Super Admin';
if (!$phone || !$pin || strlen($pin) < 4) {
    echo json_encode(array('ok' => false, 'error' => 'Pass ?phone=...&pin=... (4+ digits)&name=... in the URL.'));
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    echo json_encode(array('ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()));
    exit;
}

$s = $pdo->prepare('SELECT id FROM ' . DB_PREFIX . 'taxi_admins WHERE phone = ?');
$s->execute(array($phone));
if ($s->fetch()) {
    echo json_encode(array('ok' => false, 'error' => 'An admin with that phone number already exists.'));
    exit;
}

$pdo->prepare('INSERT INTO ' . DB_PREFIX . 'taxi_admins (association_id, full_name, phone, pin_hash, role, created_at)
               VALUES (NULL, ?, ?, ?, "super_admin", NOW())')
    ->execute(array($name, $phone, password_hash($pin, PASSWORD_DEFAULT)));

echo json_encode(array('ok' => true, 'message' => 'Super admin created. Sign in at admin.html with this phone and PIN.'));
