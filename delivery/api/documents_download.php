<?php
/**
 * ETEL Admin Documents - download / inline-view streaming handler
 * GET with Authorization: Bearer <admin token> and query params:
 *   document_id   - required
 *   version_id    - optional; a specific admin_document_versions.id to
 *                   fetch instead of the document's current version
 *   disposition   - "inline" (default; for viewing in an <iframe>/<embed>)
 *                   or "attachment" (forces a download)
 *
 * Streams the raw file bytes with the right Content-Type - never JSON.
 * Standalone for the same reason as documents_upload.php: this can't go
 * through boot.php, which always sends a JSON content-type header before
 * any action code runs.
 *
 * The front-end calls this via fetch() with an Authorization header
 * (not a bare <iframe src>, which can't carry a bearer token), turns the
 * response into a blob, and points a local object URL at the viewer -
 * same pattern already used for photo blobs elsewhere in this codebase.
 */
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ob_end_clean();

$_cors_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$_cors_allowed = array('https://ethelmarket.com', 'https://www.ethelmarket.com', 'http://localhost', 'http://127.0.0.1');
if (in_array($_cors_origin, $_cors_allowed, true)) { header('Access-Control-Allow-Origin: ' . $_cors_origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function dd_json_fail($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $msg));
    exit;
}

$_cfg_file = __DIR__ . '/config.php';
if (file_exists($_cfg_file)) require_once $_cfg_file;

$_env_file = __DIR__ . '/.env';
$_dd_env = array();
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
                $_dd_env[$k] = $v;
            }
        }
    }
}
function dd_env($key, $const, $default = '') {
    global $_dd_env;
    if (isset($_dd_env[$key]) && $_dd_env[$key] !== '') return $_dd_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($const) && constant($const) !== '') return constant($const);
    return $default;
}

define('DD_DB_HOST',      dd_env('DB_HOST',      'CFG_DB_HOST',      'localhost'));
define('DD_DB_NAME',      dd_env('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
define('DD_DB_USER',      dd_env('DB_USER',      'CFG_DB_USER',      ''));
define('DD_DB_PASS',      dd_env('DB_PASS',      'CFG_DB_PASS',      ''));
define('DD_TOKEN_SECRET', dd_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

if (!DD_DB_PASS || !DD_TOKEN_SECRET) dd_json_fail('Server misconfigured - check api/.env', 500);

function dd_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = isset($hdrs['Authorization']) ? $hdrs['Authorization'] : '';
    }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}
function dd_verify_token($token) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null;
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, DD_TOKEN_SECRET);
    return ($sig === $expected) ? array('uid' => intval($uid), 'version' => intval($version)) : null;
}

$token = dd_get_bearer();
if (!$token) dd_json_fail('Unauthorised - no token', 401);
$tok = dd_verify_token($token);
if (!$tok) dd_json_fail('Unauthorised - invalid or expired token', 401);

try {
    $pdo = new PDO(
        'mysql:host=' . DD_DB_HOST . ';dbname=' . DD_DB_NAME . ';charset=utf8mb4',
        DD_DB_USER, DD_DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) { dd_json_fail('DB connection failed', 500); }

$has_ver = false;
try {
    $c = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_version'");
    $c->execute();
    $has_ver = (bool) $c->fetch();
} catch (Exception $e) { $has_ver = false; }

try {
    $s = $pdo->prepare('SELECT id, role' . ($has_ver ? ', token_version' : '') . ' FROM users WHERE id = ? LIMIT 1');
    $s->execute(array($tok['uid']));
    $user = $s->fetch();
} catch (Exception $e) { $user = null; }

if (!$user) dd_json_fail('User not found', 401);
if ($has_ver && intval($user['token_version']) !== $tok['version']) dd_json_fail('Unauthorised - token revoked', 401);
if ($user['role'] !== 'admin') dd_json_fail('Admin access required', 403);

$managed = false;
try {
    $c2 = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_etelgroup_managed'");
    $c2->execute();
    $managed = (bool) $c2->fetch();
} catch (Exception $e) { $managed = false; }
if ($managed) {
    $s2 = $pdo->prepare('SELECT is_etelgroup_managed, staff_role FROM users WHERE id = ? LIMIT 1');
    $s2->execute(array($tok['uid']));
    $mgmt = $s2->fetch();
    if ($mgmt && !empty($mgmt['is_etelgroup_managed'])) {
        $caps = array();
        if (!empty($mgmt['staff_role'])) {
            try {
                $rp = $pdo->prepare('SELECT capabilities FROM role_permissions WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
                $rp->execute(array($mgmt['staff_role']));
                $rrow = $rp->fetch();
                if ($rrow) $caps = json_decode($rrow['capabilities'], true) ?: array();
            } catch (Exception $e) { $caps = array(); }
        }
        if (!in_array('*', $caps, true) && !in_array('documents.view', $caps, true)) {
            dd_json_fail("You don't have permission to do this (requires: documents.view)", 403);
        }
    }
}

require_once __DIR__ . '/lib/documents_storage.php';

$docId = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
$versionId = isset($_GET['version_id']) ? intval($_GET['version_id']) : 0;
$disposition = (isset($_GET['disposition']) && $_GET['disposition'] === 'attachment') ? 'attachment' : 'inline';
if (!$docId) dd_json_fail('document_id is required', 400);

try {
    $s = $pdo->prepare('SELECT * FROM admin_documents WHERE id=? AND deleted_at IS NULL');
    $s->execute(array($docId));
    $doc = $s->fetch();
    if (!$doc) dd_json_fail('Document not found', 404);

    $storageName = $doc['storage_filename'];
    $downloadName = $doc['original_filename'];
    $mime = $doc['mime_type'];

    if ($versionId) {
        $vs = $pdo->prepare('SELECT * FROM admin_document_versions WHERE id=? AND document_id=?');
        $vs->execute(array($versionId, $docId));
        $ver = $vs->fetch();
        if (!$ver) dd_json_fail('Version not found', 404);
        $storageName = $ver['storage_filename'];
        $downloadName = pathinfo($doc['original_filename'], PATHINFO_FILENAME) . '_v' . $ver['version_number'] . '.' . $doc['file_ext'];
    }
} catch (Exception $e) {
    dd_json_fail('Could not load document', 500);
}

$path = etel_docs_storage_dir() . '/' . basename($storageName);
if (!is_file($path)) dd_json_fail('File is missing on disk', 404);

// An .html/.htm/.js document served inline (the default - see the
// admin-documents.js iframe viewer) would execute in this app's own
// origin with the viewing admin's real session (bearer token lives in
// localStorage - see core.js). Any account with documents.manage
// (not necessarily a full owner-tier admin) could otherwise plant a
// document that steals a HIGHER-privileged admin's token the moment
// they open it to view. Force a real download for these regardless of
// the requested disposition - the client can never opt back into
// inline rendering for a type that can carry script. Derived from the
// actual file about to be streamed ($storageName, server-generated as
// "doc_<ts>_<hex>.<ext>" - see etel_docs_new_storage_name()), not
// $doc['file_ext'], which only reflects the CURRENT version and can
// disagree with an older version fetched via version_id.
$streamedExt = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
if (in_array($streamedExt, array('html', 'htm', 'js'), true)) {
    $disposition = 'attachment';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');
readfile($path);
exit;
