<?php
/**
 * ኢE Pay — Updater API v1.0
 * Deploy files to the server without cPanel.
 * Actions: login, list_files, read_file, update_file,
 *          upload_file, upload_zip, upload_multiple,
 *          list_backups, restore_backup, delete_backup, ping
 */

/* ── Fatal guard ─────────────────────────────────────────────── */
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('success' => false, 'message' => 'PHP fatal: ' . $e['message'] . ' (line ' . $e['line'] . ')'));
    } else {
        ob_end_flush();
    }
});

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/* ── Config ──────────────────────────────────────────────────── */
$_cfg = __DIR__ . '/config.php';
if (file_exists($_cfg)) require_once $_cfg;
$_env = __DIR__ . '/.env';
if (file_exists($_env)) { $ev = parse_ini_file($_env); if ($ev) foreach ($ev as $k => $v) { if (!getenv($k)) putenv("$k=$v"); } }

if (!defined('EP_UPDATER_PASS')) define('EP_UPDATER_PASS', getenv('EP_UPDATER_PASS') ?: 'changeme');
if (!defined('EP_SECRET'))       define('EP_SECRET',       getenv('EP_SECRET')       ?: 'changeme');
if (!defined('EP_TBL_PREFIX'))   define('EP_TBL_PREFIX',   'Etelpay_');
if (!defined('EP_TBL_UPDLOG'))   define('EP_TBL_UPDLOG',   EP_TBL_PREFIX . 'ep_updater_logs');

$BASE_DIR   = dirname(__DIR__);           // /Etel.Pay/wallet/
$BACKUP_DIR = $BASE_DIR . '/backups';

$ALLOWED_EXT    = array('php', 'html', 'js', 'css', 'json', 'md', 'txt', 'sql', 'svg', 'htaccess');
// .htaccess entries included so a compromised/brute-forced Updater session cannot
// disable the deny-all rules protecting config.php/.env and the backups directory.
$PROTECTED_FILES = array('api/config.php', 'api/.env', 'api/.htaccess', 'backups/.htaccess', '.htaccess');   // never overwritten

if (!file_exists($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0755, true);

/* ── Helpers ─────────────────────────────────────────────────── */
function upd_ok($data = null, $msg = '') { echo json_encode(array('success' => true, 'data' => $data, 'message' => $msg)); exit; }
function upd_err($msg, $code = 400) { http_response_code($code); echo json_encode(array('success' => false, 'message' => $msg)); exit; }

/* ── Token sign / verify (same HMAC pattern as delivery) ──────── */
function upd_sign_token($pass) {
    $ts  = time();
    $sig = hash_hmac('sha256', 'upd:' . $ts, $pass);
    return base64_encode(json_encode(array('v'=>1,'ts'=>$ts,'sig'=>$sig)));
}
// Same placeholder rejection as the login action below - checking this
// only at login and not here left a bypass: anyone who knows the public
// default password could self-sign a valid token with upd_sign_token()
// and hit any authenticated action directly, without ever calling login.
// Comparison must be case-insensitive: config.php ships EP_UPDATER_PASS
// as the literal 'CHANGE_ME' (uppercase) - the old check here only
// rejected the lowercase 'changeme', so a fresh checkout whose config.php
// was never edited would treat 'CHANGE_ME' as a valid, configured
// password and grant full file-deploy (arbitrary PHP write) access to
// anyone who tried that known placeholder.
function upd_password_is_configured($pass) {
    $p = strtolower(trim((string)$pass));
    return strlen($p) >= 4 && $p !== 'changeme' && $p !== 'change_this_updater_password' && $p !== 'change-me';
}
function upd_verify_token($token) {
    if (!$token) return false;
    if (!upd_password_is_configured(EP_UPDATER_PASS)) return false;
    $d = @json_decode(@base64_decode($token), true);
    if (!$d || !isset($d['ts'], $d['sig'])) return false;
    if ((time() - (int)$d['ts']) > 86400) return false;
    $expected = hash_hmac('sha256', 'upd:' . $d['ts'], EP_UPDATER_PASS);
    return hash_equals($expected, $d['sig']);
}
function upd_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) { $hh = getallheaders(); $h = isset($hh['Authorization']) ? $hh['Authorization'] : ''; }
    global $_body;
    // Check JSON body
    if (!$h && isset($_body['token'])) return trim($_body['token']);
    // Check FormData POST (multipart uploads)
    if (!$h && isset($_POST['token'])) return trim($_POST['token']);
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}
function upd_require_auth() {
    $t = upd_get_bearer();
    if (!upd_verify_token($t)) upd_err('Unauthorized — invalid or expired token', 401);
}

/* ── Auto-detect subfolder from filename ─────────────────────── */
function upd_detect_path($name) {
    $base  = basename($name);
    $known = array(
        'v2_verify.php'         => 'api/v2_verify.php',
        'updater-api.php' => 'api/updater-api.php',
        'config.php'      => 'api/config.php',
        'core.js'         => 'js/core.js',
        'api.js'          => 'js/api.js',
        'config.js'       => 'js/config.js',
        'pay.css'         => 'css/pay.css',
        'sw.js'           => 'sw.js',
        'manifest.json'   => 'manifest.json',
        '.htaccess'       => '.htaccess',
    );
    if (isset($known[$base])) return $known[$base];
    $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $dirs = array('js' => 'js/', 'css' => 'css/', 'php' => 'api/', 'sql' => 'api/');
    return (isset($dirs[$ext]) ? $dirs[$ext] : '') . $base;
}

/* ── Backup a file before overwriting ────────────────────────── */
function upd_backup($full_path, $BACKUP_DIR) {
    if (!file_exists($full_path)) return false;
    $rel  = str_replace(array('/', '\\'), '_', ltrim(str_replace(dirname(dirname($full_path)), '', $full_path), '/\\'));
    $dest = $BACKUP_DIR . '/' . date('Ymd_His') . '_' . $rel;
    return @copy($full_path, $dest) ? $dest : false;
}

/* ── Logging ─────────────────────────────────────────────────── */
function upd_log($action, $file) {
    try {
        // Lazy-create log table
        if (!defined('EP_DB_HOST')) return;
        $pdo = new PDO(
            'mysql:host=' . EP_DB_HOST . ';dbname=' . EP_DB_NAME . ';charset=utf8mb4',
            EP_DB_USER, EP_DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . EP_TBL_UPDLOG . '` (
            `id`         INT(11) NOT NULL AUTO_INCREMENT,
            `action`     VARCHAR(50) NOT NULL,
            `file_path`  VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $pdo->prepare('INSERT INTO `' . EP_TBL_UPDLOG . '` (action, file_path, ip_address) VALUES (?, ?, ?)')->execute(array($action, $file, $ip));
    } catch (Exception $e) {
        error_log('[EtelPay Updater] upd_log failed for action=' . $action . ' file=' . $file . ': ' . $e->getMessage());
    }
}

/* -- Login brute-force lockout ----------------------------------
   Unlike the main wallet's ep_login, this password check had no
   attempt tracking at all - brute-forceable at whatever rate the
   host tolerates. Single shared password, so limiting is per-IP
   only (no separate account to key on). */
function upd_login_db() {
    if (!defined('EP_DB_HOST')) return null;
    try {
        return new PDO(
            'mysql:host=' . EP_DB_HOST . ';dbname=' . EP_DB_NAME . ';charset=utf8mb4',
            EP_DB_USER, EP_DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
    } catch (Exception $e) {
        error_log('[EtelPay Updater] DB connect failed: ' . $e->getMessage());
        return null;
    }
}
function upd_ensure_login_attempts_table($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . EP_TBL_PREFIX . 'ep_updater_login_attempts` (
        `id`         INT(11) NOT NULL AUTO_INCREMENT,
        `ip_address` VARCHAR(45) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function upd_login_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}
function upd_login_rate_limited() {
    $pdo = upd_login_db();
    if (!$pdo) return false; // fail open only if DB is unreachable, matching upd_log's own fallback
    try {
        upd_ensure_login_attempts_table($pdo);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM `' . EP_TBL_PREFIX . 'ep_updater_login_attempts`
             WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute(array(upd_login_ip()));
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) {
        error_log('[EtelPay Updater] rate-limit check failed: ' . $e->getMessage());
        return false;
    }
}
function upd_record_failed_login() {
    $pdo = upd_login_db();
    if (!$pdo) return;
    try {
        upd_ensure_login_attempts_table($pdo);
        $pdo->prepare('INSERT INTO `' . EP_TBL_PREFIX . 'ep_updater_login_attempts` (ip_address) VALUES (?)')
            ->execute(array(upd_login_ip()));
    } catch (Exception $e) {
        error_log('[EtelPay Updater] failed-login record failed: ' . $e->getMessage());
    }
}

/* ── Pure-PHP ZIP reader (no ZipArchive needed) ─────────────── */
function upd_read_zip_entries($data) {
    $entries = array();
    $len     = strlen($data);
    // Find end-of-central-directory record
    for ($i = $len - 22; $i >= 0; $i--) {
        if (substr($data, $i, 4) === "\x50\x4b\x05\x06") {
            $eocd = unpack('vtotal/Voffset', substr($data, $i + 10 - 2, 6));
            $off  = $eocd['offset'];
            break;
        }
    }
    if (!isset($off)) return $entries;
    while ($off < $len) {
        if (substr($data, $off, 4) !== "\x50\x4b\x01\x02") break;
        $h        = unpack('vmethod/x2/x2/x2/x4/Vcrc/Vcsize/Vsize/vflen/veflen/vclen', substr($data, $off + 10, 26));
        $fname    = substr($data, $off + 46, $h['vflen']);
        $comment  = substr($data, $off + 46 + $h['vflen'] + $h['veflen'], $h['vclen']);
        $lhoff    = unpack('V', substr($data, $off + 42, 4));
        $lhoff    = $lhoff[1];
        // Local file header
        $lh_fn    = unpack('vflen/veflen', substr($data, $lhoff + 26, 4));
        $doff     = $lhoff + 30 + $lh_fn['vflen'] + $lh_fn['veflen'];
        $entries[] = array(
            'name'   => $fname,
            'method' => $h['vmethod'],
            'csize'  => $h['vcsize'],
            'size'   => $h['vsize'],
            'crc'    => $h['vcrc'],
            'doff'   => $doff,
        );
        $off += 46 + $h['vflen'] + $h['veflen'] + $h['vclen'];
    }
    return $entries;
}

function upd_extract_entry($data, $entry) {
    $raw = substr($data, $entry['doff'], $entry['csize']);
    if ($entry['method'] === 0)  return $raw;                           // stored
    if ($entry['method'] === 8)  return gzinflate($raw);               // deflated
    return false;
}

/* ── ZIP deploy (shared) ──────────────────────────────────────── */
function upd_do_zip_deploy($zf, $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXT, $PROTECTED_FILES) {
    if (strtolower(pathinfo($zf['name'], PATHINFO_EXTENSION)) !== 'zip') upd_err('Only .zip files accepted');
    $zdata = file_get_contents($zf['tmp_name']);
    if (!$zdata) upd_err('Cannot read uploaded ZIP');

    $entries = upd_read_zip_entries($zdata);
    if (!$entries) upd_err('ZIP appears empty or corrupt');

    $results = array(); $skipped = array(); $errors = array();

    foreach ($entries as $ent) {
        $name = $ent['name'];
        // Skip directories, git, backups
        if (substr($name, -1) === '/') continue;
        if (preg_match('#^(backups/|\.git/|\.vscode/)#', $name)) { $skipped[] = $name; continue; }
        // Strip leading folder (e.g. wallet/) if present
        $rel = preg_replace('#^[^/]+/#', '', $name);
        if (!$rel) continue;

        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, $ALLOWED_EXT, true)) { $skipped[] = $rel; continue; }
        if (in_array($rel, $PROTECTED_FILES, true)) { $skipped[] = $rel . ' (protected)'; continue; }

        $dest = realpath($BASE_DIR) . '/' . $rel;
        // Security: no path traversal
        if (strpos($dest, realpath($BASE_DIR)) !== 0) { $skipped[] = $rel . ' (traversal)'; continue; }

        $dir = dirname($dest);
        if (!file_exists($dir)) @mkdir($dir, 0755, true);
        upd_backup($dest, $BACKUP_DIR);

        $content = upd_extract_entry($zdata, $ent);
        if ($content === false) { $errors[] = $rel . ' (decompress failed)'; continue; }
        if (file_put_contents($dest, $content) !== false) {
            $results[] = array('file' => $rel, 'size' => strlen($content));
        } else {
            $errors[] = $rel . ' (write failed)';
        }
    }

    upd_log('upload_zip', $zf['name'] . ' (' . count($results) . ' files)');
    upd_ok(array(
        'mode'      => 'zip_extracted',
        'extracted' => count($results),
        'skipped'   => count($skipped),
        'errors'    => $errors,
        'files'     => $results,
        'time'      => date('Y-m-d H:i:s'),
    ), count($results) . ' files deployed from ZIP');
}

/* ── Input ───────────────────────────────────────────────────── */
$_raw  = file_get_contents('php://input');
$_body = array();
if ($_raw) { $j = json_decode($_raw, true); if (is_array($j)) $_body = $j; }
$action = isset($_GET['action'])  ? trim($_GET['action'])  :
         (isset($_POST['action']) ? trim($_POST['action']) :
         (isset($_body['action']) ? trim($_body['action']) : ''));

/* ══════════════════════════════════════════════════════════════
   ROUTER
   ══════════════════════════════════════════════════════════════ */

switch ($action) {

/* ── Ping (no auth) ──────────────────────────────────────────── */
case 'ping':
    upd_ok(array('version' => defined('EP_APP_VERSION') ? EP_APP_VERSION : '1.0.1', 'time' => date('Y-m-d H:i:s'), 'php' => PHP_VERSION), 'ኢE Pay Updater ready');
    // upd_ok exits, but add break for safety
    break;

/* ── Login ────────────────────────────────────────────────────── */
case 'login': {
    if (upd_login_rate_limited()) upd_err('Too many attempts. Please wait a while and try again.', 429);
    $pass = isset($_body['password']) ? trim($_body['password']) : '';
    if (!$pass) upd_err('Password required', 400);
    $expected = defined('EP_UPDATER_PASS') ? EP_UPDATER_PASS : '';
    if (!upd_password_is_configured($expected)) {
        upd_err('Updater password not configured — set EP_UPDATER_PASS in config.php', 503);
    }
    if (!hash_equals($expected, $pass)) { upd_record_failed_login(); upd_err('Wrong password', 401); }
    $token = upd_sign_token($pass);
    upd_ok(array('token' => $token, 'expires_in' => 86400), 'Authenticated');
}

/* ── List files ───────────────────────────────────────────────── */
case 'list_files': {
    upd_require_auth();
    $sub  = isset($_body['path']) ? trim($_body['path']) : '';
    $dir  = realpath($BASE_DIR . '/' . ltrim($sub, '/'));
    if (!$dir || strpos($dir, realpath($BASE_DIR)) !== 0) upd_err('Invalid path');
    $out  = array();
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        $rel  = str_replace($BASE_DIR . DIRECTORY_SEPARATOR, '', $f->getPathname());
        $rel  = str_replace('\\', '/', $rel);
        $base = basename($rel);
        if (in_array($base, array('.git', 'backups', 'node_modules', 'uploads'))) continue;
        if ($base[0] === '.' && $base !== '.htaccess') continue;
        $out[] = array(
            'path'     => $rel,
            'size'     => $f->getSize(),
            'size_kb'  => round($f->getSize() / 1024, 1),
            'modified' => date('Y-m-d H:i:s', $f->getMTime()),
            'ext'      => strtolower($f->getExtension()),
        );
    }
    usort($out, function($a, $b) { return strcmp($a['path'], $b['path']); });
    upd_ok(array('files' => $out, 'count' => count($out)));
}

/* ── Read file ────────────────────────────────────────────────── */
case 'read_file': {
    upd_require_auth();
    $rel  = isset($_body['path']) ? ltrim($_body['path'], '/') : '';
    if (in_array($rel, $PROTECTED_FILES, true)) upd_err('File is protected', 403);
    $full = realpath($BASE_DIR . '/' . $rel);
    if (!$full || strpos($full, realpath($BASE_DIR)) !== 0) upd_err('Invalid path');
    if (!file_exists($full) || is_dir($full)) upd_err('File not found', 404);
    $content = file_get_contents($full);
    upd_ok(array('path' => $rel, 'content' => $content, 'size' => strlen($content), 'modified' => date('Y-m-d H:i:s', filemtime($full))));
}

/* ── Update file (inline edit) ───────────────────────────────── */
case 'update_file': {
    upd_require_auth();
    $rel     = isset($_body['path'])    ? ltrim($_body['path'], '/') : '';
    $content = isset($_body['content']) ? $_body['content']          : null;
    if (!$rel || $content === null) upd_err('Path and content required');
    if (in_array($rel, $PROTECTED_FILES, true)) upd_err('File is protected');
    $full = $BASE_DIR . '/' . $rel;
    $real = realpath($full) ?: $full;
    if (strpos(dirname($real), realpath($BASE_DIR)) !== 0) upd_err('Path traversal denied');
    if (!file_exists(dirname($full))) @mkdir(dirname($full), 0755, true);
    $backed = upd_backup($full, $BACKUP_DIR);
    if (file_put_contents($full, $content) === false) upd_err('Write failed — check permissions', 500);
    upd_log('update_file', $rel);
    upd_ok(array('written' => $rel, 'size' => strlen($content), 'backed_up' => $backed !== false, 'time' => date('Y-m-d H:i:s')), $rel . ' saved');
}

/* ── Upload single file ───────────────────────────────────────── */
case 'upload_file': {
    upd_require_auth();
    if (empty($_FILES['file'])) upd_err('No file uploaded');
    $f    = $_FILES['file'];
    $tp   = isset($_POST['target_path']) ? ltrim(trim($_POST['target_path']), '/') : upd_detect_path($f['name']);
    if (in_array($tp, $PROTECTED_FILES, true)) upd_err('File is protected');
    $ext  = strtolower(pathinfo($tp, PATHINFO_EXTENSION));
    if (!in_array($ext, array_merge($ALLOWED_EXT, array('png','jpg','jpeg','gif','ico','webp','zip')), true)) upd_err('File type not allowed');
    if ($ext === 'zip') {
        upd_do_zip_deploy($f, $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXT, $PROTECTED_FILES);
    }
    $full = $BASE_DIR . '/' . $tp;
    if (strpos(realpath(dirname($full)) ?: dirname($full), realpath($BASE_DIR)) !== 0) upd_err('Path traversal denied');
    if (!file_exists(dirname($full))) @mkdir(dirname($full), 0755, true);
    $backed = upd_backup($full, $BACKUP_DIR);
    if (!move_uploaded_file($f['tmp_name'], $full)) upd_err('Deploy failed — check permissions', 500);
    upd_log('upload_file', $tp);
    upd_ok(array('written' => $tp, 'size' => filesize($full), 'size_kb' => round(filesize($full)/1024,1), 'backed_up' => $backed !== false, 'time' => date('Y-m-d H:i:s')), $tp . ' deployed');
}

/* ── Upload ZIP ────────────────────────────────────────────────── */
case 'upload_zip': {
    upd_require_auth();
    if (empty($_FILES['file'])) upd_err('No file uploaded');
    upd_do_zip_deploy($_FILES['file'], $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXT, $PROTECTED_FILES);
}

/* ── Upload multiple files ────────────────────────────────────── */
case 'upload_multiple': {
    upd_require_auth();
    if (empty($_FILES['files'])) upd_err('No files uploaded');
    $ff    = $_FILES['files'];
    $total = count($ff['name']);
    $ok    = array(); $errs = array();
    for ($i = 0; $i < $total; $i++) {
        $name = $ff['name'][$i];
        $tmp  = $ff['tmp_name'][$i];
        $tp   = upd_detect_path($name);
        if (in_array($tp, $PROTECTED_FILES, true)) { $errs[] = $name . ' (protected)'; continue; }
        $ext  = strtolower(pathinfo($tp, PATHINFO_EXTENSION));
        if (!in_array($ext, $ALLOWED_EXT, true)) { $errs[] = $name . ' (type not allowed)'; continue; }
        $full = $BASE_DIR . '/' . $tp;
        if (!file_exists(dirname($full))) @mkdir(dirname($full), 0755, true);
        upd_backup($full, $BACKUP_DIR);
        if (move_uploaded_file($tmp, $full)) { $ok[] = $tp; }
        else { $errs[] = $name . ' (write failed)'; }
    }
    upd_log('upload_multiple', count($ok) . ' files');
    upd_ok(array('uploaded' => count($ok), 'total' => $total, 'errors' => $errs, 'files' => $ok, 'time' => date('Y-m-d H:i:s')), count($ok) . '/' . $total . ' files deployed');
}

/* ── List backups ─────────────────────────────────────────────── */
case 'list_backups': {
    upd_require_auth();
    $files = glob($BACKUP_DIR . '/*');
    $out   = array();
    if ($files) foreach ($files as $f) {
        $out[] = array('name' => basename($f), 'size' => filesize($f), 'size_kb' => round(filesize($f)/1024,1), 'modified' => date('Y-m-d H:i:s', filemtime($f)));
    }
    usort($out, function($a,$b){ return strcmp($b['modified'],$a['modified']); });
    upd_ok(array('backups' => $out, 'count' => count($out)));
}

/* ── Restore backup ───────────────────────────────────────────── */
case 'restore_backup': {
    upd_require_auth();
    $name = basename(isset($_body['name']) ? $_body['name'] : '');
    $src  = $BACKUP_DIR . '/' . $name;
    if (!$name || !file_exists($src)) upd_err('Backup not found', 404);
    // Re-derive original path from backup filename: YYYYMMDD_HHiiss_rel_path
    $rel  = preg_replace('/^\d{8}_\d{6}_/', '', $name);
    $rel  = str_replace('_', '/', $rel);
    $full = $BASE_DIR . '/' . $rel;
    if (!file_exists(dirname($full))) @mkdir(dirname($full), 0755, true);
    if (!copy($src, $full)) upd_err('Restore failed', 500);
    upd_log('restore_backup', $rel);
    upd_ok(array('restored' => $rel, 'time' => date('Y-m-d H:i:s')), $rel . ' restored');
}

/* ── Delete backup ────────────────────────────────────────────── */
case 'delete_backup': {
    upd_require_auth();
    $name = basename(isset($_body['name']) ? $_body['name'] : '');
    $path = $BACKUP_DIR . '/' . $name;
    if (!$name || !file_exists($path)) upd_err('Backup not found', 404);
    unlink($path);
    upd_ok(null, 'Backup deleted');
}


/* ── Run SQL ─────────────────────────────────────────────── */
case 'run_sql': {
    upd_require_auth();
    $sql = isset($_body['sql']) ? trim($_body['sql']) : '';
    if (!$sql) upd_err('No SQL provided');
    // Safety: block destructive ops unless force flag set
    $force = isset($_body['force']) && $_body['force'] === true;
    $upper = strtoupper(substr(ltrim($sql), 0, 6));
    if (in_array($upper, array('DROP T', 'TRUNCA')) && !$force) {
        upd_err('Destructive SQL blocked. Send force:true to override.');
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . EP_DB_HOST . ';dbname=' . EP_DB_NAME . ';charset=utf8mb4',
            EP_DB_USER, EP_DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        // Support multiple statements separated by ;
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $results = array();
        $affected = 0;
        foreach ($stmts as $s) {
            if (!$s) continue;
            $st = $pdo->prepare($s);
            $st->execute();
            $rows = $st->rowCount();
            $affected += $rows;
            // For SELECT, return data
            $up = strtoupper(substr(ltrim($s), 0, 6));
            if (in_array($up, array('SELECT', 'SHOW  ', 'DESCRI'))) {
                $results[] = array('sql' => substr($s, 0, 60), 'rows' => $st->fetchAll(PDO::FETCH_ASSOC));
            } else {
                $results[] = array('sql' => substr($s, 0, 60), 'affected' => $rows);
            }
        }
        upd_ok(array('statements' => count($stmts), 'affected' => $affected, 'results' => $results), 'SQL executed OK');
    } catch (Exception $e) {
        upd_err('SQL error: ' . $e->getMessage(), 500);
    }
}

/* ── Get version/config info ───────────────────────────────────── */
case 'info': {
    upd_require_auth();
    upd_ok(array(
        'version'  => defined('EP_APP_VERSION') ? EP_APP_VERSION : '1.0.0',
        'php'      => PHP_VERSION,
        'db_host'  => EP_DB_HOST,
        'db_name'  => EP_DB_NAME,
        'base_dir' => $BASE_DIR,
        'time'     => date('Y-m-d H:i:s'),
    ), 'System info');
}

/* ── Unknown ────────────────────────────────────────────── */
default:
    upd_err('Unknown updater action: ' . htmlspecialchars($action), 404);
}
