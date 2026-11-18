<?php
// ============================================================
// ETEL app (hub) - which apps show on the phones.
//   get_hub_apps          {}                        any signed-in user  -> { apps: { key: { mode, url } } } only the apps the owner changed
//   admin_hub_apps_get    {}                        owner admin         -> every app with its default and current setting
//   admin_hub_apps_save   { key, mode, url? }       owner admin         -> mode on | admins | off; url only for apps with no built-in address
//   admin_hub_content_get {}                        owner admin         -> every app's names, descriptions, label, order, icon + the home texts and banner
//   admin_hub_content_save { key, title_en?, title_am?, desc_en?, desc_am?, badge?, sort_order?, icon_url? }   owner admin
//   admin_hub_settings_save { settings: { welcome_title_en, tagline_en, support_phone, banner_enabled, ... } }  owner admin
// Owner-only ('owner.only', like the release channels): it decides what every user's phone shows.
// ============================================================
require_once __DIR__ . '/../lib/hub_apps.php';
require_once __DIR__ . '/../lib/hub_content.php';

switch ($action) {
case 'get_hub_apps': {
    $pdo = db(); $ru = require_auth($pdo);
    $pub = hub_content_public($pdo, isset($ru['role']) ? $ru['role'] : '');
    ok(array('apps' => hub_apps_public($pdo), 'content' => $pub['content'], 'texts' => $pub['texts'], 'banner' => $pub['banner'], 'generated_at' => date('Y-m-d H:i:s')));
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
case 'admin_hub_content_get': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    ok(array('content' => hub_content_all($pdo), 'settings' => hub_settings_all($pdo), 'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    break;
}
case 'admin_hub_content_save': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    $key = isset($body['key']) ? (string) $body['key'] : '';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $fields = array();
    foreach (array('title_en', 'title_am', 'desc_en', 'desc_am', 'badge', 'sort_order', 'icon_url') as $f) { if (array_key_exists($f, $body)) $fields[$f] = $body[$f]; }
    try { $err = hub_content_save($pdo, $adm['id'], $key, $fields, $host); }
    catch (Exception $e) { error_log('[ETEL][hub_content] ' . $e->getMessage()); fail('Could not save. If this keeps happening, run the database update (migration 112).', 500); }
    if ($err !== '') fail($err, 422);
    ok(array('saved' => true));
    break;
}
case 'admin_hub_settings_save': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $map = (isset($body['settings']) && is_array($body['settings'])) ? $body['settings'] : array();
    try { $err = hub_settings_save($pdo, $adm['id'], $map, $host); }
    catch (Exception $e) { error_log('[ETEL][hub_settings] ' . $e->getMessage()); fail('Could not save. If this keeps happening, run the database update (migration 112).', 500); }
    if ($err !== '') fail($err, 422);
    ok(array('saved' => true));
    break;
}
}
