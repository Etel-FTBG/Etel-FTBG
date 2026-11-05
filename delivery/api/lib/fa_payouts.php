<?php
// ============================================================
// ETEL Ride - Field Agents: payout batches (manual Telebirr payment).
//
// Nothing here moves money. An admin creates a batch (payable earnings are locked into it), exports a CSV, pays the
// agents in Telebirr by hand, then marks the batch paid with the Telebirr references. Every step is audited and every
// status change is guarded (UPDATE ... WHERE status = ...) so a double click or two admins cannot pay a batch twice.
// ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/fa_core.php';

if (!function_exists('fa_payout_candidates')) {

/* Approved agents with a payout number whose NET payable (bonuses minus clawbacks, not yet in a batch) reaches
   min_payout_etb. Suspended or rejected agents are never paid out automatically. */
function fa_payout_candidates($pdo) {
    $S = fa_settings($pdo);
    $q = $pdo->query("SELECT a.id, a.full_name, a.payout_phone, a.phone, SUM(e.amount) AS net, COUNT(e.id) AS n
                      FROM fa_earnings e JOIN fa_agents a ON a.id = e.agent_id
                      WHERE e.status = 'payable' AND e.payout_item_id = 0 AND a.status = 'approved' AND a.payout_phone <> ''
                      GROUP BY a.id, a.full_name, a.payout_phone, a.phone
                      HAVING SUM(e.amount) >= " . floatval($S['min_payout_etb']) . " AND SUM(e.amount) > 0
                      ORDER BY net DESC");
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/* Creates a draft batch for the given agent ids (or every candidate when $agentIds is empty). */
function fa_payout_create($pdo, $adminId, $agentIds, $note) {
    $cands = fa_payout_candidates($pdo);
    $want = array();
    foreach ((array) $agentIds as $x) $want[intval($x)] = 1;
    $pick = array();
    foreach ($cands as $c) { if (!$want || isset($want[intval($c['id'])])) $pick[] = $c; }
    if (!$pick) throw new Exception('No agent is ready for payout (check the minimum payout amount and payout numbers)');
    $pdo->prepare("INSERT INTO fa_payout_batches (status, note, created_by, created_at) VALUES ('draft', ?, ?, NOW())")
        ->execute(array(substr((string) $note, 0, 255), intval($adminId)));
    $bid = intval($pdo->lastInsertId());
    $total = 0; $count = 0;
    foreach ($pick as $c) {
        $pdo->prepare("INSERT INTO fa_payout_items (batch_id, agent_id, amount, payout_phone, status, created_at) VALUES (?,?,0,?,'pending',NOW())")
            ->execute(array($bid, intval($c['id']), $c['payout_phone']));
        $iid = intval($pdo->lastInsertId());
        // Lock this agent's payable earnings into the item. The WHERE re-checks status so a concurrent change cannot slip in.
        $pdo->prepare("UPDATE fa_earnings SET payout_item_id = ?, updated_at = NOW() WHERE agent_id = ? AND status = 'payable' AND payout_item_id = 0")
            ->execute(array($iid, intval($c['id'])));
        $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM fa_earnings WHERE payout_item_id = ?');
        $s->execute(array($iid));
        $amt = round(floatval($s->fetchColumn()), 2);
        $pdo->prepare('UPDATE fa_payout_items SET amount = ? WHERE id = ?')->execute(array($amt, $iid));
        $total += $amt; $count++;
    }
    $pdo->prepare('UPDATE fa_payout_batches SET item_count = ?, total_amount = ? WHERE id = ?')->execute(array($count, round($total, 2), $bid));
    fa_audit($pdo, 'admin', $adminId, 'payout_batch_created', 'batch', $bid, (string) $note, array('items' => $count, 'total' => round($total, 2)));
    return $bid;
}

function fa_csv_cell($v) {
    $v = (string) $v;
    // Spreadsheet formula injection: a leading = + - @ would be executed by Excel, so neutralise it.
    if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}

/* CSV for the Telebirr bulk-pay sheet. Marks a draft batch as exported (an exported batch can still be marked paid or cancelled). */
function fa_payout_export($pdo, $adminId, $batchId) {
    $b = $pdo->prepare('SELECT * FROM fa_payout_batches WHERE id = ?'); $b->execute(array(intval($batchId))); $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) throw new Exception('Batch not found');
    if (!in_array($batch['status'], array('draft', 'exported'), true)) throw new Exception('This batch is ' . $batch['status'] . ' and cannot be exported');
    $q = $pdo->prepare('SELECT i.id, i.amount, i.payout_phone, a.full_name, a.code FROM fa_payout_items i JOIN fa_agents a ON a.id = i.agent_id WHERE i.batch_id = ? ORDER BY i.id');
    $q->execute(array($batch['id']));
    $lines = array('"item_id","telebirr_phone","name","amount_etb","reference"');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = implode(',', array(fa_csv_cell($r['id']), fa_csv_cell($r['payout_phone']), fa_csv_cell($r['full_name']),
                                      fa_csv_cell(number_format($r['amount'], 2, '.', '')), fa_csv_cell('ETEL-AGENT-B' . $batch['id'] . '-' . $r['id'])));
    }
    if ($batch['status'] === 'draft') {
        $pdo->prepare("UPDATE fa_payout_batches SET status='exported', exported_at=NOW() WHERE id=? AND status='draft'")->execute(array($batch['id']));
    }
    fa_audit($pdo, 'admin', $adminId, 'payout_batch_exported', 'batch', $batch['id'], '', array('items' => count($lines) - 1));
    return array('filename' => 'etel-agent-payout-batch-' . $batch['id'] . '.csv', 'csv' => implode("\r\n", $lines) . "\r\n");
}

/* $refs: optional map item_id => Telebirr reference. Only a draft or exported batch can be paid, exactly once. */
function fa_payout_mark_paid($pdo, $adminId, $batchId, $refs) {
    $claim = $pdo->prepare("UPDATE fa_payout_batches SET status='paid', paid_at=NOW(), paid_by=? WHERE id=? AND status IN ('draft','exported')");
    $claim->execute(array(intval($adminId), intval($batchId)));
    if ($claim->rowCount() < 1) throw new Exception('This batch is not open for payment (already paid or cancelled)');
    $items = $pdo->prepare('SELECT * FROM fa_payout_items WHERE batch_id = ?'); $items->execute(array(intval($batchId)));
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $ref = (is_array($refs) && isset($refs[$it['id']])) ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $refs[$it['id']]), 0, 60) : '';
        $pdo->prepare("UPDATE fa_payout_items SET status='paid', paid_at=NOW(), tx_ref=? WHERE id=?")->execute(array($ref, $it['id']));
        $pdo->prepare("UPDATE fa_earnings SET status = IF(status = 'payable', 'paid', status), reason_code = IF(status = 'payable', 'PAID', reason_code), updated_at = NOW() WHERE payout_item_id = ?")
            ->execute(array($it['id']));
        fa_notify($pdo, $it['agent_id'], 'payout_paid', array('amount' => $it['amount'], 'batch' => intval($batchId)));
    }
    fa_audit($pdo, 'admin', $adminId, 'payout_batch_paid', 'batch', $batchId, '', array('items' => $items->rowCount()));
    return true;
}

/* Cancels an unpaid batch and releases its earnings back to payable so they can be batched again. */
function fa_payout_cancel($pdo, $adminId, $batchId, $reason) {
    $c = $pdo->prepare("UPDATE fa_payout_batches SET status='cancelled' WHERE id=? AND status IN ('draft','exported')");
    $c->execute(array(intval($batchId)));
    if ($c->rowCount() < 1) throw new Exception('Only an unpaid batch can be cancelled');
    $pdo->prepare("UPDATE fa_earnings SET payout_item_id = 0, updated_at = NOW() WHERE payout_item_id IN (SELECT id FROM fa_payout_items WHERE batch_id = ?)")->execute(array(intval($batchId)));
    $pdo->prepare("UPDATE fa_payout_items SET status='failed' WHERE batch_id = ?")->execute(array(intval($batchId)));
    fa_audit($pdo, 'admin', $adminId, 'payout_batch_cancelled', 'batch', $batchId, $reason, array());
    return true;
}

}
