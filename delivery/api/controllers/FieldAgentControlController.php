<?php
// ============================================================
// ETEL Admin - Agents control centre.
//   admin_fa_controls_list   {}                                              -> agents with 30-day numbers and their controls   (agents.view)
//   admin_fa_controls_save   { ids: [..], payout_hold?, hold_reason?, daily_cap_etb?, note? }  -> changes the controls of 1 to 100 agents  (agents.manage)
// Nothing here pays, approves or rejects. It only holds payouts, sets a daily limit or saves a note; every change is audited.
// ============================================================
require_once __DIR__ . '/../lib/fa_core.php';
require_once __DIR__ . '/../lib/fa_controls.php';

switch ($action) {
case 'admin_fa_controls_list': {
    $pdo = db(); require_admin($pdo, 'agents.view');
    $S = fa_settings($pdo);
    ok(array('agents' => fa_controls_list($pdo), 'programme_daily_cap' => floatval($S['daily_cap_etb']), 'generated_at' => date('Y-m-d H:i:s')));
    break;
}
case 'admin_fa_controls_save': {
    $pdo = db(); $adm = require_admin($pdo, 'agents.manage');
    $ids = (isset($body['ids']) && is_array($body['ids'])) ? array_slice($body['ids'], 0, 100) : array();
    if (!$ids) fail('Choose at least one agent.');
    list($clean, $err) = fa_controls_clean($body);
    if ($err !== '') fail($err, 422);
    if (!$clean) fail('Nothing to change.');
    try { $n = fa_controls_save($pdo, $adm['id'], $ids, $clean); }
    catch (Exception $e) { error_log('[ETEL][fa_controls] ' . $e->getMessage()); fail('Could not save. If this keeps happening, run the database update (migration 110).', 500); }
    ok(array('changed' => $n));
    break;
}
}
