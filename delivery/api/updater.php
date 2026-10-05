<?php
// ============================================================
// ETEL OS Updater  v1.0
// Upload to: ethelmarket.com/etel/updater.php
// SECURITY: password now loaded from api/.env (UPDATER_PASSWORD key)
// via the same bulletproof line-parser used elsewhere in this
// codebase - never hardcode it here again.
// ============================================================

//  LOAD .env (bulletproof cPanel reader, handles non-ASCII comments) 
$_upd_env_file = __DIR__ . '/.env';
$_upd_env = array();
if (file_exists($_upd_env_file)) {
    $_upd_lines = @file($_upd_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($_upd_lines) {
        foreach ($_upd_lines as $_upd_line) {
            $_upd_line = ltrim($_upd_line, "\xEF\xBB\xBF");
            $_upd_trimmed = ltrim($_upd_line);
            if ($_upd_trimmed === '' || $_upd_trimmed[0] === '#') continue;
            if (strpos($_upd_trimmed, '=') === false) continue;
            $_upd_parts = explode('=', $_upd_trimmed, 2);
            $_upd_k = trim($_upd_parts[0]);
            $_upd_v = isset($_upd_parts[1]) ? trim($_upd_parts[1]) : '';
            if ($_upd_v !== '' && $_upd_v[0] !== '"' && $_upd_v[0] !== "'") {
                $_upd_hash = strpos($_upd_v, ' #');
                if ($_upd_hash !== false) $_upd_v = rtrim(substr($_upd_v, 0, $_upd_hash));
            }
            if (strlen($_upd_v) >= 2 &&
                (($_upd_v[0] === '"' && $_upd_v[strlen($_upd_v)-1] === '"') ||
                 ($_upd_v[0] === "'" && $_upd_v[strlen($_upd_v)-1] === "'"))) {
                $_upd_v = substr($_upd_v, 1, strlen($_upd_v) - 2);
            }
            if ($_upd_k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $_upd_k)) {
                $_upd_env[$_upd_k] = $_upd_v;
            }
        }
    }
}
$_upd_pw = isset($_upd_env['UPDATER_PASSWORD']) ? $_upd_env['UPDATER_PASSWORD'] : getenv('UPDATER_PASSWORD');

//  SECURITY  no hardcoded fallback; refuse to run if not configured 
if (!$_upd_pw) {
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['ok' => false, 'error' => 'UPDATER_PASSWORD not set in api/.env - refusing to start for safety.']));
}
define('UPDATER_PASSWORD', $_upd_pw);
define('ETEL_ROOT', __DIR__ . '/');   // path to your etel folder
define('BACKUP_DIR', __DIR__ . '/backups/');

header('Content-Type: application/json');
// Was "*" - any website could call this deploy tool from a visitor's browser.
$_upd_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($_upd_origin, array('https://ethelmarket.com', 'https://www.ethelmarket.com'), true)) {
    header('Access-Control-Allow-Origin: ' . $_upd_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Auth');

//  AUTH CHECK 
// This tool can write and extract files on the server, so a guessed password
// means full code execution. Wrong attempts are counted per IP in a temp file
// (no DB dependency here): 8 misses in 15 minutes locks that IP out, and the
// password is compared in constant time.
function upd_fail_file() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    return rtrim(sys_get_temp_dir(), '/\\') . '/etel_upd_fail_' . md5($ip);
}
function upd_recent_failures() {
    $f = upd_fail_file();
    if (!is_file($f)) return [];
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) return [];
    $cut = time() - 900;
    return array_values(array_filter($d, function ($t) use ($cut) { return is_int($t) && $t > $cut; }));
}
function checkAuth() {
    $fails = upd_recent_failures();
    if (count($fails) >= 8) {
        http_response_code(429);
        die(json_encode(['ok' => false, 'error' => 'Too many failed attempts. Try again in 15 minutes.']));
    }
    $auth = $_SERVER['HTTP_X_AUTH'] ?? $_POST['auth'] ?? $_GET['auth'] ?? '';
    if (!is_string($auth) || $auth === '' || !hash_equals(UPDATER_PASSWORD, $auth)) {
        $fails[] = time();
        @file_put_contents(upd_fail_file(), json_encode($fails), LOCK_EX);
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Unauthorized. Wrong password.']));
    }
}

function ok($data, $msg = 'Success') {
    echo json_encode(['ok' => true, 'message' => $msg, 'data' => $data]);
    exit;
}

function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

//  ROUTER 
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

switch ($action) {

    //  STATUS: Get current file versions and system info 
    case 'status':
        checkAuth();
        $files = [
            'index.html'            => ETEL_ROOT . 'index.html',
            'config.js'             => ETEL_ROOT . 'config.js',
            'etel-engine.js'        => ETEL_ROOT . 'etel-engine.js',
            'sw.js'                 => ETEL_ROOT . 'sw.js',
            'manifest.json'         => ETEL_ROOT . 'manifest.json',
            'skills/registry.json'  => ETEL_ROOT . 'skills/registry.json',
            'api/api.php'           => ETEL_ROOT . 'api/api.php',
        ];
        $status = [];
        foreach ($files as $name => $path) {
            $status[$name] = [
                'exists'   => file_exists($path),
                'size'     => file_exists($path) ? round(filesize($path) / 1024, 1) . ' KB' : '',
                'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : '',
            ];
        }
        // Count skills
        $regPath = ETEL_ROOT . 'skills/registry.json';
        $skillCount = 0;
        if (file_exists($regPath)) {
            $reg = json_decode(file_get_contents($regPath), true);
            $skillCount = count($reg['skills'] ?? []);
        }
        // Backups
        $backups = [];
        if (is_dir(BACKUP_DIR)) {
            $dirs = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
            rsort($dirs);
            foreach (array_slice($dirs, 0, 5) as $d) {
                $backups[] = basename($d);
            }
        }
        ok([
            'files'       => $status,
            'skill_count' => $skillCount,
            'php_version' => PHP_VERSION,
            'disk_free'   => round(disk_free_space(ETEL_ROOT) / 1024 / 1024) . ' MB',
            'backups'     => $backups,
            'etel_root'   => ETEL_ROOT,
        ], 'System status loaded');

    //  BACKUP: Create a backup of current files 
    case 'backup':
        checkAuth();
        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = BACKUP_DIR . $timestamp . '/';

        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }
        mkdir($backupPath, 0755, true);
        mkdir($backupPath . 'skills/', 0755, true);
        mkdir($backupPath . 'api/', 0755, true);

        $filesToBackup = [
            'index.html'           => ETEL_ROOT . 'index.html',
            'config.js'            => ETEL_ROOT . 'config.js',
            'etel-engine.js'       => ETEL_ROOT . 'etel-engine.js',
            'sw.js'                => ETEL_ROOT . 'sw.js',
            'skills/registry.json' => ETEL_ROOT . 'skills/registry.json',
        ];

        $backed = [];
        foreach ($filesToBackup as $name => $src) {
            if (file_exists($src)) {
                copy($src, $backupPath . $name);
                $backed[] = $name;
            }
        }

        // Keep only last 10 backups
        $allBackups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
        rsort($allBackups);
        foreach (array_slice($allBackups, 10) as $old) {
            array_map('unlink', glob("$old/*"));
            @rmdir($old . '/skills');
            @rmdir($old . '/api');
            rmdir($old);
        }

        ok(['backup_id' => $timestamp, 'files' => $backed], "Backup created: $timestamp");

    //  RESTORE: Restore from a backup 
    case 'restore':
        checkAuth();
        $backupId = preg_replace('/[^0-9_\-]/', '', $_POST['backup_id'] ?? '');
        if (!$backupId) err('No backup_id provided');
        $backupPath = BACKUP_DIR . $backupId . '/';
        if (!is_dir($backupPath)) err('Backup not found: ' . $backupId);

        $restored = [];
        $files = glob($backupPath . '*.{html,js,json,php}', GLOB_BRACE);
        $files = array_merge($files, glob($backupPath . 'skills/*.json'));
        foreach ($files as $src) {
            $rel  = str_replace($backupPath, '', $src);
            $dest = ETEL_ROOT . $rel;
            if (copy($src, $dest)) $restored[] = $rel;
        }
        ok(['restored' => $restored], "Restored from backup: $backupId");

    //  UPLOAD: Receive and apply an uploaded file 
    case 'upload':
        checkAuth();
        if (empty($_FILES['file'])) err('No file uploaded');

        $file     = $_FILES['file'];
        $filename = basename($file['name']);
        $allowed  = ['index.html', 'config.js', 'etel-engine.js', 'sw.js', 'manifest.json', 'registry.json'];

        if (!in_array($filename, $allowed)) {
            err('File not allowed: ' . $filename . '. Allowed: ' . implode(', ', $allowed));
        }
        if ($file['size'] > 5 * 1024 * 1024) err('File too large (max 5MB)');
        if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error code: ' . $file['error']);

        // Determine destination
        $dest = ETEL_ROOT . $filename;
        if ($filename === 'registry.json') {
            $dest = ETEL_ROOT . 'skills/registry.json';
            // Validate JSON
            $json = file_get_contents($file['tmp_name']);
            $parsed = json_decode($json, true);
            if (!$parsed || !isset($parsed['skills'])) err('Invalid registry.json  must have "skills" array');
        }
        if ($filename === 'index.html') {
            // Validate it's actually HTML
            $content = file_get_contents($file['tmp_name']);
            if (strpos($content, '<!DOCTYPE html>') === false && strpos($content, '<html') === false) {
                err('Invalid HTML file');
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            err('Failed to write file. Check folder permissions.');
        }

        ok([
            'file'     => $filename,
            'dest'     => $dest,
            'size'     => round(filesize($dest) / 1024, 1) . ' KB',
            'modified' => date('Y-m-d H:i:s'),
        ], "$filename updated successfully!");

    //  UPLOAD ZIP: Upload and extract a zip update 
    case 'upload_zip':
        checkAuth();
        if (empty($_FILES['zipfile'])) err('No zip file uploaded');

        $zip     = $_FILES['zipfile'];
        $zipPath = sys_get_temp_dir() . '/etel_update_' . time() . '.zip';

        if ($zip['error'] !== UPLOAD_ERR_OK) err('Upload error: ' . $zip['error']);
        if ($zip['size'] > 20 * 1024 * 1024) err('Zip too large (max 20MB)');
        if (!move_uploaded_file($zip['tmp_name'], $zipPath)) err('Failed to save zip');

        // Allowed files inside zip (whitelist)
        $allowed = [
            'index.html', 'config.js', 'etel-engine.js', 'sw.js',
            'manifest.json', 'skills/registry.json',
        ];

        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            unlink($zipPath);
            err('Invalid or corrupt zip file');
        }

        $updated = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry    = $za->getNameIndex($i);
            // Strip leading folder name (e.g. etel-final/)
            $parts    = explode('/', $entry);
            $relative = implode('/', array_slice($parts, 1));

            if (!$relative || substr($relative, -1) === '/') continue;
            if (!in_array($relative, $allowed)) continue;

            $dest    = ETEL_ROOT . $relative;
            $content = $za->getFromIndex($i);
            $dir     = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dest, $content);
            $updated[] = $relative;
        }
        $za->close();
        unlink($zipPath);

        if (empty($updated)) err('No matching files found in zip. Expected: ' . implode(', ', $allowed));
        ok(['updated' => $updated], count($updated) . ' files updated from zip!');

    //  DELETE BACKUP 
    case 'delete_backup':
        checkAuth();
        $backupId = preg_replace('/[^0-9_\-]/', '', $_POST['backup_id'] ?? '');
        if (!$backupId) err('No backup_id');
        $backupPath = BACKUP_DIR . $backupId . '/';
        if (!is_dir($backupPath)) err('Backup not found');
        array_map('unlink', glob("$backupPath*"));
        array_map('unlink', glob("$backupPath*/*"));
        @rmdir($backupPath . 'skills/');
        @rmdir($backupPath . 'api/');
        rmdir($backupPath);
        ok([], "Backup $backupId deleted");

    default:
        err('Unknown action: ' . $action . '. Valid: status, backup, restore, upload, upload_zip, delete_backup');
}
