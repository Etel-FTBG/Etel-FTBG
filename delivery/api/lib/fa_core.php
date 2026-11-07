<?php
// ============================================================
// ETEL Ride - Field Agents: core helpers (settings, audit log, codes, masking, notifications).
// Pure helpers: nothing here changes a ride, a user or a payment. ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }

if (!function_exists('fa_defaults')) {

/* key => array(default, min, max). Every number the admin can change lives here, with its safe default.
   The same defaults are seeded by migration 101; this table is the in-code fallback and the validator. */
function fa_defaults() {
    return array(
        'program_enabled'         => array(0,   0,    1),
        'bonus_first_etb'         => array(20,  0,    500),
        'bonus_repeat_etb'        => array(30,  0,    500),
        'repeat_trips_required'   => array(3,   2,    20),
        'repeat_window_days'      => array(30,  7,    180),
        'qualify_window_days'     => array(30,  3,    180),
        'min_trip_km'             => array(1,   0,    50),
        'min_trip_minutes'        => array(3,   0,    180),
        'require_digital_payment' => array(0,   0,    1),
        'hold_days'               => array(7,   3,    60),
        'hold_days_silver'        => array(7,   3,    60),
        'hold_days_gold'          => array(7,   3,    60),
        'clawback_window_days'    => array(30,  7,    180),
        'daily_cap_etb'           => array(100, 0,    10000),
        'weekly_cap_etb'          => array(400, 0,    50000),
        'monthly_budget_etb'      => array(0,   0,    10000000),
        'budget_alert_pct'        => array(80,  10,   100),
        'min_payout_etb'          => array(50,  0,    5000),
        'risk_hold_threshold'     => array(60,  1,    100),
        'risk_high_threshold'     => array(80,  1,    100),
        'risk_w_same_device'      => array(45,  0,    100),
        'risk_w_repeat_pair'      => array(30,  0,    100),
        'risk_w_burst'            => array(25,  0,    100),
        'risk_w_short_trip'       => array(15,  0,    100),
        'risk_w_odd_hours'        => array(10,  0,    100),
        'risk_w_code_reuse'       => array(20,  0,    100),
        'risk_w_agent_history'    => array(15,  0,    100),
        'risk_burst_count'        => array(5,   2,    100),
        'risk_burst_minutes'      => array(60,  5,    1440),
        'risk_pair_threshold'     => array(3,   2,    50),
        'risk_short_trip_km'      => array(1.5, 0,    50),
        'risk_odd_hour_start'     => array(0,   0,    23),
        'risk_odd_hour_end'       => array(4,   0,    24),
        'risk_w_shared_identity'  => array(25,  0,    100),
        // Phase 4: tiers (quality, never a higher bonus), nudges, alerts, digest
        'tier_window_days'        => array(90,  30,   365),
        'tier_silver_min_riders'  => array(5,   1,    500),
        'tier_silver_min_repeat_pct' => array(20, 0,  100),
        'tier_gold_min_riders'    => array(15,  1,    1000),
        'tier_gold_min_repeat_pct' => array(35, 0,    100),
        'tier_max_reject_pct'     => array(20,  0,    100),
        'tier_max_risk'           => array(40,  0,    100),
        'dormant_days'            => array(14,  3,    90),
        'nudge_cooldown_days'     => array(7,   1,    60),
        'cap_alert_pct'           => array(80,  10,   100),
        'digest_enabled'          => array(1,   0,    1),
        // Fixed at 0 on purpose: EtelAI summaries are only a design hook (see lib/fa_insights.php). Nothing can switch it on from the screen.
        'ai_summary_enabled'      => array(0,   0,    0),
        // Phase 6: agents booking a ride for a customer. cash_counts = a CASH trip may earn the bonus for an agent-booked customer;
        // review_trips = how many of an agent's booked customers must have been released by an admin before new ones pay without review (0 = never review).
        'agent_booking_enabled'    => array(1,  0,  1),
        'agent_booking_daily_cap'  => array(10, 1,  100),
        'agent_booking_cash_counts' => array(1, 0,  1),
        'agent_booking_review_trips' => array(10, 0, 100),
        'agent_booking_sms'        => array(1,  0,  1),
        'agent_booking_sms_am'     => array(0,  0,  1),
        'risk_w_sequential_phones' => array(25, 0,  100),
    );
}

/* All settings merged over the defaults. Cached per request; fa_settings_save() clears the cache. */
function fa_settings($pdo, $refresh = false) {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $out = array();
    foreach (fa_defaults() as $k => $d) $out[$k] = $d[0] + 0;
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM fa_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['setting_key']]) && is_numeric($r['setting_value'])) $out[$r['setting_key']] = $r['setting_value'] + 0;
        }
    } catch (Exception $e) { /* table not migrated yet: safe defaults (programme disabled) */ }
    $cache = $out;
    return $out;
}

function fa_setting($pdo, $key) {
    $s = fa_settings($pdo);
    return isset($s[$key]) ? $s[$key] : 0;
}

/* Validates and saves a partial map of settings. Unknown keys are ignored; out-of-range values are rejected
   (never clamped silently). Every changed value is written to the audit log with old and new. */
function fa_settings_save($pdo, $input, $adminId) {
    $defs = fa_defaults();
    $cur = fa_settings($pdo, true);
    $changes = array();
    foreach ($input as $k => $v) {
        if (!isset($defs[$k])) continue;
        if (!is_numeric($v)) throw new Exception('Setting ' . $k . ' must be a number');
        $v = $v + 0;
        if ($v < $defs[$k][1] || $v > $defs[$k][2]) {
            throw new Exception('Setting ' . $k . ' must be between ' . $defs[$k][1] . ' and ' . $defs[$k][2]);
        }
        if ($cur[$k] != $v) $changes[$k] = $v;
    }
    $merged = array_merge($cur, $changes);
    if ($merged['risk_hold_threshold'] > $merged['risk_high_threshold']) {
        throw new Exception('The hold threshold cannot be higher than the high-risk threshold');
    }
    if ($merged['weekly_cap_etb'] > 0 && $merged['daily_cap_etb'] > $merged['weekly_cap_etb']) {
        throw new Exception('The daily cap cannot be higher than the weekly cap');
    }
    if ($merged['tier_gold_min_riders'] < $merged['tier_silver_min_riders']) throw new Exception('Gold needs at least as many riders as Silver');
    if ($merged['tier_gold_min_repeat_pct'] < $merged['tier_silver_min_repeat_pct']) throw new Exception('Gold needs at least the repeat rate of Silver');
    foreach (array('hold_days_silver', 'hold_days_gold') as $hk) {
        if ($merged[$hk] > $merged['hold_days']) throw new Exception('Tier hold days cannot be longer than the standard hold');
    }
    $up = $pdo->prepare('INSERT INTO fa_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()');
    foreach ($changes as $k => $v) {
        $up->execute(array($k, (string) $v, intval($adminId)));
        fa_audit($pdo, 'admin', $adminId, 'setting_changed', 'setting', 0, '', array('key' => $k, 'old' => $cur[$k], 'new' => $v));
    }
    return fa_settings($pdo, true);
}

/* ---------- audit log (append-only, hash-chained) ----------
   Rows are only ever INSERTed. Each row stores the hash of the row before it, so any later edit or
   delete breaks the chain and is reported by fa_audit_verify(). The log is append-only by application
   code and tamper-evident by this chain; MySQL itself is not locked (no trigger privileges needed). */
function fa_audit($pdo, $actorType, $actorId, $action, $entity, $entityId, $reason, $detail) {
    try {
        $gotLock = false;
        try { $gotLock = (bool) $pdo->query("SELECT GET_LOCK('fa_audit_chain', 3)")->fetchColumn(); } catch (Exception $e) {}
        $prev = '';
        $q = $pdo->query('SELECT row_hash FROM fa_audit_log ORDER BY id DESC LIMIT 1');
        $p = $q ? $q->fetchColumn() : false;
        if ($p) $prev = $p;
        $now = date('Y-m-d H:i:s');
        $detailJson = json_encode(is_array($detail) ? $detail : array());
        $reason = substr((string) $reason, 0, 255);
        $hash = hash('sha256', $prev . '|' . $now . '|' . $actorType . '|' . intval($actorId) . '|' . $action . '|' . $entity . '|' . intval($entityId) . '|' . $reason . '|' . $detailJson);
        $pdo->prepare('INSERT INTO fa_audit_log (created_at, actor_type, actor_id, action, entity, entity_id, reason, detail, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($now, $actorType, intval($actorId), $action, $entity, intval($entityId), $reason, $detailJson, $prev, $hash));
        if ($gotLock) { try { $pdo->query("SELECT RELEASE_LOCK('fa_audit_chain')"); } catch (Exception $e) {} }
    } catch (Exception $e) {
        error_log('[ETEL][fa_audit] ' . $e->getMessage());
    }
}

/* Re-computes the chain. Returns array(ok, rows, first_bad_id). */
function fa_audit_verify($pdo) {
    $prev = ''; $n = 0;
    $q = $pdo->query('SELECT * FROM fa_audit_log ORDER BY id ASC');
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        $h = hash('sha256', $prev . '|' . $r['created_at'] . '|' . $r['actor_type'] . '|' . intval($r['actor_id']) . '|' . $r['action'] . '|' . $r['entity'] . '|' . intval($r['entity_id']) . '|' . $r['reason'] . '|' . $r['detail']);
        if ($r['prev_hash'] !== $prev || $r['row_hash'] !== $h) return array('ok' => false, 'rows' => $n, 'first_bad_id' => intval($r['id']));
        $prev = $r['row_hash'];
    }
    return array('ok' => true, 'rows' => $n, 'first_bad_id' => 0);
}

/* ---------- small helpers ---------- */
function fa_phone_last9($p) {
    $d = preg_replace('/[^0-9]/', '', (string) $p);
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

/* 0912345678 -> 09** *** *78 : enough for an agent to recognise their rider, never the full number. */
function fa_mask_phone($p) {
    $l = fa_phone_last9($p);
    if (strlen($l) < 9) return '*** ***';
    return '0' . substr($l, 0, 1) . '** *** *' . substr($l, -2);
}

/* Server-side re-hash of a client-supplied device/ip token, so stored values cannot be matched
   against any other system and a leaked table reveals nothing about the device. */
function fa_hash($s) {
    $salt = defined('TOKEN_SECRET_FINAL') ? TOKEN_SECRET_FINAL : 'fa';
    return hash('sha256', 'fa|' . $salt . '|' . $s);
}

function fa_money($x) { return round(floatval($x), 2); }

/* Unambiguous alphabet (no 0/O, 1/I/L). 7 characters = about 17 billion codes. */
function fa_normalize_code($c) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c));
}

function fa_random_int($max) {
    if (function_exists('random_int')) return random_int(0, $max - 1);
    $b = openssl_random_pseudo_bytes(4);
    $v = hexdec(bin2hex($b));
    return $v % $max;
}

function fa_gen_code($pdo) {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $c = '';
        for ($i = 0; $i < 7; $i++) $c .= $alpha[fa_random_int(strlen($alpha))];
        $q = $pdo->prepare('SELECT COUNT(*) FROM fa_agents WHERE code = ?');
        $q->execute(array($c));
        if (!$q->fetchColumn()) return $c;
    }
    throw new Exception('Could not generate a unique agent code');
}

/* Which channels may carry a notification. ONLY 'portal' is on. Telegram and SMS are designed for later (a sender would read rows
   WHERE channel = 'telegram' / 'sms' AND delivered_at IS NULL) but nothing in this code base sends outside the portal. */
function fa_channels_enabled() { return array('portal' => true, 'telegram' => false, 'sms' => false); }

/* In-portal notification. Channels for Telegram / SMS are DESIGNED but OFF: a row is only ever created with
   channel 'portal'. A future sender would read rows WHERE channel = 'telegram' AND delivered_at IS NULL.
   Nothing external is sent from this file. */
function fa_notify($pdo, $agentId, $kind, $params) {
    try {
        $pdo->prepare('INSERT INTO fa_notifications (agent_id, kind, params, channel, created_at) VALUES (?,?,?,?,NOW())')
            ->execute(array(intval($agentId), $kind, substr(json_encode(is_array($params) ? $params : array()), 0, 500), 'portal'));
    } catch (Exception $e) { error_log('[ETEL][fa_notify] ' . $e->getMessage()); }
}

/* Plain-language English reason for a reason_code. The portal shows the translated version (lang JSON);
   this is the admin-facing text and the English fallback. Codes are stable: never rename one. */
/* True when this referral came from an agent booking a ride for the customer (fa_bookings), not from the customer scanning a code. */
function fa_ref_is_agent_booked($pdo, $ref) {
    try {
        $q = $pdo->prepare('SELECT 1 FROM fa_bookings WHERE agent_id = ? AND customer_user_id = ? LIMIT 1');
        $q->execute(array(intval($ref['agent_id']), intval($ref['rider_user_id'])));
        return (bool) $q->fetchColumn();
    } catch (Exception $e) { return false; }
}

function fa_reason_text($code) {
    $m = array(
        'SELF_USER'        => 'The rider is the same person as the agent.',
        'SELF_ETELID'      => 'The rider has the same Etel ID as the agent.',
        'SELF_PHONE'       => 'The rider has the same phone number as the agent.',
        'PAYOUT_IS_RIDER'  => 'The agent payout number belongs to the rider.',
        'NOT_NEW_RIDER'    => 'The rider already had a completed trip before using the code.',
        'CLAIM_EXPIRED'    => 'The rider did not complete a qualifying trip in time.',
        'RIDE_REVERSED'    => 'The trip was cancelled or refunded.',
        'RISK_HOLD'        => 'Held for admin review because the risk score is high.',
        'CAP_DAILY'        => 'Held because the agent reached the daily earnings limit.',
        'CAP_WEEKLY'       => 'Held because the agent reached the weekly earnings limit.',
        'AGENT_BOOKING_REVIEW' => 'Held for admin review: this agent booked the ride for the customer and has not yet built up enough checked bookings.',
        'ADMIN_RELEASED'   => 'Released by an admin after review.',
        'ADMIN_REJECTED'   => 'Rejected by an admin after review.',
        'PAID'             => 'Paid.',
    );
    return isset($m[$code]) ? $m[$code] : $code;
}

}

if (!function_exists('fa_customer_message')) {
require_once __DIR__ . '/sms_send.php';
/* The texts a customer an agent booked for can get. English fits ONE SMS credit (160 characters). Amharic (Ethiopic script) is sent as Unicode,
   which is limited to 70 characters per credit, so each Amharic text costs 2 credits - it is used only when admin turns on "Amharic texts".
   The Amharic strings are stored as \\u escapes so this file stays pure ASCII. */
function fa_customer_texts($lang) {
    if ($lang === 'am') {
        $j = array(
            'welcome' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u12c8\\u12aa\\u120d \\u1309\\u12de \\u12a0\\u12d8\\u12d8\\u120d\\u12ce\\u1362 \\u1239\\u134c\\u1229 \\u12ed\\u12f0\\u12cd\\u120b\\u120d\\u1363 \\u1260\\u1325\\u122c \\u12ed\\u12ad\\u1348\\u1209\\u1362 \\u1208\\u1218\\u12a8\\u1273\\u1270\\u120d ethelmarket.com/delivery/ride/ \\u1308\\u1265\\u1270\\u12cd \\"\\u12e8\\u12ed\\u1208\\u134d \\u1243\\u120d \\u1228\\u1231?\\" \\u12ed\\u132b\\u1291\\u1362"',
            'reset' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u1309\\u12de\\u12ce\\u1295 \\u1208\\u1218\\u12a8\\u1273\\u1270\\u120d \\u12c8\\u12ed\\u121d \\u1260\\u12a2\\u1275\\u12a4\\u120d \\u1354\\u12ed \\u1208\\u1218\\u12ad\\u1348\\u120d ethelmarket.com/delivery/ride/ \\u1308\\u1265\\u1270\\u12cd \\"\\u12e8\\u12ed\\u1208\\u134d \\u1243\\u120d \\u1228\\u1231?\\" \\u12ed\\u132b\\u1291\\u1362"',
            'verify' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 ethelmarket.com/delivery/ride/ \\u1308\\u1265\\u1270\\u12cd \\u1218\\u1308\\u1208\\u132b\\u12ce\\u1295 \\u12eb\\u121f\\u1209\\u1364 \\u12a0\\u12ab\\u12cd\\u1295\\u1275\\u12ce \\u1219\\u1209 \\u1260\\u1219\\u1209 \\u12ed\\u1228\\u130b\\u1308\\u1323\\u120d\\u1362"',
            'test' => '"\\u12a2\\u1275\\u12a4\\u120d\\u1366 \\u12e8\\u1219\\u12a8\\u122b \\u1218\\u120d\\u12a5\\u12ad\\u1275 \\u12a8\\u12a2\\u1275\\u12a4\\u120d \\u12a0\\u12f5\\u121a\\u1295\\u1362 \\u12a4\\u1235\\u12a4\\u121d\\u12a4\\u1235 \\u12a5\\u12e8\\u1230\\u122b \\u1290\\u12cd\\u1362"',
        );
        $o = array();
        foreach ($j as $k => $v) $o[$k] = json_decode($v);
        return $o;
    }
    return array(
        'welcome' => 'ETEL Ride: an agent booked a ride for you. The driver will call; pay in cash. To follow it open ethelmarket.com/delivery/ride/ and tap Forgot password.',
        'reset' => 'ETEL Ride: to follow your rides or pay with EtelPay open ethelmarket.com/delivery/ride/ and tap Forgot password.',
        'verify' => 'ETEL Ride: sign in at ethelmarket.com/delivery/ride/ and complete your profile to fully verify your account.',
        'test' => 'ETEL: test text from Etel Admin. SMS is working.',
    );
}
/* kind: welcome (sent automatically to a NEW customer), reset (set your password), verify (complete your profile), test (admin test text).
   lang: 'en' or 'am'. Returns 'sent', 'failed' or 'off' (no SMS service set up). Never throws. */
function fa_customer_message($phone, $kind, $lang = 'en') {
    $texts = fa_customer_texts($lang);
    if (!isset($texts[$kind]) || $texts[$kind] === null) return 'failed';
    return etel_sms_send($phone, $texts[$kind]);
}
}
