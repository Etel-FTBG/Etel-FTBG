<?php
/**
 * ETEL Image Upload Handler
 * POST multipart with Authorization: Bearer <token> and field: image (file)
 * Returns JSON { success, data: { url } }
 */
ob_start();
error_reporting(0);
ini_set('display_errors','0');
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
/* Restrict CORS to your domain */
$_cors_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$_cors_allowed = array('https://ethelmarket.com','https://www.ethelmarket.com','http://localhost','http://127.0.0.1');
if (in_array($_cors_origin, $_cors_allowed, true)) { header('Access-Control-Allow-Origin: ' . $_cors_origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/*  LOAD config.php then .env (robust cPanel reader)  */
$_cfg_file = __DIR__ . '/config.php';
if (file_exists($_cfg_file)) require_once $_cfg_file;

$_env_file = __DIR__ . '/.env';
$_up_env = array();
if (file_exists($_env_file)) {
    $lines = @file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = ltrim($line, "\xEF\xBB\xBF");
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') continue;
            if (strpos($trimmed, '=') === false) continue;
            $parts = explode('=', $trimmed, 2);
            $k = trim($parts[0]);
            $v = isset($parts[1]) ? trim($parts[1]) : '';
            if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
                $hash = strpos($v, ' #');
                if ($hash !== false) $v = rtrim(substr($v, 0, $hash));
            }
            if (strlen($v) >= 2 &&
                (($v[0] === '"'  && $v[strlen($v)-1] === '"') ||
                 ($v[0] === "'"  && $v[strlen($v)-1] === "'"))) {
                $v = substr($v, 1, strlen($v) - 2);
            }
            if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                $_up_env[$k] = $v;
            }
        }
    }
}
function up_env($key, $const, $default = '') {
    global $_up_env;
    if (isset($_up_env[$key]) && $_up_env[$key] !== '') return $_up_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($const) && constant($const) !== '') return constant($const);
    return $default;
}

/*  CONFIG  */
define('UP_DB_HOST',      up_env('DB_HOST',      'CFG_DB_HOST',      'localhost'));
define('UP_DB_NAME',      up_env('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
define('UP_DB_USER',      up_env('DB_USER',      'CFG_DB_USER',      ''));
define('UP_DB_PASS',      up_env('DB_PASS',      'CFG_DB_PASS',      ''));
define('UP_TOKEN_SECRET', up_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

/*  Guard: refuse to run without a real secret  */
if (!UP_DB_PASS || !UP_TOKEN_SECRET) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server misconfigured  check api/.env']);
    exit;
}

function upfail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function upok($data) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

require_once __DIR__ . '/lib/image_tools.php';   // up_compress_image(), EXIF rotation

/*  Token validation  */
function up_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = isset($hdrs['Authorization']) ? $hdrs['Authorization'] : '';
    }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}
/* Same token format as boot.php's gen_token()/verify_token() - see
   migration 006 / boot.php's etel_bump_token_version() for why
   token_version exists and why it has to be checked here too. */
function up_verify_token($token) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null; // old 3-part tokens fail closed
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, UP_TOKEN_SECRET);
    return ($sig === $expected) ? ['uid' => intval($uid), 'version' => intval($version)] : null;
}

/*  Auth: any logged-in user can upload images  */
$token = up_get_bearer();
if (!$token) upfail('Unauthorised  no token', 401);

$tok = up_verify_token($token);
if (!$tok) upfail('Unauthorised  invalid or expired token', 401);

try {
    $pdo = new PDO(
        'mysql:host=' . UP_DB_HOST . ';dbname=' . UP_DB_NAME . ';charset=utf8mb4',
        UP_DB_USER, UP_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) { upfail('DB connection failed', 500); }

$up_has_ver = false;
try {
    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_version'");
    $colCheck->execute();
    $up_has_ver = (bool) $colCheck->fetch();
} catch (Exception $e) { $up_has_ver = false; }

try {
    $s = $pdo->prepare('SELECT id, role' . ($up_has_ver ? ', token_version' : '') . ' FROM users WHERE id = ? LIMIT 1');
    $s->execute([$tok['uid']]);
    $user = $s->fetch();
} catch (Exception $e) { $user = null; }

if (!$user) upfail('User not found', 401);
if ($up_has_ver && intval($user['token_version']) !== $tok['version']) upfail('Unauthorised  token revoked', 401);
$uid = $tok['uid'];

/*  File validation  */
if (empty($_FILES['image'])) upfail('No image file in request (field name: "image")', 400);
$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) upfail('Upload error code: ' . $file['error'], 400);

$ftype = mime_content_type($file['tmp_name']);
$allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($ftype, $allowed_mime)) upfail('Only JPG, PNG, GIF, WEBP images are allowed', 400);
if ($file['size'] > 5 * 1024 * 1024) upfail('Max image size is 5 MB', 400);

/*  Save file  */
$ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$ext     = $ext_map[$ftype];

/* Optional 'type' field lets callers other than the order-item photo
   flow (e.g. driver onboarding's selfie/license photo) land in their
   own clearly-named folder instead of uploads/items/. Unrecognized or
   missing type falls back to the original 'items' behavior so every
   existing caller that doesn't send this field keeps working exactly
   as before. */
$type_map = [
    'driver_selfie'  => ['folder' => 'driver_selfies', 'prefix' => 'selfie_'],
    'driver_license' => ['folder' => 'driver_licenses', 'prefix' => 'license_'],
    'topup_receipt'  => ['folder' => 'topup_receipts', 'prefix' => 'receipt_'],
    'shop_cover'     => ['folder' => 'shop_covers', 'prefix' => 'cover_'],
    'driver_insurance_photo'            => ['folder' => 'driver_documents', 'prefix' => 'doc_'],
    'driver_technical_inspection_photo' => ['folder' => 'driver_documents', 'prefix' => 'doc_'],
    'driver_road_fund_photo'            => ['folder' => 'driver_documents', 'prefix' => 'doc_'],
];
$reqType = isset($_POST['type']) ? trim($_POST['type']) : '';
$folder  = isset($type_map[$reqType]) ? $type_map[$reqType]['folder'] : 'items';
$prefix  = isset($type_map[$reqType]) ? $type_map[$reqType]['prefix'] : 'item_';

/* Per-user upload budget (30 per 10 minutes, 150 for admins) so one account cannot fill the disk. Fails open if the table is missing. */
try {
    $upKey = substr('upload:' . $uid . '|' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0'), 0, 120);
    $upc = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > ?');
    $upc->execute([$upKey, date('Y-m-d H:i:s', time() - 600)]);
    // Admins add menu photos in bulk (Admin > Menu items > Add many photos at once), so they get a larger budget.
    $upMax = (isset($user['role']) && $user['role'] === 'admin') ? 150 : 30;
    if ((int) $upc->fetchColumn() >= $upMax) upfail('Too many uploads. Please wait a few minutes and try again.', 429);
    $pdo->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())')->execute([$upKey]);
} catch (Exception $e) { /* no throttle table - allow */ }

$dir     = dirname(__DIR__) . '/uploads/' . $folder . '/';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_dir($dir)) upfail('Could not create uploads directory', 500);

/* Defence in depth: nothing in an uploads folder may ever run as a script or be listed, even if a bad file got in. */
$up_guard = dirname(__DIR__) . '/uploads/.htaccess';
if (!is_file($up_guard)) {
    @file_put_contents($up_guard, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n    Header always set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
}

$fname = $prefix . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest  = $dir . $fname;
if (!move_uploaded_file($file['tmp_name'], $dest)) upfail('Failed to save file', 500);

/* Resize/compress after saving, before returning the URL. This is why
   uploads/ ended up at 29MB across just 13 files - full camera-resolution
   selfies, license photos, and receipts, some several MB each, saved
   with zero processing. Caps the LONG side at 1600px (plenty for reading
   a license or verifying a face - this isn't a print asset) and
   re-encodes as JPEG at quality 82, which is a large size cut with no
   real loss of legibility. Skips silently (keeps the original file) if
   GD isn't available or the resize fails for any reason - a working
   upload always matters more than a smaller one. */
up_compress_image($dest, $ftype);

// Build public URL
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'];
$base  = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
upok(['url' => $proto . '://' . $host . $base . '/uploads/' . $folder . '/' . $fname, 'filename' => $fname]);
