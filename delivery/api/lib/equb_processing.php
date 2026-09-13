<?php
require_once __DIR__ . '/wallet_ops.php';

if (!function_exists('equb_process_group_round')) {
    // Processes exactly one due round for one group: attempts to
    // collect any still-pending contributions, and only pays out the
    // recipient once every active member's contribution has cleared.
    // Returns an array of log lines (for cron output / admin UI
    // feedback) - never echoes directly, so it's safely reusable from
    // both a CLI cron context and an HTTP admin action.
    function equb_process_group_round($pdo, $groupId, $maxGraceDays = 3) {
        $log = array();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM equb_groups WHERE id=? FOR UPDATE');
            $lock->execute(array($groupId));
            $group = $lock->fetch();
            if (!$group || !in_array($group['status'], array('active', 'at_risk'), true)) {
                $pdo->rollBack();
                $log[] = 'Group not active or not found - nothing to process.';
                return $log;
            }
            $round = intval($group['current_round']);
            $log[] = "Processing group #$groupId ('{$group['name']}') round $round";

            $members = $pdo->prepare("SELECT * FROM equb_members WHERE group_id=? AND status='active' ORDER BY join_order ASC");
            $members->execute(array($groupId));
            $members = $members->fetchAll(PDO::FETCH_ASSOC);

            $anyMissed = false;
            foreach ($members as $mem) {
                $c = $pdo->prepare('SELECT * FROM equb_contributions WHERE group_id=? AND member_id=? AND round_number=?');
                $c->execute(array($groupId, $mem['id'], $round));
                $contrib = $c->fetch();
                if (!$contrib) {
                    $pdo->prepare("INSERT INTO equb_contributions (group_id, member_id, round_number, amount, status) VALUES (?,?,?,?,'pending')")
                        ->execute(array($groupId, $mem['id'], $round, $group['contribution_amount']));
                    $contrib = array('status' => 'pending');
                }
                if ($contrib['status'] === 'paid') continue;

                $ok = wallet_debit($pdo, $mem['user_id'], floatval($group['contribution_amount']),
                    "Equb '{$group['name']}' round $round contribution", $groupId);
                if ($ok) {
                    $wt = $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
                    $pdo->prepare("UPDATE equb_contributions SET status='paid', wallet_transaction_id=?, paid_at=NOW() WHERE group_id=? AND member_id=? AND round_number=?")
                        ->execute(array($wt, $groupId, $mem['id'], $round));
                    $log[] = "  collected from user #{$mem['user_id']}";
                } else {
                    $pdo->prepare("UPDATE equb_contributions SET status='missed' WHERE group_id=? AND member_id=? AND round_number=?")
                        ->execute(array($groupId, $mem['id'], $round));
                    $log[] = "  INSUFFICIENT FUNDS: user #{$mem['user_id']}";
                    $anyMissed = true;
                }
            }

            if ($anyMissed) {
                $graceUsed = intval($group['grace_days_used']) + 1;
                if ($graceUsed > $maxGraceDays) {
                    $pdo->prepare("UPDATE equb_groups SET status='at_risk', grace_days_used=? WHERE id=?")->execute(array($graceUsed, $groupId));
                    $log[] = '  -> grace period exhausted, group flagged at_risk for admin review';
                } else {
                    $pdo->prepare("UPDATE equb_groups SET grace_days_used=?, next_contribution_date=DATE_ADD(next_contribution_date, INTERVAL 1 DAY) WHERE id=?")
                        ->execute(array($graceUsed, $groupId));
                    $log[] = "  -> retrying tomorrow (grace day $graceUsed of $maxGraceDays)";
                }
                $pdo->commit();
                return $log;
            }

            $recipient = $pdo->prepare("SELECT * FROM equb_members WHERE group_id=? AND payout_round=? AND status='active'");
            $recipient->execute(array($groupId, $round));
            $recipient = $recipient->fetch();
            if (!$recipient) {
                $log[] = "  ERROR: no recipient found for round $round - flagging at_risk";
                $pdo->prepare("UPDATE equb_groups SET status='at_risk' WHERE id=?")->execute(array($groupId));
                $pdo->commit();
                return $log;
            }
            $potAmount = round(floatval($group['contribution_amount']) * count($members), 2);
            $walletTxId = wallet_credit($pdo, $recipient['user_id'], $potAmount, "Equb '{$group['name']}' round $round payout", $groupId);
            $pdo->prepare('INSERT INTO equb_payouts (group_id, round_number, recipient_member_id, amount, wallet_transaction_id) VALUES (?,?,?,?,?)')
                ->execute(array($groupId, $round, $recipient['id'], $potAmount, $walletTxId));
            $log[] = "  PAID OUT $potAmount to user #{$recipient['user_id']} (round $round complete)";

            $nextRound = $round + 1;
            if ($nextRound > intval($group['total_rounds'])) {
                $pdo->prepare("UPDATE equb_groups SET status='completed' WHERE id=?")->execute(array($groupId));
                $log[] = '  Group complete - all rounds paid out.';
            } else {
                $freqDays = $group['frequency'] === 'weekly' ? 7 : ($group['frequency'] === 'biweekly' ? 14 : 30);
                $nextDate = date('Y-m-d', strtotime($group['next_contribution_date'] . " +$freqDays days"));
                $pdo->prepare("UPDATE equb_groups SET current_round=?, next_contribution_date=?, grace_days_used=0 WHERE id=?")
                    ->execute(array($nextRound, $nextDate, $groupId));
                $cstmt = $pdo->prepare("INSERT INTO equb_contributions (group_id, member_id, round_number, amount, status) VALUES (?,?,?,?,'pending')");
                foreach ($members as $mem) {
                    $cstmt->execute(array($groupId, $mem['id'], $nextRound, $group['contribution_amount']));
                }
                $log[] = "  Round $nextRound scheduled for $nextDate";
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $log[] = '  ERROR: ' . $e->getMessage();
        }
        return $log;
    }
}
