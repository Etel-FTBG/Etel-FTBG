<?php
/**
 * ETEL Admin Branding Upload Handler
 * POST multipart with Authorization: Bearer <admin token> and fields:
 *   slot  - which image this replaces (see $SLOTS below)
 *   image - the uploaded file
 *
 * Mirrors api/upload.php's config/env/token-verification pattern exactly
 * (same reasons: boot.php's JSON-only body parsing can't handle multipart
 * file uploads at all, same as the existing upload.php already had to
 * work around) - but gated to admin accounts only, and writes to the
 * actual FIXED, well-known file paths the manifests/HTML already
 * reference instead of a randomly-named uploads/ file. That's the whole
 * point: replace what's already there, don't create something new that
 * still needs a manifest/HTML edit to actually take effect.
 *
 * Returns JSON { success, data: { message } }
 */
ob_start();
error_reporting(0);
ini_set('display_errors','0');
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$_cors_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$_cors_allowed = array('https://ethelmarket.com','https://www.ethelmarket.com','http://localhost','http://127.0.0.1');
if (in_array($_cors_origin, $_cors_allowed, true)) { header('Access-Control-Allow-Origin: ' . $_cors_origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

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
function bup_env($key, $const, $default = '') {
    global $_up_env;
    if (isset($_up_env[$key]) && $_up_env[$key] !== '') return $_up_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($const) && constant($const) !== '') return constant($const);
    return $default;
}

define('BUP_DB_HOST',      bup_env('DB_HOST',      'CFG_DB_HOST',      'localhost'));
define('BUP_DB_NAME',      bup_env('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
define('BUP_DB_USER',      bup_env('DB_USER',      'CFG_DB_USER',      ''));
define('BUP_DB_PASS',      bup_env('DB_PASS',      'CFG_DB_PASS',      ''));
define('BUP_TOKEN_SECRET', bup_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

if (!BUP_DB_PASS || !BUP_TOKEN_SECRET) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server misconfigured - check api/.env']);
    exit;
}

function bupfail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function bupok($data) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function bup_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = isset($hdrs['Authorization']) ? $hdrs['Authorization'] : '';
    }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}
function bup_verify_token($token) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null;
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, BUP_TOKEN_SECRET);
    return ($sig === $expected) ? ['uid' => intval($uid), 'version' => intval($version)] : null;
}

$token = bup_get_bearer();
if (!$token) bupfail('Unauthorised - no token', 401);
$tok = bup_verify_token($token);
if (!$tok) bupfail('Unauthorised - invalid or expired token', 401);

try {
    $pdo = new PDO(
        'mysql:host=' . BUP_DB_HOST . ';dbname=' . BUP_DB_NAME . ';charset=utf8mb4',
        BUP_DB_USER, BUP_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) { bupfail('DB connection failed', 500); }

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

if (!$user) bupfail('User not found', 401);
if ($up_has_ver && intval($user['token_version']) !== $tok['version']) bupfail('Unauthorised - token revoked', 401);
if ($user['role'] !== 'admin') bupfail('Admin access required', 403);
/* Delivery collapses every EtelGroup-assigned staff role (Dispatcher,
 * Finance, Support, ...) into this same role='admin' DB value - see
 * user_capabilities()/require_admin() in boot.php for the real per-role
 * breakdown via staff_role + role_permissions. The check above alone
 * does NOT mean "is the site owner" here, it just means "is some kind
 * of staff member" - branding replaces company-wide, public-facing
 * assets (favicon, all 6 apps' icons, header logos, splash), so it
 * needs the same 'settings.manage' capability gate as boot.php's
 * admin_create_announcement, duplicated here (short inline version,
 * not a require of boot.php) since this script intentionally stays
 * standalone for multipart-upload support. */
$branding_managed = false;
try {
    $colCheck2 = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_etelgroup_managed'");
    $colCheck2->execute();
    $branding_managed = (bool) $colCheck2->fetch();
} catch (Exception $e) { $branding_managed = false; }
if ($branding_managed) {
    $s2 = $pdo->prepare('SELECT is_etelgroup_managed, staff_role FROM users WHERE id = ? LIMIT 1');
    $s2->execute([$tok['uid']]);
    $mgmt = $s2->fetch();
    $isManaged = $mgmt && !empty($mgmt['is_etelgroup_managed']);
    if ($isManaged) {
        $caps = array();
        if (!empty($mgmt['staff_role'])) {
            try {
                $rp = $pdo->prepare('SELECT capabilities FROM role_permissions WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
                $rp->execute([$mgmt['staff_role']]);
                $rrow = $rp->fetch();
                if ($rrow) $caps = json_decode($rrow['capabilities'], true) ?: array();
            } catch (Exception $e) { $caps = array(); }
        }
        if (!in_array('*', $caps, true) && !in_array('settings.manage', $caps, true)) {
            bupfail("You don't have permission to do this (requires: settings.manage)", 403);
        }
    }
    // Not EtelGroup-managed (a true legacy/independent admin account,
    // per the is_etelgroup_managed convention documented elsewhere) ->
    // unrestricted, same as every other capability check in this system.
}

/* ── File validation (same limits as upload.php) ── */
if (empty($_FILES['image'])) bupfail('No image file in request (field name: "image")', 400);
$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) bupfail('Upload error code: ' . $file['error'], 400);
$ftype = mime_content_type($file['tmp_name']);
$allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($ftype, $allowed_mime)) bupfail('Only JPG, PNG, GIF, WEBP images are allowed', 400);
if ($file['size'] > 8 * 1024 * 1024) bupfail('Max image size is 8 MB', 400);
if (!function_exists('imagecreatetruecolor')) bupfail('Server image library (GD) is not available - cannot process this upload', 500);

/* ── Slot whitelist ──
 * Each slot maps to real, existing file paths already referenced by
 * the manifests/HTML - uploading to a slot overwrites those files in
 * place, so no manifest/HTML edit is ever needed to make a change take
 * effect. 'icon' slots get the full PWA size set generated via a
 * center-crop-to-square (same approach used when this branding was
 * first set up by hand); 'logo'/'splash'/'favicon' slots get a single
 * resized file. */
$ROOT = dirname(__DIR__); // delivery/
$SLOTS = [
    // PWA icons - each generates the full size set for that app
    'icon_driver'   => ['type' => 'icon',   'prefix' => 'driver-'],
    'icon_ride'     => ['type' => 'icon',   'prefix' => 'ride-'],
    'icon_courier'  => ['type' => 'icon',   'prefix' => 'courier-'],
    'icon_service'  => ['type' => 'icon',   'prefix' => 'service-'],
    'icon_admin'    => ['type' => 'icon',   'prefix' => 'admin-'],
    'icon_default'  => ['type' => 'icon',   'prefix' => ''],
    // Header logos shown on login.html (assets/images/logo-*.png + .webp)
    'logo_driver'   => ['type' => 'logo',   'file' => 'logo-driver'],
    'logo_ride'     => ['type' => 'logo',   'file' => 'logo-ride'],
    'logo_courier'  => ['type' => 'logo',   'file' => 'logo-courier'],
    'logo_service'  => ['type' => 'logo',   'file' => 'logo-service'],
    'logo_admin'    => ['type' => 'logo',   'file' => 'logo-admin'],
    'logo_default'  => ['type' => 'logo',   'file' => 'logo'],
    // Custom in-page splash overlay (see class comment in each app's
    // <body> - single shared file, all five apps use the same one)
    'splash'        => ['type' => 'splash', 'file' => 'master-logo-splash.png'],
    // Browser tab favicon (site root)
    'favicon'       => ['type' => 'favicon', 'file' => 'favicon.ico'],
];

$slot = isset($_POST['slot']) ? trim($_POST['slot']) : '';
if (!isset($SLOTS[$slot])) bupfail('Unknown slot: ' . $slot, 400);
$cfg = $SLOTS[$slot];

/* ── Image loading (source for all slot types) ── */
switch ($ftype) {
    case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
    case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
    case 'image/gif':  $src = @imagecreatefromgif($file['tmp_name']);  break;
    case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false; break;
    default: $src = false;
}
if (!$src) bupfail('Could not read the uploaded image', 400);
$srcW = imagesx($src); $srcH = imagesy($src);

/* Center-crop to a square, matching the approach already used for
 * every existing icon in this system - avoids distorted/stretched
 * icons from a non-square source image. */
function bup_square_crop($src, $srcW, $srcH) {
    $side = min($srcW, $srcH);
    $sx = (int) (($srcW - $side) / 2);
    $sy = (int) (($srcH - $side) / 2);
    $out = imagecreatetruecolor($side, $side);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $side, $side, $transparent);
    imagecopyresampled($out, $src, 0, 0, $sx, $sy, $side, $side, $side, $side);
    return $out;
}
function bup_resize_to($src, $srcW, $srcH, $size) {
    $sq = bup_square_crop($src, $srcW, $srcH);
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagecopyresampled($out, $sq, 0, 0, 0, 0, $size, $size, imagesx($sq), imagesy($sq));
    imagedestroy($sq);
    return $out;
}

$written = [];

if ($cfg['type'] === 'icon') {
    $sizes = [72, 96, 128, 144, 152, 192, 384, 512];
    $iconsDir = $ROOT . '/icons';
    if (!is_dir($iconsDir)) bupfail('icons/ directory not found on server', 500);
    foreach ($sizes as $size) {
        $img = bup_resize_to($src, $srcW, $srcH, $size);
        $path = $iconsDir . '/' . $cfg['prefix'] . 'icon-' . $size . '.png';
        if (@imagepng($img, $path, 6)) $written[] = basename($path);
        imagedestroy($img);
    }
    // Maskable variants (192, 512) - reuse the same crop.
    foreach ([192, 512] as $size) {
        $img = bup_resize_to($src, $srcW, $srcH, $size);
        $path = $iconsDir . '/' . $cfg['prefix'] . 'icon-' . $size . '-maskable.png';
        if (@imagepng($img, $path, 6)) $written[] = basename($path);
        imagedestroy($img);
    }
    // Apple touch icon (180)
    $img = bup_resize_to($src, $srcW, $srcH, 180);
    $path = $iconsDir . '/' . $cfg['prefix'] . 'apple-touch-icon.png';
    if (@imagepng($img, $path, 6)) $written[] = basename($path);
    imagedestroy($img);

} elseif ($cfg['type'] === 'logo') {
    // Header logo shown on login.html - not square-cropped (a wordmark
    // logo often isn't square), just capped at a sensible max height
    // matching the CSS's own .del-auth-logo-img { height:44px } sizing -
    // 200px tall gives real headroom for retina displays without
    // shipping an oversized file.
    $maxH = 200;
    $scale = min(1, $maxH / $srcH);
    $nw = max(1, (int) round($srcW * $scale));
    $nh = max(1, (int) round($srcH * $scale));
    $img = imagecreatetruecolor($nw, $nh);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, $nw, $nh, $transparent);
    imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $srcW, $srcH);
    $imgDir = $ROOT . '/assets/images';
    if (!is_dir($imgDir)) @mkdir($imgDir, 0755, true);
    $pngPath = $imgDir . '/' . $cfg['file'] . '.png';
    if (@imagepng($img, $pngPath, 6)) $written[] = basename($pngPath);
    if (function_exists('imagewebp')) {
        $webpPath = $imgDir . '/' . $cfg['file'] . '.webp';
        if (@imagewebp($img, $webpPath, 90)) $written[] = basename($webpPath);
    }
    imagedestroy($img);

} elseif ($cfg['type'] === 'splash') {
    $img = bup_resize_to($src, $srcW, $srcH, 512);
    $imgDir = $ROOT . '/assets/images';
    if (!is_dir($imgDir)) @mkdir($imgDir, 0755, true);
    $path = $imgDir . '/' . $cfg['file'];
    if (@imagepng($img, $path, 6)) $written[] = basename($path);
    imagedestroy($img);

} elseif ($cfg['type'] === 'favicon') {
    // GD has no native, reliable .ico encoder - saving a 32x32 PNG
    // alongside instead and pointing browsers at that is far more
    // robust than hand-rolling an ICO container format. Modern
    // browsers accept PNG favicons directly.
    $img = bup_resize_to($src, $srcW, $srcH, 32);
    $path = $ROOT . '/favicon.png';
    if (@imagepng($img, $path, 6)) $written[] = 'favicon.png';
    imagedestroy($img);
}

imagedestroy($src);

if (!$written) bupfail('Nothing was written - check server file permissions on the target folder', 500);

bupok([
    'message' => count($written) . ' file(s) updated for slot "' . $slot . '"',
    'files'   => $written,
    'note'    => ($cfg['type'] === 'favicon')
        ? 'Saved as favicon.png (GD cannot reliably write true .ico files) - if your <link rel="icon"> still points at favicon.ico, update it to favicon.png once, or keep both in sync manually.'
        : null,
]);
