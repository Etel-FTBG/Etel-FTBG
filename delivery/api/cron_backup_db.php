<?php
/**
 * ETEL Delivery - database backup and backup check (cron entry point).
 *
 * cPanel -> Cron Jobs -> once a day (for example 02:30):
 *   wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_backup_db.php?key=YOUR_CRON_KEY"
 * It reuses CRON_DISPATCH_KEY from api/.env, like the other cron scripts.
 *
 * What it does (?action=...):
 *   run     (default) write a compressed SQL dump + a small manifest, then prune old ones
 *   verify  re-read the newest dump end to end and prove it is complete and intact
 *   list    show the stored backups
 *
 * Where the files go: BACKUP_DIR in api/.env if you set it, otherwise a folder
 * called etel_backups next to public_html (outside the web), otherwise
 * api/_backups (blocked from the web by .htaccess). The dump holds password
 * hashes and personal data, so keep it out of the web where you can, and copy
 * it off the server now and then (cPanel File Manager -> download).
 * BACKUP_KEEP (default 7) sets how many to keep.
 *
 * Restore: cPanel -> phpMyAdmin -> pick the database -> Import -> choose the
 * .sql.gz file (phpMyAdmin reads gzip directly). If it is bigger than the
 * upload limit, use cPanel -> Backup Wizard / ask the host to restore it.
 * Not covered here: views, triggers and stored routines (this app uses none).
 * The same file also works with:  gunzip -c file.sql.gz | mysql -u USER -p DB
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(600);
@ini_set('memory_limit', '256M');

function bk_reply($arr, $code = 200) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$_bk_env = etel_parse_env_file(__DIR__ . '/.env');
function bk_env($key, $default = '') {
    global $_bk_env;
    if (isset($_bk_env[$key]) && $_bk_env[$key] !== '') return $_bk_env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

// Works from the web (?key=) and from the command line (key=... action=...).
$params = $_GET;
if (php_sapi_name() === 'cli' && isset($argv)) {
    foreach (array_slice($argv, 1) as $a) { if (strpos($a, '=') !== false) { list($k, $v) = explode('=', $a, 2); $params[$k] = $v; } }
}
$expectedKey = bk_env('CRON_DISPATCH_KEY', '');
$givenKey    = isset($params['key']) ? $params['key'] : '';
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    bk_reply(array('success' => false, 'message' => 'Missing or wrong ?key= - set CRON_DISPATCH_KEY in api/.env first'), 403);
}
$action = isset($params['action']) ? $params['action'] : 'run';

function bk_connect() {
    return new PDO(
        'mysql:host=' . bk_env('DB_HOST', 'localhost') . ';dbname=' . bk_env('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
        bk_env('DB_USER', ''), bk_env('DB_PASS', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
              PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false)
    );
}

/* Folder for the backups; returns array(path, inWeb). */
function bk_dir() {
    $set = bk_env('BACKUP_DIR', '');
    $candidates = array();
    if ($set !== '') $candidates[] = array($set, false);
    $outside = dirname(dirname(dirname(__DIR__))) . '/etel_backups'; // next to public_html
    $candidates[] = array($outside, false);
    $candidates[] = array(__DIR__ . '/_backups', true);
    foreach ($candidates as $c) {
        $d = $c[0];
        if (!is_dir($d)) { @mkdir($d, 0700, true); }
        if (is_dir($d) && is_writable($d)) {
            if ($c[1]) { // inside the web tree: lock it down
                @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
                @file_put_contents($d . '/index.html', '');
            }
            return array(realpath($d), $c[1]);
        }
    }
    return array(null, false);
}

function bk_q($pdo, $v) {
    return $v === null ? 'NULL' : $pdo->quote($v);
}

/* Writes one table. Rows are one tuple per line so the check can count them. */
function bk_dump_table($gz, $pdo, $table) {
    $t = '`' . str_replace('`', '``', $table) . '`';
    gzwrite($gz, "\n-- ----------------------------\n-- Table: $table\n-- ----------------------------\n");
    gzwrite($gz, "DROP TABLE IF EXISTS $t;\n");
    $cr = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM);
    gzwrite($gz, $cr[1] . ";\n");
    // Columns whose bytes must be written as hex (binary/blob).
    $binCols = array(); $names = array();
    foreach ($pdo->query("SHOW COLUMNS FROM $t")->fetchAll() as $col) {
        $names[] = $col['Field'];
        $binCols[] = (bool) preg_match('/(blob|binary|varbinary)/i', $col['Type']);
    }
    $stmt = $pdo->query("SELECT * FROM $t");
    $rows = 0; $inBatch = 0; $pending = null;
    // One tuple per line, 200 per INSERT. A tuple's separator depends on whether
    // another row follows, so each one is held back until the next row is read.
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $vals = array();
        foreach ($r as $i => $v) {
            if ($v === null) $vals[] = 'NULL';
            elseif ($binCols[$i]) $vals[] = ($v === '' ? "''" : '0x' . bin2hex($v));
            else $vals[] = $pdo->quote($v);
        }
        $tuple = '(' . implode(',', $vals) . ')';
        if ($pending !== null) {
            gzwrite($gz, $pending . (($inBatch >= 200) ? ";\n" : ",\n"));
            if ($inBatch >= 200) $inBatch = 0;
        }
        if ($inBatch === 0) gzwrite($gz, "INSERT INTO $t VALUES\n");
        $pending = $tuple; $inBatch++; $rows++;
    }
    $stmt->closeCursor();
    if ($pending !== null) gzwrite($gz, $pending . ";\n");
    return $rows;
}

function bk_list($dir) {
    $out = array();
    foreach (glob($dir . '/etel-*.sql.gz') as $f) {
        $out[] = array('file' => basename($f), 'bytes' => filesize($f), 'mtime' => filemtime($f));
    }
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    return $out;
}

list($dir, $inWeb) = bk_dir();
if (!$dir) bk_reply(array('success' => false, 'message' => 'No writable folder for backups. Set BACKUP_DIR in api/.env.'), 500);

try {
    if ($action === 'list') {
        $items = bk_list($dir);
        foreach ($items as &$it) { $it['age_hours'] = round((time() - $it['mtime']) / 3600, 1); $it['mtime'] = date('Y-m-d H:i:s', $it['mtime']); }
        bk_reply(array('success' => true, 'folder_in_web_tree' => $inWeb, 'backups' => $items));
    }

    if ($action === 'run') {
        $pdo = bk_connect();
        $name = 'etel-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $path = $dir . '/' . $name . '.sql.gz';
        $tmp  = $path . '.part';
        $gz = gzopen($tmp, 'wb6');
        if (!$gz) bk_reply(array('success' => false, 'message' => 'Could not create the backup file'), 500);
        gzwrite($gz, "-- ETEL Delivery database backup\n-- Created: " . date('Y-m-d H:i:s') . " (Africa/Addis_Ababa)\n-- Database: " . bk_env('DB_NAME', '') . "\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $tables = array();
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) $tables[] = $r[0];
        // A second connection for the row streams: an open unbuffered read blocks other queries.
        $pdo2 = bk_connect();
        $counts = array(); $total = 0;
        foreach ($tables as $t) {
            $n = bk_dump_table($gz, $pdo2, $t);
            $counts[$t] = $n; $total += $n;
        }
        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- ETEL-BACKUP-COMPLETE tables=" . count($tables) . " rows=" . $total . "\n");
        gzclose($gz);
        if (!rename($tmp, $path)) bk_reply(array('success' => false, 'message' => 'Could not finish the backup file'), 500);
        @chmod($path, 0600);
        $manifest = array('file' => basename($path), 'created' => date('Y-m-d H:i:s'), 'bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path), 'tables' => $counts, 'rows' => $total);
        file_put_contents($dir . '/' . $name . '.json', json_encode($manifest));
        @chmod($dir . '/' . $name . '.json', 0600);
        // Retention
        $keep = max(1, intval(bk_env('BACKUP_KEEP', '7')));
        $all = bk_list($dir);
        $pruned = 0;
        foreach (array_slice($all, $keep) as $old) {
            @unlink($dir . '/' . $old['file']);
            @unlink($dir . '/' . preg_replace('/\.sql\.gz$/', '.json', $old['file']));
            $pruned++;
        }
        // Optional automatic copy to an S3-compatible bucket (lib/backup_offsite.php). Never stops the backup itself.
        $offsite = array('off', 'not set up');
        try {
            require_once __DIR__ . '/lib/backup_offsite.php';
            $offsite = etel_backup_push_offsite(array(
                'endpoint' => bk_env('BACKUP_S3_ENDPOINT'), 'region' => bk_env('BACKUP_S3_REGION'), 'bucket' => bk_env('BACKUP_S3_BUCKET'),
                'key' => bk_env('BACKUP_S3_KEY'), 'secret' => bk_env('BACKUP_S3_SECRET'), 'prefix' => bk_env('BACKUP_S3_PREFIX'),
                'passphrase' => bk_env('BACKUP_ENC_PASSPHRASE')), $path);
            if ($offsite[0] !== 'off') {
                if (!defined('APP_VER')) define('APP_VER', 'cron-backup');
                require_once __DIR__ . '/lib/system_health.php';
                etel_health_heartbeat($pdo, 'backup_offsite', $offsite[0] . ': ' . $offsite[1]);
            }
        } catch (Exception $e) { $offsite = array('failed', 'unexpected error'); error_log('[ETEL][backup] off-site copy: ' . $e->getMessage()); }
        bk_reply(array('success' => true, 'offsite' => $offsite[0] . ': ' . $offsite[1], 'file' => basename($path), 'bytes' => $manifest['bytes'], 'tables' => count($tables),
            'rows' => $total, 'kept' => min($keep, count($all)), 'pruned' => $pruned, 'folder_in_web_tree' => $inWeb,
            'warning' => $inWeb ? 'Backups are inside the web folder (blocked by .htaccess). Set BACKUP_DIR to a folder outside public_html.' : null));
    }

    if ($action === 'verify') {
        $all = bk_list($dir);
        if (!$all) bk_reply(array('success' => false, 'ok' => false, 'message' => 'No backups found'), 404);
        $file = $all[0]['file'];
        if (!empty($params['file'])) {
            $file = basename($params['file']);
            if (!is_file($dir . '/' . $file)) bk_reply(array('success' => false, 'ok' => false, 'message' => 'No such backup'), 404);
        }
        $path = $dir . '/' . $file;
        $problems = array();
        $mfPath = preg_replace('/\.sql\.gz$/', '.json', $path);
        $mf = is_file($mfPath) ? json_decode(file_get_contents($mfPath), true) : null;
        if (!$mf) $problems[] = 'manifest missing';
        if ($mf && $mf['sha256'] !== hash_file('sha256', $path)) $problems[] = 'file changed since it was written (checksum differs)';
        $gz = gzopen($path, 'rb');
        if (!$gz) bk_reply(array('success' => false, 'ok' => false, 'message' => 'Cannot open the backup'), 500);
        $cur = null; $counts = array(); $complete = false; $createSeen = array(); $total = 0;
        while (($line = gzgets($gz, 1048576)) !== false) {
            if (strpos($line, 'INSERT INTO `') === 0) { preg_match('/^INSERT INTO `((?:[^`]|``)+)`/', $line, $m); $cur = str_replace('``', '`', $m[1]); if (!isset($counts[$cur])) $counts[$cur] = 0; continue; }
            if ($line !== '' && $line[0] === '(' && $cur !== null) { $counts[$cur]++; $total++; continue; }
            if (strpos($line, 'CREATE TABLE `') === 0) { preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $line, $m); $createSeen[str_replace('``', '`', $m[1])] = true; $cur = null; continue; }
            if (strpos($line, '-- ETEL-BACKUP-COMPLETE') === 0) { $complete = true; }
        }
        $eof = gzeof($gz); gzclose($gz);
        if (!$eof) $problems[] = 'file ends early (corrupt gzip)';
        if (!$complete) $problems[] = 'completion marker missing - the backup was cut short';
        if ($mf) {
            foreach ($mf['tables'] as $t => $n) {
                if (!isset($createSeen[$t])) $problems[] = "table $t missing from the dump";
                $got = isset($counts[$t]) ? $counts[$t] : 0;
                if ($got !== intval($n)) $problems[] = "table $t: dump has $got rows, backup recorded $n";
            }
        }
        // Compare with the live database (information only: it keeps changing).
        $live = array();
        try {
            $pdo = bk_connect();
            foreach ($counts as $t => $n) {
                $c = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
                if (intval($c) !== $n) $live[$t] = array('backup' => $n, 'live_now' => intval($c));
            }
        } catch (Exception $e) { $live = array('error' => 'could not read live counts'); }
        bk_reply(array('success' => true, 'ok' => count($problems) === 0, 'file' => $file,
            'age_hours' => round((time() - filemtime($path)) / 3600, 1), 'tables' => count($createSeen), 'rows' => $total,
            'problems' => $problems, 'differs_from_live_now' => $live));
    }

    bk_reply(array('success' => false, 'message' => 'Unknown action. Use run, verify or list.'), 400);
} catch (Exception $e) {
    error_log('[ETEL][backup] ' . $e->getMessage());
    bk_reply(array('success' => false, 'message' => 'Backup failed - see the server error log'), 500);
}
