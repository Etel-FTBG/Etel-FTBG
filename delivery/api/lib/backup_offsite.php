<?php
/**
 * Off-site copy of the nightly database backup.
 *
 * The daily backup (cron_backup_db.php) stays on the same server as the database. If that server is lost, so are
 * the backups. This sends each new backup to any S3-compatible bucket (Backblaze B2, Cloudflare R2, Wasabi,
 * Amazon S3, ...), encrypted first, because the dump holds phone numbers and password hashes.
 *
 * Switched on by settings in api/.env (nothing happens while BACKUP_S3_BUCKET is empty):
 *   BACKUP_S3_ENDPOINT   host only, e.g. s3.us-west-004.backblazeb2.com
 *   BACKUP_S3_REGION     e.g. us-west-004
 *   BACKUP_S3_BUCKET     bucket name (keep it PRIVATE)
 *   BACKUP_S3_KEY / BACKUP_S3_SECRET   access key id and secret
 *   BACKUP_S3_PREFIX     optional folder, default "etel/"
 *   BACKUP_ENC_PASSPHRASE  required: the copy is encrypted with it. KEEP IT SAFE: without it the copy is useless.
 *
 * Everything here is plain PHP (curl + openssl), no libraries. A failure never breaks the local backup.
 * Restore: php api/decrypt_backup_cli.php <file.enc> <out.sql.gz> <passphrase>   then import the .sql.gz as usual.
 */
if (!function_exists('etel_sigv4_sign')) {

/**
 * AWS Signature Version 4 for one request. $headers: lowercase name => value, must include host and x-amz-date
 * (and x-amz-content-sha256 for S3). Returns array(authorization, signed_headers, signature).
 */
function etel_sigv4_sign($method, $canonicalUri, $query, $headers, $payloadHash, $region, $service, $accessKey, $secret) {
    ksort($headers);
    $canonHeaders = ''; $names = array();
    foreach ($headers as $k => $v) { $canonHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', $v)) . "\n"; $names[] = $k; }
    $signed = implode(';', $names);
    $canonical = $method . "\n" . $canonicalUri . "\n" . $query . "\n" . $canonHeaders . "\n" . $signed . "\n" . $payloadHash;
    $amzDate = $headers['x-amz-date'];
    $day = substr($amzDate, 0, 8);
    $scope = $day . '/' . $region . '/' . $service . '/aws4_request';
    $toSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);
    $k = hash_hmac('sha256', $day, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    $sig = hash_hmac('sha256', $toSign, $k);
    return array(
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig,
        'signed_headers' => $signed, 'signature' => $sig);
}

/** Uploads a file with PUT. Returns array(ok, message). Path-style URL: https://endpoint/bucket/key */
function etel_s3_put_file($endpoint, $region, $bucket, $accessKey, $secret, $objectKey, $file) {
    if (!function_exists('curl_init')) return array(false, 'curl is not available');
    $size = @filesize($file);
    if ($size === false || $size < 1) return array(false, 'file is empty or unreadable');
    $payloadHash = hash_file('sha256', $file);
    // https always; plain http only to this machine, so the upload code can be tested against a local stand-in
    $scheme = 'https'; $host = $endpoint;
    if (preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $endpoint)) { $scheme = 'http'; $host = substr($endpoint, 7); }
    $uri = '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
    $amz = gmdate('Ymd\THis\Z');
    $headers = array('host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amz);
    $s = etel_sigv4_sign('PUT', $uri, '', $headers, $payloadHash, $region, 's3', $accessKey, $secret);
    $fh = fopen($file, 'rb');
    $ch = curl_init($scheme . '://' . $host . $uri);
    curl_setopt_array($ch, array(
        CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => array('Authorization: ' . $s['authorization'], 'x-amz-content-sha256: ' . $payloadHash,
                                    'x-amz-date: ' . $amz, 'Expect:'),
    ));
    $body = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $err = curl_error($ch);
    curl_close($ch); fclose($fh);
    if ($code >= 200 && $code < 300) return array(true, 'uploaded ' . $size . ' bytes');
    return array(false, 'HTTP ' . $code . ($err ? ' ' . $err : '') . ' ' . substr(trim(strip_tags((string) $body)), 0, 120));
}

/* Format: "ETELBK1" | salt(16) | iv(12) | tag(16) | AES-256-GCM ciphertext. Key = PBKDF2-SHA256(passphrase, salt, 200000). */
function etel_backup_encrypt_file($in, $out, $passphrase) {
    if ($passphrase === '' || !function_exists('openssl_encrypt')) return false;
    $size = @filesize($in);
    if ($size === false || $size > 300 * 1048576) return false;   // held in memory: refuse something unexpectedly huge
    $plain = file_get_contents($in);
    if ($plain === false) return false;
    $salt = random_bytes(16); $iv = random_bytes(12);
    $key = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 32, true);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) return false;
    return file_put_contents($out, 'ETELBK1' . $salt . $iv . $tag . $ct) !== false;
}

function etel_backup_decrypt_file($in, $out, $passphrase) {
    $raw = @file_get_contents($in);
    if ($raw === false || strlen($raw) < 7 + 16 + 12 + 16 || substr($raw, 0, 7) !== 'ETELBK1') return false;
    $salt = substr($raw, 7, 16); $iv = substr($raw, 23, 12); $tag = substr($raw, 35, 16); $ct = substr($raw, 51);
    $key = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 32, true);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) return false;   // wrong passphrase or the file was changed
    return file_put_contents($out, $plain) !== false;
}

/**
 * Encrypts and uploads one backup file when the settings are present.
 * $cfg: array of the BACKUP_* values. Returns array(status, message): status 'off' | 'ok' | 'failed'.
 */
function etel_backup_push_offsite($cfg, $file) {
    foreach (array('endpoint' => 'BACKUP_S3_ENDPOINT', 'bucket' => 'BACKUP_S3_BUCKET', 'key' => 'BACKUP_S3_KEY', 'secret' => 'BACKUP_S3_SECRET') as $k => $name) {
        if (empty($cfg[$k])) return array('off', $name . ' is not set');
    }
    if (empty($cfg['passphrase'])) return array('failed', 'BACKUP_ENC_PASSPHRASE is not set: the copy must be encrypted');
    $enc = $file . '.enc';
    if (!etel_backup_encrypt_file($file, $enc, $cfg['passphrase'])) { @unlink($enc); return array('failed', 'could not encrypt the backup'); }
    $prefix = isset($cfg['prefix']) && $cfg['prefix'] !== '' ? rtrim($cfg['prefix'], '/') . '/' : 'etel/';
    $region = !empty($cfg['region']) ? $cfg['region'] : 'us-east-1';
    list($ok, $msg) = etel_s3_put_file($cfg['endpoint'], $region, $cfg['bucket'], $cfg['key'], $cfg['secret'], $prefix . basename($file) . '.enc', $enc);
    @unlink($enc);
    return array($ok ? 'ok' : 'failed', $msg);
}

}
