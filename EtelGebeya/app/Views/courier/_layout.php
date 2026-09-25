<?php
/**
 * Courier panel layout helper -- included at top of every courier view.
 * Sets $navItems, mirrors vendor/_layout.php's structure.
 *
 * Usage: require __DIR__.'/_layout.php';
 * Variables expected: $pageTitle, $courier, $auth (passed from controller)
 */
use App\Core\View;

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base    = rtrim(parse_url(View::url('/'), PHP_URL_PATH) ?? '', '/');
$cp      = $base . '/courier';

$navItems = [
    ['icon'=>'bi-speedometer2', 'label'=>'Dashboard',    'url'=>View::url('/courier'),            'active'=>$current===$cp||$current===$cp.'/'],
    ['icon'=>'bi-truck',        'label'=>'My Deliveries','url'=>View::url('/courier/deliveries'), 'active'=>str_contains($current,'courier/deliveries')],
    ['icon'=>'bi-house',        'label'=>'Back to Store','url'=>View::url('/'),                   'active'=>false],
];
