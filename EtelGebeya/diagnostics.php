<?php
// ============================================================
//  ETEL GEBEYA — Diagnostics API
//  Called by admin-panel.js Health Monitor widget
// ============================================================
declare(strict_types=1);
require __DIR__ . '/config.php';

auth();

$t0     = microtime(true);
$checks = [];

function chk(string $name, string $status, string $msg): array {
    return compact('name', 'status', 'msg');
}

// ── 1. Database connectivity ───────────────────────────────
$db = db();
if ($db) {
    $checks[] = chk('Database', 'pass', 'Connected — ' . DB_NAME);
} else {
    $checks[] = chk('Database', 'fail', $GLOBALS['__db_error'] ?? 'Connection failed');
}

// ── 2. Core tables ─────────────────────────────────────────
if ($db) {
    $core = ['users', 'user_roles', 'products', 'orders', 'transactions', 'vendors'];
    try {
        $rows     = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('strtolower', $rows);
        foreach ($core as $tbl) {
            $full = strtolower(t($tbl));
            if (in_array($full, $existing, true)) {
                $checks[] = chk('Table: ' . $tbl, 'pass', 'OK');
            } else {
                $checks[] = chk('Table: ' . $tbl, 'fail', 'missing — run schema.sql');
            }
        }
    } catch (Throwable $e) {
        $checks[] = chk('Tables', 'warn', substr($e->getMessage(), 0, 80));
    }
}

// ── 3. EtelID service reachability ────────────────────────
$etelid_url = defined('ETELID_SERVICE_URL') ? ETELID_SERVICE_URL : '';
if ($etelid_url) {
    $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    @file_get_contents(rtrim($etelid_url, '/') . '/service/resolve', false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) { $code = (int)$m[1]; break; }
    }
    if ($code >= 200 && $code < 500) {
        $checks[] = chk('EtelID API', 'pass', 'Reachable (HTTP ' . $code . ')');
    } else {
        $checks[] = chk('EtelID API', 'warn', $code ? 'HTTP ' . $code : 'Timeout / unreachable');
    }
} else {
    $checks[] = chk('EtelID API', 'info', 'Not configured');
}

// ── 4. Uploads directory ──────────────────────────────────
$uploads = dirname(__DIR__) . '/public/uploads';
if (is_dir($uploads) && is_writable($uploads)) {
    $checks[] = chk('Uploads dir', 'pass', 'Writable');
} elseif (is_dir($uploads)) {
    $checks[] = chk('Uploads dir', 'warn', 'Not writable');
} else {
    $checks[] = chk('Uploads dir', 'warn', 'Missing');
}

// ── 5. PHP version ────────────────────────────────────────
$checks[] = chk('PHP', version_compare(PHP_VERSION, '8.0') >= 0 ? 'pass' : 'warn', PHP_VERSION);

// ── 6. HTTPS ──────────────────────────────────────────────
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
$checks[] = chk('HTTPS', $https ? 'pass' : 'warn', $https ? 'On' : 'Off');

// ── Summary ───────────────────────────────────────────────
$summary = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];
foreach ($checks as $c) { $summary[$c['status']] = ($summary[$c['status']] ?? 0) + 1; }

ok(['checks' => $checks, 'summary' => $summary, 'elapsed_ms' => round((microtime(true) - $t0) * 1000)]);
