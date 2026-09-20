<?php
// ============================================================
// ETEL TAXI Updater - v1.0
// Upload to: yourdomain/EtelTaxi/updater.php
// Adapted from EtelAI's updater.php (same proven, already-debugged ZIP
// deploy logic - including the wrapper-folder detection fix, so a flat
// zip of real subfolder paths like api/controllers/X.php deploys
// correctly instead of landing at the project root).
// IMPORTANT: Set ETEL_TAXI_UPDATER_PASS in cPanel before using this.
// ============================================================

$_env_pass = getenv('ETEL_TAXI_UPDATER_PASS');
if (!$_env_pass) {
    http_response_code(503);
    die(json_encode(['ok' => false, 'error' => 'Updater not configured. Set ETEL_TAXI_UPDATER_PASS env variable in cPanel first.']));
}
define('UPDATER_PASSWORD', $_env_pass);
define('ETEL_ROOT', __DIR__ . '/');
define('BACKUP_DIR', __DIR__ . '/backups/');

header('Content-Type: application/json');
$allowed_origin = getenv('ETEL_TAXI_DOMAIN') ?: 'https://ethelmarket.com';
header('Access-Control-Allow-Origin: ' . $allowed_origin);
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Auth');
header('Vary: Origin');

function checkAuth() {
    $auth = $_SERVER['HTTP_X_AUTH'] ?? $_POST['auth'] ?? $_GET['auth'] ?? '';
    if ($auth !== UPDATER_PASSWORD) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Unauthorized. Wrong password.']));
    }
}
function ok($data, $msg = 'Success') { echo json_encode(['ok' => true, 'message' => $msg, 'data' => $data]); exit; }
function err($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

switch ($action) {

    case 'status':
        checkAuth();
        $files = [
            'driver.html'                         => ETEL_ROOT . 'driver.html',
            'admin.html'                           => ETEL_ROOT . 'admin.html',
            'api/boot.php'                         => ETEL_ROOT . 'api/boot.php',
            'api/config.php'                       => ETEL_ROOT . 'api/config.php',
            'api/controllers/DriverController.php' => ETEL_ROOT . 'api/controllers/DriverController.php',
            'api/controllers/AdminController.php'  => ETEL_ROOT . 'api/controllers/AdminController.php',
            'api/lib/taxi_payments.php'            => ETEL_ROOT . 'api/lib/taxi_payments.php',
        ];
        $status = [];
        foreach ($files as $name => $path) {
            $status[$name] = [
                'exists'   => file_exists($path),
                'size'     => file_exists($path) ? round(filesize($path) / 1024, 1) . ' KB' : '-',
                'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : '-',
            ];
        }
        $backups = [];
        if (is_dir(BACKUP_DIR)) {
            $dirs = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
            rsort($dirs);
            foreach (array_slice($dirs, 0, 5) as $d) $backups[] = basename($d);
        }
        ok([
            'files'       => $status,
            'php_version' => PHP_VERSION,
            'disk_free'   => round(disk_free_space(ETEL_ROOT) / 1024 / 1024) . ' MB',
            'backups'     => $backups,
        ], 'System status loaded');

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

        $rel  = trim($_POST['path'] ?? $filename);
        $rel  = ltrim(str_replace(['..', './'], '/', $rel), '/');
        $dest = realpath(ETEL_ROOT) . '/' . $rel;
        if (strpos(realpath(dirname($dest)) ?: '', realpath(ETEL_ROOT)) !== 0) err('Invalid path');

        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) err("Cannot create directory: $dir");

        if (file_exists($dest)) {
            if (!is_dir(BACKUP_DIR . '_single/')) mkdir(BACKUP_DIR . '_single/', 0755, true);
            copy($dest, BACKUP_DIR . '_single/' . date('Ymd-His') . '-' . str_replace('/', '_', $rel));
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Failed to write file. Check folder permissions.');

        ok(['file' => $filename, 'path' => $rel, 'size' => round(filesize($dest) / 1024, 1) . ' KB'], "$rel updated successfully!");

    case 'upload_zip':
        checkAuth();
        if (empty($_FILES['zipfile'])) err('No zip file uploaded');
        $zip     = $_FILES['zipfile'];
        $zipPath = sys_get_temp_dir() . '/etel_taxi_update_' . time() . '.zip';
        if ($zip['error'] !== UPLOAD_ERR_OK) err('Upload error: ' . $zip['error']);
        if ($zip['size'] > 20 * 1024 * 1024) err('Zip too large (max 20MB)');
        if (!move_uploaded_file($zip['tmp_name'], $zipPath)) err('Failed to save zip');

        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) { unlink($zipPath); err('Invalid or corrupt zip file'); }

        if (!is_dir(BACKUP_DIR . '_zip/')) mkdir(BACKUP_DIR . '_zip/', 0755, true);
        $ts = date('Ymd-His');

        // Same wrapper-folder detection as EtelAI's fixed updater: only
        // strip a leading folder if EVERY entry shares one common,
        // non-empty top-level name. A flat zip of real relative paths
        // (api/controllers/X.php, no wrapper) is left exactly as-is.
        $topLevels = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            if (!$entry || substr($entry, -1) === '/' || basename($entry) === '.DS_Store') continue;
            $parts = explode('/', $entry);
            $topLevels[] = count($parts) > 1 ? $parts[0] : '';
        }
        $uniqueTops = array_unique($topLevels);
        $wrapperFolder = (count($uniqueTops) === 1 && $uniqueTops[0] !== '') ? $uniqueTops[0] : null;

        $updated = []; $skipped = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            if (!$entry || substr($entry, -1) === '/' || basename($entry) === '.DS_Store') continue;

            $relative = $wrapperFolder !== null
                ? implode('/', array_slice(explode('/', $entry), 1))
                : $entry;
            $relative = ltrim(str_replace(['..', './'], '/', $relative), '/');
            if ($relative === '') continue;

            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $allowed = ['html','htm','js','css','json','php','sql','txt','md','htaccess','xml','svg','webp','png','jpg','ico'];
            if (!in_array($ext, $allowed) && $ext !== '') { $skipped[] = $relative; continue; }

            $dest = ETEL_ROOT . $relative;
            $dir  = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (file_exists($dest)) copy($dest, BACKUP_DIR . '_zip/' . $ts . '-' . str_replace('/', '_', $relative));

            file_put_contents($dest, $za->getFromIndex($i));
            $updated[] = $relative;
        }
        $za->close();
        unlink($zipPath);

        if (empty($updated)) err('No allowed files found in zip.' . (!empty($skipped) ? ' Skipped: ' . implode(', ', $skipped) : ''));
        ok(['updated' => $updated, 'skipped' => $skipped], count($updated) . ' files deployed from zip!');

    case 'delete_backup':
        checkAuth();
        $backupId = preg_replace('/[^0-9_\-]/', '', $_POST['backup_id'] ?? '');
        if (!$backupId) err('No backup_id');
        $backupPath = BACKUP_DIR . $backupId . '/';
        if (!is_dir($backupPath)) err('Backup not found');
        array_map('unlink', glob("$backupPath*"));
        rmdir($backupPath);
        ok([], "Backup $backupId deleted");

    default:
        err('Unknown action: ' . $action . '. Valid: status, upload, upload_zip, delete_backup');
}
