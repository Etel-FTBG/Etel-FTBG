<?php
// ============================================================
// ETEL Ride - Field Agents: admin actions. Capabilities (role_permissions): agents.view, agents.manage, agents.pay.
// An unrestricted admin ('*') has all three. Every state change is written to the append-only fa_audit_log.
//   admin_fa_settings_get / admin_fa_settings_save
//   admin_fa_agents_list / admin_fa_agent_get / admin_fa_agent_decide
//   admin_fa_earnings_list / admin_fa_earning_decide   (referral review, fraud queue, bulk release/reject)
//   admin_fa_run_earnings / admin_fa_stats
//   admin_fa_payouts_list / admin_fa_payout_create / admin_fa_payout_export / admin_fa_payout_mark_paid / admin_fa_payout_cancel
//   admin_fa_audit_list
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_risk.php';
require_once __DIR__ . '/../lib/fa_earnings.php';
require_once __DIR__ . '/../lib/fa_payouts.php';
require_once __DIR__ . '/../lib/fa_risk.php';

if (!function_exists('fa_adm_limit')) {
/* The agent's last 10 bookings for customers (phone masked), with the ride status. */
function fa_adm_bookings($pdo, $agentId) {
    try {
        $q = $pdo->prepare('SELECT b.id, b.ride_id, b.vehicle_type, b.bonus_eligible, b.new_account, b.created_at, r.status AS ride_status, u.phone AS cust_phone FROM fa_bookings b LEFT JOIN rides r ON r.id = b.ride_id LEFT JOIN users u ON u.id = b.customer_user_id WHERE b.agent_id = ? ORDER BY b.id DESC LIMIT 10');
        $q->execute(array(intval($agentId)));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['cust_phone'] = fa_mask_phone($r['cust_phone']); }
        unset($r);
        return $rows;
    } catch (Exception $e) { return array(); }
}
function fa_adm_limit($v, $def, $max) { $n = intval($v); return ($n < 1) ? $def : min($n, $max); }
function fa_adm_reason($body, $required) {
    $r = trim(isset($body['reason']) ? (string) $body['reason'] : '');
    if ($required && strlen($r) < 3) fail('Please write a short reason (it is recorded and the agent is told in simple words)');
    return substr($r, 0, 255);
}
}

switch ($action) {

case 'admin_fa_settings_get': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $meta = array();
    foreach (fa_defaults() as $k => $d) $meta[$k] = array('default' => $d[0], 'min' => $d[1], 'max' => $d[2]);
    ok(array('settings' => fa_settings($pdo, true), 'meta' => $meta, 'budget' => fa_budget_status($pdo)));
    break;
}

case 'admin_fa_settings_save': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    try { ok(array('settings' => fa_settings_save($pdo, isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : array(), $adm['id']))); }
    catch (Exception $e) { fail($e->getMessage(), 400); }
    break;
}

case 'admin_fa_agents_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $w = array('1=1'); $p = array();
    if (!empty($body['status']) && in_array($body['status'], array('pending', 'approved', 'suspended', 'rejected'), true)) { $w[] = 'a.status = ?'; $p[] = $body['status']; }
    if (!empty($body['q'])) { $like = '%' . str_replace(array('%', '_'), array('', ''), trim($body['q'])) . '%'; $w[] = '(a.full_name LIKE ? OR a.phone LIKE ? OR a.venue_name LIKE ? OR a.code LIKE ?)'; array_push($p, $like, $like, $like, $like); }
    $lim = fa_adm_limit(isset($body['limit']) ? $body['limit'] : 0, 100, 500);
    $q = $pdo->prepare("SELECT a.id, a.full_name, a.phone, a.venue_name, a.venue_type, a.venue_zone, a.status, a.age_status, a.age_note, a.tier, a.code, a.risk_score, a.risk_reasons, a.last_referral_at, a.created_at,
                               (SELECT COUNT(*) FROM fa_referrals r WHERE r.agent_id = a.id) AS referrals,
                               (SELECT COALESCE(SUM(e.amount),0) FROM fa_earnings e WHERE e.agent_id = a.id AND e.status = 'payable') AS payable,
                               (SELECT COALESCE(SUM(e.amount),0) FROM fa_earnings e WHERE e.agent_id = a.id AND e.status = 'held') AS held
                        FROM fa_agents a WHERE " . implode(' AND ', $w) . " ORDER BY (a.status = 'pending') DESC, a.id DESC LIMIT " . $lim);
    $q->execute($p);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['risk_reasons'] = json_decode((string) $r['risk_reasons'], true); }
    unset($r);
    ok(array('agents' => $rows));
    break;
}

case 'admin_fa_agent_get': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    $q = $pdo->prepare('SELECT * FROM fa_agents WHERE id = ?'); $q->execute(array($id)); $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) fail('Agent not found', 404);
    unset($a['device_hash']);
    $a['risk_reasons'] = json_decode((string) $a['risk_reasons'], true);
    $rf = $pdo->prepare("SELECT r.id, r.claimed_at, r.status, r.reject_code, r.risk_score, r.risk_reasons, u.phone AS rider_phone FROM fa_referrals r LEFT JOIN users u ON u.id = r.rider_user_id WHERE r.agent_id = ? ORDER BY r.id DESC LIMIT 100");
    $rf->execute(array($id));
    $refs = $rf->fetchAll(PDO::FETCH_ASSOC);
    foreach ($refs as &$r) { $r['rider_phone'] = fa_mask_phone($r['rider_phone']); $r['risk_reasons'] = json_decode((string) $r['risk_reasons'], true); }
    unset($r);
    $au = $pdo->prepare("SELECT created_at, actor_type, actor_id, action, reason, detail FROM fa_audit_log WHERE (entity = 'agent' AND entity_id = ?) ORDER BY id DESC LIMIT 30");
    $au->execute(array($id));
    ok(array('agent' => $a, 'referrals' => $refs, 'audit' => $au->fetchAll(PDO::FETCH_ASSOC), 'shared_with' => fa_agent_shared_identity($pdo, $a), 'bookings' => fa_adm_bookings($pdo, $id)));
    break;
}

case 'admin_fa_sms_test': {
    // Send ONE text to a number the admin types, to check the SMS service and the wording before real customers are texted.
    // Optional: kind (default test), lang (en | am | both) and body (the draft being edited, not yet saved).
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    require_once __DIR__ . '/../lib/fa_sms_templates.php';
    $ph = isset($body['phone']) ? (string) $body['phone'] : '';
    if (etel_sms_msisdn($ph) === '') fail('Enter a valid phone number, for example 0911234567.');
    $kind = isset($body['kind']) ? (string) $body['kind'] : 'test';
    $lang = isset($body['lang']) ? (string) $body['lang'] : 'en';
    if (!array_key_exists($kind, fa_sms_kinds())) fail('Unknown message type');
    if (!in_array($lang, fa_sms_langs(), true)) fail('Unknown language');
    $q = $pdo->prepare("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'sms_test' AND actor_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"); $q->execute(array($adm['id']));
    if (intval($q->fetchColumn()) > 0) fail('A test text was just sent. Please wait a minute.', 429);
    if (isset($body['body']) && is_string($body['body']) && trim($body['body']) !== '') {
        $draft = str_replace("\r\n", "\n", $body['body']);
        $bad = fa_sms_validate($kind, $draft); if ($bad) fail($bad);
        $text = str_replace('{link}', fa_sms_link(), $draft);
    } else { $text = fa_sms_resolve($pdo, $kind, $lang); }
    $st = etel_sms_send($ph, $text);
    fa_audit($pdo, 'admin', $adm['id'], 'sms_test', 'setting', 0, '', array('result' => $st, 'kind' => $kind, 'lang' => $lang));
    if ($st === 'off') fail('No SMS service is set up on the server yet (add SMSET_API_KEY to api/.env).', 409, array('reason' => 'SMS_OFF'));
    if ($st !== 'sent') { $why = etel_sms_last_error(); fail('The test text was not sent. ' . ($why !== '' ? $why : ''), 502, array('reason' => 'SMS_FAILED', 'detail' => $why)); }
    $c = fa_sms_cost($text);
    ok(array('sent' => true, 'chars' => $c['chars'], 'segments' => $c['segments'], 'encoding' => $c['encoding']));
    break;
}

case 'admin_fa_sms_templates_get': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    require_once __DIR__ . '/../lib/fa_sms_templates.php';
    ok(fa_sms_overview($pdo, fa_settings($pdo, true)));
    break;
}

case 'admin_fa_sms_template_save': {
    // Save the admin's wording for one message and one language variant. reset = true (or the default text) removes the edit.
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    require_once __DIR__ . '/../lib/fa_sms_templates.php';
    $kind = isset($body['kind']) ? (string) $body['kind'] : ''; $lang = isset($body['lang']) ? (string) $body['lang'] : '';
    if (!array_key_exists($kind, fa_sms_kinds())) fail('Unknown message type');
    if (!in_array($lang, fa_sms_langs(), true)) fail('Unknown language');
    if (!fa_sms_ensure_table($pdo)) fail('The message table could not be created. Run the database update (migration 105) and try again.', 500);
    $def = fa_sms_defaults(); $text = isset($body['body']) ? rtrim(str_replace("\r\n", "\n", (string) $body['body'])) : '';
    $reset = !empty($body['reset']) || $text === $def[$kind][$lang];
    if ($reset) {
        $pdo->prepare('DELETE FROM fa_sms_templates WHERE kind = ? AND lang = ?')->execute(array($kind, $lang));
        fa_audit($pdo, 'admin', $adm['id'], 'sms_template_reset', 'setting', 0, $kind . '/' . $lang, array());
    } else {
        $bad = fa_sms_validate($kind, $text); if ($bad) fail($bad, 400);
        $pdo->prepare('INSERT INTO fa_sms_templates (kind, lang, body, updated_by, updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = NOW()')->execute(array($kind, $lang, $text, intval($adm['id'])));
        fa_audit($pdo, 'admin', $adm['id'], 'sms_template_saved', 'setting', 0, $kind . '/' . $lang, array('chars' => fa_sms_len($text)));
    }
    ok(fa_sms_overview($pdo, fa_settings($pdo, true)));
    break;
}

case 'admin_fa_sms_mode_save': {
    // Which language customers get: en | am | both.
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    require_once __DIR__ . '/../lib/fa_sms_templates.php';
    $m = isset($body['mode']) ? (string) $body['mode'] : '';
    $map = array('en' => 0, 'am' => 1, 'both' => 2);
    if (!isset($map[$m])) fail('Unknown language choice');
    try { fa_settings_save($pdo, array('agent_booking_sms_lang' => $map[$m], 'agent_booking_sms_am' => 0), $adm['id']); }
    catch (Exception $e) { fail($e->getMessage(), 400); }
    ok(fa_sms_overview($pdo, fa_settings($pdo, true)));
    break;
}

case 'admin_fa_customer_message': {
    // Send a customer an agent booked for either the "set your password" message or the "complete your profile" reminder.
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $bid = intval(isset($body['booking_id']) ? $body['booking_id'] : 0);
    $kind = isset($body['kind']) ? (string) $body['kind'] : '';
    if (!in_array($kind, array('reset', 'verify'), true)) fail('Unknown message type');
    $q = $pdo->prepare('SELECT b.id, b.agent_id, u.phone FROM fa_bookings b LEFT JOIN users u ON u.id = b.customer_user_id WHERE b.id = ?'); $q->execute(array($bid)); $bk = $q->fetch(PDO::FETCH_ASSOC);
    if (!$bk || empty($bk['phone'])) fail('Booking not found', 404);
    $q = $pdo->prepare("SELECT COUNT(*) FROM fa_audit_log WHERE entity = 'booking' AND entity_id = ? AND action = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $q->execute(array($bid, 'customer_message_' . $kind));
    if (intval($q->fetchColumn()) > 0) fail('This message was just sent. Please wait 10 minutes before sending it again.', 429);
    $S = fa_settings($pdo); $st = fa_customer_message($bk['phone'], $kind, fa_sms_mode($S), $pdo);
    if ($st === 'off') fail('No SMS service is set up on the server yet (add SMSET_API_KEY to api/.env), so no text can be sent. Ask the customer to tap Forgot password in the Etel Ride app.', 409, array('reason' => 'SMS_OFF'));
    if ($st !== 'sent') { $why = function_exists('etel_sms_last_error') ? etel_sms_last_error() : '';
        fail('The text was not sent.' . ($why !== '' ? ' ' . $why : '') . ' (On the free test package only numbers you verified in the SMS dashboard can receive texts.)', 502, array('reason' => 'SMS_FAILED', 'detail' => $why)); }
    fa_audit($pdo, 'admin', $adm['id'], 'customer_message_' . $kind, 'booking', $bid, '', array('agent' => intval($bk['agent_id'])));
    ok(array('sent' => true, 'kind' => $kind));
    break;
}

case 'admin_fa_agent_decide': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $id = intval(isset($body['id']) ? $body['id'] : 0);
    $dec = isset($body['decision']) ? (string) $body['decision'] : '';
    $q = $pdo->prepare('SELECT * FROM fa_agents WHERE id = ?'); $q->execute(array($id)); $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) fail('Agent not found', 404);
    if ($dec === 'approve') {
        if ($a['status'] === 'approved') ok(array('status' => 'approved', 'code' => $a['code']));
        // Age gate: verified automatically by EtelID (adult AND Fayda reviewed), or confirmed by an admin who checked the ID card.
        if (!in_array($a['age_status'], array('auto_verified', 'manual_verified'), true)) {
            if (empty($body['manual_age_ok'])) fail('Age is not verified. Check the agent\'s Fayda ID card, then approve with "I checked the ID: 18 or older".', 409, array('code' => 'age_unverified'));
            $note = trim(isset($body['age_note']) ? (string) $body['age_note'] : '');
            if (strlen($note) < 3) fail('Write what you checked (for example: Fayda card seen, born 1998).');
            $pdo->prepare("UPDATE fa_agents SET age_status='manual_verified', age_note=?, age_checked_by=?, age_checked_at=NOW() WHERE id=?")->execute(array(substr($note, 0, 200), $adm['id'], $id));
            fa_audit($pdo, 'admin', $adm['id'], 'age_manually_verified', 'agent', $id, $note, array());
        }
        $code = $a['code'] ? $a['code'] : fa_gen_code($pdo);
        $pdo->prepare("UPDATE fa_agents SET status='approved', code=?, decided_by=?, decided_at=NOW(), decision_reason='', updated_at=NOW() WHERE id=?")->execute(array($code, $adm['id'], $id));
        fa_audit($pdo, 'admin', $adm['id'], 'agent_approved', 'agent', $id, '', array('code' => $code));
        fa_notify($pdo, $id, 'agent_approved', array());
        ok(array('status' => 'approved', 'code' => $code));
    }
    if (in_array($dec, array('reject', 'suspend'), true)) {
        $reason = fa_adm_reason($body, true);
        $st = $dec === 'reject' ? 'rejected' : 'suspended';
        $pdo->prepare("UPDATE fa_agents SET status=?, decided_by=?, decided_at=NOW(), decision_reason=?, updated_at=NOW() WHERE id=?")->execute(array($st, $adm['id'], $reason, $id));
        fa_audit($pdo, 'admin', $adm['id'], 'agent_' . $st, 'agent', $id, $reason, array());
        fa_notify($pdo, $id, 'agent_' . $st, array());
        ok(array('status' => $st));
    }
    if ($dec === 'reinstate') {
        if ($a['status'] !== 'suspended') fail('Only a suspended agent can be reinstated');
        $reason = fa_adm_reason($body, true);
        $pdo->prepare("UPDATE fa_agents SET status='approved', decided_by=?, decided_at=NOW(), decision_reason='', updated_at=NOW() WHERE id=?")->execute(array($adm['id'], $id));
        fa_audit($pdo, 'admin', $adm['id'], 'agent_reinstated', 'agent', $id, $reason, array());
        ok(array('status' => 'approved'));
    }
    fail('Unknown decision');
    break;
}

case 'admin_fa_earnings_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $w = array('1=1'); $p = array();
    foreach (array('status' => array('pending', 'payable', 'held', 'paid', 'rejected', 'clawed_back'), 'kind' => array('first_trip', 'repeat3', 'clawback')) as $f => $allowed) {
        if (!empty($body[$f]) && in_array($body[$f], $allowed, true)) { $w[] = 'e.' . $f . ' = ?'; $p[] = $body[$f]; }
    }
    if (!empty($body['agent_id'])) { $w[] = 'e.agent_id = ?'; $p[] = intval($body['agent_id']); }
    if (!empty($body['min_risk'])) { $w[] = 'e.risk_score >= ?'; $p[] = intval($body['min_risk']); }
    $lim = fa_adm_limit(isset($body['limit']) ? $body['limit'] : 0, 100, 500);
    $off = max(0, intval(isset($body['offset']) ? $body['offset'] : 0));
    $q = $pdo->prepare("SELECT e.id, e.referral_id, e.agent_id, e.kind, e.amount, e.status, e.reason_code, e.reason_note, e.earned_at, e.payable_at, e.risk_score, e.risk_reasons, e.ride_id,
                               a.full_name AS agent_name, a.venue_name, a.tier, u.phone AS rider_phone, rr.driver_id
                        FROM fa_earnings e JOIN fa_agents a ON a.id = e.agent_id LEFT JOIN fa_referrals rr ON rr.id = e.referral_id AND e.referral_id > 0 LEFT JOIN users u ON u.id = rr.rider_user_id
                        WHERE " . implode(' AND ', $w) . " ORDER BY (e.status = 'held') DESC, e.risk_score DESC, e.id DESC LIMIT $lim OFFSET $off");
    $q->execute($p);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['rider_phone'] = fa_mask_phone($r['rider_phone']); $r['risk_reasons'] = json_decode((string) $r['risk_reasons'], true); $r['reason_text'] = fa_reason_text($r['reason_code']); }
    unset($r);
    ok(array('earnings' => $rows));
    break;
}

case 'admin_fa_earning_decide': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $dec = isset($body['decision']) ? (string) $body['decision'] : '';
    $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : array(isset($body['id']) ? $body['id'] : 0);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 100);
    if (!$ids) fail('Select at least one item');
    if (!in_array($dec, array('release', 'reject'), true)) fail('Unknown decision');
    $reason = fa_adm_reason($body, true);
    $done = 0; $skipped = 0;
    foreach ($ids as $id) {
        $q = $pdo->prepare('SELECT * FROM fa_earnings WHERE id = ?'); $q->execute(array($id)); $e = $q->fetch(PDO::FETCH_ASSOC);
        if (!$e) { $skipped++; continue; }
        if ($dec === 'release') {
            // Only a HELD item can be released; it re-enters the normal hold timer (original earned date + hold days).
            $u = $pdo->prepare("UPDATE fa_earnings SET status='pending', reason_code='ADMIN_RELEASED', reason_note=?, decided_by=?, decided_at=NOW(), updated_at=NOW() WHERE id=? AND status='held'");
            $u->execute(array($reason, $adm['id'], $id));
        } else {
            $u = $pdo->prepare("UPDATE fa_earnings SET status='rejected', reason_code='ADMIN_REJECTED', reason_note=?, decided_by=?, decided_at=NOW(), updated_at=NOW() WHERE id=? AND status IN ('pending','held') AND payout_item_id=0");
            $u->execute(array($reason, $adm['id'], $id));
        }
        if ($u->rowCount() < 1) { $skipped++; continue; }
        $done++;
        fa_audit($pdo, 'admin', $adm['id'], 'earning_' . $dec, 'earning', $id, $reason, array('agent' => intval($e['agent_id']), 'amount' => $e['amount'], 'was' => $e['status'], 'score' => intval($e['risk_score'])));
        fa_notify($pdo, $e['agent_id'], $dec === 'release' ? 'bonus_pending' : 'referral_rejected', array('earning' => $id, 'amount' => $e['amount'], 'reason' => $dec === 'release' ? '' : 'ADMIN_REJECTED'));
    }
    ok(array('done' => $done, 'skipped' => $skipped));
    break;
}

case 'admin_fa_run_earnings': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $res = fa_run_earnings($pdo);
    fa_audit($pdo, 'admin', $adm['id'], 'earnings_job_run', 'job', 0, '', $res);
    ok(array('result' => $res, 'budget' => fa_budget_status($pdo)));
    break;
}

case 'admin_fa_stats': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $S = fa_settings($pdo);
    $one = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
    $acquired = intval($one("SELECT COUNT(*) FROM fa_referrals WHERE status = 'qualified' AND first_ride_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"));
    $repeat = intval($one("SELECT COUNT(*) FROM fa_referrals r WHERE r.status = 'qualified' AND r.first_ride_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND EXISTS (SELECT 1 FROM fa_earnings e WHERE e.referral_id = r.id AND e.kind = 'repeat3')"));
    $cost = floatval($one("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE status IN ('pending','payable','held','paid') AND earned_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"));
    ok(array(
        'programme_enabled' => !empty($S['program_enabled']),
        'agents_active' => intval($one("SELECT COUNT(*) FROM fa_agents WHERE status = 'approved'")),
        'agents_pending' => intval($one("SELECT COUNT(*) FROM fa_agents WHERE status = 'pending'")),
        'partners_waiting' => (function ($pdo) { try { return intval($pdo->query("SELECT COUNT(*) FROM fa_partners WHERE status = 'submitted'")->fetchColumn()); } catch (Exception $e) { return 0; } })($pdo),
        'riders_acquired_30d' => $acquired,
        'repeat_rate_30d' => $acquired ? round($repeat / $acquired * 100, 1) : 0,
        'cost_per_rider' => $acquired ? round($cost / $acquired, 2) : 0,
        'owed_payable' => round(floatval($one("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE status = 'payable'")), 2),
        'in_hold' => round(floatval($one("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE status = 'pending'")), 2),
        'flagged' => intval($one("SELECT COUNT(*) FROM fa_earnings WHERE status = 'held'")),
        'high_risk' => intval($one("SELECT COUNT(*) FROM fa_earnings WHERE status IN ('held','pending') AND risk_score >= " . intval($S['risk_high_threshold']))),
        'budget' => fa_budget_status($pdo),
    ));
    break;
}

case 'admin_fa_payouts_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $b = $pdo->query('SELECT * FROM fa_payout_batches ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    ok(array('candidates' => fa_payout_candidates($pdo), 'batches' => $b));
    break;
}

case 'admin_fa_payout_create': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.pay');
    try { ok(array('batch_id' => fa_payout_create($pdo, $adm['id'], isset($body['agent_ids']) ? $body['agent_ids'] : array(), isset($body['note']) ? $body['note'] : ''))); }
    catch (Exception $e) { fail($e->getMessage(), 409); }
    break;
}

case 'admin_fa_payout_export': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.pay');
    try { ok(fa_payout_export($pdo, $adm['id'], isset($body['batch_id']) ? $body['batch_id'] : 0)); }
    catch (Exception $e) { fail($e->getMessage(), 409); }
    break;
}

case 'admin_fa_payout_mark_paid': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.pay');
    try { fa_payout_mark_paid($pdo, $adm['id'], isset($body['batch_id']) ? $body['batch_id'] : 0, isset($body['refs']) && is_array($body['refs']) ? $body['refs'] : array()); ok(array('paid' => true)); }
    catch (Exception $e) { fail($e->getMessage(), 409); }
    break;
}

case 'admin_fa_payout_cancel': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.pay');
    try { fa_payout_cancel($pdo, $adm['id'], isset($body['batch_id']) ? $body['batch_id'] : 0, fa_adm_reason($body, true)); ok(array('cancelled' => true)); }
    catch (Exception $e) { fail($e->getMessage(), 409); }
    break;
}

case 'admin_fa_audit_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $w = array('1=1'); $p = array();
    if (!empty($body['entity'])) { $w[] = 'entity = ?'; $p[] = substr((string) $body['entity'], 0, 30); }
    if (!empty($body['entity_id'])) { $w[] = 'entity_id = ?'; $p[] = intval($body['entity_id']); }
    if (!empty($body['action_name'])) { $w[] = 'action = ?'; $p[] = substr((string) $body['action_name'], 0, 60); }
    $lim = fa_adm_limit(isset($body['limit']) ? $body['limit'] : 0, 100, 500);
    $off = max(0, intval(isset($body['offset']) ? $body['offset'] : 0));
    $q = $pdo->prepare('SELECT id, created_at, actor_type, actor_id, action, entity, entity_id, reason, detail FROM fa_audit_log WHERE ' . implode(' AND ', $w) . " ORDER BY id DESC LIMIT $lim OFFSET $off");
    $q->execute($p);
    $out = array('entries' => $q->fetchAll(PDO::FETCH_ASSOC));
    if (!empty($body['verify'])) $out['chain'] = fa_audit_verify($pdo);
    ok($out);
    break;
}


case 'admin_fa_reports': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $days = max(7, min(90, intval(isset($body['days']) ? $body['days'] : 30)));
    $since = date('Y-m-d', time() - 86400 * $days);
    $ok = "e.kind <> 'clawback' AND e.status IN ('pending','payable','held','paid')";
    // Daily series (zero-filled so the chart has no gaps).
    $series = array();
    for ($i = $days; $i >= 0; $i--) $series[date('Y-m-d', time() - 86400 * $i)] = array('d' => date('Y-m-d', time() - 86400 * $i), 'claims' => 0, 'new_riders' => 0, 'repeat' => 0, 'cost' => 0);
    $q = $pdo->prepare("SELECT DATE(claimed_at) d, COUNT(*) n FROM fa_referrals WHERE claimed_at >= ? GROUP BY d"); $q->execute(array($since));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['d']])) $series[$r['d']]['claims'] = intval($r['n']);
    $q = $pdo->prepare("SELECT DATE(first_ride_at) d, COUNT(*) n FROM fa_referrals WHERE status = 'qualified' AND first_ride_at >= ? GROUP BY d"); $q->execute(array($since));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['d']])) $series[$r['d']]['new_riders'] = intval($r['n']);
    $q = $pdo->prepare("SELECT DATE(e.earned_at) d, SUM(e.kind = 'repeat3') rep, COALESCE(SUM(e.amount),0) c FROM fa_earnings e WHERE $ok AND e.earned_at >= ? GROUP BY d"); $q->execute(array($since));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['d']])) { $series[$r['d']]['repeat'] = intval($r['rep']); $series[$r['d']]['cost'] = round(floatval($r['c']), 2); }
    // Per-agent performance.
    $q = $pdo->prepare("SELECT a.id, a.full_name, a.venue_name, a.venue_type, a.tier, a.status, a.risk_score,
        (SELECT COUNT(*) FROM fa_referrals r WHERE r.agent_id = a.id AND r.claimed_at >= ?) AS claims,
        (SELECT COUNT(*) FROM fa_referrals r WHERE r.agent_id = a.id AND r.status = 'qualified' AND r.first_ride_at >= ?) AS acquired,
        (SELECT COUNT(*) FROM fa_referrals r WHERE r.agent_id = a.id AND r.status = 'rejected' AND r.claimed_at >= ?) AS rejected,
        (SELECT COUNT(*) FROM fa_referrals r WHERE r.agent_id = a.id AND r.status = 'qualified' AND r.first_ride_at >= ?
            AND EXISTS (SELECT 1 FROM fa_earnings x WHERE x.referral_id = r.id AND x.kind = 'repeat3' AND x.status NOT IN ('rejected','clawed_back'))) AS repeaters,
        (SELECT COALESCE(SUM(e.amount),0) FROM fa_earnings e WHERE e.agent_id = a.id AND $ok AND e.earned_at >= ?) AS cost,
        (SELECT COALESCE(SUM(e.amount),0) FROM fa_earnings e WHERE e.agent_id = a.id AND e.status = 'held') AS held
        FROM fa_agents a WHERE a.status IN ('approved','suspended') ORDER BY acquired DESC, claims DESC LIMIT 200");
    $q->execute(array($since, $since, $since, $since, $since));
    $agents = $q->fetchAll(PDO::FETCH_ASSOC);
    $tot = array('claims' => 0, 'acquired' => 0, 'repeaters' => 0, 'rejected' => 0, 'cost' => 0); $byType = array();
    foreach ($agents as &$a) {
        foreach (array('claims', 'acquired', 'repeaters', 'rejected') as $k) { $a[$k] = intval($a[$k]); $tot[$k] += $a[$k]; }
        $a['cost'] = round(floatval($a['cost']), 2); $a['held'] = round(floatval($a['held']), 2); $tot['cost'] += $a['cost'];
        $a['repeat_rate'] = $a['acquired'] ? round($a['repeaters'] / $a['acquired'] * 100, 1) : 0;
        $a['cost_per_rider'] = $a['acquired'] ? round($a['cost'] / $a['acquired'], 2) : 0;
        $t = $a['venue_type']; if (!isset($byType[$t])) $byType[$t] = array('venue_type' => $t, 'agents' => 0, 'acquired' => 0, 'repeaters' => 0, 'cost' => 0);
        $byType[$t]['agents']++; $byType[$t]['acquired'] += $a['acquired']; $byType[$t]['repeaters'] += $a['repeaters']; $byType[$t]['cost'] += $a['cost'];
    }
    unset($a);
    foreach ($byType as &$b) { $b['repeat_rate'] = $b['acquired'] ? round($b['repeaters'] / $b['acquired'] * 100, 1) : 0; $b['cost_per_rider'] = $b['acquired'] ? round($b['cost'] / $b['acquired'], 2) : 0; $b['cost'] = round($b['cost'], 2); }
    unset($b);
    ok(array('days' => $days, 'since' => $since, 'daily' => array_values($series), 'agents' => $agents, 'by_venue_type' => array_values($byType),
             'totals' => array('claims' => $tot['claims'], 'acquired' => $tot['acquired'], 'repeaters' => $tot['repeaters'], 'rejected' => $tot['rejected'], 'cost' => round($tot['cost'], 2),
                               'repeat_rate' => $tot['acquired'] ? round($tot['repeaters'] / $tot['acquired'] * 100, 1) : 0,
                               'cost_per_rider' => $tot['acquired'] ? round($tot['cost'] / $tot['acquired'], 2) : 0)));
    break;
}


case 'admin_fa_payout_items': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $bid = intval(isset($body['batch_id']) ? $body['batch_id'] : 0);
    $b = $pdo->prepare('SELECT * FROM fa_payout_batches WHERE id = ?'); $b->execute(array($bid)); $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) fail('Batch not found', 404);
    $q = $pdo->prepare('SELECT i.id, i.agent_id, i.amount, i.payout_phone, i.tx_ref, i.status, i.paid_at, a.full_name, a.venue_name,
                               (SELECT COUNT(*) FROM fa_earnings e WHERE e.payout_item_id = i.id) AS earnings
                        FROM fa_payout_items i JOIN fa_agents a ON a.id = i.agent_id WHERE i.batch_id = ? ORDER BY i.id');
    $q->execute(array($bid));
    ok(array('batch' => $batch, 'items' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

}
