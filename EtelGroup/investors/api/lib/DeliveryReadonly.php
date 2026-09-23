<?php
// ============================================================
// Investor Relations — read-only Delivery & Ride connector
// Opens a SEPARATE PDO connection to Delivery & Ride's own database
// (never EtelGroup's), using credentials the admin entered once in the
// setup wizard (stored in ir_settings, password encrypted - see
// Crypto.php). This connection must be opened with a dedicated MySQL
// user that has SELECT-only privilege on Delivery & Ride's `rides`
// table - see the grants documented in the setup wizard / README. This
// code never issues INSERT/UPDATE/DELETE against that connection, but
// the real safety boundary is the MySQL grant itself, not this file.
//
// Queries against Delivery & Ride's real `rides` table (see
// delivery/api/controllers/RideController.php for the authoritative
// schema this mirrors): status ENUM(...,'completed',...), fare_paid
// DECIMAL, completed_at DATETIME. "Gross fare" = SUM(fare_paid) for
// completed trips - the amount actually paid, not the pre-trip
// fare_estimate.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

function ir_delivery_config(PDO $pdo): array {
    return [
        'host' => ir_get_setting($pdo, 'delivery_db_host', '') ?? '',
        'name' => ir_get_setting($pdo, 'delivery_db_name', '') ?? '',
        'user' => ir_get_setting($pdo, 'delivery_db_user', '') ?? '',
        'pass' => ir_decrypt(ir_get_setting($pdo, 'delivery_db_pass_enc', '') ?? ''),
    ];
}

function ir_delivery_is_configured(PDO $pdo): bool {
    $c = ir_delivery_config($pdo);
    return $c['host'] !== '' && $c['name'] !== '' && $c['user'] !== '';
}

// Opens a fresh read-only connection every call (this is a low-volume
// admin dashboard, not a hot path - no need for a persistent pool, and
// a fresh connection means a revoked/changed grant is reflected
// immediately rather than cached on a stale connection).
function ir_delivery_connect(PDO $pdo): PDO {
    $c = ir_delivery_config($pdo);
    if (!ir_delivery_is_configured($pdo)) {
        throw new Exception('Delivery & Ride read-only connection is not configured yet - finish the setup wizard first.');
    }
    $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

// Used by the setup wizard's "Test Connection" button - connects and
// runs one trivial SELECT against `rides`, never anything else.
function ir_delivery_test_connection(PDO $pdo): array {
    $dconn = ir_delivery_connect($pdo);
    $count = (int)$dconn->query("SELECT COUNT(*) FROM rides WHERE status = 'completed'")->fetchColumn();
    return ['ok' => true, 'completed_rides_total' => $count];
}

// Pulls daily completed-trip counts and gross fares for [from, to]
// (inclusive, Y-m-d) directly from Delivery & Ride, and upserts them
// into ir_daily_trip_cache. Returns the same rows it just cached.
// Throws on any connection/query failure - callers MUST let that
// surface as a "not connected" state, never silently substitute cached
// or zero data without saying so.
function ir_delivery_fetch_daily(PDO $pdo, string $from, string $to): array {
    $dconn = ir_delivery_connect($pdo);
    $stmt = $dconn->prepare(
        "SELECT DATE(completed_at) AS trip_date,
                COUNT(*) AS trip_count,
                COALESCE(SUM(fare_paid), 0) AS gross_fare
         FROM rides
         WHERE status = 'completed'
           AND completed_at IS NOT NULL
           AND DATE(completed_at) BETWEEN ? AND ?
         GROUP BY DATE(completed_at)
         ORDER BY trip_date"
    );
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();

    ir_ensure_core_tables($pdo);
    $upsert = $pdo->prepare(
        'INSERT INTO ir_daily_trip_cache (trip_date, trip_count, gross_fare, fetched_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE trip_count = VALUES(trip_count), gross_fare = VALUES(gross_fare), fetched_at = NOW()'
    );
    foreach ($rows as $r) {
        $upsert->execute([$r['trip_date'], (int)$r['trip_count'], (float)$r['gross_fare']]);
    }
    return $rows;
}

// Rolling 4-week (28-day) average trips/day, ending today, for the
// Tranche 2 milestone panel - real data only. Refreshes the cache for
// that window first; throws (never fabricates) if the connection isn't
// configured or fails.
function ir_delivery_rolling_4week_avg(PDO $pdo): array {
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-27 days'));
    $rows = ir_delivery_fetch_daily($pdo, $from, $to);
    $totalTrips = 0;
    foreach ($rows as $r) $totalTrips += (int)$r['trip_count'];
    return [
        'from' => $from,
        'to' => $to,
        'days' => 28,
        'total_trips' => $totalTrips,
        'avg_per_day' => round($totalTrips / 28, 1),
        'daily' => $rows,
    ];
}
