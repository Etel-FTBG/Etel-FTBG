<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database -- PDO singleton with MySQL (cPanel/production) and SQLite (local dev).
 *
 * Auto-detects driver:
 *   - APP_DB=sqlite  ?  storage/database.sqlite  (local dev, zero setup)
 *   - otherwise      ?  MySQL using config/app.php credentials  (cPanel)
 *
 * Usage:
 *   Database::getInstance()->query("SELECT * FROM users WHERE id = ?", [1])->fetch();
 *   Database::getInstance()->fetch("SELECT * FROM products", []);
 */
final class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private string $driver = 'mysql';
    private bool $connected = false;

    private function __construct()
    {
        $dbEnv   = getenv('APP_DB') ?: '';
        $dbType  = ($dbEnv === 'sqlite') ? 'sqlite' : 'mysql';
        $this->driver = $dbType;

        if ($dbType === 'sqlite') {
            $path = __DIR__ . '/../../storage/database.sqlite';
            $dir  = dirname($path);
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            if (is_file($path)) {
                $this->pdo = new PDO("sqlite:$path", null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->connected = true;
            }
        } else {
            $cfg = $GLOBALS['__config']['db'] ?? [];
            $host = $cfg['host'] ?? '127.0.0.1';
            $port = $cfg['port'] ?? '3306';
            $name = $cfg['name'] ?? '';
            $user = $cfg['user'] ?? 'root';
            $pass = $cfg['pass'] ?? '';
            $charset = $cfg['charset'] ?? 'utf8mb4';

            if ($name === '' || ($user === 'root' && $pass === '')) {
                // No DB configured yet -- skip connection, let install handle it
                return;
            }

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
            $this->connected = true;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database not connected. Run /install first.');
        }
        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** Table prefix (MySQL only -- returns '' for SQLite). */
    public function prefix(): string
    {
        if ($this->driver === 'sqlite') return '';
        return $GLOBALS['__config']['db']['prefix'] ?? '';
    }

    /** Prefix a table name (no-op on SQLite). */
    public function t(string $table): string
    {
        return $this->prefix() . $table;
    }

    /** @param array $params positional [v1, v2, ...] */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $sql = $this->applyPrefix($sql);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $col = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($col);
    }

    public function insert(string $table, array $data): int
    {
        $table = $this->t($table);
        $cols  = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $table = $this->t($table);
        $set = [];
        foreach (array_keys($data) as $col) { $set[] = "`$col` = ?"; }
        $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE $where";
        $stmt = $this->query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $table = $this->t($table);
        $sql = "DELETE FROM `$table` WHERE $where";
        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Run a raw SQL file (for schema seeding).
     * On SQLite, auto-converts MySQL ENUM ? TEXT and removes ENGINE= clauses.
     */
    public function execFile(string $filepath): void
    {
        $sql = file_get_contents($filepath);
        if ($sql === false) { throw new \RuntimeException("Cannot read: $filepath"); }

        if ($this->driver === 'sqlite') {
            $sql = $this->mysqlToSqlite($sql);
        }

        foreach ($this->splitSql($sql) as $stmt) {
            $t = trim($stmt);
            if ($t !== '') { $this->pdo->exec($t); }
        }
    }

    private function mysqlToSqlite(string $sql): string
    {
        // Remove ENGINE=, AUTO_INCREMENT quirks, COLLATE clauses
        $sql = preg_replace('/ENGINE=\w+/', '', $sql);
        $sql = preg_replace('/COLLATE=utf8mb4_unicode_ci/', '', $sql);
        $sql = preg_replace('/DEFAULT CHARSET=utf8mb4/', '', $sql);
        $sql = preg_replace('/ON DELETE CASCADE/', '', $sql);
        $sql = preg_replace('/ON DELETE SET NULL/', '', $sql);
        $sql = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/', '', $sql);
        $sql = preg_replace('/COLLATE [a-z0-9_]+/i', '', $sql);
        // ENUM ? TEXT
        $sql = preg_replace_callback('/ENUM\([^)]+\)/', fn($m) => 'TEXT', $sql);
        // Unsigned ? nothing
        $sql = preg_replace('/\s+UNSIGNED/', '', $sql);
        // TINYINT(1) ? INTEGER (SQLite boolean)
        $sql = preg_replace('/TINYINT\(1\)/', 'INTEGER', $sql);
        // BIGINT ? INTEGER
        $sql = preg_replace('/BIGINT/', 'INTEGER', $sql);
        // DECIMAL ? REAL
        $sql = preg_replace('/DECIMAL\([^)]+\)/', 'REAL', $sql);
        // JSON ? TEXT
        $sql = preg_replace('/JSON/', 'TEXT', $sql);
        // INT UNSIGNED ? INTEGER
        $sql = preg_replace('/INT\s+UNSIGNED/', 'INTEGER', $sql);
        // AUTO_INCREMENT (MySQL) ? AUTOINCREMENT (SQLite)
        $sql = preg_replace('/AUTO_INCREMENT/', 'AUTOINCREMENT', $sql);
        // Add PRIMARY KEY for SQLite autoincrement
        $sql = preg_replace('/NOT NULL AUTOINCREMENT/', 'NOT NULL PRIMARY KEY AUTOINCREMENT', $sql);
        // VARCHAR ? TEXT
        $sql = preg_replace('/VARCHAR\(\d+\)/', 'TEXT', $sql);
        return $sql;
    }

    private function applyPrefix(string $sql): string
    {
        if ($this->driver !== 'mysql') return $sql;
        $prefix = $this->prefix();
        if ($prefix === '') return $sql;
        return preg_replace('/`eg_/', '`' . $prefix, $sql);
    }

    private function splitSql(string $sql): array
    {
        $out = []; $buf = '';
        $inSingle = false; $inDouble = false; $inBack = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if (!$inDouble && !$inBack && $c === "'" && ($n === '' || $n !== "'")) {
                $inSingle = !$inSingle; $buf .= $c; continue;
            }
            if (!$inSingle && !$inBack && $c === '"') { $inDouble = !$inDouble; $buf .= $c; continue; }
            if (!$inSingle && !$inDouble && $c === '`') { $inBack = !$inBack; $buf .= $c; continue; }
            if (!$inSingle && !$inDouble && !$inBack && $c === '-' && $n === '-') {
                while ($i < $len && $sql[$i] !== "\n") $i++; continue;
            }
            if ($c === ';' && !$inSingle && !$inDouble && !$inBack) {
                $t = trim($buf); if ($t !== '') $out[] = $t; $buf = ''; continue;
            }
            $buf .= $c;
        }
        $t = trim($buf); if ($t !== '') $out[] = $t;
        return $out;
    }
}
