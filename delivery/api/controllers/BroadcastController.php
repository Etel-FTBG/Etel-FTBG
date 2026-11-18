<?php
// ============================================================
// Messages to phones, from Admin > ETEL app > Send a message. Owner admins only.
//   admin_broadcast_preview { audience, phone? }                  -> { people, reachable, name }   how many would get it (nothing is sent)
//   admin_broadcast_send    { audience, phone?, title, body }     -> { id, recipients }            sends after the answer is returned
//   admin_broadcast_list    {}                                    -> { broadcasts: [...] }         the last 50, with how many were reached
// ============================================================
require_once __DIR__ . '/../lib/broadcast.php';
if (!function_exists('etel_push_to_user')) { require_once __DIR__ . '/../push.php'; }

switch ($action) {
case 'admin_broadcast_preview': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    try { $r = broadcast_resolve($pdo, isset($body['audience']) ? (string) $body['audience'] : '', isset($body['phone']) ? $body['phone'] : ''); }
    catch (Exception $e) { error_log('[ETEL][broadcast] ' . $e->getMessage()); fail('Could not check. Run the database update (migration 114) if this keeps happening.', 500); }
    if ($r['error'] !== '') fail($r['error'], 422);
    ok(array('people' => $r['people'], 'reachable' => count($r['ids']), 'name' => $r['name'], 'max' => broadcast_max_recipients()));
    break;
}
case 'admin_broadcast_send': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    try { $r = broadcast_start($pdo, $adm['id'], isset($body['audience']) ? (string) $body['audience'] : '', isset($body['phone']) ? $body['phone'] : '', isset($body['title']) ? $body['title'] : '', isset($body['body']) ? $body['body'] : ''); }
    catch (Exception $e) { error_log('[ETEL][broadcast] ' . $e->getMessage()); fail('Could not send. Run the database update (migration 114) if this keeps happening.', 500); }
    if ($r['error'] !== '') fail($r['error'], 422);
    broadcast_deliver_later($pdo, $r['id'], $r['ids'], isset($body['title']) ? $body['title'] : '', isset($body['body']) ? $body['body'] : '');
    ok(array('id' => $r['id'], 'recipients' => count($r['ids'])));
    break;
}
case 'admin_broadcast_list': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    try { ok(array('broadcasts' => broadcast_history($pdo))); }
    catch (Exception $e) { error_log('[ETEL][broadcast] ' . $e->getMessage()); fail('Could not load the history. Run the database update (migration 114).', 500); }
    break;
}
}
