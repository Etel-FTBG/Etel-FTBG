<?php
// ============================================================
// ETEL Agents control centre - per-agent controls set by an admin (table fa_agent_controls, migration 110).
//   payout_hold    1 = this agent is skipped when a payout batch is built. Earnings keep counting and stay payable.
//   daily_cap_etb  this agent's own daily earnings limit (0 = use the programme setting).
//   note           internal note, never shown to the agent.
// Every function works when the table is not migrated yet: no controls, programme defaults.
// ============================================================
if (!function_exists('fa_controls_held_ids')) {

/* Agent ids whose payouts are on hold. */
function fa_controls_held_ids($pdo) {
    try {
        $out = array();
        foreach ($pdo->query('SELECT agent_id FROM fa_agent_controls WHERE payout_hold = 1')->fetchAll(PDO::FETCH_NUM) as $r) $out[] = intval($r[0]);
        return $out;
    } catch (Exception $e) { return array(); }
}

/* The daily earnings limit for one agent: the agent's own limit when set, else the programme's. */
function fa_controls_daily_cap($pdo, $agentId, $programmeCap) {
    try {
        $q = $pdo->prepare('SELECT daily_cap_etb FROM fa_agent_controls WHERE agent_id = ?');
        $q->execute(array(intval($agentId)));
        $v = $q->fetchColumn();
        if ($v !== false && floatval($v) > 0) return floatval($v);
    } catch (Exception $e) { /* not migrated */ }
    return floatval($programmeCap);
}

/* Cleans and checks the fields an admin may change. Returns array(clean, error). Fields not sent are left out. */
function fa_controls_clean($in) {
    $c = array();
    if (array_key_exists('payout_hold', $in)) $c['payout_hold'] = !empty($in['payout_hold']) && $in['payout_hold'] !== '0' ? 1 : 0;
    if (array_key_exists('hold_reason', $in)) $c['hold_reason'] = trim(substr((string) $in['hold_reason'], 0, 160));
    if (array_key_exists('daily_cap_etb', $in)) {
        if ($in['daily_cap_etb'] === '' || $in['daily_cap_etb'] === null) $in['daily_cap_etb'] = 0;
        if (!is_numeric($in['daily_cap_etb'])) return array(null, 'The daily limit must be a number (0 = use the programme setting).');
        $v = floatval($in['daily_cap_etb']);
        if ($v < 0 || $v > 10000) return array(null, 'The daily limit must be between 0 and 10000 ETB.');
        $c['daily_cap_etb'] = round($v, 2);
    }
    if (array_key_exists('note', $in)) $c['note'] = trim(substr((string) $in['note'], 0, 500));
    if (!empty($c['payout_hold']) && (!isset($c['hold_reason']) || $c['hold_reason'] === '')) return array(null, 'Write a short reason when you hold payouts.');
    if (isset($c['payout_hold']) && $c['payout_hold'] === 0 && !isset($c['hold_reason'])) $c['hold_reason'] = '';
    return array($c, '');
}

/* Saves the controls for one or more agents (same change for each). Returns the number of agents changed. Writes one audit row per agent. */
function fa_controls_save($pdo, $adminId, $agentIds, $changes) {
    $n = 0;
    foreach ($agentIds as $id) {
        $id = intval($id); if ($id <= 0) continue;
        $e = $pdo->prepare('SELECT id FROM fa_agents WHERE id = ?'); $e->execute(array($id));
        if (!$e->fetchColumn()) continue;
        $old = $pdo->prepare('SELECT payout_hold, hold_reason, daily_cap_etb, note FROM fa_agent_controls WHERE agent_id = ?'); $old->execute(array($id));
        $cur = $old->fetch(PDO::FETCH_ASSOC);
        $base = $cur ? $cur : array('payout_hold' => 0, 'hold_reason' => '', 'daily_cap_etb' => 0, 'note' => '');
        $new = array_merge($base, $changes);
        $diff = array();
        foreach ($changes as $k => $v) { if (!isset($base[$k]) || (string) $base[$k] !== (string) $v) $diff[$k] = array('from' => isset($base[$k]) ? $base[$k] : null, 'to' => $v); }
        if (!$diff && $cur) continue;
        if ($cur) {
            $pdo->prepare('UPDATE fa_agent_controls SET payout_hold = ?, hold_reason = ?, daily_cap_etb = ?, note = ?, updated_by = ?, updated_at = NOW() WHERE agent_id = ?')
                ->execute(array(intval($new['payout_hold']), $new['hold_reason'], $new['daily_cap_etb'], $new['note'], intval($adminId), $id));
        } else {
            $pdo->prepare('INSERT INTO fa_agent_controls (agent_id, payout_hold, hold_reason, daily_cap_etb, note, updated_by, updated_at) VALUES (?,?,?,?,?,?,NOW())')
                ->execute(array($id, intval($new['payout_hold']), $new['hold_reason'], $new['daily_cap_etb'], $new['note'], intval($adminId)));
        }
        $act = isset($diff['payout_hold']) ? (intval($new['payout_hold']) ? 'payout_hold_on' : 'payout_hold_off') : 'agent_controls_saved';
        fa_audit($pdo, 'admin', $adminId, $act, 'agent', $id, isset($new['hold_reason']) ? $new['hold_reason'] : '', $diff);
        $n++;
    }
    return $n;
}

/* One row per agent (approved and suspended) with the numbers an admin needs to decide: last 30 days and the controls. */
function fa_controls_list($pdo) {
    $from = date('Y-m-d H:i:s', time() - 30 * 86400);
    $rows = $pdo->query("SELECT id, full_name, venue_name, venue_zone, phone, status, tier, risk_score, last_referral_at, created_at FROM fa_agents
                         WHERE status IN ('approved','suspended') ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $m = array();
    foreach ($rows as $r) $m[intval($r['id'])] = array('riders30' => 0, 'earned30' => 0.0, 'payable' => 0.0, 'today' => 0.0, 'ctl' => null);
    $sum = function ($sql, $params, $fn) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($params); foreach ($q->fetchAll(PDO::FETCH_NUM) as $x) $fn($x); } catch (Exception $e) { /* table missing: zeros */ } };
    $sum("SELECT agent_id, COUNT(*) FROM fa_referrals WHERE claimed_at >= ? AND status <> 'rejected' GROUP BY agent_id", array($from), function ($x) use (&$m) { if (isset($m[intval($x[0])])) $m[intval($x[0])]['riders30'] = intval($x[1]); });
    $sum("SELECT agent_id, SUM(amount) FROM fa_earnings WHERE earned_at >= ? AND status IN ('pending','held','payable','paid') GROUP BY agent_id", array($from), function ($x) use (&$m) { if (isset($m[intval($x[0])])) $m[intval($x[0])]['earned30'] = round(floatval($x[1]), 2); });
    $sum("SELECT agent_id, SUM(amount) FROM fa_earnings WHERE status = 'payable' AND payout_item_id = 0 GROUP BY agent_id", array(), function ($x) use (&$m) { if (isset($m[intval($x[0])])) $m[intval($x[0])]['payable'] = round(floatval($x[1]), 2); });
    $sum("SELECT agent_id, SUM(amount) FROM fa_earnings WHERE earned_at >= ? AND status IN ('pending','held','payable','paid') GROUP BY agent_id", array(date('Y-m-d') . ' 00:00:00'), function ($x) use (&$m) { if (isset($m[intval($x[0])])) $m[intval($x[0])]['today'] = round(floatval($x[1]), 2); });
    $sum('SELECT agent_id, payout_hold, hold_reason, daily_cap_etb, note FROM fa_agent_controls', array(), function ($x) use (&$m) {
        if (isset($m[intval($x[0])])) $m[intval($x[0])]['ctl'] = array('payout_hold' => intval($x[1]), 'hold_reason' => $x[2], 'daily_cap_etb' => floatval($x[3]), 'note' => $x[4]);
    });
    $out = array();
    foreach ($rows as $r) {
        $id = intval($r['id']); $x = $m[$id]; $c = $x['ctl'];
        $out[] = array('id' => $id, 'name' => $r['full_name'], 'venue' => $r['venue_name'], 'zone' => $r['venue_zone'], 'phone' => $r['phone'], 'status' => $r['status'], 'tier' => $r['tier'],
                       'risk' => intval($r['risk_score']), 'last_referral_at' => $r['last_referral_at'], 'riders30' => $x['riders30'], 'earned30' => $x['earned30'],
                       'earned_today' => $x['today'], 'payable' => $x['payable'],
                       'payout_hold' => $c ? $c['payout_hold'] : 0, 'hold_reason' => $c ? $c['hold_reason'] : '', 'daily_cap_etb' => $c ? $c['daily_cap_etb'] : 0, 'note' => $c ? $c['note'] : '');
    }
    return $out;
}

}
