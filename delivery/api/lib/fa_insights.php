<?php
// ============================================================
// ETEL Ride - Field Agents: tiers, insights, nudges, weekly digest.   (read-only on rides; writes only fa_* tables)
//
//  TIERS (Bronze / Silver / Gold) are about QUALITY: repeat-rider rate and low fraud, over tier_window_days. A tier NEVER raises
//  a bonus or a cap. The only perks are a shorter hold (hold_days_silver / hold_days_gold, never below the 3-day floor) and the badge.
//  INSIGHTS are plain counts and percentages an admin can read; nothing here decides anything about a person.
//  NUDGES are in-portal messages only, at most one per agent per nudge_cooldown_days.
//  DIGEST is a weekly snapshot for the admin, in English and Amharic.
//
//  EtelAI HOOK (design only, NOT built, OFF): fa_digest_ai_payload() shows exactly what a future weekly AI summary would be given:
//  totals only, never a name, phone, Etel ID, code, device or trip. fa_ai_summary() always answers "disabled" and sends nothing.
//  Sending anything to an external model needs the owner's explicit approval first. ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/fa_core.php';
require_once __DIR__ . '/fa_earnings.php';

if (!function_exists('fa_tier_for')) {

/* Quality numbers for every approved or suspended agent over the last $days days. Returns map agent_id => metrics. */
function fa_agent_metrics_all($pdo, $days) {
    $since = date('Y-m-d H:i:s', time() - 86400 * intval($days));
    $m = array();
    $q = $pdo->query("SELECT id, full_name, venue_name, venue_type, venue_zone, tier, risk_score, status FROM fa_agents WHERE status IN ('approved','suspended')");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $m[intval($a['id'])] = array('id' => intval($a['id']), 'name' => $a['full_name'], 'venue' => $a['venue_name'], 'type' => $a['venue_type'], 'zone' => $a['venue_zone'],
            'tier' => $a['tier'], 'risk' => intval($a['risk_score']), 'status' => $a['status'], 'acquired' => 0, 'repeaters' => 0, 'rejected' => 0, 'repeat_pct' => 0, 'reject_pct' => 0);
    }
    $s = $pdo->prepare("SELECT agent_id, COUNT(*) n FROM fa_referrals WHERE status = 'qualified' AND first_ride_at >= ? GROUP BY agent_id"); $s->execute(array($since));
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($m[intval($r['agent_id'])])) $m[intval($r['agent_id'])]['acquired'] = intval($r['n']);
    $s = $pdo->prepare("SELECT r.agent_id, COUNT(*) n FROM fa_referrals r WHERE r.status = 'qualified' AND r.first_ride_at >= ?
                        AND EXISTS (SELECT 1 FROM fa_earnings e WHERE e.referral_id = r.id AND e.kind = 'repeat3' AND e.status NOT IN ('rejected','clawed_back')) GROUP BY r.agent_id"); $s->execute(array($since));
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($m[intval($r['agent_id'])])) $m[intval($r['agent_id'])]['repeaters'] = intval($r['n']);
    $s = $pdo->prepare("SELECT agent_id, COUNT(*) n FROM fa_referrals WHERE status = 'rejected' AND claimed_at >= ? GROUP BY agent_id"); $s->execute(array($since));
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($m[intval($r['agent_id'])])) $m[intval($r['agent_id'])]['rejected'] = intval($r['n']);
    foreach ($m as $id => $x) {
        $m[$id]['repeat_pct'] = $x['acquired'] ? round($x['repeaters'] / $x['acquired'] * 100, 1) : 0;
        $den = $x['acquired'] + $x['rejected'];
        $m[$id]['reject_pct'] = $den >= 5 ? round($x['rejected'] / $den * 100, 1) : 0;       // too few referrals to judge: not held against the agent
    }
    return $m;
}

/* The tier an agent's numbers earn. Quality only: enough riders, enough of them coming back, few rejections, low risk. */
function fa_tier_for($x, $S) {
    $clean = $x['reject_pct'] <= $S['tier_max_reject_pct'] && $x['risk'] <= $S['tier_max_risk'];
    if ($clean && $x['acquired'] >= $S['tier_gold_min_riders'] && $x['repeat_pct'] >= $S['tier_gold_min_repeat_pct']) return 'gold';
    if ($clean && $x['acquired'] >= $S['tier_silver_min_riders'] && $x['repeat_pct'] >= $S['tier_silver_min_repeat_pct']) return 'silver';
    return 'bronze';
}

/* Recalculates every approved agent's tier. Writes only when the tier changes; each change is audited with the numbers and the agent is told in plain words. */
function fa_recompute_tiers($pdo) {
    $S = fa_settings($pdo);
    $m = fa_agent_metrics_all($pdo, $S['tier_window_days']);
    $changed = array(); $counts = array('bronze' => 0, 'silver' => 0, 'gold' => 0); $order = array('bronze' => 0, 'silver' => 1, 'gold' => 2);
    foreach ($m as $id => $x) {
        if ($x['status'] !== 'approved') continue;
        $new = fa_tier_for($x, $S); $counts[$new]++;
        if ($new === $x['tier']) continue;
        $u = $pdo->prepare("UPDATE fa_agents SET tier = ?, tier_updated_at = NOW(), updated_at = NOW() WHERE id = ? AND tier = ?");
        $u->execute(array($new, $id, $x['tier']));
        if ($u->rowCount() < 1) continue;
        $changed[] = array('agent' => $id, 'from' => $x['tier'], 'to' => $new);
        fa_audit($pdo, 'system', 0, 'tier_changed', 'agent', $id, '', array('from' => $x['tier'], 'to' => $new, 'riders' => $x['acquired'], 'repeat_pct' => $x['repeat_pct'], 'reject_pct' => $x['reject_pct'], 'risk' => $x['risk']));
        fa_notify($pdo, $id, $order[$new] > $order[$x['tier']] ? 'tier_up' : 'tier_down', array('tier' => $new));
    }
    return array('checked' => count($m), 'changed' => $changed, 'counts' => $counts);
}

/* What the agent sees about the next level: only "how many more riders" and "what repeat rate". Never the fraud or risk limits. */
function fa_tier_progress($pdo, $agent) {
    $S = fa_settings($pdo);
    $m = fa_agent_metrics_all($pdo, $S['tier_window_days']);
    $x = isset($m[intval($agent['id'])]) ? $m[intval($agent['id'])] : array('acquired' => 0, 'repeat_pct' => 0);
    $tier = $agent['tier'];
    $next = $tier === 'bronze' ? 'silver' : ($tier === 'silver' ? 'gold' : '');
    $out = array('tier' => $tier, 'next' => $next, 'riders' => $x['acquired'], 'repeat_pct' => $x['repeat_pct'], 'window_days' => intval($S['tier_window_days']),
                 'hold_days' => fa_hold_days($S, $tier), 'need_riders' => 0, 'need_repeat_pct' => 0);
    if ($next !== '') {
        $out['need_riders'] = max(0, intval($S['tier_' . $next . '_min_riders']) - $x['acquired']);
        $out['need_repeat_pct'] = max(0, intval(ceil($S['tier_' . $next . '_min_repeat_pct'] - $x['repeat_pct'])));
        $out['next_hold_days'] = fa_hold_days($S, $next);
    }
    return $out;
}

/* ---------- insights ---------- */
function fa_group_quality($m, $key, $min) {
    $g = array();
    foreach ($m as $x) {
        $k = trim($x[$key]); if ($k === '' || $x['status'] !== 'approved') continue;
        if (!isset($g[$k])) $g[$k] = array('name' => $k, 'agents' => 0, 'acquired' => 0, 'repeaters' => 0);
        $g[$k]['agents']++; $g[$k]['acquired'] += $x['acquired']; $g[$k]['repeaters'] += $x['repeaters'];
    }
    $out = array();
    foreach ($g as $v) { if ($v['acquired'] < $min) continue; $v['repeat_pct'] = round($v['repeaters'] / $v['acquired'] * 100, 1); $out[] = $v; }
    usort($out, function ($a, $b) { if ($a["repeat_pct"] != $b["repeat_pct"]) return $b["repeat_pct"] > $a["repeat_pct"] ? 1 : -1; return $b["acquired"] - $a["acquired"]; });
    return array_slice($out, 0, 5);
}

function fa_insights($pdo, $days) {
    $S = fa_settings($pdo);
    $days = max(7, min(180, intval($days)));
    $m = fa_agent_metrics_all($pdo, $days);
    // best agents: quality first (repeat rate), then volume; a few riders are not enough to rank
    $best = array();
    foreach ($m as $x) { if ($x['status'] === 'approved' && $x['acquired'] >= 3) { $x['score'] = round($x['repeat_pct'] + min($x['acquired'], 30), 1); $best[] = $x; } }
    usort($best, function ($a, $b) { return $b["score"] > $a["score"] ? 1 : ($b["score"] < $a["score"] ? -1 : 0); });
    // dormant agents
    $dormant = array();
    $q = $pdo->prepare("SELECT id, full_name, venue_name, last_referral_at, COALESCE(last_referral_at, decided_at, created_at) AS since_at FROM fa_agents
                        WHERE status = 'approved' AND COALESCE(last_referral_at, decided_at, created_at) < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY since_at ASC LIMIT 100");
    $q->execute(array(intval($S['dormant_days'])));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $n = $pdo->prepare("SELECT MAX(created_at) FROM fa_notifications WHERE agent_id = ? AND kind = 'nudge'"); $n->execute(array($d['id'])); $last = $n->fetchColumn();
        $can = !$last || strtotime($last) < time() - 86400 * intval($S['nudge_cooldown_days']);
        $dormant[] = array('id' => intval($d['id']), 'name' => $d['full_name'], 'venue' => $d['venue_name'], 'days_since' => intval(floor((time() - strtotime($d['since_at'])) / 86400)), 'last_nudge_at' => $last ?: null, 'can_nudge' => $can);
    }
    // agents close to their daily / weekly cap
    $near = array();
    $q = $pdo->query("SELECT e.agent_id, a.full_name, SUM(CASE WHEN DATE(e.earned_at) = CURDATE() THEN e.amount ELSE 0 END) AS d,
                             SUM(CASE WHEN YEARWEEK(e.earned_at, 1) = YEARWEEK(CURDATE(), 1) THEN e.amount ELSE 0 END) AS w
                      FROM fa_earnings e JOIN fa_agents a ON a.id = e.agent_id
                      WHERE e.kind <> 'clawback' AND e.status IN ('pending','payable','paid') AND e.earned_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY e.agent_id, a.full_name");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pd = $S['daily_cap_etb'] > 0 ? round($r['d'] / $S['daily_cap_etb'] * 100) : 0; $pw = $S['weekly_cap_etb'] > 0 ? round($r['w'] / $S['weekly_cap_etb'] * 100) : 0;
        if ($pd >= $S['cap_alert_pct'] || $pw >= $S['cap_alert_pct']) $near[] = array('id' => intval($r['agent_id']), 'name' => $r['full_name'], 'daily_pct' => $pd, 'weekly_pct' => $pw);
    }
    $budget = fa_budget_status($pdo);
    $tiers = array('bronze' => 0, 'silver' => 0, 'gold' => 0);
    foreach ($m as $x) if ($x['status'] === 'approved') $tiers[$x['tier']]++;
    $alerts = array();
    if ($budget['budget'] > 0 && $budget['projected_pct'] >= 100) $alerts[] = array('level' => 'critical', 'code' => 'BUDGET_PROJECTED', 'text' => 'At this pace the month will pass the programme budget (projected ' . $budget['projected_pct'] . '%).');
    elseif ($budget['budget'] > 0 && $budget['alert']) $alerts[] = array('level' => 'warn', 'code' => 'BUDGET_NEAR', 'text' => 'The programme has used ' . $budget['pct_used'] . '% of its monthly budget.');
    if ($near) $alerts[] = array('level' => 'info', 'code' => 'NEAR_CAP', 'text' => count($near) . ' agent(s) are close to their daily or weekly limit.');
    $old = intval($pdo->query("SELECT COUNT(*) FROM fa_earnings WHERE status = 'held' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)")->fetchColumn());
    if ($old) $alerts[] = array('level' => 'warn', 'code' => 'HELD_WAITING', 'text' => $old . ' held bonus(es) have waited more than 48 hours for a decision.');
    if ($dormant) $alerts[] = array('level' => 'info', 'code' => 'DORMANT', 'text' => count($dormant) . ' agent(s) have not brought a rider in ' . intval($S['dormant_days']) . ' days.');
    return array('days' => $days, 'best_agents' => array_slice($best, 0, 5), 'best_venues' => fa_group_quality($m, 'venue', 3), 'best_zones' => fa_group_quality($m, 'zone', 3),
        'dormant' => $dormant, 'near_cap' => $near, 'budget' => $budget, 'tiers' => $tiers, 'alerts' => $alerts,
        'tier_rules' => array('window_days' => intval($S['tier_window_days']), 'silver' => array(intval($S['tier_silver_min_riders']), floatval($S['tier_silver_min_repeat_pct'])),
            'gold' => array(intval($S['tier_gold_min_riders']), floatval($S['tier_gold_min_repeat_pct'])), 'max_reject_pct' => floatval($S['tier_max_reject_pct']), 'max_risk' => intval($S['tier_max_risk']),
            'hold_days' => array('bronze' => intval($S['hold_days']), 'silver' => intval($S['hold_days_silver']), 'gold' => intval($S['hold_days_gold']))));
}

/* In-portal nudge to dormant agents. At most one per agent per nudge_cooldown_days; only approved agents. Returns array(sent, skipped). */
function fa_nudge($pdo, $adminId, $agentIds) {
    $S = fa_settings($pdo); $sent = array(); $skipped = array();
    foreach (array_slice(array_unique(array_map('intval', (array) $agentIds)), 0, 100) as $id) {
        $q = $pdo->prepare("SELECT id, status FROM fa_agents WHERE id = ?"); $q->execute(array($id)); $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a || $a['status'] !== 'approved') { $skipped[] = array('id' => $id, 'why' => 'not an approved agent'); continue; }
        $n = $pdo->prepare("SELECT MAX(created_at) FROM fa_notifications WHERE agent_id = ? AND kind = 'nudge'"); $n->execute(array($id)); $last = $n->fetchColumn();
        if ($last && strtotime($last) >= time() - 86400 * intval($S['nudge_cooldown_days'])) { $skipped[] = array('id' => $id, 'why' => 'already nudged in the last ' . intval($S['nudge_cooldown_days']) . ' days'); continue; }
        fa_notify($pdo, $id, 'nudge', array('bonus' => $S['bonus_first_etb']));
        fa_audit($pdo, 'admin', $adminId, 'nudge_sent', 'agent', $id, '', array('channel' => 'portal'));
        $sent[] = $id;
    }
    return array('sent' => $sent, 'skipped' => $skipped);
}

/* ---------- weekly digest ---------- */
function fa_period_stats($pdo, $from, $to) {
    $one = function ($sql, $p) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $p = array($from, $to);
    $cost = floatval($one("SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE kind <> 'clawback' AND status IN ('pending','payable','held','paid') AND earned_at >= ? AND earned_at < ?", $p));
    $acq = intval($one("SELECT COUNT(*) FROM fa_referrals WHERE status = 'qualified' AND first_ride_at >= ? AND first_ride_at < ?", $p));
    return array('claims' => intval($one("SELECT COUNT(*) FROM fa_referrals WHERE claimed_at >= ? AND claimed_at < ?", $p)), 'acquired' => $acq,
        'repeaters' => intval($one("SELECT COUNT(*) FROM fa_earnings WHERE kind = 'repeat3' AND status NOT IN ('rejected','clawed_back') AND earned_at >= ? AND earned_at < ?", $p)),
        'rejected' => intval($one("SELECT COUNT(*) FROM fa_referrals WHERE status = 'rejected' AND claimed_at >= ? AND claimed_at < ?", $p)),
        'cost' => round($cost, 2), 'cost_per_rider' => $acq ? round($cost / $acq, 2) : 0,
        'new_agents' => intval($one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'agent_approved' AND created_at >= ? AND created_at < ?", $p)),
        'paid_out' => round(floatval($one("SELECT COALESCE(SUM(amount),0) FROM fa_payout_items WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?", $p)), 2),
        'tier_changes' => intval($one("SELECT COUNT(*) FROM fa_audit_log WHERE action = 'tier_changed' AND created_at >= ? AND created_at < ?", $p)));
}
function fa_digest_build($pdo) {
    $now = time(); $to = date('Y-m-d H:i:s', $now); $mid = date('Y-m-d H:i:s', $now - 7 * 86400); $from = date('Y-m-d H:i:s', $now - 14 * 86400);
    $cur = fa_period_stats($pdo, $mid, $to); $prev = fa_period_stats($pdo, $from, $mid);
    $delta = array(); foreach ($cur as $k => $v) $delta[$k] = round($v - $prev[$k], 2);
    $top = array();
    $q = $pdo->prepare("SELECT a.full_name, a.venue_name, COUNT(*) n FROM fa_referrals r JOIN fa_agents a ON a.id = r.agent_id WHERE r.status = 'qualified' AND r.first_ride_at >= ? AND r.first_ride_at < ? GROUP BY a.id, a.full_name, a.venue_name ORDER BY n DESC LIMIT 3");
    $q->execute(array($mid, $to)); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) $top[] = array('name' => $t['full_name'], 'venue' => $t['venue_name'], 'riders' => intval($t['n']));
    $held = intval($pdo->query("SELECT COUNT(*) FROM fa_earnings WHERE status = 'held'")->fetchColumn());
    $dig = array('from' => $mid, 'to' => $to, 'now' => $cur, 'before' => $prev, 'delta' => $delta, 'top_agents' => $top, 'held_now' => $held, 'budget' => fa_budget_status($pdo));
    $sg = function ($n) { return ($n > 0 ? '+' : '') . $n; };
    $names = array(); foreach ($top as $t) $names[] = $t['name'] . ' (' . $t['riders'] . ')';
    $dig['text_en'] = "Etel agents - week to " . substr($to, 0, 10) . "\nNew riders: " . $cur['acquired'] . " (" . $sg($delta['acquired']) . " vs last week)\nRepeat riders: " . $cur['repeaters'] . " (" . $sg($delta['repeaters']) . ")\nCost: ETB " . $cur['cost'] . ", per rider ETB " . $cur['cost_per_rider'] .
        "\nRejected referrals: " . $cur['rejected'] . "\nWaiting for a decision now: " . $held . "\nPaid to agents: ETB " . $cur['paid_out'] . "\nNew agents approved: " . $cur['new_agents'] . "\nTop agents: " . ($names ? implode(', ', $names) : 'none yet');
    $dig['text_am'] = fa_digest_am($cur, $delta, $held, $names, substr($to, 0, 10));
    return $dig;
}
/* Amharic digest. Strings are \uXXXX escapes decoded at run time so this file stays pure ASCII. */
function fa_digest_am($cur, $delta, $held, $names, $date) {
    $u = function ($s) { return json_decode('"' . $s . '"'); };
    $sg = function ($n) { return ($n > 0 ? '+' : '') . $n; };
    return $u('\u12e8\u12a2\u1275\u12a4\u120d \u12c8\u12aa\u120e\u127d - \u12e8\u1233\u121d\u1295\u1271 \u121b\u1320\u1243\u1208\u12eb \u12a5\u1235\u12a8 ') . $date . "\n" .
        $u('\u12a0\u12f2\u1235 \u1270\u1233\u134b\u122a\u12ce\u127d\u1366 ') . $cur['acquired'] . ' (' . $sg($delta['acquired']) . ")\n" .
        $u('\u1270\u12f0\u130b\u130b\u121a \u1270\u1233\u134b\u122a\u12ce\u127d\u1366 ') . $cur['repeaters'] . ' (' . $sg($delta['repeaters']) . ")\n" .
        $u('\u12c8\u132a\u1366 \u1265\u122d ') . $cur['cost'] . $u('\u1363 \u1208\u12a0\u1295\u12f5 \u1270\u1233\u134b\u122a \u1265\u122d ') . $cur['cost_per_rider'] . "\n" .
        $u('\u12cd\u12f5\u1245 \u12e8\u1270\u12f0\u1228\u1309 \u122a\u1348\u122b\u120e\u127d\u1366 ') . $cur['rejected'] . "\n" .
        $u('\u12a0\u1201\u1295 \u12cd\u1233\u1294 \u12e8\u121a\u1320\u1265\u1241\u1366 ') . $held . "\n" .
        $u('\u1208\u12c8\u12aa\u120e\u127d \u12e8\u1270\u12a8\u1348\u1208\u1366 \u1265\u122d ') . $cur['paid_out'] . "\n" .
        $u('\u12a0\u12f2\u1235 \u12e8\u1270\u1348\u1240\u12f1 \u12c8\u12aa\u120e\u127d\u1366 ') . $cur['new_agents'] . "\n" .
        $u('\u121d\u122d\u1325 \u12c8\u12aa\u120e\u127d\u1366 ') . ($names ? implode(', ', $names) : $u('\u12a5\u1235\u12ab\u1201\u1295 \u12e8\u1208\u121d'));
}

function fa_digest_store($pdo) {
    $S = fa_settings($pdo);
    if (empty($S['digest_enabled'])) return null;
    $week = date('Y-m-d', strtotime('monday this week'));
    $dig = fa_digest_build($pdo);
    $i = $pdo->prepare('INSERT IGNORE INTO fa_digests (week_start, payload, created_at) VALUES (?,?,NOW())');
    $i->execute(array($week, json_encode($dig)));
    if ($i->rowCount() < 1) return null;                           // this week's digest already exists
    $id = intval($pdo->lastInsertId());
    fa_audit($pdo, 'system', 0, 'digest_created', 'digest', $id, '', array('week' => $week));
    return $id;
}

/* ---------- EtelAI hook: DESIGN ONLY. Nothing below ever contacts anything. ---------- */
function fa_digest_ai_payload($dig) {
    // Totals only. No names, phones, Etel IDs, codes, devices, trips or venues.
    return array('period' => array($dig['from'], $dig['to']), 'now' => $dig['now'], 'before' => $dig['before'], 'delta' => $dig['delta'],
                 'held_now' => $dig['held_now'], 'budget_pct_used' => isset($dig['budget']['pct_used']) ? $dig['budget']['pct_used'] : 0, 'languages' => array('am', 'en'));
}
function fa_ai_summary($pdo, $dig) {
    return array('enabled' => false, 'reason' => 'EtelAI summaries are not switched on. Nothing leaves this server. The owner must approve what would be sent first.',
                 'would_send' => fa_digest_ai_payload($dig));
}

}
