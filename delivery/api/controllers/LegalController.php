<?php
// ============================================================
// Google Play readiness: legal texts and account deletion requests.
//   get_legal_doc          { doc: privacy | terms }          public (no sign-in)  -> { custom: bool, title, body, updated_at }
//   admin_legal_get        {}                                owner admin          -> both documents as the owner wrote them
//   admin_legal_save       { doc, title, body }              owner admin          -> saved (empty body = back to the built-in page)
//   admin_deletion_list    {}                                owner admin          -> "Delete my account" requests with their state
//   admin_deletion_decide  { message_id, decision, note? }   owner admin          -> decision delete (anonymises the account) | reject
// ============================================================
require_once __DIR__ . '/../lib/legal_docs.php';
require_once __DIR__ . '/../lib/account_deletion.php';

switch ($action) {
case 'get_legal_doc': {
    $pdo = db();
    $key = isset($body['doc']) ? (string) $body['doc'] : '';
    $d = legal_doc_get($pdo, $key);
    if (!$d) ok(array('custom' => false));
    ok(array('custom' => true, 'title' => $d['title'], 'body' => $d['body'], 'updated_at' => $d['updated_at']));
    break;
}
case 'admin_legal_get': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    $out = array();
    foreach (legal_doc_keys() as $k) { $d = legal_doc_get($pdo, $k); $out[$k] = $d ? $d : array('title' => '', 'body' => '', 'updated_at' => ''); }
    ok(array('docs' => $out));
    break;
}
case 'admin_legal_save': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    try { $err = legal_doc_save($pdo, $adm['id'], isset($body['doc']) ? (string) $body['doc'] : '', isset($body['title']) ? $body['title'] : '', isset($body['body']) ? $body['body'] : ''); }
    catch (Exception $e) { error_log('[ETEL][legal] ' . $e->getMessage()); fail('Could not save. If this keeps happening, run the database update (migration 113).', 500); }
    if ($err !== '') fail($err, 422);
    ok(array('saved' => true));
    break;
}
case 'admin_deletion_list': {
    $pdo = db(); require_admin($pdo, 'owner.only');
    try { ok(array('requests' => deletion_list($pdo))); }
    catch (Exception $e) { error_log('[ETEL][deletion] ' . $e->getMessage()); fail('Could not load the requests. Run the database update (migration 113).', 500); }
    break;
}
case 'admin_deletion_decide': {
    $pdo = db(); $adm = require_admin($pdo, 'owner.only');
    try { $err = deletion_decide($pdo, $adm['id'], isset($body['message_id']) ? $body['message_id'] : 0, isset($body['decision']) ? (string) $body['decision'] : '', isset($body['note']) ? $body['note'] : ''); }
    catch (Exception $e) { error_log('[ETEL][deletion] ' . $e->getMessage()); fail('Could not save the decision.', 500); }
    if ($err !== '') fail($err, 422);
    ok(array('saved' => true));
    break;
}
}
