<?php
// ============================================================
// ETEL TAXI - Migration runner  v1.0
// Visit /api/migrate.php?key=YOUR_TAXI_ADMIN_KEY&apply=1 to run any
// migration files that haven't been applied yet. Safe to re-run - each
// file only runs once, tracked in _etel_taxi_migrations.
// ============================================================

header('Content-Type: text/plain');
require_once __DIR__ . '/config.php';

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!defined('TAXI_ADMIN_KEY') || TAXI_ADMIN_KEY === '' || !hash_equals(TAXI_ADMIN_KEY, $key)) {
    http_response_code(401);
    echo "Unauthorized - set TAXI_ADMIN_KEY in api/.env first, then pass ?key=that value.\n";
    exit;
}

try {
    // MYSQL_ATTR_USE_BUFFERED_QUERY forced on - this host's PDO driver
    // defaults to unbuffered, so an EXECUTE of dynamic SQL that returns
    // a result set (e.g. 002's "SELECT 1" branch) leaves the connection
    // busy and the next exec() below fails with error 2014.
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true));
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . "\n";
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS `_etel_taxi_migrations` (
    `filename` VARCHAR(255) NOT NULL PRIMARY KEY,
    `applied_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query("SELECT filename FROM _etel_taxi_migrations")->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$dryRun = !isset($_GET['apply']);
echo $dryRun ? "DRY RUN - add &apply=1 to actually run these:\n\n" : "APPLYING:\n\n";

foreach ($files as $path) {
    $name = basename($path);
    if (in_array($name, $applied, true)) {
        echo "SKIP  (already applied)  $name\n";
        continue;
    }
    echo ($dryRun ? "WOULD RUN  " : "RUNNING    ") . "$name\n";
    if ($dryRun) continue;

    $sql = file_get_contents($path);
    // Strip full-line "--" comments BEFORE splitting into statements. A
    // header comment block that runs straight into the first real
    // statement (no blank ";\n" between them) used to get merged with
    // it into one chunk; that chunk still started with "--", so the
    // skip-check below silently dropped the comment AND the real SQL
    // statement together (e.g. migration 002's first SET @col_exists=
    // statement was never executed, so its ALTER TABLE never ran even
    // though the migration reported OK).
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Naive statement split on ";\n" - matches the same simple
    // approach used elsewhere in Etel, so every migration file in this
    // project keeps one statement-terminating ";" per line.
    $statements = array_filter(array_map('trim', explode(";\n", $sql)));
    try {
        $pdo->beginTransaction();
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $pdo->prepare("INSERT INTO _etel_taxi_migrations (filename, applied_at) VALUES (?, NOW())")->execute(array($name));
        // A DDL statement (ALTER/CREATE/etc) above already implicitly
        // committed everything, including the INSERT just above - calling
        // commit() again with nothing left open throws "There is no
        // active transaction", even though the migration fully succeeded.
        // Same inTransaction() guard as the rollBack() path below.
        if ($pdo->inTransaction()) $pdo->commit();
        echo "  OK\n";
    } catch (Exception $e) {
        // Print the real error FIRST - a DDL statement (ALTER/CREATE/etc,
        // including one run via PREPARE/EXECUTE dynamic SQL) implicitly
        // commits the transaction the moment it runs, success or failure.
        // If that already happened, calling rollBack() below throws its
        // own "no active transaction" exception - uncaught, that crashes
        // the whole script with a silent HTTP 500 and this message never
        // gets printed. inTransaction() check + its own try/catch avoids
        // that so the ACTUAL error is always visible.
        echo "  FAILED: " . $e->getMessage() . "\n";
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Exception $e2) {
            echo "  (rollback also failed, likely already implicitly committed by a DDL statement above: " . $e2->getMessage() . ")\n";
        }
        echo "\nStopped - fix the error above before continuing.\n";
        exit;
    }
}

echo "\nDone.\n";
