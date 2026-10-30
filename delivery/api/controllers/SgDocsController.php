<?php
// ============================================================
// ETEL Delivery - papers a business hands in before going live (see lib/sg_docs.php)
//   sg_golive            { sg_id? }                          -> the business's status and its go-live checklist (same list admin sees)
//   sg_docs_list         { sg_id? }                          -> the papers and their state (owner: their own business; admin: pass sg_id)
//   sg_doc_submit        { doc_type, file_url, sg_id? }      -> hand in or replace one paper (owner, or admin for a business: service_givers.manage); goes back to "pending"
//   admin_sg_doc_review  { id, decision, reason? }           -> approve, or reject with a reason (service_givers.manage)
// The photo is uploaded first with type "sg_document" (api/upload.php), which saves it in the private documents folder.
// ============================================================

require_once __DIR__ . '/../lib/sg_docs.php';

switch ($action) {

case 'sg_golive': {
    $pdo = db();
    $au = require_auth($pdo);
    $sg = sg_docs_target($pdo, $au, $body, false);
    require_once __DIR__ . '/../lib/sg_claim.php';
    $q = $pdo->prepare('SELECT sg.*, u.phone FROM service_givers sg JOIN users u ON u.id = sg.user_id WHERE sg.id = ?');
    $q->execute(array($sg));
    $g = $q->fetch(PDO::FETCH_ASSOC);
    if (!$g) fail('Service giver not found', 404);
    $n = $pdo->prepare('SELECT COUNT(*) FROM items WHERE service_giver_id = ?');
    $n->execute(array($sg));
    $claim = array();
    try { $claim = etel_sg_claim_state($pdo, $sg); } catch (Exception $e) { $claim = array(); }
    $rd = etel_sg_readiness($g, intval($n->fetchColumn()), $claim, etel_sg_docs_complete(etel_sg_docs_list($pdo, $sg)));
    ok(array('sg_id' => $sg, 'business_name' => $g['business_name'], 'status' => $g['status'], 'readiness' => $rd));
    break;
}

case 'sg_docs_list': {
    $pdo = db();
    $au = require_auth($pdo);
    $sg = sg_docs_target($pdo, $au, $body, false);
    ok(array('sg_id' => $sg, 'documents' => etel_sg_docs_list($pdo, $sg), 'types' => etel_sg_doc_types()));
    break;
}

case 'sg_doc_submit': {
    $pdo = db();
    $au = require_auth($pdo);
    $sg = sg_docs_target($pdo, $au, $body, true);
    $type = isset($body['doc_type']) ? trim($body['doc_type']) : '';
    $types = etel_sg_doc_types();
    if (!isset($types[$type])) fail('Choose which paper this is', 400);
    $url = isset($body['file_url']) ? trim($body['file_url']) : '';
    if (!etel_sg_doc_url_ok($url)) fail('Upload the photo first, then send it', 400);
    try {
        etel_sg_docs_ensure($pdo);
        $pdo->prepare("INSERT INTO sg_documents (sg_id, doc_type, file_url, status, reject_reason, uploaded_by, reviewed_by, reviewed_at)
                       VALUES (?,?,?,'pending',NULL,?,NULL,NULL)
                       ON DUPLICATE KEY UPDATE file_url = VALUES(file_url), status = 'pending', reject_reason = NULL, uploaded_by = VALUES(uploaded_by), reviewed_by = NULL, reviewed_at = NULL")
            ->execute(array($sg, $type, $url, intval($au['id'])));
    } catch (Exception $e) { fail_ex($e, 'Could not save the paper', 500); }
    ok(array('message' => $types[$type]['label'] . ' sent. ETEL will check it.', 'documents' => etel_sg_docs_list($pdo, $sg)));
    break;
}

case 'admin_sg_doc_review': {
    $pdo = db();
    $u = require_admin($pdo, 'service_givers.manage');
    if (etel_is_ride_only($pdo, $u)) fail('You can only change ride accounts', 403);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id required', 400);
    $dec = isset($body['decision']) ? trim($body['decision']) : '';
    if ($dec !== 'approve' && $dec !== 'reject') fail('decision must be approve or reject', 400);
    $reason = isset($body['reason']) ? trim($body['reason']) : '';
    if ($dec === 'reject' && $reason === '') fail('Tell the owner what is wrong, so they can send it again', 400);
    if (strlen($reason) > 255) $reason = substr($reason, 0, 255);
    etel_sg_docs_ensure($pdo);
    $upd = $pdo->prepare('UPDATE sg_documents SET status = ?, reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
    try { $upd->execute(array($dec === 'approve' ? 'approved' : 'rejected', $dec === 'approve' ? null : $reason, intval($u['id']), $id)); }
    catch (Exception $e) { fail_ex($e, 'Could not save', 500); }
    $chk = $pdo->prepare('SELECT sg_id FROM sg_documents WHERE id = ?');
    $chk->execute(array($id));
    $sg = $chk->fetchColumn();
    if (!$sg) fail('Paper not found', 404);
    ok(array('message' => $dec === 'approve' ? 'Approved' : 'Sent back to the owner', 'documents' => etel_sg_docs_list($pdo, intval($sg))));
    break;
}

}

/* Which business a caller may work on: an owner or staff login their own; an admin (service_givers.manage) the one named by sg_id. $write needs owner/admin. */
function sg_docs_target($pdo, $au, $body, $write) {
    if ($au['role'] === 'admin') {
        require_admin($pdo, 'service_givers.manage');
        $sg = isset($body['sg_id']) ? intval($body['sg_id']) : (isset($_GET['sg_id']) ? intval($_GET['sg_id']) : 0);
        if ($sg <= 0) fail('sg_id required', 400);
        $c = $pdo->prepare('SELECT id FROM service_givers WHERE id = ?');
        $c->execute(array($sg));
        if (!$c->fetchColumn()) fail('Service giver not found', 404);
        return $sg;
    }
    if ($au['role'] !== 'service_giver') fail('Not a service giver account', 403);
    $own = $pdo->prepare('SELECT id FROM service_givers WHERE user_id = ? LIMIT 1');
    $own->execute(array(intval($au['id'])));
    $sg = $own->fetchColumn();
    if ($sg) return intval($sg);
    if ($write) fail('Only the business owner can hand in papers', 403);
    $st = $pdo->prepare('SELECT service_giver_id FROM sg_staff WHERE user_id = ? AND active = 1 LIMIT 1');
    try { $st->execute(array(intval($au['id']))); $sg = $st->fetchColumn(); } catch (Exception $e) { $sg = false; }
    if (!$sg) fail('No business is linked to this account', 404);
    return intval($sg);
}
