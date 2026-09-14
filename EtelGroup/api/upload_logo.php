<?php
// ============================================================
// Etel Group super-page — Company logo file upload
// Deploy as: EtelGroup/api/upload_logo.php
//
// A deliberately separate file from boot.php: that router only reads
// php://input and JSON-decodes it, which is empty for a real
// multipart/form-data upload (the browser puts file data in $_FILES,
// not the raw JSON body stream boot.php expects). Rather than
// complicate that router with a special case, this is its own small,
// single-purpose endpoint - same auth, same response shape
// ({ok,success,data}/{ok,success,error,message}), so the frontend can
// treat it consistently with every other admin action.
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/auth.php';

function ok($data = null) {
    echo json_encode(array('ok' => true, 'success' => true, 'data' => $data));
    exit;
}
function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(array('ok' => false, 'success' => false, 'error' => $msg, 'message' => $msg));
    exit;
}

eg_require_admin();

if (!isset($_FILES['logo']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
    fail('No file uploaded (expected multipart field "logo")', 400);
}
$file = $_FILES['logo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $phpErrors = array(
        UPLOAD_ERR_INI_SIZE   => 'File is larger than this server allows',
        UPLOAD_ERR_FORM_SIZE  => 'File is larger than allowed',
        UPLOAD_ERR_PARTIAL    => 'Upload was interrupted - please try again',
        UPLOAD_ERR_NO_FILE    => 'No file was actually received',
    );
    fail($phpErrors[$file['error']] ?? 'Upload failed (error ' . $file['error'] . ')', 400);
}

// 3MB cap - a company logo has no business being larger than this, and
// it keeps the public homepage fast (these load on first paint, on
// every visitor's connection).
$maxBytes = 3 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    fail('File is too large - please keep logos under 3MB', 400);
}

// Real mime-type sniffing (not just trusting the filename extension or
// the browser-supplied Content-Type, either of which a malicious upload
// could lie about) via finfo, restricted to a small safe raster-image
// allowlist. SVG is deliberately excluded - an SVG can embed <script>
// and this file is served back out publicly on the homepage, so
// allowing SVG here would be a stored-XSS path.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = array(
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
);
if (!isset($allowed[$mime])) {
    fail('Please upload a PNG, JPG, or WEBP image (got: ' . htmlspecialchars($mime) . ')', 400);
}
$ext = $allowed[$mime];

// Companies are looked up by slug elsewhere in this app, so reuse that
// as the filename base for a predictable, human-readable path
// ("etel-market.png") rather than a random hash - re-uploading a new
// logo for the same company naturally overwrites the old file instead
// of accumulating orphaned uploads forever. Falls back to a random
// name if no slug was supplied (e.g. uploading before the company
// itself has been saved yet).
$slug = isset($_POST['slug']) ? strtolower(trim((string)$_POST['slug'])) : '';
$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
$base = $slug !== '' ? $slug : ('logo-' . bin2hex(random_bytes(6)));

$uploadDir = __DIR__ . '/../icons/uploads';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        fail('Could not create upload directory on the server', 500);
    }
}
// .htaccess safety net in the upload folder itself: even though only
// png/jpg/webp are ever written here, this guarantees nothing placed in
// this folder (now or by any future bug) can ever be executed as PHP.
$htaccessPath = $uploadDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    @file_put_contents($htaccessPath, "php_flag engine off\nAddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phar\n");
}

$filename = $base . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    fail('Could not save the uploaded file on the server', 500);
}
@chmod($destPath, 0644);

// Cache-bust the returned URL with the file's own mtime, so re-uploading
// a new logo for the same company (same filename, since it's slug-based)
// doesn't get stuck showing the old cached image indefinitely.
$publicUrl = 'https://ethelmarket.com/EtelGroup/icons/uploads/' . $filename . '?v=' . filemtime($destPath);

eg_log_activity('logo_uploaded', ($slug !== '' ? $slug : $filename) . ' (' . $file['size'] . ' bytes)');

ok(array('url' => $publicUrl, 'filename' => $filename));
