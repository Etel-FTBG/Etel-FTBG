<?php
// ============================================================
//  ETEL GEBEYA — Diagnostics API
//  Called by admin-panel.js Health Monitor widget
// ============================================================
declare(strict_types=1);
require __DIR__ . '/config.php';

auth(); // requires valid admin token

$t0 = microtime(true);
$checks = [];

function chk(string $name, string $status, string $msg): array {
    return compact('name', 'status', 'msg');
}

// ── 1. Database connectivity ───────────────────────────────
try {
    $db = db();
    if ($db) {
        $db->query('SELECT 1');
        $checks[] = chk('Database', 'pass', 'Connected to ' . DB_NAME);
    } else {
        $checks[] = chk('Database', 'fail', $GLOBALS['__db_error'] ?? 'Connection failed');
    }
} catch (Throwable $e) {
    $checks[] = chk('Database', 'fail', $e->getMessage());
}

// ── 2. Core tables ─────────────────────────────────────────
if ($db) {
    $core_tables = ['users', 'user_roles', 'products', 'orders', 'transactions', 'vendors'];
    try {
        $existing = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($core_tables as $tbl) {
            $full = (defined('TBL') ? TBL : '') . $tbl;
            if (in_array($full, $existing, true)) {
                $checks[] = chk('Table: ' . $tbl, 'pass', 'exists');
            } else {
                $checks[] = chk('Table: ' . $tbl, 'fail', 'missing — run schema.sql');
            }
        }
    } catch (Throwable $e) {
        $checks[] = chk('Tables', 'warn', 'Could not list tables: ' . $e->getMessage());
    }
}

// ── 3. EtelID service reachability ────────────────────────
$etelid_url = defined('ETELID_SERVICE_URL') ? ETELID_SERVICE_URL : '';
if ($etelid_url) {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($etelid_url . '/service/resolve', false, $ctx);
    // A 400/403 response means the endpoint exists (key required) — that's correct
    $http_status = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
        $http_status = (int)($m[1] ?? 0);
    }
    if ($http_status >= 200 && $http_status < 500) {
        $checks[] = chk('EtelID API', 'pass', 'Reachable (HTTP ' . $http_status . ')');
    } else {
        $checks[] = chk('EtelID API', 'warn', $http_status ? 'HTTP ' . $http_status : 'Unreachable / timeout');
    }
} else {
    $checks[] = chk('EtelID API', 'info', 'ETELID_SERVICE_URL not configured');
}

// ── 4. Uploads directory writable ─────────────────────────
$uploads = dirname(__DIR__) . '/public/uploads';
if (is_dir($uploads) && is_writable($uploads)) {
    $checks[] = chk('Uploads dir', 'pass', 'Writable');
} elseif (is_dir($uploads)) {
    $checks[] = chk('Uploads dir', 'warn', 'Exists but not writable');
} else {
    $checks[] = chk('Uploads dir', 'warn', 'Directory missing');
}

// ── 5. PHP version ────────────────────────────────────────
$php_ver = PHP_VERSION;
$checks[] = chk('PHP Version', version_compare($php_ver, '8.0') >= 0 ? 'pass' : 'warn', $php_ver);

// ── 6. HTTPS ──────────────────────────────────────────────
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
$checks[] = chk('HTTPS', $https ? 'pass' : 'warn', $https ? 'Enabled' : 'Not detected');

// ── Summary ───────────────────────────────────────────────
$summary = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];
foreach ($checks as $c) { $summary[$c['status']] = ($summary[$c['status']] ?? 0) + 1; }
$elapsed_ms = round((microtime(true) - $t0) * 1000);

ok(['checks' => $checks, 'summary' => $summary, 'elapsed_ms' => $elapsed_ms]);
