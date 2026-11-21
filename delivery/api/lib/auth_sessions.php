<?php
/**
 * Signed-in devices. Tokens stay stateless (boot.php gen_token / verify_token); this file only adds a small table that remembers which
 * tokens the server has seen, so a person can see "where am I signed in" and sign one device out.
 *   - The fingerprint is sha256 of the token's four fixed parts (uid:ts:version:signature), so a changed spelling of the same token has
 *     the same fingerprint and the token itself is never stored.
 *   - A token is registered the first time it is used (so phones that are already signed in appear without signing in again).
 *   - A revoked fingerprint is refused by get_auth_user(); the person keeps using the other devices.
 * Everything here fails open: if the table cannot be read or written, nobody is signed out and nothing breaks.
 */

/** array(fingerprint, issued-at timestamp) of a token, or null when it is not a well-formed 4-part token. */
function etel_sess_fingerprint($token) {
    $d = @base64_decode((string) $token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) !== 4) return null;
    return array(hash('sha256', $p[0] . ':' . $p[1] . ':' . $p[2] . ':' . $p[3]), intval($p[1]));
}

/** A short readable name for a device from its User-Agent, for example "Chrome on Windows" or "ETEL phone app (Android)". */
function etel_sess_label($ua) {
    $ua = (string) $ua;
    if ($ua === '') return 'Unknown device';
    $os = '';
    if (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) $os = 'iPhone';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    if (stripos($ua, 'okhttp') !== false || stripos($ua, 'Expo') !== false || stripos($ua, 'CFNetwork') !== false || stripos($ua, 'Darwin') !== false) {
        return 'ETEL phone app' . ($os !== '' ? ' (' . $os . ')' : (stripos($ua, 'okhttp') !== false ? ' (Android)' : ''));
    }
    $br = '';
    if (stripos($ua, 'Edg/') !== false) $br = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $br = 'Opera';
    elseif (stripos($ua, 'Firefox') !== false) $br = 'Firefox';
    elseif (stripos($ua, 'Chrome') !== false || stripos($ua, 'CriOS') !== false) $br = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false) $br = 'Safari';
    if ($br === '' && $os === '') return 'Unknown device';
    return ($br !== '' ? $br : 'Browser') . ($os !== '' ? ' on ' . $os : '');
}

function etel_sess_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return substr($ip, 0, 45);
}

/** Creates the table the first time it is needed (the migration file does the same). */
function etel_sess_create_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `auth_sessions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT, `user_id` INT(11) NOT NULL, `token_hash` CHAR(64) NOT NULL,
        `label` VARCHAR(80) NOT NULL DEFAULT '', `ip` VARCHAR(45) NOT NULL DEFAULT '',
        `created_at` DATETIME DEFAULT NULL, `last_seen` DATETIME DEFAULT NULL, `revoked_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_token_hash` (`token_hash`), KEY `idx_user` (`user_id`, `revoked_at`, `last_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Called for every authenticated request. Registers the token on first sight, keeps last_seen fresh (at most every 5 minutes) and
 * returns false when this device was signed out. True otherwise, including on any error.
 */
function etel_sess_touch($pdo, $uid, $token) {
    $fp = etel_sess_fingerprint($token);
    if ($fp === null) return true;
    for ($try = 0; $try < 2; $try++) {
        try {
            $q = $pdo->prepare('SELECT id, user_id, revoked_at, last_seen FROM auth_sessions WHERE token_hash = ?');
            $q->execute(array($fp[0]));
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
                $made = ($fp[1] > 0 && $fp[1] <= time()) ? date('Y-m-d H:i:s', $fp[1]) : date('Y-m-d H:i:s');
                $pdo->prepare('INSERT IGNORE INTO auth_sessions (user_id, token_hash, label, ip, created_at, last_seen) VALUES (?,?,?,?,?,NOW())')
                    ->execute(array(intval($uid), $fp[0], etel_sess_label($ua), etel_sess_ip(), $made));
                return true;
            }
            if (!empty($r['revoked_at'])) return false;
            if (!empty($r['last_seen']) && strtotime($r['last_seen']) < time() - 300) {
                $pdo->prepare('UPDATE auth_sessions SET last_seen = NOW(), ip = ? WHERE id = ?')->execute(array(etel_sess_ip(), intval($r['id'])));
            }
            return true;
        } catch (PDOException $e) {
            if ($try === 0 && ($e->getCode() === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false)) {
                try { etel_sess_create_table($pdo); } catch (Exception $e2) { return true; }
                continue;
            }
            return true;
        } catch (Exception $e) {
            return true;
        }
    }
    return true;
}

/** The person's devices that are still signed in, newest activity first. $currentToken marks the one making the request. */
function etel_sess_list($pdo, $uid, $currentToken) {
    $cur = etel_sess_fingerprint($currentToken);
    $out = array();
    try {
        $pdo->exec("DELETE FROM auth_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 8 DAY)");
        $q = $pdo->prepare('SELECT id, token_hash, label, ip, created_at, last_seen FROM auth_sessions
                            WHERE user_id = ? AND revoked_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 8 DAY)
                            ORDER BY last_seen DESC, id DESC LIMIT 50');
        $q->execute(array(intval($uid)));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = array('id' => intval($r['id']), 'label' => $r['label'], 'ip' => etel_sess_mask_ip($r['ip']), 'signed_in_at' => $r['created_at'],
                           'last_seen' => $r['last_seen'], 'current' => ($cur !== null && $r['token_hash'] === $cur[0]));
        }
    } catch (Exception $e) { /* table missing: no list */ }
    return $out;
}

/** 196.188.12.34 -> 196.188.x.x (enough to recognise a network, not enough to locate a person). */
function etel_sess_mask_ip($ip) {
    if (strpos($ip, '.') !== false) { $p = explode('.', $ip); return count($p) === 4 ? $p[0] . '.' . $p[1] . '.x.x' : ''; }
    if (strpos($ip, ':') !== false) { $p = explode(':', $ip); return $p[0] . ':' . (isset($p[1]) ? $p[1] : '') . ':x'; }
    return '';
}

/** Signs one of the person's own devices out. Returns true when a row was changed. */
function etel_sess_revoke($pdo, $uid, $sessionId) {
    $s = $pdo->prepare('UPDATE auth_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
    $s->execute(array(intval($sessionId), intval($uid)));
    return $s->rowCount() > 0;
}

/** Signs every device out except the one making the request. Returns how many were signed out. */
function etel_sess_revoke_others($pdo, $uid, $currentToken) {
    $cur = etel_sess_fingerprint($currentToken);
    if ($cur === null) return 0;
    $s = $pdo->prepare('UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND token_hash <> ?');
    $s->execute(array(intval($uid), $cur[0]));
    return $s->rowCount();
}
