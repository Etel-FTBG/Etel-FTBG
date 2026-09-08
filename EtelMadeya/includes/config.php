<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - CONFIGURATION
// ═══════════════════════════════════════════════════════════════════

session_start();

// ─── DATABASE CONFIGURATION ─────────────────────────────────────────
// IMPORTANT: Update these credentials for your live server!
// For localhost:
// define('DB_HOST', 'localhost');
// define('DB_USER', 'root');
// define('DB_PASS', '');

// For live server (ethelmarket.com):
// You need to get these from your hosting control panel (cPanel/Plesk)
define('DB_HOST', 'localhost');  // Usually 'localhost' or specific host from cPanel
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');  // CHANGE THIS to your cPanel database username
define('DB_PASS', '');  // CHANGE THIS to your cPanel database password
define('DB_CHARSET', 'utf8mb4');

// ─── SITE CONFIGURATION ─────────────────────────────────────────────
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
define('SITE_NAME', 'Etel Madeya');
define('SITE_NAME_AM', 'ኢትል ማደያ');
define('SITE_PHONE', '+251-467-34-64-41');
define('SITE_EMAIL', 'ethelmarket@gmail.com');
define('SITE_ADDRESS', 'Addis Ababa, Ethiopia');
define('SITE_HOURS', 'Mon-Sun: 24/7');

// ─── UPLOAD CONFIGURATION ───────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// ─── QUEUE SETTINGS ─────────────────────────────────────────────────
define('ALERT_THRESHOLD', 30); // Alert when within 30 positions
define('MAX_QUEUE_SIZE', 500); // Maximum queue size per controller
define('SESSION_TIMEOUT', 3600 * 8); // 8 hours

// ─── SECURITY SETTINGS ──────────────────────────────────────────────
define('RATE_LIMIT_LOGIN', 5); // Max login attempts per 15 minutes
define('RATE_LIMIT_REGISTER', 10); // Max registrations per hour per IP

// ─── FUEL TYPES ─────────────────────────────────────────────────────
define('FUEL_TYPES', [
    'benzene' => 'Benzene (ቤንዚን)',
    'diesel' => 'Diesel (ናፍጣ)',
    'kerosene' => 'Kerosene (ኬሮሲን)'
]);

// ─── VEHICLE TYPES ──────────────────────────────────────────────────
define('DEFAULT_VEHICLE_TYPES', [
    'bajaj' => 'Bajaj (ባጃጅ)',
    'taxi' => 'Taxi (ታክሲ)',
    'private' => 'Private Car (የግል መኪና)',
    'minibus' => 'Minibus (ሚኒባስ)',
    'truck' => 'Truck (ጭነት)',
    'motorcycle' => 'Motorcycle (ሞተርሳይክል)'
]);

// ─── TIMEZONE ───────────────────────────────────────────────────────
date_default_timezone_set('Africa/Addis_Ababa');

// ─── ERROR HANDLING ─────────────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ─── HELPER FUNCTIONS ───────────────────────────────────────────────
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function uploadImage($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed'];
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['error' => 'File too large (max 5MB)'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return ['error' => 'Invalid file type'];
    }
    
    $filename = uniqid('img_') . '.' . $ext;
    $filepath = UPLOAD_DIR . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['error' => 'Failed to save file'];
    }
    
    return ['filename' => $filename, 'url' => UPLOAD_URL . $filename];
}

function generatePlateNumber() {
    $regions = ['AA', 'OR', 'AM', 'TG', 'SO', 'BG', 'SN', 'HR'];
    $region = $regions[array_rand($regions)];
    $code = rand(1, 5);
    $number = str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    return $region . '-' . $code . '-' . $number;
}

function isOddPlate($plate) {
    // Extract last digit from plate number
    preg_match('/(\d)(?!.*\d)/', $plate, $matches);
    if (empty($matches)) return null;
    $lastDigit = (int)$matches[1];
    return $lastDigit % 2 !== 0;
}

// Load core files
require_once __DIR__ . '/database-new.php';
require_once __DIR__ . '/security.php';
