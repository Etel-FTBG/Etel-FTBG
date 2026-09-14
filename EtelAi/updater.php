<?php
// ============================================================
// ETEL OS Updater · v1.0
// Upload to: ethelmarket.com/etel/updater.php
// IMPORTANT: Set your UPDATER_PASSWORD below before uploading!
// ============================================================

// ── SECURITY — Password via cPanel Environment Variable ──
// In cPanel → Software → Setup Node.js App (or PHP INI): set ETEL_UPDATER_PASS
// to a real secret of your own choosing. A previous version of this file
// documented the actual live password directly in this comment - never do
// that; a comment in a public repo is not a secure place to store one.
// If not set, access is DENIED — do NOT fall back to a hardcoded password in production.
$_env_pass = getenv('ETEL_UPDATER_PASS');
if (!$_env_pass) {
    http_response_code(503);
    die(json_encode(['ok' => false, 'error' => 'Updater not configured. Set ETEL_UPDATER_PASS env variable in cPanel.']));
}
define('UPDATER_PASSWORD', $_env_pass);
define('ETEL_ROOT', __DIR__ . '/');
define('BACKUP_DIR', __DIR__ . '/backups/');

header('Content-Type: application/json');
// CORS — only allow your own domain, not wildcard
$allowed_origin = 'https://ethelmarket.com';
$req_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($req_origin === $allowed_origin || (defined('PHP_SAPI') && PHP_SAPI === 'cli')) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
} else {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Auth');
header('Vary: Origin');

// ── AUTH CHECK ──
function checkAuth() {
    $auth = $_SERVER['HTTP_X_AUTH'] ?? $_POST['auth'] ?? $_GET['auth'] ?? '';
    if ($auth !== UPDATER_PASSWORD) {
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

// ── ROUTER ──
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

switch ($action) {

    // ── STATUS: Get current file versions and system info ──
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
                'size'     => file_exists($path) ? round(filesize($path) / 1024, 1) . ' KB' : '—',
                'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : '—',
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

    // ── BACKUP: Create a backup of current files ──
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

    // ── RESTORE: Restore from a backup ──
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

    // ── UPLOAD: Receive and apply an uploaded file to any project path ──
    // Same pattern as the EtelGebeya updater: allow-list by extension, not
    // by exact filename, so any project file (e.g. api/etel-os.php) can be
    // deployed. Destination path comes from $_POST['path'], falling back to
    // the uploaded filename (with legacy special-cases preserved below).
    case 'upload':
        checkAuth();
        if (empty($_FILES['file'])) err('No file uploaded');

        $file     = $_FILES['file'];
        $filename = basename($file['name']);
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed  = ['html','htm','js','css','json','php','sql','txt','md','htaccess','xml','svg','webp','png','jpg','ico'];

        if (!in_array($ext, $allowed) && $ext !== '') err("Extension .$ext not allowed. Allowed: " . implode(', ', $allowed));
        if ($file['size'] > 10 * 1024 * 1024) err('File too large (max 10MB)');
        if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error code: ' . $file['error']);

        // Determine destination — explicit path wins, else legacy default, else filename as-is
        $rel = trim($_POST['path'] ?? '');
        if ($rel === '' && $filename === 'registry.json') $rel = 'skills/registry.json';
        if ($rel === '') $rel = $filename;
        $rel  = ltrim(str_replace(['..', './'], '/', $rel), '/');
        $dest = realpath(ETEL_ROOT) . '/' . $rel;
        if (strpos(realpath(dirname($dest)) ?: '', realpath(ETEL_ROOT)) !== 0) err('Invalid path');

        if (basename($rel) === 'registry.json') {
            $json   = file_get_contents($file['tmp_name']);
            $parsed = json_decode($json, true);
            if (!$parsed || !isset($parsed['skills'])) err('Invalid registry.json — must have "skills" array');
        }
        if (basename($rel) === 'index.html') {
            $content = file_get_contents($file['tmp_name']);
            if (strpos($content, '<!DOCTYPE html>') === false && strpos($content, '<html') === false) {
                err('Invalid HTML file');
            }
        }

        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) err("Cannot create directory: $dir");

        // Backup existing file before overwrite
        if (file_exists($dest)) {
            if (!is_dir(BACKUP_DIR . '_single/')) mkdir(BACKUP_DIR . '_single/', 0755, true);
            copy($dest, BACKUP_DIR . '_single/' . date('Ymd-His') . '-' . str_replace('/', '_', $rel));
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            err('Failed to write file. Check folder permissions.');
        }

        ok([
            'file'     => $filename,
            'path'     => $rel,
            'dest'     => $dest,
            'size'     => round(filesize($dest) / 1024, 1) . ' KB',
            'modified' => date('Y-m-d H:i:s'),
        ], "$rel updated successfully!");

    // ── UPLOAD ZIP: Upload and deploy every file inside a zip to its own path ──
    case 'upload_zip':
        checkAuth();
        if (empty($_FILES['zipfile'])) err('No zip file uploaded');

        $zip     = $_FILES['zipfile'];
        $zipPath = sys_get_temp_dir() . '/etel_update_' . time() . '.zip';

        if ($zip['error'] !== UPLOAD_ERR_OK) err('Upload error: ' . $zip['error']);
        if ($zip['size'] > 20 * 1024 * 1024) err('Zip too large (max 20MB)');
        if (!move_uploaded_file($zip['tmp_name'], $zipPath)) err('Failed to save zip');

        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            unlink($zipPath);
            err('Invalid or corrupt zip file');
        }

        if (!is_dir(BACKUP_DIR . '_zip/')) mkdir(BACKUP_DIR . '_zip/', 0755, true);
        $ts = date('Ymd-His');

        // Detect whether every entry shares one common top-level wrapper
        // folder (e.g. a zip of "etel-final/index.html",
        // "etel-final/api/foo.php") before stripping anything. The old
        // code stripped the first path segment unconditionally, which
        // silently mangled any zip that already has real subfolders and
        // no wrapper - api/providers.php became providers.php at the
        // project root instead of inside api/, for every zip built this
        // way (which is most of them - a flat zip of already-correct
        // relative paths, no wrapper folder at all).
        $topLevels = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            if (!$entry || substr($entry, -1) === '/' || basename($entry) === '.DS_Store') continue;
            $parts = explode('/', $entry);
            $topLevels[] = count($parts) > 1 ? $parts[0] : '';
        }
        $uniqueTops = array_unique($topLevels);
        // Only strip if there's exactly one top-level name AND it's
        // non-empty for every entry (i.e. nothing sits at the zip root).
        $wrapperFolder = (count($uniqueTops) === 1 && $uniqueTops[0] !== '') ? $uniqueTops[0] : null;

        $updated = []; $skipped = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            if (!$entry || substr($entry, -1) === '/' || basename($entry) === '.DS_Store') continue;

            if ($wrapperFolder !== null) {
                $parts    = explode('/', $entry);
                $relative = implode('/', array_slice($parts, 1));
            } else {
                $relative = $entry;
            }
            $relative = ltrim(str_replace(['..', './'], '/', $relative), '/');
            if ($relative === '') continue;

            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $allowed = ['html','htm','js','css','json','php','sql','txt','md','htaccess','xml','svg','webp','png','jpg','ico'];
            if (!in_array($ext, $allowed) && $ext !== '') { $skipped[] = $relative; continue; }

            $dest = ETEL_ROOT . $relative;
            $dir  = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            if (file_exists($dest)) {
                copy($dest, BACKUP_DIR . '_zip/' . $ts . '-' . str_replace('/', '_', $relative));
            }

            $content = $za->getFromIndex($i);
            file_put_contents($dest, $content);
            $updated[] = $relative;
        }
        $za->close();
        unlink($zipPath);

        if (empty($updated)) err('No allowed files found in zip.' . (!empty($skipped) ? ' Skipped: ' . implode(', ', $skipped) : ''));
        ok(['updated' => $updated, 'skipped' => $skipped], count($updated) . ' files deployed from zip!');

    // ── DELETE BACKUP ──
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
