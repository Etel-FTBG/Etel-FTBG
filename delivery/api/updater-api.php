<?php
/* ETEL Delivery - File Updater API */

/* Catch PHP fatal errors, always return JSON */
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'PHP error: ' . $e['message'] . ' (line ' . $e['line'] . ')']);
    } else {
        ob_end_flush();
    }
});

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
/* This endpoint can read/write/deploy arbitrary files with admin auth -
   a wildcard CORS origin let any third-party page that obtained a stolen
   token (e.g. via XSS elsewhere) read source code / responses cross-site.
   Restrict to the site's own origin. */
$_allowed_origins = ['https://ethelmarket.com', 'https://www.ethelmarket.com'];
$_req_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($_req_origin, $_allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $_req_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/*  Load credentials 
   Priority: .env file (direct parse) > putenv > config.php constants
   This robust loader handles cPanel shared hosting where putenv()
   values are sometimes not visible via getenv() due to php-fpm pools.
    */

/* Step 1: load config.php constants (may be blank template now) */
$_cfg = __DIR__ . '/config.php';
if (file_exists($_cfg)) require_once $_cfg;

/* Step 2: parse .env with bulletproof line-by-line reader */
$_env = __DIR__ . '/.env';
$_env_vals = array();
if (file_exists($_env)) {
    $lines = @file($_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
                (($v[0] === '"' && $v[strlen($v)-1] === '"') ||
                 ($v[0] === "'" && $v[strlen($v)-1] === "'"))) {
                $v = substr($v, 1, strlen($v) - 2);
            }
            if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                $_env_vals[$k] = $v;
            }
        }
    }
}

/* Step 3: helper */
function upd_cfg($key, $cfg_const, $default = '') {
    global $_env_vals;
    if (isset($_env_vals[$key]) && $_env_vals[$key] !== '') return $_env_vals[$key];
    $ge = getenv($key);
    if ($ge !== false && $ge !== '') return $ge;
    if (defined($cfg_const) && constant($cfg_const) !== '') return constant($cfg_const);
    return $default;
}

if (!defined('UPD_DB_HOST'))  define('UPD_DB_HOST',  upd_cfg('DB_HOST',      'CFG_DB_HOST',      'localhost'));
if (!defined('UPD_DB_NAME'))  define('UPD_DB_NAME',  upd_cfg('DB_NAME',      'CFG_DB_NAME',      'etel_delivery'));
if (!defined('UPD_DB_USER'))  define('UPD_DB_USER',  upd_cfg('DB_USER',      'CFG_DB_USER',      ''));
if (!defined('UPD_DB_PASS'))  define('UPD_DB_PASS',  upd_cfg('DB_PASS',      'CFG_DB_PASS',      ''));
if (!defined('UPD_SECRET'))   define('UPD_SECRET',   upd_cfg('TOKEN_SECRET', 'CFG_TOKEN_SECRET', ''));

/* Guard: refuse to run without DB credentials */
if (!UPD_DB_USER || !UPD_DB_PASS) {
    ob_end_clean();
    http_response_code(503);
    echo json_encode(array(
        'success' => false,
        'message' => 'Updater: DB credentials missing. Check api/.env has DB_USER and DB_PASS set.',
        'diag'    => array(
            'env_file_exists'  => file_exists($_env),
            'env_vars_loaded'  => count($_env_vals),
            'db_user_set'      => UPD_DB_USER !== '',
            'db_pass_set'      => UPD_DB_PASS !== '',
        )
    ));
    exit;
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO('mysql:host=' . UPD_DB_HOST . ';dbname=' . UPD_DB_NAME . ';charset=utf8mb4',
            UPD_DB_USER, UPD_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Exception $ex) {
        error_log('[ETEL][updater] DB connect failed: ' . $ex->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database unavailable']);
        exit;
    }
    return $pdo;
}

function get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && function_exists('getallheaders')) { $hh = getallheaders(); $h = isset($hh['Authorization']) ? $hh['Authorization'] : ''; }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
}

/* Same token format as boot.php's gen_token()/verify_token() - both files
   authenticate the SAME tokens (a user's login token doubles as their
   admin-updater token), so the format and the revocation check below have
   to stay in lockstep with boot.php. See migration 006 / boot.php's
   etel_bump_token_version() for why token_version exists. */
function verify_token($token) {
    if (!$token) return null;
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null; // old 3-part tokens fail closed
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, UPD_SECRET);
    return ($sig === $expected) ? ['uid' => intval($uid), 'version' => intval($version)] : null;
}

function upd_table_columns($pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$table]);
        $cols = $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        $cols = [];
    }
    $cache[$table] = $cols;
    return $cols;
}

function get_auth_user($pdo) {
    $t = get_bearer();
    if (!$t) return null;
    $tok = verify_token($t);
    if (!$tok) return null;
    $hasVer = in_array('token_version', upd_table_columns($pdo, 'users'), true);
    $s = $pdo->prepare('SELECT id,name,phone,role' . ($hasVer ? ',token_version' : '') . ' FROM users WHERE id=? LIMIT 1');
    $s->execute([$tok['uid']]);
    $u = $s->fetch();
    if (!$u) return null;
    if ($hasVer && intval($u['token_version']) !== $tok['version']) return null;
    return $u;
}

function require_admin($pdo) {
    $u = get_auth_user($pdo);
    if (!$u) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
    if ($u['role'] !== 'admin') { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only']); exit; }
    return $u;
}

function upd_ok($data = null, $msg = '') { echo json_encode(['success' => true, 'data' => $data, 'message' => $msg]); exit; }
function upd_err($msg, $code = 400) { http_response_code($code); echo json_encode(['success' => false, 'message' => $msg]); exit; }
function log_updater_action($pdo, $user_id, $action, $file_path) {
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS `updater_logs` (
          `id`          INT(11)      NOT NULL AUTO_INCREMENT,
          `user_id`     INT(11)      NOT NULL,
          `action`      VARCHAR(50)  NOT NULL,
          `file_path`   VARCHAR(255) NOT NULL,
          `ip_address`  VARCHAR(45)  NOT NULL,
          `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user` (`user_id`),
          KEY `idx_action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $s = $pdo->prepare('INSERT INTO updater_logs (user_id, action, file_path, ip_address) VALUES (?, ?, ?, ?)');
        $s->execute([$user_id, $action, $file_path, $ip]);
    } catch (Exception $e) {}
}

/* Resolve a path segment-by-segment ("." and ".." handled), without
   requiring the path to already exist on disk (realpath() can't be used
   for files that don't exist yet, e.g. new uploads). */
function etel_canonicalize($path) {
    $path = str_replace('\\', '/', $path);
    $abs  = (strpos($path, '/') === 0);
    $parts = explode('/', $path);
    $out = [];
    foreach ($parts as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return ($abs ? '/' : '') . implode('/', $out);
}

/* Safely resolve a user-supplied relative path against $BASE_DIR.
   Returns the canonical path relative to $BASE_DIR (using '/' separators,
   no leading slash) on success, or false if the input escapes $BASE_DIR
   (directory traversal) or contains a null byte.
   This replaces the old single-pass str_replace(['../', ...]) filter,
   which was bypassable with overlapping payloads like "..././" - after
   one substitution pass those collapse back into "../". Canonicalizing
   the full path in one shot, the way this function does, closes that gap
   and also normalizes things like "api/./.env" so the $PROTECTED_FILES
   check below can't be bypassed with non-canonical spellings. */
function etel_safe_rel_path($BASE_DIR, $input) {
    if ($input === null || $input === '') return false;
    if (strpos($input, "\0") !== false) return false;
    $baseReal = realpath($BASE_DIR);
    if ($baseReal === false) return false;
    $baseReal = str_replace('\\', '/', $baseReal);

    $input = str_replace('\\', '/', $input);
    $input = ltrim($input, '/');
    $canonicalFull = etel_canonicalize($baseReal . '/' . $input);

    if ($canonicalFull !== $baseReal && strpos($canonicalFull, $baseReal . '/') !== 0) {
        return false; // escaped the base directory
    }
    $rel = ltrim(substr($canonicalFull, strlen($baseReal)), '/');
    if ($rel === '') return false;
    return $rel;
}

/* Auto-detect correct subfolder from filename */
function etel_detect_path($name) {
    $base = basename($name);
    $known = [
        'updater.php'          => 'api/updater.php',
        'updater-api.php'      => 'api/updater-api.php',
        'api.php'              => 'api/api.php',
        'boot.php'             => 'api/boot.php',
        'upload.php'           => 'api/upload.php',
        'config.php'           => 'api/config.php',
        'config-etb.php'       => 'api/config-etb.php',
        'core.js'              => 'js/core.js',
        'admin.js'             => 'js/admin.js',
        'admin-api.js'         => 'js/admin/admin-api.js',
        /* Controllers -- must go into api/controllers/, not api/ */
        'AdminController.php'  => 'api/controllers/AdminController.php',
        'AuthController.php'   => 'api/controllers/AuthController.php',
        'OrderController.php'  => 'api/controllers/OrderController.php',
        'ServiceGiverController.php' => 'api/controllers/ServiceGiverController.php',
        'RideController.php'   => 'api/controllers/RideController.php',
    ];
    if (isset($known[$base])) return $known[$base];
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $dirs = ['js' => 'js/', 'css' => 'css/', 'php' => 'api/', 'sql' => 'api/'];
    return (isset($dirs[$ext]) ? $dirs[$ext] : '') . $base;
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

$BASE_DIR   = dirname(__DIR__);
$BACKUP_DIR = $BASE_DIR . '/backups';
$ALLOWED_EXTENSIONS = ['php', 'html', 'js', 'css', 'json', 'md', 'txt', 'sql', 'svg'];
$PROTECTED_FILES    = ['api/config.php', 'api/.env'];

if (!file_exists($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0755, true);

/* Backup rotation - prevent inode exhaustion on shared hosting.
   Every deploy writes a new timestamped backup file and nothing ever
   removed old ones (audit finding: "Backup Bloat"). Prune anything
   older than $max_age_days on every request that touches the updater -
   cheap (single glob + filemtime check) and keeps this self-maintaining
   without a cron job, which this shared-hosting account may not have. */
function etel_prune_old_backups($backup_dir, $max_age_days = 30) {
    $files = @glob($backup_dir . '/*');
    if (!$files) return 0;
    $cutoff = time() - ($max_age_days * 86400);
    $pruned = 0;
    foreach ($files as $f) {
        if (is_file($f) && @filemtime($f) < $cutoff) {
            if (@unlink($f)) $pruned++;
        }
    }
    return $pruned;
}
etel_prune_old_backups($BACKUP_DIR, 14); // was 30 - backups/ ships inside the audited/deployed folder, keep it lean

/* Age-based pruning alone doesn't help during a busy multi-day session -
   a heavy edit day can produce dozens of same-file backups that are all
   well under 30 days old. Group backups by their logical filename (the
   part after the "YYYY-MM-DD_HHMMSS_" timestamp prefix) and keep only the
   $keep_per_file newest of each, regardless of age. Runs on every request
   that touches the updater, same as the age-based prune above, so this
   directory stays self-maintaining without a cron job. */
function etel_prune_backups_keep_latest($backup_dir, $keep_per_file = 8) {
    $files = @glob($backup_dir . '/*');
    if (!$files) return 0;
    $groups = [];
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $base = basename($f);
        $logical = preg_replace('/^\d{4}-\d{2}-\d{2}_\d{6}_/', '', $base);
        $groups[$logical][] = $f;
    }
    $pruned = 0;
    foreach ($groups as $logical => $group) {
        if (count($group) <= $keep_per_file) continue;
        usort($group, function ($a, $b) { return filemtime($b) - filemtime($a); });
        foreach (array_slice($group, $keep_per_file) as $old) {
            if (@unlink($old)) $pruned++;
        }
    }
    return $pruned;
}
etel_prune_backups_keep_latest($BACKUP_DIR, 3); // was 8 - 3 rollback points per file is enough and keeps the folder small

$pdo   = db();
$admin = require_admin($pdo);

/*  Shared ZIP deploy function (no goto, fixes PHP fatal)  */
function etel_do_zip_deploy($zf, $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXTENSIONS, $PROTECTED_FILES, $pdo, $admin_id) {
    if (!$zf || $zf['error'] !== UPLOAD_ERR_OK) { upd_err('Upload error: ' . ($zf['error'] ?? 'no file')); }
    if (strtolower(pathinfo($zf['name'], PATHINFO_EXTENSION)) !== 'zip') upd_err('Only .zip files accepted');

    $zdata = @file_get_contents($zf['tmp_name']);
    if ($zdata === false || $zdata === '') upd_err('Cannot read uploaded ZIP');
    $zlen = strlen($zdata);

    /* Find End-of-Central-Directory signature */
    $eocd = false;
    for ($i = $zlen - 22; $i >= max(0, $zlen - 65557); $i--) {
        if (substr($zdata, $i, 4) === "\x50\x4b\x05\x06") { $eocd = $i; break; }
    }
    if ($eocd === false) upd_err('Invalid or corrupt ZIP (no EOCD signature found)');

    $cd_count  = unpack('v', substr($zdata, $eocd + 10, 2))[1];
    $cd_offset = unpack('V', substr($zdata, $eocd + 16, 4))[1];
    if ($cd_count === 0) upd_err('ZIP is empty');

    /* Detect common prefix folder to strip (e.g. delivery_final/) */
    $strip  = '';
    $snames = [];
    $spos   = $cd_offset;
    for ($si = 0; $si < min((int)$cd_count, 12); $si++) {
        if (substr($zdata, $spos, 4) !== "\x50\x4b\x01\x02") break;
        $snl  = unpack('v', substr($zdata, $spos + 28, 2))[1];
        $sel  = unpack('v', substr($zdata, $spos + 30, 2))[1];
        $scl  = unpack('v', substr($zdata, $spos + 32, 2))[1];
        $snames[] = substr($zdata, $spos + 46, $snl);
        $spos += 46 + $snl + $sel + $scl;
    }
    if ($snames) {
        $sp = array_map(function ($n) { $x = explode('/', $n); return count($x) > 1 ? $x[0] . '/' : ''; }, $snames);
        $su = array_unique($sp);
        if (count($su) === 1 && $su[0] !== '') $strip = $su[0];
    }

    $ZIP_EXT = array_merge($ALLOWED_EXTENSIONS, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'svg', 'md', 'txt']);
    $results = []; $errors = []; $skipped = [];

    $pos = $cd_offset;
    for ($zi = 0; $zi < (int)$cd_count; $zi++) {
        if (substr($zdata, $pos, 4) !== "\x50\x4b\x01\x02") break;

        /* Central Directory record layout (offsets from record START including 4-byte sig):
            0: PK\x01\x02  (4 bytes)
            4: version made by  (2)
            6: version needed   (2)
            8: general purpose  (2)
           10: compression method (2)   what we want
           12: mod time          (2)
           14: mod date          (2)
           16: crc-32            (4)
           20: compressed size   (4)
           24: uncompressed size (4)
           28: file name length  (2)   nl
           30: extra field len   (2)   el
           32: file comment len  (2)   cl
           34: disk number start (2)
           36: int file attrs    (2)
           38: ext file attrs    (4)
           42: local header off  (4)   loff
        Total before filename: 46 bytes */
        $method = unpack('v', substr($zdata, $pos + 10, 2))[1];
        $csz    = unpack('V', substr($zdata, $pos + 20, 4))[1];
        $nl     = unpack('v', substr($zdata, $pos + 28, 2))[1];
        $el     = unpack('v', substr($zdata, $pos + 30, 2))[1];
        $cl     = unpack('v', substr($zdata, $pos + 32, 2))[1];
        $loff   = unpack('V', substr($zdata, $pos + 42, 4))[1];

        $name = substr($zdata, $pos + 46, $nl);
        $pos += 46 + $nl + $el + $cl;

        if (substr($name, -1) === '/') continue; /* directory entry */

        /* Strip top-level folder */
        $rel = ($strip && strpos($name, $strip) === 0) ? substr($name, strlen($strip)) : $name;
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (!$rel) continue;

        /* Auto-detect subfolder for flat-root files */
        if (dirname($rel) === '.') {
            if (function_exists('etel_detect_path')) $rel = etel_detect_path($rel);
        }

        $bn = basename($rel);
        if (!$bn || $bn[0] === '.') { $skipped[] = $rel; continue; }
        if (preg_match('#^(backups|\\.git|\\.vscode)/#', $rel)) { $skipped[] = $rel; continue; }
        /* Canonicalize and re-confirm the entry stays inside BASE_DIR - defense
           in depth alongside the literal "../" substring check below. */
        $safeRel = etel_safe_rel_path($BASE_DIR, $rel);
        if ($safeRel === false || strpos($rel, '../') !== false) { $errors[] = $rel; continue; }
        $rel = $safeRel;
        $rex = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($rex, $ZIP_EXT)) { $skipped[] = $rel; continue; }
        if (in_array($rel, $PROTECTED_FILES, true)) { $skipped[] = $rel; continue; }

        /* Local file header layout:
            0: PK\x03\x04  (4)
            4: version needed (2)
            6: general purpose (2)
            8: compression (2)
           10: mod time (2)
           12: mod date (2)
           14: crc-32 (4)
           18: compressed size (4)
           22: uncompressed size (4)
           26: file name length (2)
           28: extra field length (2)
           30+nl+el: file data start */
        if (substr($zdata, $loff, 4) !== "\x50\x4b\x03\x04") { $errors[] = $rel; continue; }
        $lnl  = unpack('v', substr($zdata, $loff + 26, 2))[1];
        $lel  = unpack('v', substr($zdata, $loff + 28, 2))[1];
        $doff = $loff + 30 + $lnl + $lel;
        $comp = substr($zdata, $doff, $csz);

        /* Decompress: method 0=STORE, 8=DEFLATE */
        if ($method === 8)     { $fc = @gzinflate($comp); }
        elseif ($method === 0) { $fc = $comp; }
        else { $skipped[] = $rel . ' (unsupported method ' . $method . ')'; continue; }
        if ($fc === false) { $errors[] = $rel . ' (decompress failed)'; continue; }

        $dest = rtrim($BASE_DIR, '/\\') . '/' . $rel;
        $ddir = dirname($dest);
        if (!is_dir($ddir)) @mkdir($ddir, 0755, true);
        if (file_exists($dest)) {
            @copy($dest, $BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . str_replace(['/', '\\'], '_', $rel));
        }
        if (file_put_contents($dest, $fc) !== false) $results[] = $rel;
        else $errors[] = $rel . ' (write failed)';
    }
    unset($zdata);

    log_updater_action($pdo, $admin_id, 'upload_zip', $zf['name'] . ' (' . count($results) . ' files)');
    upd_ok([
        'mode'      => 'zip_extracted',
        'extracted' => count($results),
        'skipped'   => count($skipped),
        'errors'    => $errors,
        'files'     => $results,
        'skipped_list' => array_slice($skipped, 0, 30),
        'strip'     => $strip,
        'cd_count'  => $cd_count,
        'zip_size'  => $zlen,
        'time'      => date('Y-m-d H:i:s'),
    ], count($results) . ' files deployed from ZIP (skipped: ' . count($skipped) . ', errors: ' . count($errors) . ')');
}


switch ($action) {

    case 'list_files':
        function scanDir($dir, $base, $prefix = '') {
            global $ALLOWED_EXTENSIONS;
            $out = [];
            if (!is_dir($dir)) return $out;
            $items = @scandir($dir);
            if (!$items) return $out;
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if (in_array($item, ['backups', '.git', 'uploads', 'node_modules']) || $item[0] === '.') continue;
                $path = $dir . '/' . $item;
                $rel  = $prefix . $item;
                if (is_dir($path)) {
                    $out[] = ['name' => $item, 'path' => $rel, 'type' => 'directory', 'size' => 0, 'modified' => filemtime($path),
                              'children' => scanDir($path, $base, $rel . '/')];
                } else {
                    $ext = pathinfo($item, PATHINFO_EXTENSION);
                    $out[] = ['name' => $item, 'path' => $rel, 'type' => 'file', 'extension' => $ext,
                              'size' => filesize($path), 'modified' => filemtime($path),
                              'editable' => in_array($ext, $ALLOWED_EXTENSIONS)];
                }
            }
            return $out;
        }
        upd_ok(scanDir($BASE_DIR, $BASE_DIR));
        break;

    case 'read_file':
        $fp = isset($_GET['file']) ? etel_safe_rel_path($BASE_DIR, $_GET['file']) : false;
        if ($fp === false) upd_err('Invalid file path');
        if (!$fp) upd_err('File path required');
        if (in_array($fp, $PROTECTED_FILES, true)) upd_err('This file is protected');
        $full = $BASE_DIR . '/' . $fp;
        if (!file_exists($full) || !is_file($full)) upd_err('File not found', 404);
        upd_ok(['name' => basename($full), 'path' => $fp, 'size' => filesize($full),
                'modified' => filemtime($full), 'extension' => pathinfo($full, PATHINFO_EXTENSION),
                'content' => file_get_contents($full)]);
        break;

    case 'update_file':
        $fp      = isset($_POST['file']) ? etel_safe_rel_path($BASE_DIR, $_POST['file']) : false;
        $content = isset($_POST['content']) ? $_POST['content'] : null;
        if ($fp === false) upd_err('Invalid file path');
        if (!$fp || $content === null) upd_err('File path and content required');
        if (in_array($fp, $PROTECTED_FILES, true)) upd_err('This file is protected');
        $full = $BASE_DIR . '/' . $fp;
        if (!file_exists($full)) upd_err('File not found', 404);
        @copy($full, $BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . str_replace(['/', '\\'], '_', $fp));
        if (file_put_contents($full, $content) === false) upd_err('Write failed - check permissions', 500);
        log_updater_action($pdo, $admin['id'], 'update_file', $fp);
        upd_ok(['path' => $fp, 'size' => filesize($full), 'time' => date('Y-m-d H:i:s')], 'File updated');
        break;

    case 'upload_file':
        if (!isset($_FILES['file'])) upd_err('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) upd_err('Upload error: ' . $file['error']);
        $tp_in = isset($_POST['path']) ? trim($_POST['path']) : '';
        if (!$tp_in) $tp_in = etel_detect_path($file['name']);
        $tp = etel_safe_rel_path($BASE_DIR, $tp_in);
        if ($tp === false || !$tp) upd_err('Invalid path');
        $ext = strtolower(pathinfo($tp, PATHINFO_EXTENSION));
        $all_ext = array_merge($ALLOWED_EXTENSIONS, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'zip']);
        if (!in_array($ext, $all_ext)) upd_err('Extension .' . $ext . ' is not allowed');
        if (in_array($tp, $PROTECTED_FILES, true)) upd_err('Protected file');
        if ($ext === 'zip') {
            /* Route to the shared ZIP handler function  no goto needed */
            etel_do_zip_deploy($_FILES['file'], $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXTENSIONS, $PROTECTED_FILES, $pdo, $admin['id']);
        }
        $full = $BASE_DIR . '/' . $tp;
        $dir  = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $backed = false;
        if (file_exists($full)) { @copy($full, $BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . str_replace(['/', '\\'], '_', $tp)); $backed = true; }
        if (!move_uploaded_file($file['tmp_name'], $full)) upd_err('Deploy failed - check permissions', 500);
        log_updater_action($pdo, $admin['id'], 'upload_file', $tp);
        upd_ok(['written' => $tp, 'size' => filesize($full), 'size_kb' => round(filesize($full) / 1024, 1), 'backed_up' => $backed, 'time' => date('Y-m-d H:i:s')], $tp . ' deployed');
        break;

    case 'upload_zip':
        if (!isset($_FILES['file'])) upd_err('No file uploaded');
        etel_do_zip_deploy($_FILES['file'], $BASE_DIR, $BACKUP_DIR, $ALLOWED_EXTENSIONS, $PROTECTED_FILES, $pdo, $admin['id']);
        break; /* etel_do_zip_deploy always exits */

    case 'upload_multiple':
        if (empty($_FILES['files'])) upd_err('No files uploaded');
        $mf    = $_FILES['files'];
        $paths = isset($_POST['paths']) ? (array)$_POST['paths'] : [];
        $MEXT  = array_merge($ALLOWED_EXTENSIONS, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp']);
        $mok   = []; $merr = [];
        $total = is_array($mf['name']) ? count($mf['name']) : 0;
        for ($mi = 0; $mi < $total; $mi++) {
            if ($mf['error'][$mi] !== UPLOAD_ERR_OK) { $merr[] = $mf['name'][$mi]; continue; }
            $orig = $mf['name'][$mi];
            $tp_in = (isset($paths[$mi]) && $paths[$mi] !== '') ? trim($paths[$mi]) : etel_detect_path($orig);
            $tp   = etel_safe_rel_path($BASE_DIR, $tp_in);
            if ($tp === false || !$tp)                    { $merr[] = $orig; continue; }
            if (in_array($tp, $PROTECTED_FILES, true))     { $merr[] = $orig; continue; }
            $mext = strtolower(pathinfo($tp, PATHINFO_EXTENSION));
            if (!in_array($mext, $MEXT))             { $merr[] = $orig; continue; }
            $full = $BASE_DIR . '/' . $tp;
            $dir  = dirname($full);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (file_exists($full)) @copy($full, $BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . str_replace(['/', '\\'], '_', $tp));
            if (move_uploaded_file($mf['tmp_name'][$mi], $full))
                $mok[] = ['file' => $tp, 'size' => filesize($full), 'size_kb' => round(filesize($full) / 1024, 1)];
            else $merr[] = $orig;
        }
        log_updater_action($pdo, $admin['id'], 'upload_multiple', count($mok) . ' files');
        upd_ok(['uploaded' => count($mok), 'total' => $total, 'errors' => $merr, 'files' => $mok, 'time' => date('Y-m-d H:i:s')],
               count($mok) . ' of ' . $total . ' files deployed');
        break;

    case 'create_backup':
        $fp = isset($_POST['file']) ? etel_safe_rel_path($BASE_DIR, $_POST['file']) : false;
        if ($fp === false) upd_err('Invalid file path');
        if (!$fp) upd_err('File path required');
        $full = $BASE_DIR . '/' . $fp;
        if (!file_exists($full)) upd_err('File not found', 404);
        $bk = $BACKUP_DIR . '/' . date('Y-m-d_His') . '_' . str_replace(['/', '\\'], '_', $fp);
        if (!@copy($full, $bk)) upd_err('Backup failed', 500);
        upd_ok(['backup' => basename($bk), 'original' => $fp, 'time' => date('Y-m-d H:i:s')], 'Backup created');
        break;

    case 'list_backups':
        $files = @glob($BACKUP_DIR . '/*.{php,html,js,css,json,sql,md}', GLOB_BRACE);
        if (!$files) $files = [];
        $out = [];
        foreach ($files as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'modified' => filemtime($f)];
        }
        usort($out, function ($a, $b) { return $b['modified'] - $a['modified']; });
        upd_ok(['backups' => $out, 'count' => count($out)]);
        break;

    case 'restore_backup':
        $bn = isset($_POST['backup']) ? basename($_POST['backup']) : '';
        $tp = isset($_POST['target']) ? etel_safe_rel_path($BASE_DIR, $_POST['target']) : false;
        if ($tp === false) upd_err('Invalid target path');
        if (!$bn || !$tp) upd_err('Backup name and target path required');
        if (in_array($tp, $PROTECTED_FILES, true)) upd_err('This file is protected');
        $bk   = $BACKUP_DIR . '/' . $bn;
        $full = $BASE_DIR . '/' . $tp;
        if (!file_exists($bk)) upd_err('Backup file not found', 404);
        if (!@copy($bk, $full)) upd_err('Restore failed', 500);
        log_updater_action($pdo, $admin['id'], 'restore_backup', $tp . ' from ' . $bn);
        upd_ok(['restored' => $tp, 'from' => $bn, 'time' => date('Y-m-d H:i:s')], 'Restored from backup');
        break;

    case 'delete_backup':
        $bn = isset($_POST['backup']) ? basename($_POST['backup']) : '';
        if (!$bn) upd_err('Backup name required');
        $bk = $BACKUP_DIR . '/' . $bn;
        if (!file_exists($bk)) upd_err('Backup not found', 404);
        if (!@unlink($bk)) upd_err('Delete failed', 500);
        log_updater_action($pdo, $admin['id'], 'delete_backup', $bn);
        upd_ok(['deleted' => $bn], 'Backup deleted');
        break;

    case 'prune_backups':
        $days = isset($_POST['max_age_days']) ? max(1, intval($_POST['max_age_days'])) : 30;
        $pruned = etel_prune_old_backups($BACKUP_DIR, $days);
        log_updater_action($pdo, $admin['id'], 'prune_backups', $pruned . ' files older than ' . $days . 'd');
        upd_ok(['pruned' => $pruned, 'max_age_days' => $days, 'remaining' => count(@glob($BACKUP_DIR . '/*') ?: [])],
               $pruned . ' old backup(s) removed');
        break;

    case 'prune_backups_keep_latest':
        $keep = isset($_POST['keep_per_file']) ? max(1, intval($_POST['keep_per_file'])) : 8;
        $pruned = etel_prune_backups_keep_latest($BACKUP_DIR, $keep);
        log_updater_action($pdo, $admin['id'], 'prune_backups_keep_latest', $pruned . ' files beyond newest ' . $keep . ' per source file');
        upd_ok(['pruned' => $pruned, 'keep_per_file' => $keep, 'remaining' => count(@glob($BACKUP_DIR . '/*') ?: [])],
               $pruned . ' old backup(s) removed');
        break;

    case 'system_info':
        upd_ok([
            'php_version'  => PHP_VERSION,
            'disk_free'    => disk_free_space($BASE_DIR),
            'disk_total'   => disk_total_space($BASE_DIR),
            'base_dir'     => $BASE_DIR,
            'backup_count' => count(@glob($BACKUP_DIR . '/*') ?: []),
            'time'         => date('Y-m-d H:i:s'),
            'gzinflate'    => function_exists('gzinflate'),
            'ziparchive'   => class_exists('ZipArchive'),
        ]);
        break;

    default:
        upd_err('Unknown action: ' . $action, 404);
}
