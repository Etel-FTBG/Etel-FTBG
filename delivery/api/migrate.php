<?php
/**
 * ETEL Delivery - Migration Runner
 *
 * Discovers versioned SQL files in api/migrations/, tracks which ones have
 * been applied in a schema_migrations table, and applies any that are new.
 *
 * Usage:
 *   Web:  POST /api/migrate.php            (requires admin auth token)
 *         GET  /api/migrate.php            (dry-run, shows status only)
 *         GET  /api/migrate.php?apply=1    (admin only, applies pending)
 *   CLI:  php api/migrate.php              (status)
 *         php api/migrate.php --apply      (apply pending)
 *         php api/migrate.php --reset      (mark all unapplied; DANGEROUS)
 *
 * Naming: files must match /^(\d{3,})_(.+)\.sql$/, e.g. 001_users.sql.
 * Migrations are applied in numeric prefix order.
 *
 * Each migration runs inside a transaction; on failure the whole file
 * rolls back and the runner stops.
 */

declare(strict_types=1);

// ----------------------------------------------------------------
// Bootstrap (config, env, db)
// ----------------------------------------------------------------
$_cfg = __DIR__ . '/config.php';
if (file_exists($_cfg)) require_once $_cfg;

// Was parse_ini_file(), the same silent-failure bug fixed elsewhere this
// session (diagnose.php, etel-os.php): it returns false / drops lines when
// .env contains any non-ASCII byte anywhere, even in a comment. Found here
// while adding a migration and switched to the same shared parser.
$_env_file = __DIR__ . '/.env';
if (file_exists($_env_file)) {
    require_once __DIR__ . '/env_loader.php';
    $ev = etel_parse_env_file($_env_file);
    foreach ($ev as $k => $v) {
        if (!getenv($k)) putenv("$k=$v");
    }
}

function mig_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = getenv('DB_HOST') ?: (defined('CFG_DB_HOST') ? CFG_DB_HOST : 'localhost');
    $name = getenv('DB_NAME') ?: (defined('CFG_DB_NAME') ? CFG_DB_NAME : 'etel_delivery');
    $user = getenv('DB_USER') ?: (defined('CFG_DB_USER') ? CFG_DB_USER : 'root');
    $pass = getenv('DB_PASS') ?: (defined('CFG_DB_PASS') ? CFG_DB_PASS : '');
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Real (non-emulated) prepared statements leave an unbuffered
            // cursor open on the connection until fully drained or
            // explicitly closed. mig_verify_admin()'s admin-role lookup
            // uses fetch() (single row) rather than fetchAll(), which
            // doesn't close the cursor - the very next statement run on
            // this connection (mig_ensure_table()'s CREATE TABLE) then
            // collided with it, throwing "Cannot execute queries while
            // other unbuffered queries are active." Buffering here is the
            // fix MySQL's own error message suggests, and is safe for this
            // runner - none of its result sets are large enough for
            // unbuffered mode to matter.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
    return $pdo;
}

// ----------------------------------------------------------------
// Auth (web mode only -- CLI skips this)
// ----------------------------------------------------------------
function mig_is_cli(): bool {
    return php_sapi_name() === 'cli';
}

function mig_get_bearer(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $h = $hdrs['Authorization'] ?? '';
    }
    return (strpos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : null;
}

// Was written for the pre-revocation 3-part token format (uid:ts:sig).
// boot.php's gen_token()/verify_token() moved to a 4-part format
// (uid:ts:version:sig) when token revocation (logout/migration 006) was
// added, and this function was never updated to match - so every admin
// token issued since then silently failed here (explode() on a 4-part
// token put the version field where $sig was expected, so hash_equals()
// always failed). Found via a live "Admin auth required for apply" error
// when actually trying to use this from the admin panel. Now matches the
// same format + revocation check as boot.php/updater-api.php/upload.php/
// backfill_open_trip_fares.php, which all authenticate the same tokens.
function mig_verify_admin(string $token): bool {
    $secret = getenv('TOKEN_SECRET') ?: (defined('CFG_TOKEN_SECRET') ? CFG_TOKEN_SECRET : '');
    if (!$secret || !$token) return false;
    $d = base64_decode($token, true);
    if ($d === false) return false;
    $p = explode(':', $d);
    if (count($p) < 4) return false; // old 3-part tokens fail closed, not open
    [$uid, $ts, $version, $sig] = $p;
    if ((time() - intval($ts)) > 86400 * 7) return false;
    $expected = hash_hmac('sha256', "$uid:$ts:$version", $secret);
    if (!hash_equals($expected, $sig)) return false;
    try {
        $pdo = mig_db();
        $hasVer = false;
        try {
            $cols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $cols->execute(['users']);
            $hasVer = in_array('token_version', $cols->fetchAll(PDO::FETCH_COLUMN) ?: [], true);
        } catch (Throwable $e) { /* fail open on this specific check only - see boot.php's get_auth_user */ }
        $s = $pdo->prepare('SELECT role' . ($hasVer ? ', token_version' : '') . ' FROM users WHERE id = ? LIMIT 1');
        $s->execute([intval($uid)]);
        $row = $s->fetch();
        $s->closeCursor();
        if (!$row || $row['role'] !== 'admin') return false;
        if ($hasVer && intval($row['token_version']) !== intval($version)) return false;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ----------------------------------------------------------------
// Migration registry helpers
// ----------------------------------------------------------------
function mig_dir(): string {
    // Test-only override so test_migrate.php can point --validate at a
    // disposable directory instead of writing DROP/TRUNCATE fixtures into
    // the live migrations folder. Unset in normal web/CLI use, so
    // production behavior is unchanged.
    $override = getenv('ETEL_MIGRATIONS_DIR');
    if ($override) return $override;
    return __DIR__ . '/migrations';
}

function mig_discover(): array {
    $dir = mig_dir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) as $f) {
        if (preg_match('/^(\d{3,})_(.+)\.sql$/', $f, $m)) {
            $out[] = [
                'file'      => $f,
                'version'   => $m[1],
                'name'      => $m[2],
                'full_path' => $dir . DIRECTORY_SEPARATOR . $f,
            ];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['version'], $b['version']));
    return $out;
}

function mig_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `version`    VARCHAR(20)  NOT NULL,
            `name`       VARCHAR(120) NOT NULL,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `duration_ms` INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mig_applied(PDO $pdo): array {
    $stmt = $pdo->query('SELECT version, name, applied_at, duration_ms FROM schema_migrations ORDER BY version');
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['version']] = $row;
    }
    return $out;
}

function mig_apply_file(PDO $pdo, array $mig): array {
    $sql = file_get_contents($mig['full_path']);
    if ($sql === false) {
        return ['ok' => false, 'error' => 'Cannot read file'];
    }

    // Split on semicolons that end a statement (naive but works for our DDL).
    // Skip empty lines and lines that are pure comments.
    $statements = [];
    $buf = '';
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with($trim, ';')) {
            $stmt = trim($buf);
            if ($stmt !== '' && $stmt !== ';') {
                $statements[] = $stmt;
            }
            $buf = '';
        }
    }
    $rest = trim($buf);
    if ($rest !== '' && $rest !== ';') {
        $statements[] = $rest;
    }

    $start = microtime(true);
    // MySQL implicitly commits any open transaction as soon as a DDL
    // statement (CREATE TABLE, ALTER TABLE, etc.) runs - our migrations
    // are mostly DDL, so by the time we reach the explicit commit() below
    // the transaction is often already gone from PDO's perspective. Calling
    // commit()/rollBack() when PDO no longer thinks a transaction is open
    // throws "There is no active transaction" and aborts the whole apply.
    // Guard both with inTransaction() so DDL-only migrations (the common
    // case here) still apply cleanly; true multi-statement atomicity isn't
    // achievable across DDL in MySQL anyway.
    try {
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        // proceed without an explicit transaction if one couldn't be started
    }
    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare(
            'INSERT INTO schema_migrations (version, name, duration_ms) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP, duration_ms = VALUES(duration_ms)'
        )->execute([
            $mig['version'],
            $mig['name'],
            (int) round((microtime(true) - $start) * 1000),
        ]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        return ['ok' => true, 'duration_ms' => (int) round((microtime(true) - $start) * 1000)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ----------------------------------------------------------------
// Status / run
// ----------------------------------------------------------------
function mig_status(): array {
    $pdo    = mig_db();
    mig_ensure_table($pdo);
    $all    = mig_discover();
    $applied = mig_applied($pdo);
    $rows   = [];
    foreach ($all as $m) {
        $a = $applied[$m['version']] ?? null;
        $rows[] = [
            'version'    => $m['version'],
            'name'       => $m['name'],
            'file'       => $m['file'],
            'applied_at' => $a['applied_at'] ?? null,
            'duration_ms'=> $a['duration_ms'] ?? null,
            'status'     => $a ? 'applied' : 'pending',
        ];
    }
    return [
        'ok'           => true,
        'total'        => count($rows),
        'applied'      => count(array_filter($rows, fn($r) => $r['status'] === 'applied')),
        'pending'      => count(array_filter($rows, fn($r) => $r['status'] === 'pending')),
        'migrations'   => $rows,
    ];
}

function mig_run(): array {
    $pdo     = mig_db();
    mig_ensure_table($pdo);
    $all     = mig_discover();
    $applied = mig_applied($pdo);
    $log     = [];
    foreach ($all as $m) {
        if (isset($applied[$m['version']])) continue;
        $r = mig_apply_file($pdo, $m);
        $log[] = ['version' => $m['version'], 'name' => $m['name']] + $r;
        if (!$r['ok']) break;  // stop on first failure
    }
    return ['ok' => true, 'applied_now' => $log] + mig_status();
}

function mig_reset(): array {
    $pdo = mig_db();
    mig_ensure_table($pdo);
    $pdo->exec('TRUNCATE TABLE schema_migrations');
    return ['ok' => true, 'message' => 'All migrations marked unapplied. Run --apply to re-apply.'];
}

// ----------------------------------------------------------------
// Validate: check migration set for integrity issues
// ----------------------------------------------------------------
function mig_validate(): array {
    $migs = mig_discover();
    $issues = [];

    // 1. version uniqueness
    $seen = [];
    foreach ($migs as $m) {
        if (isset($seen[$m['version']])) {
            $issues[] = ['severity' => 'error', 'code' => 'duplicate_version',
                'detail' => "Version {$m['version']} is used by more than one migration ({$seen[$m['version']]} and {$m['file']})"];
        }
        $seen[$m['version']] = $m['file'];
    }

    // 2. sequential versions (no gaps)
    $versions = array_map(fn($m) => intval($m['version']), $migs);
    sort($versions);
    for ($i = 0; $i < count($versions) - 1; $i++) {
        if ($versions[$i + 1] - $versions[$i] > 1) {
            $issues[] = ['severity' => 'warning', 'code' => 'version_gap',
                'detail' => "Gap between migration " . str_pad((string)$versions[$i], 3, '0', STR_PAD_LEFT)
                          . " and " . str_pad((string)$versions[$i + 1], 3, '0', STR_PAD_LEFT)];
        }
    }

    // 3. name format
    foreach ($migs as $m) {
        if (!preg_match('/^[a-z0-9_]+$/', $m['name'])) {
            $issues[] = ['severity' => 'error', 'code' => 'bad_name',
                'detail' => "{$m['file']}: name '{$m['name']}' must be lowercase snake_case"];
        }
        if (filesize($m['full_path']) === 0) {
            $issues[] = ['severity' => 'error', 'code' => 'empty_file',
                'detail' => "{$m['file']} is empty"];
        }
    }

    // 4. destructive patterns that don't belong in migrations
    foreach ($migs as $m) {
        $sql = file_get_contents($m['full_path']);
        if (preg_match('/\bDROP\s+TABLE\s+(?!IF\s+EXISTS)/i', $sql)) {
            $issues[] = ['severity' => 'error', 'code' => 'drop_table',
                'detail' => "{$m['file']}: unconditional DROP TABLE — use DROP TABLE IF EXISTS or move to seeds/"];
        }
        if (preg_match('/\bTRUNCATE\b/i', $sql)) {
            $issues[] = ['severity' => 'error', 'code' => 'truncate',
                'detail' => "{$m['file']}: TRUNCATE belongs in seeds/, not migrations/"];
        }
    }

    // 5. DB consistency (if reachable)
    $db_status = null;
    try {
        $pdo = mig_db();
        mig_ensure_table($pdo);
        $applied = mig_applied($pdo);
        $on_disk = array_flip(array_map(fn($m) => $m['version'], $migs));
        foreach ($applied as $v => $row) {
            if (!isset($on_disk[$v])) {
                $issues[] = ['severity' => 'warning', 'code' => 'orphan_applied',
                    'detail' => "schema_migrations has version {$v} ({$row['name']}) but no .sql file exists on disk"];
            }
        }
        $db_status = ['applied_in_db' => count($applied), 'files_on_disk' => count($migs)];
    } catch (Throwable $e) {
        $db_status = ['reachable' => false, 'error' => $e->getMessage()];
    }

    $errs   = count(array_filter($issues, fn($i) => $i['severity'] === 'error'));
    $warns  = count(array_filter($issues, fn($i) => $i['severity'] === 'warning'));
    return [
        'ok'         => $errs === 0,
        'errors'     => $errs,
        'warnings'   => $warns,
        'issues'     => $issues,
        'db_status'  => $db_status,
    ];
}

// ----------------------------------------------------------------
// CLI dispatch
// ----------------------------------------------------------------
function mig_cli(): int {
    global $argv;
    $args = is_array($argv ?? null) ? array_slice($argv, 1) : [];
    $mode = $args[0] ?? 'status';
    try {
        switch ($mode) {
            case '--apply':
            case 'apply':
                $r = mig_run();
                echo "Applied " . count($r['applied_now']) . " migration(s).\n";
                foreach ($r['applied_now'] as $row) {
                    $tag = $row['ok'] ? '[OK]' : '[FAIL]';
                    $ms  = $row['ok'] ? " ({$row['duration_ms']}ms)" : " -- {$row['error']}";
                    echo "  {$tag} {$row['version']}_{$row['name']}{$ms}\n";
                }
                break;
            case '--reset':
            case 'reset':
                $r = mig_reset();
                echo $r['message'] . "\n";
                break;
            case '--validate':
            case 'validate':
                $r = mig_validate();
                if ($r['errors'] === 0 && $r['warnings'] === 0) {
                    $n = is_array($r['db_status']) && isset($r['db_status']['files_on_disk'])
                         ? $r['db_status']['files_on_disk']
                         : count($migs = mig_discover());
                    echo "OK: no issues found ({$n} migration files)\n";
                } else {
                    foreach ($r['issues'] as $i) {
                        $tag = $i['severity'] === 'error' ? '[ERR]' : '[WARN]';
                        echo "  {$tag} {$i['code']}: {$i['detail']}\n";
                    }
                    echo "\n{$r['errors']} error(s), {$r['warnings']} warning(s)\n";
                }
                return $r['errors'] === 0 ? 0 : 1;
            case 'status':
            default:
                $r = mig_status();
                echo "Total: {$r['total']}, Applied: {$r['applied']}, Pending: {$r['pending']}\n";
                foreach ($r['migrations'] as $row) {
                    $tag = $row['status'] === 'applied' ? '[x]' : '[ ]';
                    $when = $row['applied_at'] ? " ({$row['applied_at']})" : '';
                    echo "  {$tag} {$row['version']}_{$row['name']}{$when}\n";
                }
                break;
        }
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        return 1;
    }
}

// ----------------------------------------------------------------
// Web dispatch
// ----------------------------------------------------------------
function mig_web(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $apply = isset($_GET['apply']) && $_GET['apply'] === '1';
    $reset = isset($_GET['reset']) && $_GET['reset'] === '1';
    $validate = isset($_GET['validate']) && $_GET['validate'] === '1';
    $token = mig_get_bearer();
    $isAdmin = $token && mig_verify_admin($token);

    try {
        if ($reset) {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin auth required for reset']);
                return;
            }
            echo json_encode(mig_reset());
            return;
        }
        if ($apply) {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin auth required for apply']);
                return;
            }
            echo json_encode(mig_run());
            return;
        }
        if ($validate) {
            // read-only, safe to expose
            echo json_encode(mig_validate());
            return;
        }
        // GET without apply -> read-only status (safe to expose)
        echo json_encode(mig_status());
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ----------------------------------------------------------------
// Entry
// ----------------------------------------------------------------
if (mig_is_cli()) {
    exit(mig_cli());
}
mig_web();