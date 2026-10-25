<?php
/* Helpers for actions a courier did while offline and the app sends later.
   etel_offline_seen():  true when this request id was already applied (the app may resend after a dropped connection).
   etel_offline_when():  the moment the courier really did it, from the app's `occurred_at` (ISO time), or null to use NOW().
   The time is only trusted when it is plausible: not in the future (2 min allowance), at most 24 h old, and not before $notBefore. */

function etel_offline_seen($pdo, $userId, $reqId) {
    $reqId = is_string($reqId) ? trim($reqId) : '';
    if ($reqId === '' || strlen($reqId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $reqId)) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS offline_requests (
            user_id INT NOT NULL, req_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, req_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare('DELETE FROM offline_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)')->execute();
        $s = $pdo->prepare('INSERT IGNORE INTO offline_requests (user_id, req_id, created_at) VALUES (?,?,NOW())');
        $s->execute(array($userId, $reqId));
        return $s->rowCount() === 0;
    } catch (Exception $e) { return false; }
}

/* The action failed after etel_offline_seen() recorded it: forget it so the resend is tried again instead of being treated as done. */
function etel_offline_forget($pdo, $userId, $reqId) {
    try { $pdo->prepare('DELETE FROM offline_requests WHERE user_id=? AND req_id=?')->execute(array($userId, is_string($reqId) ? trim($reqId) : '')); } catch (Exception $e) {}
}

function etel_offline_when($occurredAt, $notBefore = null, $maxAge = 86400) {
    if (!is_string($occurredAt) || $occurredAt === '') return null;
    $t = strtotime($occurredAt);
    if ($t === false) return null;
    $now = time();
    if ($t > $now + 120 || $t < $now - $maxAge) return null;
    if ($notBefore) { $nb = strtotime($notBefore); if ($nb !== false && $t < $nb) return null; }
    return date('Y-m-d H:i:s', min($t, $now));
}
