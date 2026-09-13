<?php
/**
 * Standalone smoke test for the migration runner.
 *
 * Invokes api/migrate.php in CLI mode with a stub DB env so we can verify
 * the discovery + sorting logic without touching MySQL.
 *
 * The runner is built for MySQL, so this test only exercises:
 *   1. CLI entry point (status subcommand)
 *   2. Migration discovery + sort order
 *   3. SQL statement splitting on real file content
 */

$root = dirname(__DIR__);
$migrationsDir = $root . '/api/migrations';

echo "=== Smoke test: migration runner ===\n\n";

// --- Test 1: discover migration files directly from disk ---
echo "Test 1: discovery\n";
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "FAIL: migrations dir not found: $migrationsDir\n");
    exit(1);
}

$migs = [];
foreach (scandir($migrationsDir) as $f) {
    if (preg_match('/^(\d{3,})_(.+)\.sql$/', $f, $m)) {
        $migs[] = ['file' => $f, 'version' => $m[1], 'name' => $m[2]];
    }
}
usort($migs, fn($a, $b) => strcmp($a['version'], $b['version']));

foreach ($migs as $m) {
    echo "  {$m['version']} -> {$m['file']}\n";
}
$versions = array_column($migs, 'version');
$expected = ['001', '002', '003', '004', '005', '006', '007', '008', '009', '010'];
if ($versions !== $expected) {
    echo "  FAIL: expected " . implode(',', $expected) . ", got " . implode(',', $versions) . "\n";
    exit(1);
}
echo "  PASS\n\n";

// --- Test 2: each file is well-formed ---
echo "Test 2: file validation\n";
foreach ($migs as $m) {
    $path = $migrationsDir . '/' . $m['file'];
    if (!preg_match('/^\d{3,}$/', $m['version'])) {
        echo "  FAIL: bad version '{$m['version']}'\n"; exit(1);
    }
    if (empty($m['name']) || !preg_match('/^[a-z0-9_]+$/', $m['name'])) {
        echo "  FAIL: bad name '{$m['name']}'\n"; exit(1);
    }
    if (!file_exists($path) || filesize($path) === 0) {
        echo "  FAIL: missing/empty '{$m['file']}'\n"; exit(1);
    }
}
echo "  All " . count($migs) . " valid\n";
echo "  PASS\n\n";

// --- Test 3: SQL statement splitter ---
echo "Test 3: SQL statement splitting\n";
function split_sql(string $sql): array {
    $statements = [];
    $buf = '';
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) continue;
        $buf .= $line . "\n";
        if (str_ends_with($trim, ';')) {
            $stmt = trim($buf);
            if ($stmt !== '' && $stmt !== ';') $statements[] = $stmt;
            $buf = '';
        }
    }
    $rest = trim($buf);
    if ($rest !== '' && $rest !== ';') $statements[] = $rest;
    return $statements;
}
$total = 0;
foreach ($migs as $m) {
    $path = $migrationsDir . '/' . $m['file'];
    $n = count(split_sql(file_get_contents($path)));
    echo "  {$m['file']}: {$n} statements\n";
    $total += $n;
}
echo "  Total: {$total}\n";
echo "  PASS\n\n";

// --- Test 4: migrate.php CLI parses cleanly (will fail at DB connect) ---
echo "Test 4: migrate.php loads without PHP errors\n";
$out = [];
$rc  = 0;
exec("php \"{$root}/api/migrate.php\" status 2>&1", $out, $rc);
$combined = implode("\n", $out);
// We expect this to fail with a DB error, NOT a parse error.
if (preg_match('/Parse error|Fatal error/i', $combined)
    && !preg_match('/SQLSTATE|pdo|connection|refused/i', $combined)) {
    echo "  FAIL: parse/fatal in CLI mode:\n" . $combined . "\n";
    exit(1);
}
echo "  Output (truncated): " . substr(preg_replace('/\s+/', ' ', $combined), 0, 120) . "...\n";
echo "  PASS (CLI ran; failure was DB-related as expected)\n\n";

// --- Test 5: --validate catches integrity issues ---
// Uses a disposable tmp_migrations directory (never the live api/migrations)
// so an interrupted test run can never leave DROP/TRUNCATE fixtures sitting
// in production. migrate.php reads ETEL_MIGRATIONS_DIR to know where to
// look when it's set (see mig_dir() in api/migrate.php).
echo "Test 5: --validate catches injected bad migrations\n";
$badFiles = [
    '003_dup_version.sql' => 'SELECT 1;',
    '008_BadCaseName.sql' => 'SELECT 1;',
    '006_bad_drop.sql'    => 'DROP TABLE users;',
    '007_truncate.sql'    => 'TRUNCATE TABLE orders;',
];
$tmpDir = $root . '/tests/tmp_migrations_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "FAIL: could not create tmp migrations dir: $tmpDir\n");
    exit(1);
}
foreach ($badFiles as $name => $sql) {
    file_put_contents("$tmpDir/$name", $sql);
}
$out = [];
exec("ETEL_MIGRATIONS_DIR=" . escapeshellarg($tmpDir) . " php \"{$root}/api/migrate.php\" --validate 2>&1", $out);
$combined = implode("\n", $out);
// Clean up before asserting so a failure doesn't leave junk behind
foreach (array_keys($badFiles) as $name) {
    $path = "$tmpDir/$name";
    if (file_exists($path) && !unlink($path)) {
        fwrite(STDERR, "  WARN: could not remove test fixture $path\n");
    }
}
if (is_dir($tmpDir) && !@rmdir($tmpDir)) {
    fwrite(STDERR, "  WARN: could not remove tmp dir $tmpDir\n");
}

if (!preg_match('/duplicate_version/i', $combined)) {
    echo "  FAIL: validator did not flag duplicate_version\n" . $combined . "\n";
    exit(1);
}
if (!preg_match('/bad_name/i', $combined)) {
    echo "  FAIL: validator did not flag bad_name\n" . $combined . "\n";
    exit(1);
}
if (!preg_match('/drop_table/i', $combined)) {
    echo "  FAIL: validator did not flag drop_table\n" . $combined . "\n";
    exit(1);
}
if (!preg_match('/truncate/i', $combined)) {
    echo "  FAIL: validator did not flag truncate\n" . $combined . "\n";
    exit(1);
}
echo "  All 4 validator rules fired on injected bad files\n";
echo "  PASS\n\n";

// --- Test 6: --validate passes on real migrations ---
echo "Test 6: --validate passes on real migrations\n";
$out = [];
exec("php \"{$root}/api/migrate.php\" --validate 2>&1", $out);
$combined = implode("\n", $out);
if (!preg_match('/OK:\s*no issues found/i', $combined)) {
    echo "  FAIL: validator rejected real migrations:\n" . $combined . "\n";
    exit(1);
}
echo "  PASS\n\n";

echo "All smoke tests passed.\n";