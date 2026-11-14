<?php
/* Etel Pay Route Functions - Part 4: reject a top-up request, and let the user see their own requests.
   Runs before parts 1-3. */
function ep_topup_reason_col() {
    $pdo = ep_db();
    try {
        $c = $pdo->query('SHOW COLUMNS FROM ' . EP_TBL_TOPUP . ' LIKE "reject_reason"')->fetch();
        if (!$c) $pdo->exec('ALTER TABLE ' . EP_TBL_TOPUP . ' ADD COLUMN reject_reason VARCHAR(200) DEFAULT NULL');
    } catch (Exception $e) { /* table may not exist yet */ }
}

function ep_topup_reject_do() {
    _ep_require_admin();
    $ref    = in_str('ref');
    $reason = in_str('reason');
    if ($reason === '') $reason = 'Payment not received';
    if (strlen($reason) > 190) $reason = substr($reason, 0, 190);
    if ($ref === '') ep_err('Ref required');
    $pdo = ep_db();
    ep_topup_reason_col();
    $stmt = $pdo->prepare('SELECT status FROM ' . EP_TBL_TOPUP . ' WHERE ref=? LIMIT 1');
    $stmt->execute(array($ref));
    $row = $stmt->fetch();
    if (!$row) ep_err('Top-up not found', 404);
    if ($row['status'] === 'completed') ep_err('Already credited - it cannot be rejected');
    if ($row['status'] === 'failed') ep_ok(array('ref' => $ref, 'status' => 'failed'), 'Already rejected');
    $up = $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status="failed", reject_reason=? WHERE ref=? AND status IN ("pending","processing","expired")');
    $up->execute(array($reason, $ref));
    if ($up->rowCount() === 0) ep_err('Could not reject (status changed)');
    ep_ok(array('ref' => $ref, 'status' => 'failed', 'reason' => $reason), 'Top-up rejected');
}

function ep_route_part4($action) {
  switch ($action) {
    case 'ep_admin_topup_reject':
        ep_topup_reject_do();
        return true;
    case 'ep_my_topups': {
        $uid = ep_auth_id();
        $pdo = ep_db();
        ep_topup_reason_col();
        $q = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE user_id=? AND status IN ("processing","completed","failed") ORDER BY created_at DESC LIMIT 10');
        $q->execute(array($uid));
        ep_ok(array('requests' => $q->fetchAll()));
        return true;
    }
    default:
        return false;
  }
}
