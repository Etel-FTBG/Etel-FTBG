<?php
/* Pre-registered businesses ("drafts") and the claim link that hands them over.
 *
 * Admin pre-registers a business (status "pending", so it cannot sign in and customers never see it), then later sends the
 * owner a one-time claim code. The owner opens claim.html, types their own phone number and password, and the account is
 * theirs. The account stays "pending" until admin presses Verify, so a claim alone never makes anything live.
 *
 * Codes are stored only as a SHA-256 hash and are shown to admin once, right after they are made.
 * The table is also created by migration 096_sg_claim_codes.sql; etel_sg_claim_ensure() makes it on first use.
 */

function etel_sg_claim_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sg_claim_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sg_id INT NOT NULL,
        user_id INT NOT NULL,
        code_hash CHAR(64) NOT NULL,
        phone_is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sg_claim_hash (code_hash),
        KEY idx_sg_claim_sg (sg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* Letters and digits without 0/O/1/I/L so a code read aloud or typed from a photo is not misread. */
function etel_sg_claim_new_code() {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    return substr($s, 0, 5) . '-' . substr($s, 5);
}

function etel_sg_claim_hash($code) {
    return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code)));
}

/* A new invite for one business; any unused earlier invite stops working. Returns the plain code and its expiry. */
function etel_sg_claim_issue($pdo, $sg_id, $user_id, $placeholder, $days = 14) {
    etel_sg_claim_ensure($pdo);
    $pdo->prepare('DELETE FROM sg_claim_codes WHERE sg_id = ? AND used_at IS NULL')->execute(array($sg_id));
    $code = etel_sg_claim_new_code();
    $exp = date('Y-m-d H:i:s', time() + $days * 86400);
    $pdo->prepare('INSERT INTO sg_claim_codes (sg_id, user_id, code_hash, phone_is_placeholder, expires_at) VALUES (?,?,?,?,?)')
        ->execute(array($sg_id, $user_id, etel_sg_claim_hash($code), $placeholder ? 1 : 0, $exp));
    return array('code' => $code, 'expires_at' => $exp);
}

/* The invite row for a code that is still usable, with the business it opens; null when unknown, used or expired. */
function etel_sg_claim_find($pdo, $code) {
    etel_sg_claim_ensure($pdo);
    $s = $pdo->prepare('SELECT c.id AS claim_id, c.sg_id, c.user_id, c.expires_at, c.phone_is_placeholder, sg.business_name, sg.service_type, sg.zone, sg.status, u.name AS owner_name
        FROM sg_claim_codes c JOIN service_givers sg ON sg.id = c.sg_id JOIN users u ON u.id = c.user_id
        WHERE c.code_hash = ? AND c.used_at IS NULL AND c.expires_at > NOW() LIMIT 1');
    $s->execute(array(etel_sg_claim_hash($code)));
    $r = $s->fetch();
    return $r ? $r : null;
}

/* What the admin screen needs to know about one business's hand-over: is there a live invite, was one used, is the phone a stand-in. */
function etel_sg_claim_state($pdo, $sg_id) {
    etel_sg_claim_ensure($pdo);
    $s = $pdo->prepare('SELECT expires_at, used_at, phone_is_placeholder FROM sg_claim_codes WHERE sg_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute(array($sg_id));
    $r = $s->fetch();
    if (!$r) return array('invited' => false, 'claimed' => false, 'placeholder_phone' => false, 'expires_at' => null);
    return array(
        'invited' => $r['used_at'] === null && strtotime($r['expires_at']) > time(),
        'claimed' => $r['used_at'] !== null,
        'placeholder_phone' => intval($r['phone_is_placeholder']) === 1,
        'expires_at' => $r['expires_at'],
    );
}

/* A phone number nobody has, so a pre-registered business can exist before its owner's real number is known. */
function etel_sg_placeholder_phone($pdo) {
    for ($i = 0; $i < 30; $i++) {
        $p = '09' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        if (!etel_phone_taken($pdo, $p)) return $p;
    }
    return '';
}
