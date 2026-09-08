<?php
/**
 * Vendor panel layout helper -- included at top of every vendor view.
 * Sets $navItems and renders using the dark layout.
 *
 * Usage: require __DIR__.'/_layout.php';
 * Variables expected: $pageTitle, $seller, $auth (passed from controller)
 */
use App\Core\View;

$locale  = $GLOBALS['__i18n']->locale();
$user    = $auth->user() ?? [];
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

function vActive(string $path, string $current): string {
    return str_starts_with($current, $path) ? 'active' : '';
}

$base = rtrim(parse_url(View::url('/'), PHP_URL_PATH) ?? '', '/');
$vp   = $base . '/vendor-panel';

$navItems = [
    ['icon'=>'bi-speedometer2', 'label'=>'Dashboard',   'url'=>View::url('/vendor-panel'),          'active'=>$current===$vp||$current===$vp.'/'],
    ['icon'=>'bi-box-seam',     'label'=>'Products',    'url'=>View::url('/vendor-panel/products'),  'active'=>str_contains($current,'vendor-panel/product')],
    ['icon'=>'bi-receipt',      'label'=>'Orders',      'url'=>View::url('/vendor-panel/orders'),    'active'=>str_contains($current,'vendor-panel/orders')],
    ['icon'=>'bi-cash-stack',   'label'=>'Payouts',     'url'=>View::url('/vendor-panel/payouts'),   'active'=>str_contains($current,'vendor-panel/payouts')],
    ['icon'=>'bi-gear',         'label'=>'Settings',    'url'=>View::url('/vendor-panel/settings'),  'active'=>str_contains($current,'vendor-panel/settings')],
    ['icon'=>'bi-shop',         'label'=>'My Storefront','url'=>View::url('/vendor/'.($seller['store_slug']??'')), 'active'=>false],
    ['icon'=>'bi-house',        'label'=>'Back to Store','url'=>View::url('/'),                      'active'=>false],
];
