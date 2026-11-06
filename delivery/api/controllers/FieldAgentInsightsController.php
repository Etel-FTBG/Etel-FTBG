<?php
// ============================================================
// ETEL Ride - Field Agents: insights, tiers, nudges, digest (admin). Capabilities: agents.view (read), agents.manage (act).
//   admin_fa_insights      best agents / venues / zones, dormant agents, near-cap, budget, tiers, alerts
//   admin_fa_nudge         in-portal nudge to dormant agents (cooldown enforced, nothing sent outside the portal)
//   admin_fa_digest_list   stored weekly digests, newest first
//   admin_fa_digest_build  build (and store) this week's digest now
//   admin_fa_tiers_run     recompute tiers now
// ASCII only.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_earnings.php';
require_once __DIR__ . '/../lib/fa_insights.php';

switch ($action) {

case 'admin_fa_insights': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    ok(array('insights' => fa_insights($pdo, isset($body['days']) ? $body['days'] : 30), 'channels' => fa_channels_enabled(), 'ai' => fa_ai_summary($pdo, fa_digest_build($pdo))));
    break;
}

case 'admin_fa_nudge': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $ids = isset($body['agent_ids']) && is_array($body['agent_ids']) ? $body['agent_ids'] : array();
    if (!$ids) fail('Choose at least one agent');
    ok(fa_nudge($pdo, $adm['id'], $ids));
    break;
}

case 'admin_fa_digest_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $rows = $pdo->query('SELECT id, week_start, payload, created_at FROM fa_digests ORDER BY week_start DESC LIMIT 26')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['payload'] = json_decode((string) $r['payload'], true); }
    unset($r);
    ok(array('digests' => $rows));
    break;
}

case 'admin_fa_digest_build': {
    $pdo = db(); require_admin($pdo, 'agents.manage');
    $id = fa_digest_store($pdo);
    ok(array('created' => $id !== null, 'id' => $id, 'preview' => fa_digest_build($pdo)));
    break;
}

case 'admin_fa_tiers_run': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $r = fa_recompute_tiers($pdo);
    fa_audit($pdo, 'admin', $adm['id'], 'tiers_recomputed', 'programme', 0, '', array('changed' => count($r['changed'])));
    ok($r);
    break;
}

}
