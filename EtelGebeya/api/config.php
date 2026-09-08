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

// ?? AUTH CHECK ??????????????????????????????????????????????
// AUTH CHECK - accepts EITHER the master ADMIN_SECRET (kept for
// backward compatibility) OR a real per-admin session token issued by
// admin_login/admin_etelid_login (see api/admin.php + table
// gebeya_admin_sessions). This is the single gate every protected
// admin.php action already calls, so upgrading it here covers the
// whole file without touching each call site.
function auth(?string $capability = null): void {
    $token = trim(
        $_SERVER['HTTP_X_AUTH']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_POST['auth']
        ?? $_GET['auth']
        ?? ''
    );
    $token = str_replace('Bearer ', '', $token);
    if ($token === '') err('Unauthorized', 401);

    if (!empty(ADMIN_SECRET) && hash_equals(ADMIN_SECRET, $token)) return; // master key - always unrestricted

    $pdo = db();
    if ($pdo) {
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
