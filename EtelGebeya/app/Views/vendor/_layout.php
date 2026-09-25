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
    ['icon'=>'bi-speedometer2', 'label'=>t('vendor.nav.dashboard'),        'url'=>View::url('/vendor-panel'),          'active'=>$current===$vp||$current===$vp.'/'],
    ['icon'=>'bi-box-seam',     'label'=>t('vendor.nav.products'),         'url'=>View::url('/vendor-panel/products'),  'active'=>str_contains($current,'vendor-panel/product')],
    ['icon'=>'bi-truck',        'label'=>t('vendor.nav.suppliers'),        'url'=>View::url('/vendor-panel/suppliers'), 'active'=>str_contains($current,'vendor-panel/suppliers')],
    ['icon'=>'bi-shop-window',  'label'=>t('vendor.nav.supplier_catalog'), 'url'=>View::url('/vendor-panel/catalog'), 'active'=>str_contains($current,'vendor-panel/catalog')],
    ['icon'=>'bi-receipt',      'label'=>t('vendor.nav.orders'),           'url'=>View::url('/vendor-panel/orders'),    'active'=>str_contains($current,'vendor-panel/orders')],
    ['icon'=>'bi-cash-stack',   'label'=>t('vendor.nav.payouts'),          'url'=>View::url('/vendor-panel/payouts'),   'active'=>str_contains($current,'vendor-panel/payouts')],
    ['icon'=>'bi-gear',         'label'=>t('vendor.nav.settings'),         'url'=>View::url('/vendor-panel/settings'),  'active'=>str_contains($current,'vendor-panel/settings')],
    ['icon'=>'bi-shop',         'label'=>t('vendor.nav.my_storefront'),    'url'=>View::url('/vendor/'.($seller['store_slug']??'')), 'active'=>false],
    ['icon'=>'bi-house',        'label'=>t('vendor.nav.back_to_store'),    'url'=>View::url('/'),                      'active'=>false],
];
