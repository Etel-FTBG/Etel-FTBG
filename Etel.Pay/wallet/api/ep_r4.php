<?php
/* EtelPay Route Functions - Part 4: admin money tools.
 *
 * ep_admin_credit, ep_admin_self_topup, ep_admin_debit,
 * ep_admin_topup_approve, ep_admin_topup_reject, ep_admin_dispute_resolve, plus ep_my_topups (a user's
 * own top-up requests, for the result alerts).
 *
 * Every action here:
 *   - is capped per action (setting admin_action_cap, default 50000 ETB;
 *     a missing, zero or invalid setting falls back to the default, so
 *     there is never an unlimited mode),
 *   - runs in ONE transaction with a row lock on the wallet,
 *   - writes an audit row in <prefix>ep_admin_audit in the same
 *     transaction (no audit row = no money moved),
 *   - writes a ledger row equal to the amount that really moved.
 * The audit table is created on first use, no manual SQL needed.
 * ASCII only, PHP 5.6 compatible.
 */

if (!defined('EP_ADMIN_ACTION_CAP_DEFAULT')) define('EP_ADMIN_ACTION_CAP_DEFAULT', 50000);

function ep_admin_cap() {
    $s   = _ep_get_settings();
    $cap = isset($s['admin_action_cap']) ? (float)$s['admin_action_cap'] : 0;
    return ($cap > 0) ? $cap : (float)EP_ADMIN_ACTION_CAP_DEFAULT;
}

function ep_admin_check_amount($amount) {
    if ($amount <= 0) ep_err('Amount must be > 0');
    $cap = ep_admin_cap();
    if ($amount > $cap) ep_err('Amount is above the per-action admin limit of ETB ' . $cap, 400);
}

/* Create the audit table (before any transaction: DDL commits). */
function ep_admin_audit_ready($pdo) {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . EP_TBL_PREFIX . 'ep_admin_audit (' .
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ' .
        'admin_id BIGINT UNSIGNED NOT NULL, ' .
        'action VARCHAR(30) NOT NULL, ' .
        'target_user_id BIGINT UNSIGNED NULL, ' .
        'amount DECIMAL(18,4) NULL, ' .
        'balance_before DECIMAL(18,4) NULL, ' .
        'balance_after DECIMAL(18,4) NULL, ' .
        'ref VARCHAR(40) NULL, ' .
        'note VARCHAR(255) NULL, ' .
        'ip VARCHAR(45) NULL, ' .
        'created_at DATETIME NOT NULL, ' .
        'PRIMARY KEY (id), KEY idx_admin (admin_id), KEY idx_target (target_user_id)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/* Audit row; call inside the money transaction so a failure rolls it all back. */
function ep_admin_audit($pdo, $admin_id, $action, $target, $amount, $before, $after, $ref, $note) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
    $pdo->prepare('INSERT INTO ' . EP_TBL_PREFIX . 'ep_admin_audit (admin_id, action, target_user_id, amount, balance_before, balance_after, ref, note, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
        ->execute(array((int)$admin_id, $action, $target === null ? null : (int)$target, $amount, $before, $after, $ref, substr((string)$note, 0, 255), $ip));
}

/* One atomic credit/debit. $kind: credit | debit | self_topup.
 * Returns the new balance; refuses (ep_err) before any change on a problem. */
function ep_admin_money($admin_id, $kind, $uid, $amount, $note) {
    $amount = round((float)$amount, 2);
    ep_admin_check_amount($amount);
    $uid = (int)$uid;
    if ($uid <= 0) ep_err('User required');
    $pdo = ep_db();
    ep_admin_audit_ready($pdo);
    $err = null;
    $new = 0;
    $pdo->beginTransaction();
    try {
        $lk = $pdo->prepare('SELECT balance_etb FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1 FOR UPDATE');
        $lk->execute(array($uid));
        $row = $lk->fetch();
        if (!$row) {
            $err = array('User not found', 404);
        } else {
            $bal = (float)$row['balance_etb'];
            $ref = ep_gen_ref();
            if ($kind === 'debit') {
                if ($amount > $bal) {
                    $err = array('Debit of ETB ' . $amount . ' exceeds the balance of ETB ' . $bal, 400);
                } else {
                    $new = round($bal - $amount, 2);
                    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb - ? WHERE id = ?')->execute(array($amount, $uid));
                    $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, amount, currency, note, status, created_at) VALUES (?, "withdraw", ?, ?, "ETB", ?, "completed", NOW())')
                        ->execute(array($ref, $uid, $amount, 'Admin debit: ' . $note));
                }
            } else {
                $new = round($bal + $amount, 2);
                $label = ($kind === 'self_topup') ? ($note !== '' ? $note : 'Self top-up') : ('Admin credit: ' . $note);
                $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array($amount, $uid));
                $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?, "deposit", ?, ?, "ETB", ?, "completed", NOW())')
                    ->execute(array($ref, $uid, $amount, $label));
            }
            if (!$err) ep_admin_audit($pdo, $admin_id, $kind, $uid, $amount, $bal, $new, $ref, $note);
        }
        if ($err) $pdo->rollBack(); else $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ep_err('Admin action failed', 500);
    }
    if ($err) ep_err($err[0], $err[1]);
    return $new;
}

/* The reject reason column (shown to the user). Added once, before any
 * transaction: ALTER TABLE commits. */
function ep_topup_reason_col() {
    $pdo = ep_db();
    try {
        $c = $pdo->query('SHOW COLUMNS FROM ' . EP_TBL_TOPUP . ' LIKE "reject_reason"')->fetch();
        if (!$c) $pdo->exec('ALTER TABLE ' . EP_TBL_TOPUP . ' ADD COLUMN reject_reason VARCHAR(200) DEFAULT NULL');
    } catch (Exception $e) { /* table may not exist yet */ }
}

/* Why a top-up cannot be approved (empty string = it can). */
function ep_topup_block_reason($req) {
    $st = isset($req['status']) ? $req['status'] : '';
    if ($st === 'completed') return 'Already completed';
    if ($st === 'failed')    return 'This request was rejected or failed';
    if ($st === 'expired')   return 'This request has expired';
    if ($st !== 'pending' && $st !== 'processing') return 'This request is not pending';
    if (isset($req['method']) && $req['method'] === 'chapa') return 'Chapa top-ups are credited by Chapa verification, not by hand';
    // Never confirmed by the user and past its hour: expired.
    if ($st === 'pending' && !empty($req['expires_at']) && $req['expires_at'] < date('Y-m-d H:i:s')) return 'This request has expired';
    return '';
}

function ep_route_part4($action) {
  switch ($action) {

case 'ep_admin_credit': {
    $admin_id = _ep_require_admin();
    $amount   = round(in_float('amount'), 2);
    $nb = ep_admin_money($admin_id, 'credit', in_int('user_id'), $amount, in_str('note'));
    ep_ok(array('new_balance' => $nb), 'ETB ' . $amount . ' credited');
}

case 'ep_admin_self_topup': {
    $admin_id = _ep_require_admin();
    $amount   = round(in_float('amount'), 2);
    $nb = ep_admin_money($admin_id, 'self_topup', $admin_id, $amount, in_str('note'));
    ep_ok(array('new_balance' => $nb), 'ETB ' . $amount . ' added to your wallet');
}

case 'ep_admin_debit': {
    $admin_id = _ep_require_admin();
    $amount   = round(in_float('amount'), 2);
    $nb = ep_admin_money($admin_id, 'debit', in_int('user_id'), $amount, in_str('note'));
    ep_ok(array('new_balance' => $nb), 'ETB ' . $amount . ' debited');
}

case 'ep_admin_topup_approve': {
    $admin_id = _ep_require_admin();
    $ref = in_str('ref');
    if ($ref === '') ep_err('Ref required');
    $pdo = ep_db();
    ep_admin_audit_ready($pdo);
    $err = null;
    $credited = 0;
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE ref = ? LIMIT 1 FOR UPDATE');
        $s->execute(array($ref));
        $req = $s->fetch();
        if (!$req) {
            $err = array('Top-up not found', 404);
        } elseif (($why = ep_topup_block_reason($req)) !== '') {
            $err = array($why, 400);
        } elseif ((float)$req['amount'] <= 0 || (float)$req['amount'] > ep_admin_cap()) {
            $err = array('Amount is above the per-action admin limit of ETB ' . ep_admin_cap() . '. Raise the limit or ask the user to top up in smaller amounts.', 400);
        } else {
            $credited = round((float)$req['amount'], 2);
            $flip = $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status = "completed" WHERE ref = ? AND status IN ("pending","processing")');
            $flip->execute(array($ref));
            if ($flip->rowCount() !== 1) {
                $err = array('Already completed', 409);
            } else {
                $lk = $pdo->prepare('SELECT balance_etb FROM ' . EP_TBL_USERS . ' WHERE id = ? LIMIT 1 FOR UPDATE');
                $lk->execute(array((int)$req['user_id']));
                $row = $lk->fetch();
                if (!$row) {
                    $err = array('User not found', 404);
                } else {
                    $bal = (float)$row['balance_etb'];
                    $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array($credited, (int)$req['user_id']));
                    $txref = ep_gen_ref();
                    $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?, "deposit", ?, ?, "ETB", ?, "completed", NOW())')
                        ->execute(array($txref, (int)$req['user_id'], $credited, 'Top-up: ' . $ref));
                    ep_admin_audit($pdo, $admin_id, 'topup_approve', (int)$req['user_id'], $credited, $bal, round($bal + $credited, 2), $ref, 'Top-up approved');
                }
            }
        }
        if ($err) $pdo->rollBack(); else $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ep_err('Approval failed', 500);
    }
    if ($err) ep_err($err[0], $err[1]);
    ep_ok(array('ref' => $ref, 'credited' => $credited), 'ETB ' . $credited . ' credited');
}

case 'ep_admin_topup_reject': {
    $admin_id = _ep_require_admin();
    $ref    = in_str('ref');
    $reason = trim(in_str('reason'));
    if ($reason === '') $reason = 'Payment not received';
    if (strlen($reason) > 190) $reason = substr($reason, 0, 190);
    if ($ref === '') ep_err('Ref required');
    $pdo = ep_db();
    ep_topup_reason_col();
    ep_admin_audit_ready($pdo);
    $err  = null;
    $done = false;
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE ref = ? LIMIT 1 FOR UPDATE');
        $s->execute(array($ref));
        $req = $s->fetch();
        if (!$req) {
            $err = array('Top-up not found', 404);
        } elseif ($req['status'] === 'completed') {
            $err = array('Already credited - it cannot be rejected', 400);
        } elseif ($req['status'] === 'failed') {
            $done = true;   // already rejected: nothing to change, nothing to audit
        } elseif (isset($req['method']) && $req['method'] === 'chapa') {
            $err = array('Chapa top-ups cannot be rejected by hand', 400);
        } else {
            $flip = $pdo->prepare('UPDATE ' . EP_TBL_TOPUP . ' SET status = "failed", reject_reason = ? WHERE ref = ? AND status IN ("pending","processing","expired")');
            $flip->execute(array($reason, $ref));
            if ($flip->rowCount() !== 1) {
                $err = array('Could not reject (status changed)', 409);
            } else {
                ep_admin_audit($pdo, $admin_id, 'topup_reject', (int)$req['user_id'], round((float)$req['amount'], 2), null, null, $ref, $reason);
            }
        }
        if ($err) $pdo->rollBack(); else $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ep_err('Reject failed', 500);
    }
    if ($err) ep_err($err[0], $err[1]);
    ep_ok(array('ref' => $ref, 'status' => 'failed', 'reason' => $reason), $done ? 'Already rejected' : 'Top-up rejected');
}

case 'ep_admin_dispute_resolve': {
    $admin_id = _ep_require_admin();
    $id  = in_int('dispute_id');
    $act = in_str('action');            // resolve | reject | refund
    $res = in_str('resolution');
    if (!in_array($act, array('resolve', 'reject', 'refund'), true)) ep_err('Invalid action');
    if ($id <= 0) ep_err('Dispute required');
    $pdo = ep_db();
    ep_admin_audit_ready($pdo);
    $err = null;
    $pdo->beginTransaction();
    try {
        $dq = $pdo->prepare('SELECT * FROM ' . EP_TBL_DISPUTES . ' WHERE id = ? LIMIT 1 FOR UPDATE');
        $dq->execute(array($id));
        $d = $dq->fetch();
        if (!$d) {
            $err = array('Dispute not found', 404);
        } elseif ($d['status'] !== 'open' && $d['status'] !== 'investigating') {
            $err = array('Dispute already resolved/rejected', 400);
        } elseif ($act !== 'refund') {
            $new = ($act === 'reject') ? 'rejected' : 'resolved';
            $pdo->prepare('UPDATE ' . EP_TBL_DISPUTES . ' SET status = ?, resolution = ?, resolved_at = NOW() WHERE id = ?')->execute(array($new, $res, $id));
            ep_admin_audit($pdo, $admin_id, 'dispute_' . $act, (int)$d['user_id'], null, null, null, isset($d['tx_ref']) ? $d['tx_ref'] : null, $res);
        } else {
            // REFUND: money moves back from the receiver to the sender, or not at all.
            $tq = $pdo->prepare('SELECT * FROM ' . EP_TBL_TX . ' WHERE tx_ref = ? LIMIT 1 FOR UPDATE');
            $tq->execute(array($d['tx_ref']));
            $t = $tq->fetch();
            $amount = $t ? round((float)$t['amount'], 2) : 0;
            $from = $t ? (int)$t['receiver_ep_id'] : 0;   // gives the money back
            $to   = $t ? (int)$t['sender_ep_id'] : 0;     // gets it back
            if (!$t) {
                $err = array('Transaction not found for this dispute', 404);
            } elseif ($to <= 0 || $from <= 0 || $to === $from) {
                $err = array('Only a transfer between two wallets can be refunded', 400);
            } elseif ((int)$d['user_id'] !== $to && (int)$d['user_id'] !== $from) {
                $err = array('The person who opened the dispute was not a party to this transaction', 400);
            } elseif ($t['status'] !== 'completed') {
                $err = array('Only a completed transaction can be refunded', 400);
            } elseif ($amount <= 0 || $amount > ep_admin_cap()) {
                $err = array('Amount is above the per-action admin limit of ETB ' . ep_admin_cap() . '. Raise the limit to refund it.', 400);
            } else {
                $dup = $pdo->prepare('SELECT id FROM ' . EP_TBL_TX . ' WHERE type = "refund" AND note = ? LIMIT 1');
                $dup->execute(array('Refund: ' . $d['tx_ref']));
                if ($dup->fetch()) {
                    $err = array('This transaction was already refunded', 400);
                } else {
                    // Lock both wallets in a fixed order so two refunds cannot deadlock.
                    $ids = array($to, $from);
                    sort($ids);
                    $lk = $pdo->prepare('SELECT id, balance_etb FROM ' . EP_TBL_USERS . ' WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
                    $lk->execute($ids);
                    $bal = array();
                    while ($row = $lk->fetch()) $bal[(int)$row['id']] = (float)$row['balance_etb'];
                    if (!isset($bal[$to]) || !isset($bal[$from])) {
                        $err = array('A wallet of this transaction no longer exists', 404);
                    } elseif ($bal[$from] < $amount) {
                        $err = array('The receiver no longer has this money (balance ETB ' . $bal[$from] . '). Refund refused.', 400);
                    } else {
                        $dn = $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb - ? WHERE id = ? AND balance_etb >= ?');
                        $dn->execute(array($amount, $from, $amount));
                        if ($dn->rowCount() !== 1) {
                            $err = array('The receiver no longer has this money. Refund refused.', 409);
                        } else {
                            $pdo->prepare('UPDATE ' . EP_TBL_USERS . ' SET balance_etb = balance_etb + ? WHERE id = ?')->execute(array($amount, $to));
                            $rref = ep_gen_ref();
                            $pdo->prepare('INSERT INTO ' . EP_TBL_TX . ' (tx_ref, type, sender_ep_id, receiver_ep_id, amount, currency, note, status, created_at) VALUES (?, "refund", ?, ?, ?, "ETB", ?, "completed", NOW())')
                                ->execute(array($rref, $from, $to, $amount, 'Refund: ' . $d['tx_ref']));
                            $pdo->prepare('UPDATE ' . EP_TBL_DISPUTES . ' SET status = "resolved", resolution = ?, resolved_at = NOW() WHERE id = ?')->execute(array($res, $id));
                            ep_admin_audit($pdo, $admin_id, 'dispute_refund', $to, $amount, $bal[$to], round($bal[$to] + $amount, 2), $rref, 'Refund of ' . $d['tx_ref'] . ' from wallet ' . $from . ': ' . $res);
                        }
                    }
                }
            }
        }
        if ($err) $pdo->rollBack(); else $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ep_err('Dispute action failed', 500);
    }
    if ($err) ep_err($err[0], $err[1]);
    ep_ok(null, $act === 'refund' ? 'Dispute refunded' : ('Dispute ' . ($act === 'reject' ? 'rejected' : 'resolved')));
}

case 'ep_my_topups': {
    $uid = ep_auth_id();
    $pdo = ep_db();
    ep_topup_reason_col();
    $q = $pdo->prepare('SELECT * FROM ' . EP_TBL_TOPUP . ' WHERE user_id = ? AND status IN ("processing","completed","failed") ORDER BY created_at DESC LIMIT 10');
    $q->execute(array($uid));
    ep_ok(array('requests' => $q->fetchAll()));
}

    default: return false;
  }
  return true;
}
