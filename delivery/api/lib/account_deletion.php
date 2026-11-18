<?php
// ============================================================
// "Delete my account" requests. The person asks in the app or on the web (customer_send_message, subject below); the team
// confirms who it is, then presses Delete here. Deleting means: the account can no longer sign in, the name, phone and
// email are replaced, saved addresses, favourites and push subscriptions are removed. Orders, trips and payments stay
// (without the name and phone) for as long as the law or a dispute needs them. Table account_deletion_requests (migration 113).
// ============================================================
if (!function_exists('deletion_subject')) {

function deletion_subject() { return 'Account deletion request'; }

function deletion_list($pdo) {
    $s = $pdo->prepare("SELECT m.id AS message_id, m.user_id, m.body, m.created_at, u.name, u.phone, u.role, u.active,
                               r.status, r.note, r.handled_at
                        FROM customer_messages m
                        LEFT JOIN users u ON u.id = m.user_id
                        LEFT JOIN account_deletion_requests r ON r.message_id = m.id
                        WHERE m.subject = ? AND m.sender = 'customer'
                        ORDER BY m.id DESC LIMIT 200");
    $s->execute(array(deletion_subject()));
    $out = array();
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $done = !empty($r['status']);
        $out[] = array(
            'message_id' => intval($r['message_id']), 'user_id' => intval($r['user_id']),
            'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'role' => (string) $r['role'],
            'text' => (string) $r['body'], 'asked_at' => (string) $r['created_at'],
            'status' => $done ? (string) $r['status'] : 'open', 'note' => (string) $r['note'], 'handled_at' => (string) $r['handled_at'],
            'is_admin' => ((string) $r['role'] === 'admin') ? 1 : 0,
        );
    }
    return $out;
}

/* '' when done, else the reason. $decision: 'delete' or 'reject'. */
function deletion_decide($pdo, $adminId, $messageId, $decision, $note) {
    $messageId = intval($messageId);
    if (!in_array($decision, array('delete', 'reject'), true)) return 'Unknown decision.';
    $note = function_exists('mb_substr') ? mb_substr(trim(strip_tags((string) $note)), 0, 200, 'UTF-8') : substr(trim(strip_tags((string) $note)), 0, 200);
    $s = $pdo->prepare("SELECT id, user_id FROM customer_messages WHERE id = ? AND subject = ? AND sender = 'customer'");
    $s->execute(array($messageId, deletion_subject()));
    $m = $s->fetch(PDO::FETCH_ASSOC);
    if (!$m) return 'That request was not found.';
    $d = $pdo->prepare('SELECT message_id FROM account_deletion_requests WHERE message_id = ?');
    $d->execute(array($messageId));
    if ($d->fetchColumn()) return 'That request was already handled.';
    $uid = intval($m['user_id']);
    if ($decision === 'delete') {
        $u = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $u->execute(array($uid));
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) return 'The account no longer exists.';
        if ((string) $user['role'] === 'admin') return 'Admin and staff accounts are not deleted here. Remove the staff access first.';
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE users SET name = 'Deleted user', phone = CONCAT('deleted-', id), email = NULL, password_hash = '!', active = 0 WHERE id = ?")->execute(array($uid));
            // Personal lists. A table that does not exist on this server is skipped.
            foreach (array('push_subscriptions', 'customer_addresses', 'customer_favorites', 'ride_favorite_routes') as $t) {
                try { $pdo->prepare('DELETE FROM `' . $t . '` WHERE user_id = ?')->execute(array($uid)); } catch (Exception $e) { /* not on this server */ }
            }
            $pdo->prepare('INSERT INTO account_deletion_requests (message_id, user_id, status, note, handled_by, handled_at) VALUES (?, ?, ?, ?, ?, NOW())')
                ->execute(array($messageId, $uid, 'done', $note, intval($adminId)));
            $pdo->commit();
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return '';
    }
    $pdo->prepare('INSERT INTO account_deletion_requests (message_id, user_id, status, note, handled_by, handled_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute(array($messageId, $uid, 'rejected', $note, intval($adminId)));
    return '';
}

}
