<?php
/**
 * ETEL Admin Documents - upload / replace handler
 * POST multipart with Authorization: Bearer <admin token> and fields:
 *   action        - "upload" (new document) or "replace" (new version of an existing one)
 *   file          - the uploaded file
 *   title         - required for action=upload
 *   category      - optional, defaults to "other"
 *   description   - optional
 *   document_id   - required for action=replace
 *   change_note   - optional, for action=replace
 *
 * Mirrors api/upload.php and api/admin-branding.php's config/env/token
 * pattern exactly - boot.php's JSON-only body parsing can't handle
 * multipart file uploads at all, so this stays standalone like those two.
 *
 * Returns JSON { success, data: { document_id, version } }
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
$_du_env = array();
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
                $_du_env[$k] = $v;
            }
        }
    }
}
function du_env($key, $const, $default = '') {
    global $_du_env;
    if (isset($_du_env[$key]) && $_du_env[$key] !== '') return $_du_env[$key];
    $g = getenv($key);
    if ($g !== false && $g !== '') return $g;
    if (defined($const) && constant($const) !== '') return constant($const);
    return $default;
}

define('DU_DB_HOST',      du_env('DB_HOST',      'CFG_DB_HOST',      'localhost'));
define('DU_DB_NAME',      du_env('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
define('DU_DB_USER',      du_env('DB_USER',      'CFG_DB_USER',      ''));
define('DU_DB_PASS',      du_env('DB_PASS',      'CFG_DB_PASS',      ''));
define('DU_TOKEN_SECRET', du_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

if (!DU_DB_PASS || !DU_TOKEN_SECRET) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Server misconfigured - check api/.env'));
    exit;
}

function dufail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(array('success' => false, 'message' => $msg));
    exit;
}
function duok($data) {
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

function du_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = isset($hdrs['Authorization']) ? $hdrs['Authorization'] : '';
    }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}
function du_verify_token($token) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null;
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, DU_TOKEN_SECRET);
    return ($sig === $expected) ? array('uid' => intval($uid), 'version' => intval($version)) : null;
}

$token = du_get_bearer();
if (!$token) dufail('Unauthorised - no token', 401);
$tok = du_verify_token($token);
if (!$tok) dufail('Unauthorised - invalid or expired token', 401);

try {
    $pdo = new PDO(
        'mysql:host=' . DU_DB_HOST . ';dbname=' . DU_DB_NAME . ';charset=utf8mb4',
        DU_DB_USER, DU_DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) { dufail('DB connection failed', 500); }

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

if (!$user) dufail('User not found', 401);
if ($has_ver && intval($user['token_version']) !== $tok['version']) dufail('Unauthorised - token revoked', 401);
if ($user['role'] !== 'admin') dufail('Admin access required', 403);

/* documents.manage capability check - same inline pattern as
 * admin-branding.php's settings.manage check (this script stays
 * standalone for multipart-upload support, so it can't require
 * boot.php's require_admin() directly). */
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
        if (!in_array('*', $caps, true) && !in_array('documents.manage', $caps, true)) {
            dufail("You don't have permission to do this (requires: documents.manage)", 403);
        }
    }
}

require_once __DIR__ . '/lib/documents_storage.php';

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if (!in_array($action, array('upload', 'replace'), true)) dufail('action must be "upload" or "replace"', 400);

if (empty($_FILES['file'])) dufail('No file in request (field name: "file")', 400);
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) dufail('Upload error code: ' . $file['error'], 400);
if ($file['size'] > 20 * 1024 * 1024) dufail('Max file size is 20 MB', 400);
if ($file['size'] <= 0) dufail('Uploaded file is empty', 400);

$origName = isset($file['name']) ? $file['name'] : 'file';
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!etel_docs_is_known_ext($ext)) {
    dufail('Unsupported file type ".' . $ext . '". Allowed: PDF, Word/Excel/PowerPoint (old and new formats), OpenDocument, Markdown, TXT, XML, .rels, CSV, JSON, YAML, HTML, CSS, JS.', 400);
}

/* Reject anything whose real content looks like a script/executable
 * regardless of its extension - defense in depth against a disguised
 * double-extension trick (e.g. "report.md.php"), on top of pathinfo()
 * already only ever looking at the LAST extension. Office/OOXML files
 * (docx/pptx/xlsx) are themselves zip containers, and different PHP
 * builds' fileinfo magic databases report them inconsistently
 * (sometimes the precise OOXML mime, sometimes plain "application/zip"),
 * so this checks only for clearly dangerous mime substrings rather than
 * requiring an exact mime-per-extension match. */
$sniffed = @mime_content_type($file['tmp_name']);
$dangerous = array('php', 'x-httpd', 'x-executable', 'x-sh', 'x-python', 'x-perl', 'x-msdownload');
if ($sniffed) {
    foreach ($dangerous as $bad) {
        if (strpos($sniffed, $bad) !== false) dufail('This file was rejected as an unsafe type: ' . $sniffed, 400);
    }
}

$isText = etel_docs_is_text_ext($ext);
$mime = etel_docs_mime_for_ext($ext);
$storageName = etel_docs_new_storage_name($ext);
$dest = etel_docs_storage_dir() . '/' . $storageName;
if (!move_uploaded_file($file['tmp_name'], $dest)) dufail('Failed to save file', 500);
$size = filesize($dest);

if ($action === 'upload') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    if ($title === '') { @unlink($dest); dufail('title is required', 400); }
    $category = isset($_POST['category']) ? trim($_POST['category']) : 'other';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO admin_documents
            (title, category, description, original_filename, file_ext, mime_type, is_text_editable, storage_filename, file_size, current_version, uploaded_by)
            VALUES (?,?,?,?,?,?,?,?,?,1,?)');
        $ins->execute(array($title, $category ?: 'other', $description, $origName, $ext, $mime, $isText ? 1 : 0, $storageName, $size, $user['id']));
        $docId = intval($pdo->lastInsertId());
        $pdo->prepare('INSERT INTO admin_document_versions (document_id, version_number, storage_filename, file_size, change_note, uploaded_by) VALUES (?,1,?,?,?,?)')
            ->execute(array($docId, $storageName, $size, 'Initial upload', $user['id']));
        $pdo->commit();
        duok(array('message' => 'Document uploaded', 'document_id' => $docId, 'version' => 1));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($dest);
        error_log('[ETEL][documents_upload] upload failed: ' . $e->getMessage());
        dufail('Could not save document metadata', 500);
    }
} else { // replace
    $docId = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
    $note = isset($_POST['change_note']) ? trim($_POST['change_note']) : '';
    if (!$docId) { @unlink($dest); dufail('document_id is required for replace', 400); }
    try {
        $s = $pdo->prepare('SELECT current_version FROM admin_documents WHERE id=? AND deleted_at IS NULL');
        $s->execute(array($docId));
        $doc = $s->fetch();
        if (!$doc) { @unlink($dest); dufail('Document not found', 404); }
        $newVersion = intval($doc['current_version']) + 1;

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE admin_documents SET original_filename=?, file_ext=?, mime_type=?, is_text_editable=?, storage_filename=?, file_size=?, current_version=? WHERE id=?')
            ->execute(array($origName, $ext, $mime, $isText ? 1 : 0, $storageName, $size, $newVersion, $docId));
        $pdo->prepare('INSERT INTO admin_document_versions (document_id, version_number, storage_filename, file_size, change_note, uploaded_by) VALUES (?,?,?,?,?,?)')
            ->execute(array($docId, $newVersion, $storageName, $size, ($note !== '' ? $note : 'Replaced'), $user['id']));
        $pdo->commit();
        duok(array('message' => 'New version uploaded', 'document_id' => $docId, 'version' => $newVersion));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($dest);
        error_log('[ETEL][documents_upload] replace failed: ' . $e->getMessage());
        dufail('Could not save new version', 500);
    }
}
