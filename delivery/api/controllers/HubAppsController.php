<?php
// ============================================================
// ETEL app (hub) - which apps show on the phones.
//   get_hub_apps          {}                        any signed-in user  -> { apps: { key: { mode, url } } } only the apps the owner changed
//   admin_hub_apps_get    {}                        owner admin         -> every app with its default and current setting
//   admin_hub_apps_save   { key, mode, url? }       owner admin         -> mode on | admins | off; url only for apps with no built-in address
// Owner-only ('owner.only', like the release channels): it decides what every user's phone shows.
// ============================================================
require_once __DIR__ . '/../lib/hub_apps.php';

switch ($action) {
case 'get_hub_apps': {
    $pdo = db(); require_auth($pdo);
    ok(array('apps' => hub_apps_public($pdo), 'generated_at' => date('Y-m-d H:i:s')));
    break;
}
case 'admin_hub_apps_get': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    ok(array('apps' => hub_apps_admin_view($pdo), 'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    break;
}
case 'admin_hub_apps_save': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    $key = isset($body['key']) ? (string) $body['key'] : '';
    $mode = isset($body['mode']) ? (string) $body['mode'] : '';
    $url = isset($body['url']) ? (string) $body['url'] : '';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    try { $err = hub_apps_save($pdo, $adm['id'], $key, $mode, $url, $host); }
    catch (Exception $e) { error_log('[ETEL][hub_apps] ' . $e->getMessage()); fail('Could not save. If this keeps happening, run the database update (migration 111).', 500); }
    if ($err !== '') fail($err, 422);
    ok(array('saved' => true));
    break;
}
}
