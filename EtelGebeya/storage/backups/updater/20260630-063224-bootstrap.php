<?php
/**
 * Etel Gebeya bootstrap.
 * - Loads config
 * - Sets up environment (timezone, errors, session)
 * - Wires autoloader for app/ classes (no Composer needed)
 * - Initializes auth, i18n, view renderer
 * - Lazily connects to DB (fails gracefully -- install page handles its own DB)
 *
 * Paths are relative to project root (two levels up from public/index.php).
 */

declare(strict_types=1);

// If not set by index.php yet, calculate it here
if (!defined('EG_ROOT')) {
    define('EG_ROOT', dirname(__DIR__));
}

session_name($config['security']['session_name'] ?? 'EG_SESS');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $config['security']['session_lifetime'] ?? 604800,
        'path'     => '/',
        'httponly' => true,
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

// --- Error handling (configurable via APP_DEBUG) ---
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
if (!empty($config['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

// --- Autoloader for app/ namespace App\... ---
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = EG_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// --- Global view helpers ---
if (!function_exists('e')) {
    function e(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('url')) {
    function url(string $path = '/'): string {
        return App\Core\View::url($path);
    }
}
if (!function_exists('asset')) {
    function asset(string $path): string {
        return App\Core\View::asset($path);
    }
}
if (!function_exists('money')) {
    function money(float $amount): string {
        return App\Core\View::money($amount);
    }
}
if (!function_exists('csrf')) {
    function csrf(): string {
        $token = App\Core\Auth::getInstance()->csrfToken();
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}
if (!function_exists('t')) {
    function t(string $key, array $placeholders = []): string {
        return App\Core\I18n::getInstance()->t($key, $placeholders);
    }
}
if (!function_exists('redirect')) {
    function redirect(string $path): never {
        $base = rtrim($GLOBALS['__config']['app']['url'] ?? '', '/');
        header('Location: ' . $base . (str_starts_with($path, '/') ? $path : '/' . $path));
        exit;
    }
}

// --- Globals shared with views ---
$GLOBALS['__config'] = $config;
$GLOBALS['__i18n']   = App\Core\I18n::getInstance();
$GLOBALS['__auth']   = App\Core\Auth::getInstance();

/**
 * DB is lazily initialized. If credentials are wrong / DB doesn't exist,
 * it stays null and code that needs it will fail gracefully.
 * The install wizard creates its own PDO connection directly.
 */
$GLOBALS['__db'] = null;
if (!defined('EG_SKIP_DB') || EG_SKIP_DB !== true) {
    try {
        $GLOBALS['__db'] = App\Core\Database::getInstance();
    } catch (\Throwable $e) {
        // DB not available yet -- e.g. install page hasn't run.
        // Leave $GLOBALS['__db'] = null; controllers must check it.
        $GLOBALS['__db'] = null;
    }
}

// --- View globals (assigned on render) ---
App\Core\View::share('app', $GLOBALS['__config']['app']);
App\Core\View::share('currency', $GLOBALS['__config']['currency']);
App\Core\View::share('user', $GLOBALS['__auth']->user());
App\Core\View::share('flash', function () {
    $f = $_SESSION['_flash'] ?? null;
    if ($f) { unset($_SESSION['_flash']); }
    return $f;
});
