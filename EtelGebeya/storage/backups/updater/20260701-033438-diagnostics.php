<?php
// ============================================================
//  ETEL GEBEYA -- Diagnostics API
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

// -- 1. Database connectivity -------------------------------
$db = db();
if ($db) {
    $checks[] = chk('Database', 'pass', 'Connected -- ' . DB_NAME);
} else {
    $checks[] = chk('Database', 'fail', $GLOBALS['__db_error'] ?? 'Connection failed');
}

// -- 2. Core tables + critical columns ---------------------
if ($db) {
    // table => [critical columns that must exist]
    $core = [
        'users'      => ['id','full_name','email','phone','status'],
        'user_roles' => ['user_id','role'],
        'products'   => ['id','name_en','status','price'],
        'orders'     => ['id','status'],   // code and customer_id/user_id are checked below
        'sellers'    => ['id','user_id','shop_name','status'],
    ];
    // Optional tables -- warn if missing, don't fail
    $optional = ['transactions','payments','disputes','couriers','order_items','vendor_payouts','seller_payouts'];
    try {
        $rows     = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('strtolower', $rows);
        foreach ($core as $tbl => $requiredCols) {
            $full = strtolower(t($tbl));
            if (!in_array($full, $existing, true)) {
                $checks[] = chk('Table: ' . $tbl, 'fail', 'missing -- run schema.sql');
                continue;
            }
            // Check required columns
            $liveCols = $db->query("SHOW COLUMNS FROM `".t($tbl)."`")->fetchAll(PDO::FETCH_COLUMN);
            $missing  = array_diff($requiredCols, $liveCols);
            if ($missing) {
                $checks[] = chk('Table: ' . $tbl, 'warn', 'exists but missing columns: ' . implode(', ', $missing));
            } else {
                $checks[] = chk('Table: ' . $tbl, 'pass', count($liveCols) . ' columns OK');
            }
        }
        // Special check for orders FK column
        $oLiveCols = $db->query("SHOW COLUMNS FROM `".t('orders')."`")->fetchAll(PDO::FETCH_COLUMN);
        $hasCode   = in_array('code', $oLiveCols);
        $hasFk     = in_array('customer_id', $oLiveCols) || in_array('user_id', $oLiveCols);
        if (!$hasCode) $checks[] = chk('orders.code', 'warn', 'column missing -- order search will use ID fallback');
        if (!$hasFk)   $checks[] = chk('orders FK',   'fail', 'neither customer_id nor user_id found');
        // Optional tables -- check existence AND critical columns
        $optional = [
            'transactions'   => ['order_id','status'],
            'payments'       => ['order_id','status','amount'],
            'disputes'       => ['order_id','status'],
            'couriers'       => ['status'],
            'order_items'    => ['order_id'],
            'vendor_payouts' => ['vendor_id','amount','status'],
            'seller_payouts' => ['status'],
        ];
        foreach ($optional as $tbl => $needCols) {
            $full = strtolower(t($tbl));
            if (!in_array($full, $existing, true)) {
                $checks[] = chk('Table: ' . $tbl, 'info', 'not found (optional)');
                continue;
            }
            $liveCols = $db->query("SHOW COLUMNS FROM `".t($tbl)."`")->fetchAll(PDO::FETCH_COLUMN);
            $missing  = array_diff($needCols, $liveCols);
            if ($missing) {
                $checks[] = chk('Table: ' . $tbl, 'warn', 'exists -- missing cols: ' . implode(', ', $missing));
            } else {
                $checks[] = chk('Table: ' . $tbl, 'pass', 'present, columns OK');
            }
        }
    } catch (Throwable $e) {
        $checks[] = chk('Tables', 'warn', substr($e->getMessage(), 0, 80));
    }
}

// -- 3. EtelID service reachability ------------------------
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

// -- 4. Uploads directory ----------------------------------
$uploads = dirname(__DIR__) . '/public/uploads';
if (is_dir($uploads) && is_writable($uploads)) {
    $checks[] = chk('Uploads dir', 'pass', 'Writable');
} elseif (is_dir($uploads)) {
    $checks[] = chk('Uploads dir', 'warn', 'Not writable');
} else {
    $checks[] = chk('Uploads dir', 'warn', 'Missing');
}

// -- 5. PHP version ----------------------------------------
$checks[] = chk('PHP', version_compare(PHP_VERSION, '8.0') >= 0 ? 'pass' : 'warn', PHP_VERSION);

// -- 6. HTTPS ----------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
$checks[] = chk('HTTPS', $https ? 'pass' : 'warn', $https ? 'On' : 'Off');

// -- Summary -----------------------------------------------
$summary = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'info' => 0];
foreach ($checks as $c) { $summary[$c['status']] = ($summary[$c['status']] ?? 0) + 1; }

ok(['checks' => $checks, 'summary' => $summary, 'elapsed_ms' => round((microtime(true) - $t0) * 1000)]);
