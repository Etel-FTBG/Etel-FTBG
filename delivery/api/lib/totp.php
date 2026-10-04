<?php
/* Two-step sign-in for admin accounts (TOTP, RFC 6238).

   Opt-in per admin: an admin who has not switched it on signs in exactly as
   before. Once on, a password alone is not enough - the 6-digit code from an
   authenticator app (Google Authenticator, Authy, Aegis, Microsoft
   Authenticator...) or one of 8 single-use backup codes is also required.

   Safety rails so this can never lock the owner out:
   - it is opt-in, never forced;
   - backup codes are shown once at set-up and can be regenerated;
   - as a last resort the row can be switched off in phpMyAdmin:
       UPDATE admin_2fa SET enabled = 0 WHERE user_id = <id>;
   PHP 5-style, ASCII only, like the rest of the API. */

if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

if (!function_exists('etel_totp_code')) {

function etel_b32_encode($bin) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function etel_b32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(strpos($alphabet, $b32[$i])), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

/* 6-digit code for one 30 s time step. */
function etel_totp_code($secretBin, $step) {
    $counter = pack('N*', 0, $step); // 64-bit big-endian counter
    $hash = hash_hmac('sha1', $counter, $secretBin, true);
    $offset = ord($hash[19]) & 0x0F;
    $bin = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16)
         | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
}

/* Returns the matching time step (so the caller can refuse replays) or false.
   Allows one step of clock drift either way. */
function etel_totp_match($secretB32, $code, $now = null) {
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6) return false;
    $secret = etel_b32_decode($secretB32);
    if ($secret === '') return false;
    $step = (int) floor(($now === null ? time() : $now) / 30);
    for ($d = -1; $d <= 1; $d++) {
        if (hash_equals(etel_totp_code($secret, $step + $d), $code)) return $step + $d;
    }
    return false;
}

function etel_2fa_new_secret() {
    return etel_b32_encode(random_bytes(20)); // 160 bits
}

function etel_2fa_key() {
    return hash('sha256', 'etel-2fa|' . (defined('TOKEN_SECRET') ? TOKEN_SECRET : 'unset'), true);
}

/* The secret is encrypted at rest so a stolen database dump or backup does not
   hand over everyone's authenticator. */
function etel_2fa_encrypt($plain) {
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', etel_2fa_key(), OPENSSL_RAW_DATA, $iv);
    return 'v1:' . base64_encode($iv . $ct);
}

function etel_2fa_decrypt($stored) {
    if (strpos($stored, 'v1:') !== 0) return '';
    $raw = base64_decode(substr($stored, 3));
    if ($raw === false || strlen($raw) < 17) return '';
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', etel_2fa_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function etel_2fa_ensure_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_2fa` (
        `user_id`      INT UNSIGNED NOT NULL PRIMARY KEY,
        `secret_enc`   TEXT NOT NULL,
        `enabled`      TINYINT(1) NOT NULL DEFAULT 0,
        `backup_codes` TEXT NULL,
        `last_step`    BIGINT NOT NULL DEFAULT 0,
        `created_at`   DATETIME NULL,
        `enabled_at`   DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function etel_2fa_row($pdo, $userId) {
    try {
        etel_2fa_ensure_table($pdo);
        $s = $pdo->prepare('SELECT * FROM admin_2fa WHERE user_id=?');
        $s->execute(array(intval($userId)));
        $r = $s->fetch();
        return $r ? $r : null;
    } catch (Exception $e) { return null; }
}

function etel_2fa_is_enabled($pdo, $userId) {
    $r = etel_2fa_row($pdo, $userId);
    return $r && intval($r['enabled']) === 1;
}

/* 8 single-use codes, shown to the admin once. Only hashes are stored. */
function etel_2fa_new_backup_codes() {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
    $out = array();
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $out[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return $out;
}

function etel_2fa_hash_backup($code) {
    return hash('sha256', 'etel-bk|' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
}

/* Failure counter per ACCOUNT (not per IP): someone holding a stolen password
   can rotate IPs, but still only gets 5 guesses at the code per 10 minutes. */
function etel_2fa_failures($pdo, $userId) {
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $s->execute(array('totp_user:' . intval($userId)));
        return intval($s->fetchColumn());
    } catch (Exception $e) { return 0; }
}
function etel_2fa_record_failure($pdo, $userId) {
    try {
        $pdo->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())')->execute(array('totp_user:' . intval($userId)));
    } catch (Exception $e) { error_log('[ETEL] 2fa failure log: ' . $e->getMessage()); }
}
function etel_2fa_clear_failures($pdo, $userId) {
    try { $pdo->prepare('DELETE FROM login_attempts WHERE identifier=?')->execute(array('totp_user:' . intval($userId))); } catch (Exception $e) {}
}

/* Checks a TOTP code or a backup code for an enabled account. A used time step
   is remembered so the same code cannot be replayed. Returns true/false. */
function etel_2fa_check_code($pdo, $userId, $code) {
    $row = etel_2fa_row($pdo, $userId);
    if (!$row || intval($row['enabled']) !== 1) return false;
    $code = trim((string) $code);
    if ($code === '') return false;
    $secret = etel_2fa_decrypt($row['secret_enc']);
    if ($secret === '') return false;
    // Authenticator code (6 digits)
    if (preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code)) {
        $step = etel_totp_match($secret, $code);
        if ($step !== false && $step > intval($row['last_step'])) {
            $pdo->prepare('UPDATE admin_2fa SET last_step=? WHERE user_id=? AND last_step < ?')->execute(array($step, intval($userId), $step));
            return true;
        }
        return false;
    }
    // Backup code
    $codes = json_decode((string) $row['backup_codes'], true);
    if (!is_array($codes)) return false;
    $h = etel_2fa_hash_backup($code);
    foreach ($codes as $i => $stored) {
        if (hash_equals($stored, $h)) {
            unset($codes[$i]);
            $pdo->prepare('UPDATE admin_2fa SET backup_codes=? WHERE user_id=?')->execute(array(json_encode(array_values($codes)), intval($userId)));
            return true;
        }
    }
    return false;
}

/* Sends a JSON answer and stops. A function of its own so tests can stand in for it. */
if (!function_exists('etel_2fa_respond')) {
    function etel_2fa_respond($payload, $httpCode) {
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
}

/* The sign-in gate. Call it after the password (or EtelID) is verified and
   before a token is issued. Returns normally when the account has no 2FA or the
   code is right; otherwise it answers the request and exits. */
function etel_2fa_gate($pdo, $user, $body) {
    if (!$user || !isset($user['role']) || $user['role'] !== 'admin') return;
    if (!etel_2fa_is_enabled($pdo, $user['id'])) return;
    $code = isset($body['totp_code']) ? trim((string) $body['totp_code']) : '';
    if ($code === '') {
        etel_2fa_respond(array('success' => false, 'requires_2fa' => true,
            'message' => 'Enter the 6-digit code from your authenticator app (or a backup code).', 'v' => APP_VER), 401);
    }
    if (etel_2fa_failures($pdo, $user['id']) >= 5) {
        fail('Too many wrong codes. Try again in 10 minutes.', 429);
    }
    if (!etel_2fa_check_code($pdo, $user['id'], $code)) {
        etel_2fa_record_failure($pdo, $user['id']);
        etel_2fa_respond(array('success' => false, 'requires_2fa' => true,
            'message' => 'That code is not right. Check the time on your phone and try again.', 'v' => APP_VER), 401);
    }
    etel_2fa_clear_failures($pdo, $user['id']);
}

} // end function_exists guard
