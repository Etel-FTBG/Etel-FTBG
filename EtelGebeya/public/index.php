<?php
/**
 * Public entry point -- front controller.
 * Routes every request to the appropriate controller/action.
 */

declare(strict_types=1);

// Root path -- index.php lives in public/, go up 2 levels to project root
define('EG_ROOT', dirname(__DIR__));

// Config loaded first (before anything else) specifically so app.debug can
// gate both error display and the debug.log writes below - this used to
// force E_ALL/display_errors and write to storage/debug.log unconditionally,
// regardless of the config's own debug flag, so a real shopper hitting an
// error on production saw a full stack trace, and every request appended to
// a world-readable log file.
$config = require EG_ROOT . '/config/app.php';
$egDebug = !empty($config['app']['debug']);

if ($egDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    @file_put_contents(EG_ROOT . '/storage/debug.log', date('c') . " - index.php loaded\n", FILE_APPEND);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($egDebug) @file_put_contents(EG_ROOT . '/storage/debug.log', date('c') . " - path: $path\n", FILE_APPEND);

// If we're on the install route, skip DB init -- install handles its own connection
if (strpos($path, '/install') === 0) {
    define('EG_SKIP_DB', true);
    if ($egDebug) @file_put_contents(EG_ROOT . '/storage/debug.log', date('c') . " - EG_SKIP_DB defined\n", FILE_APPEND);
}

if ($egDebug) @file_put_contents(EG_ROOT . '/storage/debug.log', date('c') . " - config loaded\n", FILE_APPEND);

require EG_ROOT . '/bootstrap.php';

use App\Core\Router;
use App\Core\View;

// Strip the subdirectory prefix so routes work in a subdir install.
// e.g., REQUEST_URI = /EtelGebeya/products ? routed as /products
$_egBase = rtrim(parse_url($config['app']['url'] ?? '', PHP_URL_PATH) ?? '', '/');
$_egUri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($_egBase !== '' && str_starts_with($_egUri, $_egBase)) {
    $_egUri = substr($_egUri, strlen($_egBase)) ?: '/';
}

// --- Routing ---
$router = new Router();

// Storefront
$router->get('/',                       [\App\Controllers\StorefrontController::class, 'home']);
$router->get('/search',                 [\App\Controllers\StorefrontController::class, 'search']);
$router->get('/category/{slug}',        [\App\Controllers\StorefrontController::class, 'category']);
$router->get('/vendor/{slug}',          [\App\Controllers\StorefrontController::class, 'vendor']);
$router->get('/product/{slug}',         [\App\Controllers\StorefrontController::class, 'product']);
$router->get('/protection-policy',      [\App\Controllers\StorefrontController::class, 'protectionPolicy']);
$router->get('/geocode/search',         [\App\Controllers\GeocodeController::class, 'search']);
$router->get('/geocode/reverse',        [\App\Controllers\GeocodeController::class, 'reverse']);

// Cart & checkout
$router->get('/cart',                   [\App\Controllers\CartController::class, 'index']);
$router->post('/cart/add',              [\App\Controllers\CartController::class, 'add']);
$router->post('/cart/update',           [\App\Controllers\CartController::class, 'update']);
$router->post('/cart/remove',           [\App\Controllers\CartController::class, 'remove']);
$router->get('/checkout',               [\App\Controllers\CheckoutController::class, 'index']);
$router->post('/checkout/place',        [\App\Controllers\CheckoutController::class, 'place']);
$router->post('/checkout/apply-coupon', [\App\Controllers\CheckoutController::class, 'applyCoupon']);
$router->post('/checkout/quote-shipping', [\App\Controllers\CheckoutController::class, 'quoteShipping']);
$router->get('/checkout/success/{code}',[\App\Controllers\CheckoutController::class, 'success']);

// Auth
$router->get('/login',                  [\App\Controllers\AuthController::class, 'loginForm']);
$router->post('/login',                 [\App\Controllers\AuthController::class, 'login']);
$router->post('/login/etelid',          [\App\Controllers\AuthController::class, 'etelIdLogin']);
$router->get('/register',               [\App\Controllers\AuthController::class, 'registerForm']);
$router->post('/register',              [\App\Controllers\AuthController::class, 'register']);
$router->post('/logout',                [\App\Controllers\AuthController::class, 'logout']);
$router->get('/lang/{locale}',          [\App\Controllers\AuthController::class, 'setLanguage']);

// Customer
$router->get('/account',                [\App\Controllers\CustomerController::class, 'dashboard']);
$router->get('/account/orders',         [\App\Controllers\CustomerController::class, 'orders']);
$router->get('/account/orders/{code}',  [\App\Controllers\CustomerController::class, 'order']);
$router->post('/account/orders/{code}/cancel', [\App\Controllers\CustomerController::class, 'cancelOrder']);
$router->post('/account/orders/{code}/confirm', [\App\Controllers\CustomerController::class, 'confirmReceived']);
$router->post('/account/orders/{code}/dispute', [\App\Controllers\CustomerController::class, 'fileDispute']);
$router->get('/account/addresses',      [\App\Controllers\CustomerController::class, 'addresses']);
$router->post('/account/addresses',     [\App\Controllers\CustomerController::class, 'saveAddress']);
$router->post('/account/addresses/delete', [\App\Controllers\CustomerController::class, 'deleteAddress']);
$router->post('/account/addresses/default', [\App\Controllers\CustomerController::class, 'setDefaultAddress']);
$router->post('/account/profile',       [\App\Controllers\CustomerController::class, 'saveProfile']);
$router->get('/account/wishlist',       [\App\Controllers\CustomerController::class, 'wishlist']);
$router->post('/account/wishlist/add',  [\App\Controllers\CustomerController::class, 'wishlistAdd']);
$router->post('/account/wishlist/remove',[\App\Controllers\CustomerController::class, 'wishlistRemove']);
$router->post('/product/{slug}/review', [\App\Controllers\CustomerController::class, 'submitReview']);

// Vendor
$router->get('/vendor-panel',                          [\App\Controllers\VendorController::class, 'dashboard']);
$router->get('/vendor-panel/products',                 [\App\Controllers\VendorController::class, 'products']);
$router->get('/vendor-panel/products/new',             [\App\Controllers\VendorController::class, 'productForm']);
$router->get('/vendor-panel/products/{id}/edit',       [\App\Controllers\VendorController::class, 'productForm']);
$router->post('/vendor-panel/products/save',           [\App\Controllers\VendorController::class, 'productSave']);
$router->post('/vendor-panel/products/{id}/delete',    [\App\Controllers\VendorController::class, 'productDelete']);
$router->get('/vendor-panel/orders',                   [\App\Controllers\VendorController::class, 'orders']);
$router->post('/vendor-panel/orders/item-status',      [\App\Controllers\VendorController::class, 'updateOrderItemStatus']);
$router->post('/vendor-panel/orders/mark-forwarded',   [\App\Controllers\VendorController::class, 'markOrderItemForwarded']);
$router->post('/vendor-panel/orders/email-supplier',   [\App\Controllers\VendorController::class, 'emailSupplier']);
$router->get('/vendor-panel/payouts',                  [\App\Controllers\VendorController::class, 'payouts']);
$router->post('/vendor-panel/payouts/request',         [\App\Controllers\VendorController::class, 'requestPayout']);
$router->get('/vendor-panel/settings',                 [\App\Controllers\VendorController::class, 'settings']);
$router->post('/vendor-panel/settings',                [\App\Controllers\VendorController::class, 'saveSettings']);
$router->get('/vendor-panel/suppliers',                [\App\Controllers\VendorController::class, 'suppliers']);
$router->post('/vendor-panel/suppliers/save',           [\App\Controllers\VendorController::class, 'supplierSave']);
$router->post('/vendor-panel/suppliers/{id}/delete',    [\App\Controllers\VendorController::class, 'supplierDelete']);
$router->get('/vendor-panel/catalog',                   [\App\Controllers\VendorController::class, 'catalog']);
$router->post('/vendor-panel/catalog/import',            [\App\Controllers\VendorController::class, 'catalogImport']);
$router->get('/sell',                                  [\App\Controllers\VendorController::class, 'signup']);  // public vendor signup
$router->post('/sell',                                 [\App\Controllers\VendorController::class, 'signup']);

// Admin
$router->get('/admin',                          [\App\Controllers\AdminController::class, 'dashboard']);
$router->get('/admin/vendors',                  [\App\Controllers\AdminController::class, 'vendors']);
$router->post('/admin/vendors/{id}/approve',    [\App\Controllers\AdminController::class, 'approveVendor']);
$router->post('/admin/vendors/{id}/suspend',    [\App\Controllers\AdminController::class, 'suspendVendor']);
$router->get('/admin/products',                 [\App\Controllers\AdminController::class, 'products']);
$router->post('/admin/products/{id}/approve',   [\App\Controllers\AdminController::class, 'approveProduct']);
$router->post('/admin/products/{id}/reject',    [\App\Controllers\AdminController::class, 'rejectProduct']);
$router->get('/admin/orders',                   [\App\Controllers\AdminController::class, 'orders']);
$router->get('/admin/payments',                 [\App\Controllers\AdminController::class, 'payments']);
$router->post('/admin/payments/mark-paid',      [\App\Controllers\AdminController::class, 'markPaymentPaid']);
$router->get('/admin/users',                    [\App\Controllers\AdminController::class, 'users']);
$router->get('/admin/categories',               [\App\Controllers\AdminController::class, 'categories']);
$router->post('/admin/categories',              [\App\Controllers\AdminController::class, 'saveCategory']);
$router->get('/admin/catalog',                   [\App\Controllers\AdminController::class, 'catalog']);
$router->post('/admin/catalog/save',             [\App\Controllers\AdminController::class, 'saveCatalogItem']);
$router->post('/admin/catalog/{id}/delete',      [\App\Controllers\AdminController::class, 'deleteCatalogItem']);
$router->post('/admin/catalog/supplier',         [\App\Controllers\AdminController::class, 'saveCatalogSupplier']);
$router->post('/admin/catalog/import-csv',       [\App\Controllers\AdminController::class, 'importCatalogCsv']);
$router->get('/admin/settings',                  [\App\Controllers\AdminController::class, 'settings']);
$router->post('/admin/settings',                 [\App\Controllers\AdminController::class, 'saveSettings']);
$router->post('/admin/audit/run',                [\App\Controllers\AdminController::class, 'runAudit']);
$router->get('/admin/audit/settings',            [\App\Controllers\AdminController::class, 'auditSettingsGet']);
$router->post('/admin/audit/settings',           [\App\Controllers\AdminController::class, 'auditSettingsSave']);

// Courier
$router->get('/courier',                        [\App\Controllers\CourierController::class, 'dashboard']);
$router->get('/courier/deliveries',             [\App\Controllers\CourierController::class, 'deliveries']);
$router->get('/courier/deliveries/{code}',      [\App\Controllers\CourierController::class, 'delivery']);
$router->post('/courier/deliveries/{code}/status', [\App\Controllers\CourierController::class, 'updateStatus']);

// Payment callbacks (return URLs)
$router->get('/payments/telebirr/return',   [\App\Controllers\PaymentController::class, 'telebirrReturn']);
$router->post('/payments/telebirr/notify',  [\App\Controllers\PaymentController::class, 'telebirrNotify']);
$router->get('/payments/cbe/return',        [\App\Controllers\PaymentController::class, 'cbeReturn']);
$router->post('/payments/cbe/notify',       [\App\Controllers\PaymentController::class, 'cbeNotify']);
$router->get('/payments/chapa/return',      [\App\Controllers\PaymentController::class, 'chapaReturn']);
$router->post('/payments/chapa/notify',     [\App\Controllers\PaymentController::class, 'chapaNotify']);

// Installer (only if not yet installed)
$router->get('/install',   [\App\Controllers\InstallController::class, 'index']);
$router->post('/install',  [\App\Controllers\InstallController::class, 'run']);

// --- Dispatch ---
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_egUri);
} catch (\Throwable $e) {
    http_response_code(500);
    if (!empty($config['app']['debug'])) {
        echo '<pre style="padding:2rem;font-size:.9rem">' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        $code = bin2hex(random_bytes(4));
        error_log('[EG-' . $code . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        // Safe fallback -- avoid rendering a view (could itself throw)
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:3rem;text-align:center">'
           . '<h1 style="font-size:2rem">&#x1F6AB; Something went wrong</h1>'
           . '<p>Error code: <code>' . htmlspecialchars($code) . '</code></p>'
           . '<p><a href="' . htmlspecialchars($config['app']['url'] ?? '/') . '">Go home</a></p>'
           . '</body></html>';
    }
}
