<?php
// ============================================================
// ETEL Field Agents - partner onboarding.
//   Agent:  fa_partner_list {}                                   -> my partners, counts, fees, rules
//           fa_partner_get  { id }
//           fa_partner_save { id?, kind, name, phone, id_number?, data{}, items[]? }   (draft or "changes asked" only)
//           fa_partner_submit { id }                             -> goes to the owner's review queue
//           fa_partner_delete { id }                             (drafts only)
//   Admin:  admin_fa_partners_list { status?, kind?, q? }        (agents.view)
//           admin_fa_partner_get { id }                          (agents.view)
//           admin_fa_partner_decide { id, decision: approve|reject|changes, note? }   (agents.manage)
// Nothing is a real account until the owner approves. On approval the account is created, the fixed fee is paid to the agent once,
// and the new partner gets an SMS telling them to set a password with "Forgot password". The agent never sees risk flags.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_partners.php';

if (!function_exists('fa_partner_agent_or_fail')) {

function fa_partner_agent_or_fail($pdo, $user) {
    $q = $pdo->prepare('SELECT * FROM fa_agents WHERE user_id = ? LIMIT 1');
    $q->execute(array(intval($user['id'])));
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) fail('You are not an agent yet.', 403);
    if ($a['status'] !== 'approved') fail('Your agent account is not approved yet.', 403);
    if (empty(fa_setting($pdo, 'program_enabled')) || empty(fa_setting($pdo, 'partners_enabled'))) fail('Registering partners is switched off right now.', 403, array('reason' => 'PARTNERS_OFF'));
    return $a;
}

/* What the agent may see of a row: never risk data. */
function fa_partner_agent_view($r, $full) {
    $out = array('id' => intval($r['id']), 'kind' => $r['kind'], 'status' => $r['status'], 'name' => $r['name'], 'phone' => $r['phone'],
        'item_count' => intval($r['item_count']), 'fee_amount' => floatval($r['fee_amount']), 'decision_note' => $r['decision_note'],
        'submitted_at' => $r['submitted_at'], 'decided_at' => $r['decided_at'], 'updated_at' => $r['updated_at']);
    if ($full) {
        $d = json_decode((string) $r['data'], true); $it = json_decode((string) $r['items'], true);
        $out['id_number'] = $r['id_number']; $out['data'] = is_array($d) ? $d : array(); $out['items'] = is_array($it) ? $it : array();
    }
    return $out;
}

function fa_partner_load_own($pdo, $agent, $id) {
    $q = $pdo->prepare('SELECT * FROM fa_partners WHERE id = ? AND agent_id = ?');
    $q->execute(array(intval($id), intval($agent['id'])));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Not found', 404);
    return $r;
}

function fa_partner_welcome_sms($row, $code) {
    try {
        if (!function_exists('etel_sms_send')) require_once __DIR__ . '/../lib/sms_send.php';
        $what = $row['kind'] === 'shop' ? 'shop' : $row['kind'];
        $text = 'ETEL: your ' . $what . ' account is ready. Open the ETEL app, tap Forgot password, and enter ' . $row['phone'] . ' to set your password.';
        etel_sms_send($row['phone'], $text);
    } catch (Exception $e) { /* the owner can send it again; an SMS fault never undoes an approval */ } catch (Throwable $e) { }
}

}

switch ($action) {

case 'fa_partner_list': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo); $a = fa_partner_agent_or_fail($pdo, $u);
    $q = $pdo->prepare('SELECT * FROM fa_partners WHERE agent_id = ? ORDER BY updated_at DESC, id DESC LIMIT 300');
    $q->execute(array($a['id']));
    $rows = array(); $counts = array('draft' => 0, 'submitted' => 0, 'changes' => 0, 'approved' => 0, 'rejected' => 0); $earned = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = fa_partner_agent_view($r, false); $counts[$r['status']]++; if ($r['status'] === 'approved') $earned += floatval($r['fee_amount']); }
    ok(array('partners' => $rows, 'counts' => $counts, 'earned' => $earned,
        'fees' => array('courier' => fa_partner_fee($pdo, 'courier'), 'driver' => fa_partner_fee($pdo, 'driver'), 'shop' => fa_partner_fee($pdo, 'shop')),
        'shop_min_items' => intval(fa_setting($pdo, 'partner_shop_min_items')), 'pay_days' => intval(fa_setting($pdo, 'partner_pay_days'))));
    break;
}

case 'fa_partner_get': {
    $GLOBALS['ETEL_LIMITED_BLOCK'] = true;
    $pdo = db(); $u = require_auth($pdo); $a = fa_partner_agent_or_fail($pdo, $u);
    $r = fa_partner_load_own($pdo, $a, isset($body['id']) ? $body['id'] : 0);
    ok(array('partner' => fa_partner_agent_view($r, true)));
    break;
}

case 'fa_partner_save': {
    $pdo = db(); $u = require_auth($pdo); $a = fa_partner_agent_or_fail($pdo, $u);
    require_once __DIR__ . '/../validate.php';
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    $kind = isset($body['kind']) ? (string) $body['kind'] : '';
    if ($id > 0) { $cur = fa_partner_load_own($pdo, $a, $id); $kind = $cur['kind']; if (!in_array($cur['status'], array('draft', 'changes'), true)) fail('This one is already with the owner and cannot be edited.', 409); }
    if (!in_array($kind, fa_partner_kinds(), true)) fail('Choose courier, driver or shop.');
    $name = fa_partner_clean_str(isset($body['name']) ? $body['name'] : '', 120);
    $phoneRaw = isset($body['phone']) ? trim((string) $body['phone']) : '';
    $phone = $phoneRaw === '' ? '' : etel_validate_phone($phoneRaw);
    if ($phone === false) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567.');
    if ($phone !== '' && etel_phone_taken($pdo, $phone)) fail('This phone number already has an ETEL account.', 409, array('reason' => 'PHONE_TAKEN'));
    $d = fa_partner_clean_data($kind, isset($body['data']) ? $body['data'] : array());
    $items = $kind === 'shop' ? fa_partner_clean_items(isset($body['items']) ? $body['items'] : array()) : array();
    $idNum = fa_partner_clean_str(isset($body['id_number']) ? $body['id_number'] : '', 60);
    if ($name === '' && $phone === '') fail('Write at least the name or the phone number.');
    $last9 = $phone === '' ? '' : fa_phone_last9($phone);
    if ($id > 0) {
        $pdo->prepare("UPDATE fa_partners SET name=?, phone=?, phone_last9=?, id_number=?, data=?, items=?, item_count=?, updated_at=NOW() WHERE id=? AND agent_id=?")
            ->execute(array($name, $phone, $last9, $idNum, json_encode($d), json_encode($items), count($items), $id, $a['id']));
    } else {
        $pdo->prepare("INSERT INTO fa_partners (agent_id, kind, status, name, phone, phone_last9, id_number, data, items, item_count, created_at, updated_at) VALUES (?,?, 'draft', ?,?,?,?,?,?,?, NOW(), NOW())")
            ->execute(array($a['id'], $kind, $name, $phone, $last9, $idNum, json_encode($d), json_encode($items), count($items)));
        $id = intval($pdo->lastInsertId());
    }
    $r = fa_partner_load_own($pdo, $a, $id);
    $miss = fa_partner_missing($pdo, $kind, $r['name'], $r['phone'], $d, $items);
    ok(array('partner' => fa_partner_agent_view($r, true), 'missing' => $miss, 'ready' => !$miss));
    break;
}

case 'fa_partner_submit': {
    $pdo = db(); $u = require_auth($pdo); $a = fa_partner_agent_or_fail($pdo, $u);
    require_once __DIR__ . '/../validate.php';
    $r = fa_partner_load_own($pdo, $a, isset($body['id']) ? $body['id'] : 0);
    if (!in_array($r['status'], array('draft', 'changes'), true)) fail('This one was already sent.', 409);
    $d = json_decode((string) $r['data'], true); if (!is_array($d)) $d = array();
    $items = json_decode((string) $r['items'], true); if (!is_array($items)) $items = array();
    $d = fa_partner_clean_data($r['kind'], $d);
    $miss = fa_partner_missing($pdo, $r['kind'], $r['name'], $r['phone'], $d, $items);
    if ($miss) fail('Some details are missing: ' . implode(' ', array_values($miss)), 422, array('reason' => 'INCOMPLETE', 'missing' => $miss));
    $phone = etel_validate_phone($r['phone']);
    if ($phone !== false && etel_phone_taken($pdo, $phone)) fail('This phone number already has an ETEL account.', 409, array('reason' => 'PHONE_TAKEN'));
    $cap = intval(fa_setting($pdo, 'partner_daily_cap'));
    $q = $pdo->prepare("SELECT COUNT(*) FROM fa_partners WHERE agent_id = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $q->execute(array($a['id']));
    if (intval($q->fetchColumn()) >= $cap) fail('You reached the daily limit of ' . $cap . ' submissions. Try again tomorrow.', 429, array('reason' => 'DAILY_CAP'));
    list($score, $flags) = fa_partner_risk($pdo, $a, $r);
    $pdo->prepare("UPDATE fa_partners SET status='submitted', submitted_at=NOW(), risk_score=?, risk_flags=?, decision_note='', updated_at=NOW() WHERE id=? AND agent_id=? AND status IN ('draft','changes')")
        ->execute(array($score, json_encode($flags), $r['id'], $a['id']));
    fa_audit($pdo, 'agent', $a['id'], 'partner_submitted', 'partner', $r['id'], '', array('kind' => $r['kind'], 'risk' => $score));
    ok(array('submitted' => true, 'id' => intval($r['id'])));
    break;
}

case 'fa_partner_delete': {
    $pdo = db(); $u = require_auth($pdo); $a = fa_partner_agent_or_fail($pdo, $u);
    $r = fa_partner_load_own($pdo, $a, isset($body['id']) ? $body['id'] : 0);
    if ($r['status'] !== 'draft') fail('Only drafts can be deleted.', 409);
    $pdo->prepare("DELETE FROM fa_partners WHERE id = ? AND agent_id = ? AND status = 'draft'")->execute(array($r['id'], $a['id']));
    ok(array('deleted' => true));
    break;
}

case 'admin_fa_partners_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $status = isset($body['status']) ? (string) $body['status'] : (isset($_GET['status']) ? (string) $_GET['status'] : 'submitted');
    $kind = isset($body['kind']) ? (string) $body['kind'] : '';
    $qs = isset($body['q']) ? trim((string) $body['q']) : '';
    $w = array(); $p = array();
    if (in_array($status, array('draft', 'submitted', 'changes', 'approved', 'rejected'), true)) { $w[] = 'p.status = ?'; $p[] = $status; }
    if (in_array($kind, fa_partner_kinds(), true)) { $w[] = 'p.kind = ?'; $p[] = $kind; }
    if ($qs !== '') { $w[] = '(p.name LIKE ? OR p.phone LIKE ? OR a.full_name LIKE ?)'; $lk = '%' . $qs . '%'; array_push($p, $lk, $lk, $lk); }
    $sql = "SELECT p.id, p.kind, p.status, p.name, p.phone, p.item_count, p.risk_score, p.fee_amount, p.submitted_at, p.decided_at, a.id AS agent_id, a.full_name AS agent_name, a.code AS agent_code
            FROM fa_partners p JOIN fa_agents a ON a.id = p.agent_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY (p.status = 'submitted') DESC, p.submitted_at DESC, p.id DESC LIMIT 300";
    $q = $pdo->prepare($sql); $q->execute($p);
    $counts = array('draft' => 0, 'submitted' => 0, 'changes' => 0, 'approved' => 0, 'rejected' => 0);
    foreach ($pdo->query('SELECT status, COUNT(*) n FROM fa_partners GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[$c['status']] = intval($c['n']);
    ok(array('partners' => $q->fetchAll(PDO::FETCH_ASSOC), 'counts' => $counts));
    break;
}

case 'admin_fa_partners_stats': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $counts = array('draft' => 0, 'submitted' => 0, 'changes' => 0, 'approved' => 0, 'rejected' => 0);
    foreach ($pdo->query('SELECT status, COUNT(*) n FROM fa_partners GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[$c['status']] = intval($c['n']);
    $m = $pdo->query("SELECT COALESCE(SUM(status = 'approved'), 0) AS ok, COALESCE(SUM(status = 'rejected'), 0) AS bad, COALESCE(SUM(CASE WHEN status = 'approved' THEN fee_amount ELSE 0 END), 0) AS fees
                      FROM fa_partners WHERE decided_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch(PDO::FETCH_ASSOC);
    $byKind = array();
    foreach ($pdo->query("SELECT kind, COUNT(*) AS n FROM fa_partners WHERE status = 'approved' GROUP BY kind")->fetchAll(PDO::FETCH_ASSOC) as $k) $byKind[$k['kind']] = intval($k['n']);
    $oldest = $pdo->query("SELECT TIMESTAMPDIFF(HOUR, MIN(submitted_at), NOW()) FROM fa_partners WHERE status = 'submitted'")->fetchColumn();
    $top = $pdo->query("SELECT a.id, a.full_name, a.code, a.tier, COUNT(*) AS sent, SUM(p.status = 'approved') AS approved, SUM(p.status = 'rejected') AS rejected,
                               COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.fee_amount ELSE 0 END), 0) AS fees, ROUND(AVG(p.risk_score)) AS avg_risk
                        FROM fa_partners p JOIN fa_agents a ON a.id = p.agent_id WHERE p.status <> 'draft' AND p.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                        GROUP BY a.id, a.full_name, a.code, a.tier ORDER BY approved DESC, sent DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($top as &$t) { $t['sent'] = intval($t['sent']); $t['approved'] = intval($t['approved']); $t['rejected'] = intval($t['rejected']); $t['fees'] = floatval($t['fees']); $t['avg_risk'] = intval($t['avg_risk']); } unset($t);
    ok(array('counts' => $counts, 'month' => array('approved' => intval($m['ok']), 'rejected' => intval($m['bad']), 'fees' => floatval($m['fees'])), 'by_kind' => $byKind,
        'oldest_waiting_hours' => $oldest === null ? null : intval($oldest), 'agents' => $top));
    break;
}

case 'admin_fa_partner_get': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $q = $pdo->prepare('SELECT p.*, a.full_name AS agent_name, a.code AS agent_code, a.phone AS agent_phone, a.tier AS agent_tier FROM fa_partners p JOIN fa_agents a ON a.id = p.agent_id WHERE p.id = ?');
    $q->execute(array(intval(isset($body['id']) ? $body['id'] : 0)));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Not found', 404);
    $r['data'] = json_decode((string) $r['data'], true); $r['items'] = json_decode((string) $r['items'], true); $r['risk_flags'] = json_decode((string) $r['risk_flags'], true);
    if (!is_array($r['items'])) $r['items'] = array(); if (!is_array($r['risk_flags'])) $r['risk_flags'] = array();
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(status='approved') ok, SUM(status='rejected') bad FROM fa_partners WHERE agent_id = ? AND status IN ('approved','rejected','submitted')");
    $st->execute(array($r['agent_id']));
    $r['agent_stats'] = $st->fetch(PDO::FETCH_ASSOC);
    ok(array('partner' => $r, 'fee' => fa_partner_fee($pdo, $r['kind'])));
    break;
}

case 'admin_fa_partner_decide': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    $decision = isset($body['decision']) ? (string) $body['decision'] : '';
    $note = fa_partner_clean_str(isset($body['note']) ? $body['note'] : '', 255);
    if (!in_array($decision, array('approve', 'reject', 'changes'), true)) fail('Choose approve, reject or ask for changes.');
    if ($decision !== 'approve' && $note === '') fail('Write a short reason so the agent knows what to do.');
    $q = $pdo->prepare('SELECT * FROM fa_partners WHERE id = ?'); $q->execute(array($id));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Not found', 404);
    if ($r['status'] !== 'submitted') fail('This one is not waiting for a decision.', 409);
    if ($decision === 'approve') {
        try { $acc = fa_partner_create_account($pdo, $r, $adm['id']); }
        catch (Exception $e) { fail($e->getMessage(), 409, array('reason' => 'ACCOUNT_FAILED')); }
        $earnId = 0; $fee = 0;
        try { list($earnId, $fee) = fa_partner_pay_fee($pdo, $r); } catch (Exception $e) { error_log('[ETEL][partners] fee: ' . $e->getMessage()); }
        $pdo->prepare("UPDATE fa_partners SET status='approved', user_id=?, sg_id=?, fee_amount=?, earning_id=?, decision_note=?, decided_by=?, decided_at=NOW(), updated_at=NOW() WHERE id=? AND status='submitted'")
            ->execute(array($acc['user_id'], $acc['sg_id'] ?: null, $fee, $earnId ?: null, $note, $adm['id'], $r['id']));
        fa_audit($pdo, 'admin', $adm['id'], 'partner_approved', 'partner', $r['id'], '', array('kind' => $r['kind'], 'user' => $acc['user_id'], 'fee' => $fee));
        try { fa_notify($pdo, $r['agent_id'], 'partner_approved', array('partner' => intval($r['id']), 'name' => $r['name'], 'kind' => $r['kind'], 'amount' => $fee)); } catch (Exception $e) { }
        fa_partner_welcome_sms($r, $acc['code']);
        ok(array('approved' => true, 'user_id' => $acc['user_id'], 'code' => $acc['code'], 'fee' => $fee));
    }
    $new = $decision === 'reject' ? 'rejected' : 'changes';
    $pdo->prepare("UPDATE fa_partners SET status=?, decision_note=?, decided_by=?, decided_at=NOW(), updated_at=NOW() WHERE id=? AND status='submitted'")->execute(array($new, $note, $adm['id'], $r['id']));
    fa_audit($pdo, 'admin', $adm['id'], 'partner_' . $new, 'partner', $r['id'], $note, array('kind' => $r['kind']));
    try { fa_notify($pdo, $r['agent_id'], 'partner_' . $new, array('partner' => intval($r['id']), 'name' => $r['name'], 'kind' => $r['kind'], 'note' => $note)); } catch (Exception $e) { }
    ok(array('status' => $new));
    break;
}

}
