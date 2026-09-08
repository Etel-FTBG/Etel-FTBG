<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Str;
use App\Core\View;

final class InstallController
{
    public function index(): void
    {
        // If already installed, redirect to home.
        if ($this->isInstalled()) {
            View::render('install/done', ['already' => true]);
            return;
        }
        $checks = $this->runChecks();
        $cfg = $GLOBALS['__config'];
        View::render('install/index', [
            'checks' => $checks,
            'cfg'    => $cfg,
            'php'    => PHP_VERSION,
        ], null);
    }

    public function run(): void
    {
        if (!Request::isPost()) {
            Request::redirect('/install');
        }
        $dbHost = trim((string)Request::input('db_host'));
        $dbName = trim((string)Request::input('db_name'));
        $dbUser = trim((string)Request::input('db_user'));
        $dbPass = (string)Request::input('db_pass');
        $adminEmail = strtolower(trim((string)Request::input('admin_email')));
        $adminPass  = (string)Request::input('admin_password');
        $adminName  = trim((string)Request::input('admin_name')) ?: 'Site Admin';

        $errors = [];
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid admin email.';
        if (strlen($adminPass) < 8) $errors[] = 'Admin password must be >= 8 chars.';

        // Try DB connection with the provided creds
        try {
            $pdo = new \PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $errors[] = 'Cannot connect to MySQL: ' . $e->getMessage();
        }

        if (!$errors) {
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");
                $schema = file_get_contents(__DIR__ . '/../../../database/schema.sql');
                $seed   = file_get_contents(__DIR__ . '/../../../database/seed.sql');
                if (!$schema) $errors[] = 'schema.sql missing.';
                else {
                    // Run each statement separately; PDO exec is fine here.
                    foreach ($this->splitSql($schema) as $stmt) {
                        $pdo->exec($stmt);
                    }
                    foreach ($this->splitSql($seed) as $stmt) {
                        $pdo->exec($stmt);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = 'DB init failed: ' . $e->getMessage();
            }
        }

        if (!$errors) {
            // Replace the admin placeholder hash with a real bcrypt of the chosen password.
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare('UPDATE eg_users SET password_hash = ?, full_name = ?, email = ?, phone = ? WHERE id = 1');
            $stmt->execute([$hash, $adminName, $adminEmail, '+251900000001']);
            // Grant admin role
            $pdo->exec("INSERT IGNORE INTO eg_user_roles (user_id, role) VALUES (1, 'admin')");
            // Make sure the vendor/customer roles for admin user also exist (optional)
            $pdo->exec("INSERT IGNORE INTO eg_user_roles (user_id, role) VALUES (1, 'customer')");

            // Persist new DB config back to config/app.php
            $this->writeConfig([
                'db_host' => $dbHost,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_pass' => $dbPass,
            ]);

            // Mark installed
            @file_put_contents(__DIR__ . '/../../storage/.installed', date('c'));

            View::render('install/done', [
                'already'     => false,
                'admin_email' => $adminEmail,
                'site_url'    => $GLOBALS['__config']['app']['url'],
            ], null);
            return;
        }

        $checks = $this->runChecks();
        View::render('install/index', [
            'checks' => $checks,
            'cfg'    => $GLOBALS['__config'],
            'php'    => PHP_VERSION,
            'errors' => $errors,
            'old'    => [
                'db_host' => $dbHost, 'db_name' => $dbName,
                'db_user' => $dbUser, 'admin_email' => $adminEmail, 'admin_name' => $adminName,
            ],
        ], null);
    }

    private function isInstalled(): bool
    {
        return is_file(__DIR__ . '/../../../storage/.installed');
    }

    private function runChecks(): array
    {
        $root = dirname(dirname(dirname(__DIR__)));
        $checks = [];
        $checks[] = ['PHP >= 8.0',       version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION];
        $checks[] = ['pdo_mysql ext.',  extension_loaded('pdo_mysql')];
        $checks[] = ['mbstring ext.',   extension_loaded('mbstring')];
        $checks[] = ['gd or imagick',   extension_loaded('gd') || extension_loaded('imagick')];
        $checks[] = ['json ext.',       extension_loaded('json')];
        $checks[] = ['config writable', is_writable($root . '/config')];
        $checks[] = ['storage writable',is_writable($root . '/storage')];
        return $checks;
    }

    /** Split a SQL string on `;` boundaries that are not inside quotes or comments. */
    private function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $inSingle = false; $inDouble = false; $inBack = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i+1] ?? '';
            if (!$inDouble && !$inBack && $c === "'" && ($n === '' || $n !== "'" || ($i+2 < $len && $sql[$i+2] === "'"))) {
                // naive single-quote handling -- enough for our schema/seed
                $inSingle = !$inSingle; $buf .= $c; continue;
            }
            if (!$inSingle && !$inBack && $c === '"') { $inDouble = !$inDouble; $buf .= $c; continue; }
            if (!$inSingle && !$inDouble && $c === '`') { $inBack = !$inBack; $buf .= $c; continue; }
            if (!$inSingle && !$inDouble && !$inBack && $c === '-' && $n === '-') {
                // line comment -- skip to end of line
                while ($i < $len && $sql[$i] !== "\n") $i++;
                continue;
            }
            if ($c === ';' && !$inSingle && !$inDouble && !$inBack) {
                $t = trim($buf);
                if ($t !== '') $out[] = $t;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $t = trim($buf);
        if ($t !== '') $out[] = $t;
        return $out;
    }

    private function writeConfig(array $kv): void
    {
        $path = __DIR__ . '/../../../config/app.php';
        $src  = file_get_contents($path);
        foreach ($kv as $k => $v) {
            $src = preg_replace_callback(
                "/(getenv\\('$k'\\)\\s*\\?\\s*'[^']*')/",
                function ($m) use ($k, $v) {
                    $escaped = str_replace("'", "\\'", $v);
                    return "getenv('$k') ?: '$escaped'";
                },
                $src,
                1
            );
        }
        @file_put_contents($path, $src);
    }
}
