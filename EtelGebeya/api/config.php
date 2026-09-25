<?php
// ============================================================
//  ETEL GEBEYA -- API Configuration
//  Domain: https://ethelmarket.com/EtelGebeya
// ============================================================

// Silence PHP notices -- only JSON must reach the browser
error_reporting(0);
ini_set('display_errors', '0');
ob_start(); // buffer any accidental output before headers

// ?? CREDENTIALS -- loaded from .env (never hardcoded) ????????
(function () {
    $envFile = dirname(__DIR__) . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || !str_contains($trimmed, '=')) continue;
            [$k, $v] = explode('=', $trimmed, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
})();

// ?? DATABASE ??????????????????????????????????????????????
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'EtelGebeya');
define('DB_USER', $_ENV['DB_USER'] ?? 'EtelGebeya');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// ?? ADMIN AUTH ?????????????????????????????????????????????
// IMPORTANT: token stored in localStorage must match this exactly
define('ADMIN_SECRET', $_ENV['ADMIN_SECRET'] ?? '');

// ?? ETELID SERVICE KEY (inbound) ?????????????????????????????
// Shared EtelGroup service-to-service key - same value as EtelID's own
// etelid_service_key option and every sibling system's config (Etel
// Madeya, EtelPay). Used by the service_nfc_*.php endpoints to trust
// calls coming FROM EtelID's NFC card page (class-etelid-nfc.php).
// The outbound bridges (etelid_service_bridge.php, etelpay_bridge.php)
// already read $_ENV['ETELID_SERVICE_KEY'] directly for calls going
// the other way; this constant just makes the same value available
// for the inbound direction without a second .env entry.
define('ETELID_SERVICE_KEY', $_ENV['ETELID_SERVICE_KEY'] ?? '');

// ?? SITE ???????????????????????????????????????????????????
define('SITE_NAME', 'Etel Gebeya');
define('SITE_URL',  'https://ethelmarket.com/EtelGebeya');
define('CURRENCY',  'ETB');

// ?? TABLE PREFIX (empty = no prefix, as deployed on cPanel) ?
define('TBL', '');  // set to 'eg_' if using the local schema

// ?? CORS ???????????????????????????????????????????????????
header('Access-Control-Allow-Origin: ' . ($_ENV['ALLOWED_ORIGIN'] ?? 'https://ethelmarket.com'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }

// ?? HELPERS ????????????????????????????????????????????????
function ok(mixed $data, string $msg='OK'): never {
    ob_end_clean(); // discard any accidental PHP output
    echo json_encode(['ok'=>true,'msg'=>$msg,'data'=>$data]);
    exit;
}
function err(string $msg, int $code=400): never {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

function t(string $name): string { return TBL . $name; }

// ?? DB CONNECTION ???????????????????????????????????????????
$GLOBALS['__db_error'] = null;
function db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo ?: null;
    try {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES  => false]
        );
    } catch (PDOException $e) {
        $GLOBALS['__db_error'] = $e->getMessage();
        $pdo = null;
    }
    return $pdo ?: null;
}

// ?? ADMIN SCHEMA SELF-HEAL ????????????????????????????????????
// gebeya_admins / gebeya_admin_sessions / gebeya_role_permissions were
// created by hand on the live DB when per-admin login was first built,
// with no CREATE-TABLE migration checked into this repo -- so a fresh
// or restored database never gets them, and every query against them
// here was unguarded (unlike auth()'s own query, which already wraps
// this same lookup in try/catch for exactly this reason). With
// display_errors off, that uncaught PDOException reaches the browser
// as a completely empty response body -- which the login page's own
// JS can't tell apart from "never reached the server at all". Call
// this once before touching any of these tables so login self-heals
// the same way the rest of this app's schema already does (see
// App\Core\SchemaGuard / EscrowService on the app/ MVC side).
function eg_ensure_admin_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gebeya_admins (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                full_name VARCHAR(190) NOT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                role VARCHAR(100) DEFAULT NULL,
                is_etelgroup_managed TINYINT(1) NOT NULL DEFAULT 0,
                etel_id VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_phone (phone),
                KEY idx_etel_id (etel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gebeya_admin_sessions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_token (token),
                KEY idx_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS gebeya_role_permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_name VARCHAR(100) NOT NULL,
                capabilities TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gebeya_role_name (role_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\Throwable $e) { return; }

    // A gebeya_admins table created before is_etelgroup_managed/etel_id
    // existed (or before this self-heal existed at all) won't have them.
    $existing = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM gebeya_admins") as $row) {
            $existing[$row['Field']] = true;
        }
    } catch (\Throwable $e) { return; }
    $want = [
        'is_etelgroup_managed' => "ALTER TABLE gebeya_admins ADD COLUMN is_etelgroup_managed TINYINT(1) NOT NULL DEFAULT 0",
        'etel_id'              => "ALTER TABLE gebeya_admins ADD COLUMN etel_id VARCHAR(64) DEFAULT NULL",
    ];
    foreach ($want as $col => $sql) {
        if (!isset($existing[$col])) {
            try { $pdo->exec($sql); } catch (\Throwable $e) {}
        }
    }

    try {
        $pdo->exec(
            "INSERT IGNORE INTO gebeya_role_permissions (role_name, capabilities) VALUES
                ('Owner',         '[\"*\"]'),
                ('Admin',         '[\"*\"]'),
                ('Manager',       '[\"*\"]'),
                ('Dispatcher',    '[\"orders.view\",\"orders.manage\",\"vendors.view\"]'),
                ('Support',       '[\"orders.view\",\"support.view\",\"support.manage\",\"users.view\"]'),
                ('Finance',       '[\"finance.view\",\"finance.manage\",\"analytics.view\"]'),
                ('Driver Support','[\"vendors.view\",\"vendors.manage\"]')"
        );
    } catch (\Throwable $e) {}
}

// ?? AUTH CHECK ??????????????????????????????????????????????
// AUTH CHECK - accepts EITHER the master ADMIN_SECRET (kept for
// backward compatibility) OR a real per-admin session token issued by
// admin_login/admin_etelid_login (see api/admin.php + table
// gebeya_admin_sessions). This is the single gate every protected
// admin.php action already calls, so upgrading it here covers the
// whole file without touching each call site.
function auth(?string $capability = null): void {
    // Header or POST body only -- never $_GET, since a query-string token
    // lands in the server's own access logs and the admin's browser
    // history the moment the request is sent, whether or not it's ever
    // actually checked against anything.
    $token = trim(
        $_SERVER['HTTP_X_AUTH']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_POST['auth']
        ?? ''
    );
    $token = str_replace('Bearer ', '', $token);
    if ($token === '') err('Unauthorized', 401);

    if (!empty(ADMIN_SECRET) && hash_equals(ADMIN_SECRET, $token)) return; // master key - always unrestricted

    $pdo = db();
    if ($pdo) {
        eg_ensure_admin_schema($pdo);
        try {
            $s = $pdo->prepare(
                'SELECT a.* FROM gebeya_admin_sessions s
                 JOIN gebeya_admins a ON a.id = s.admin_id
                 WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
            );
            $s->execute(array($token));
            $admin = $s->fetch();
            if ($admin) {
                if ($capability !== null && !gebeya_admin_has_capability($pdo, $admin, $capability)) {
                    err("You don't have permission to do this (requires: $capability)", 403);
                }
                return;
            }
        } catch (PDOException $e) { /* table may not exist yet */ }
    }
    err('Unauthorized', 401);
}

// Loads the capability list for a gebeya_admins row.
// - Not EtelGroup-managed (is_etelgroup_managed=0) -> unrestricted,
//   same as every admin account created directly with a real password.
// - EtelGroup-managed with a current role name -> look up that role's
//   permissions (or a safe read-only baseline if unconfigured).
// - EtelGroup-managed with no current role -> zero capabilities.
function gebeya_admin_capabilities(PDO $pdo, array $admin): array {
    $managed = !empty($admin['is_etelgroup_managed']);
    if (!$managed) return array('*');
    if (empty($admin['role'])) return array();
    try {
        $s = $pdo->prepare('SELECT capabilities FROM gebeya_role_permissions WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
        $s->execute(array($admin['role']));
        $row = $s->fetch();
    } catch (Exception $e) { $row = null; }
    if (!$row) return array('orders.view', 'products.view');
    $caps = json_decode($row['capabilities'], true);
    return is_array($caps) ? $caps : array();
}
function gebeya_admin_has_capability(PDO $pdo, array $admin, string $capability): bool {
    $caps = gebeya_admin_capabilities($pdo, $admin);
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

/** The signed-in admin's row when using a real per-admin session
 *  (null for the master-key path, which isn't tied to one person).
 *  Call only after auth() has already passed. */
function current_gebeya_admin(): ?array {
    $token = trim(
        $_SERVER['HTTP_X_AUTH']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_POST['auth']
        ?? $_GET['auth']
        ?? ''
    );
    $token = str_replace('Bearer ', '', $token);
    if ($token === '') return null;
    $pdo = db();
    if (!$pdo) return null;
    try {
        $s = $pdo->prepare(
            'SELECT a.* FROM gebeya_admin_sessions s
             JOIN gebeya_admins a ON a.id = s.admin_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
        );
        $s->execute(array($token));
        $row = $s->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}
