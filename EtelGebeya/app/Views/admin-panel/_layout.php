<?php
/**
 * Admin panel layout helper -- included at top of every admin view.
 * Sets $navItems, $pageTitle, $current for the dark layout.
 *
 * Usage: require __DIR__.'/_layout.php';
 * Variables expected: $pageTitle, $auth (passed from AdminController->render())
 */
use App\Core\View;

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base    = rtrim(parse_url(View::url('/'), PHP_URL_PATH) ?? '', '/');
$adm     = $base . '/admin';

// Live badge counts for nav
$_adb  = \App\Core\Database::getInstance();
$_bnp  = (int)($_adb->fetchColumn("SELECT COUNT(*) FROM products WHERE status='pending'") ?? 0);
$_bns  = (int)($_adb->fetchColumn("SELECT COUNT(*) FROM sellers WHERE status='pending'") ?? 0);
$_bno  = (int)($_adb->fetchColumn("SELECT COUNT(*) FROM orders WHERE status='new'") ?? 0);

$navItems = [
    [
        'icon'   => 'bi-speedometer2',
        'label'  => 'Dashboard',
        'url'    => View::url('/admin'),
        'active' => $current === $adm || $current === $adm . '/',
    ],
    [
        'icon'   => 'bi-shop',
        'label'  => 'Sellers',
        'url'    => View::url('/admin/vendors'),
        'active' => str_contains($current, '/admin/vendors'),
        'badge'  => $_bns > 0 ? (string)$_bns : null,
    ],
    [
        'icon'   => 'bi-box-seam',
        'label'  => 'Products',
        'url'    => View::url('/admin/products'),
        'active' => str_contains($current, '/admin/products'),
        'badge'  => $_bnp > 0 ? (string)$_bnp : null,
    ],
    [
        'icon'   => 'bi-receipt',
        'label'  => 'Orders',
        'url'    => View::url('/admin/orders'),
        'active' => str_contains($current, '/admin/orders'),
        'badge'  => $_bno > 0 ? (string)$_bno : null,
    ],
    [
        'icon'   => 'bi-credit-card',
        'label'  => 'Payments',
        'url'    => View::url('/admin/payments'),
        'active' => str_contains($current, '/admin/payments'),
    ],
    [
        'icon'   => 'bi-people',
        'label'  => 'Users',
        'url'    => View::url('/admin/users'),
        'active' => str_contains($current, '/admin/users'),
    ],
    [
        'icon'   => 'bi-tags',
        'label'  => 'Categories',
        'url'    => View::url('/admin/categories'),
        'active' => str_contains($current, '/admin/categories'),
    ],
    [
        'icon'   => 'bi-gear',
        'label'  => 'Settings',
        'url'    => View::url('/admin/settings'),
        'active' => str_contains($current, '/admin/settings'),
    ],
    [
        'icon'   => 'bi-house',
        'label'  => 'View Store',
        'url'    => View::url('/'),
        'active' => false,
    ],
];
