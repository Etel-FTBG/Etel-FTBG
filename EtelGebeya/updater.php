<?php
// ============================================================
//  ETEL GEBEYA -- File Updater  v1.0
//  https://ethelmarket.com/EtelGebeya/updater.php
//  Same pattern as ETEL Delivery updater system.
// ============================================================
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

define('EG_ROOT',   __DIR__ . '/');
define('BACKUP_DIR', __DIR__ . '/storage/backups/updater/');

// Load ADMIN_SECRET from .env file
(function () {
    $envFile = __DIR__ . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || !str_contains($trimmed, '=')) continue;
            [$k, $v] = explode('=', $trimmed, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
})();
define('UPDATER_PASSWORD', $_ENV['ADMIN_SECRET'] ?? '');

function checkAuth(): void {
    $h = trim($_SERVER['HTTP_X_AUTH'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $h = str_replace('Bearer ', '', $h);
    $p = trim($_POST['auth'] ?? $_GET['auth'] ?? $h);
    if (empty(UPDATER_PASSWORD) || !hash_equals(UPDATER_PASSWORD, $p)) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
        exit;
    }
}
function ok(mixed $d, string $m='OK'): never { ob_end_clean(); echo json_encode(['ok'=>true,'msg'=>$m,'data'=>$d]); exit; }
function err(string $m, int $c=400): never { ob_end_clean(); http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

function humanSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576,1).' MB';
    if ($bytes >= 1024)    return round($bytes/1024,1).' KB';
    return $bytes.' B';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ?? STATUS ??????????????????????????????????????????????????
case 'status':
    checkAuth();
    $track = [
        'admin.html'           => EG_ROOT.'admin.html',
        'public/index.php'     => EG_ROOT.'public/index.php',
        'api/admin.php'        => EG_ROOT.'api/admin.php',
        'api/config.php'       => EG_ROOT.'api/config.php',
        'updater.php'          => EG_ROOT.'updater.php',
        'bootstrap.php'        => EG_ROOT.'bootstrap.php',
        'config/app.php'       => EG_ROOT.'config/app.php',
        'database/schema.sql'  => EG_ROOT.'database/schema.sql',
    ];
    $files = [];
    foreach ($track as $name => $path) {
        $files[$name] = [
            'exists'   => file_exists($path),
            'size'     => file_exists($path) ? humanSize(filesize($path)) : '--',
            'modified' => file_exists($path) ? date('Y-m-d H:i', filemtime($path)) : '--',
        ];
    }
    // DB info -- read credentials from config.php without executing it
    $db_tables = '--'; $db_size = '--';
    try {
        $_c = @file_get_contents(__DIR__.'/api/config.php') ?: '';
        preg_match("/define\('DB_HOST'\s*,\s*'([^']+)'/", $_c, $_mh);
        preg_match("/define\('DB_NAME'\s*,\s*'([^']+)'/", $_c, $_mn);
        preg_match("/define\('DB_USER'\s*,\s*'([^']+)'/", $_c, $_mu);
        preg_match("/define\('DB_PASS'\s*,\s*'([^']*)'/", $_c, $_mp);
        $_host = $_mh[1]??'localhost'; $_name=$_mn[1]??''; $_user=$_mu[1]??''; $_pass=$_mp[1]??'';
        if ($_name && $_user) {
            $_pdo = new PDO("mysql:host=$_host;dbname=$_name;charset=utf8mb4",$_user,$_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $db_tables = $_pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
            $db_size   = $_pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn() . ' MB';
        }
        unset($_c,$_mh,$_mn,$_mu,$_mp,$_host,$_name,$_user,$_pass,$_pdo);
    } catch (Throwable $e) {}

    // Backups
    $backups = [];
    if (is_dir(BACKUP_DIR)) {
        $items = scandir(BACKUP_DIR);
        rsort($items);
        foreach (array_slice(array_filter($items,fn($i)=>$i!='.'&&$i!='..'),0,10) as $f) {
            $p = BACKUP_DIR.$f;
            $backups[] = ['name'=>$f,'size'=>humanSize(filesize($p)),'time'=>date('Y-m-d H:i',filemtime($p))];
        }
    }

    ok([
        'files'      => $files,
        'php_version'=> PHP_VERSION,
        'disk_free'  => humanSize(disk_free_space('/')),
        'db_tables'  => $db_tables,
        'db_size'    => $db_size,
        'backups'    => $backups,
        'server_time'=> date('Y-m-d H:i:s'),
    ]);

// ?? UPLOAD ??????????????????????????????????????????????????
case 'upload':
    checkAuth();
    if (empty($_FILES['file'])) err('No file uploaded');
    $file = $_FILES['file'];
    $name = basename($file['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error code: '.$file['error']);

    // ?? ZIP BUNDLE: extract and deploy all files to their paths ??
    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) err('ZipArchive not available on server');
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) err('Cannot open zip file');

        $deployed = []; $errors = [];
        $ts = date('Ymd-His');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            // Skip directories and hidden system files
            if (substr($entry, -1) === '/' || basename($entry) === '.DS_Store') continue;

            // Sanitise path -- no traversal
            $rel  = ltrim(str_replace(['..', './'], '/', $entry), '/');
            $dest = EG_ROOT . $rel;
            $dir  = dirname($dest);

            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Backup existing file before overwrite
            if (file_exists($dest)) {
                copy($dest, BACKUP_DIR . $ts . '-' . str_replace('/', '_', $rel));
            }

            $content = $zip->getFromIndex($i);
            if (file_put_contents($dest, $content) === false) {
                $errors[] = $rel;
            } else {
                $deployed[] = $rel;
            }
        }
        $zip->close();

        if (!empty($errors)) {
            ok(['deployed' => $deployed, 'errors' => $errors], count($deployed).' deployed, '.count($errors).' failed');
        }
        ok(['deployed' => $deployed, 'count' => count($deployed)], count($deployed).' files deployed from zip');
    }

    // ?? SINGLE FILE ??????????????????????????????????????????
    $allowed = ['php','html','htm','css','js','json','sql','txt','md','htaccess','env','xml','svg','webp','png','jpg','ico'];
    if (!in_array($ext, $allowed) && $ext !== '') err("Extension .$ext not allowed");

    // Determine target path -- keep relative directory from filename if given via form
    $rel  = $_POST['path'] ?? $name;
    $rel  = ltrim(str_replace(['..','./'],'/',$rel), '/');
    $dest = EG_ROOT . $rel;

    // Create directories if needed
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) err("Cannot create directory: $dir");
    }

    // Backup existing file
    if (file_exists($dest)) {
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        $ts  = date('Ymd-His');
        $bak = BACKUP_DIR . $ts . '-' . $name;
        copy($dest, $bak);
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $dest)) err("Failed to write to $dest -- check permissions");

    ok([
        'name'  => $name,
        'path'  => $rel,
        'size'  => humanSize(filesize($dest)),
    ], "File deployed: $rel");

// ?? BROWSE ??????????????????????????????????????????????????
case 'browse':
    checkAuth();
    $relPath = $_GET['path'] ?? '';
    $relPath = ltrim(str_replace(['..','./'],'/',$relPath), '/');
    $dir     = realpath(EG_ROOT . $relPath);
    if (!$dir || strpos($dir, realpath(EG_ROOT)) !== 0) err('Invalid path');

    $items = [];
    foreach (scandir($dir) as $name) {
        if ($name === '.') continue;
        $full  = $dir . DIRECTORY_SEPARATOR . $name;
        $isDir = is_dir($full);
        $skip  = ['storage/backups', 'node_modules', '.git'];
        $relFull = str_replace(realpath(EG_ROOT).'/', '', $full);
        $skipThis = false;
        foreach ($skip as $s) { if (strpos($relFull,$s)===0) { $skipThis=true; break; } }
        if ($skipThis) continue;

        $items[] = [
            'name'     => $name,
            'is_dir'   => $isDir,
            'ext'      => $isDir ? '' : pathinfo($name, PATHINFO_EXTENSION),
            'size'     => $isDir ? '' : humanSize(filesize($full)),
            'modified' => date('Y-m-d H:i', filemtime($full)),
            'path'     => $relFull,
        ];
    }
    // Dirs first, then files, both alpha
    usort($items, fn($a,$b) => $b['is_dir'] <=> $a['is_dir'] ?: strcmp($a['name'],$b['name']));
    ok($items);

// ?? RESTORE BACKUP ??????????????????????????????????????????
case 'restore':
    checkAuth();
    $backup = $_POST['backup'] ?? '';
    $target = $_POST['target'] ?? '';
    if (!$backup || !$target) err('backup and target required');
    $bak  = realpath(BACKUP_DIR . $backup);
    $dest = realpath(EG_ROOT . $target);
    if (!$bak || strpos($bak, realpath(BACKUP_DIR)) !== 0) err('Invalid backup');
    if (!copy($bak, $dest)) err('Restore failed');
    ok(['restored'=>$target]);

// ?? LIST BACKUPS ????????????????????????????????????????????
case 'backups':
    checkAuth();
    $backups = [];
    if (is_dir(BACKUP_DIR)) {
        foreach (scandir(BACKUP_DIR) as $f) {
            if ($f==='.'||$f==='..') continue;
            $p = BACKUP_DIR.$f;
            $backups[] = ['name'=>$f,'size'=>humanSize(filesize($p)),'time'=>date('Y-m-d H:i',filemtime($p))];
        }
        rsort($backups);
    }
    ok($backups);

// ?? DELETE BACKUP ???????????????????????????????????????????
case 'delete_backup':
    checkAuth();
    $name = basename($_POST['name'] ?? '');
    if (!$name) err('Missing name');
    $path = BACKUP_DIR . $name;
    if (!file_exists($path)) err('Backup not found');
    unlink($path);
    ok(['deleted'=>$name]);

default:
    err("Unknown action: $action", 404);
}
