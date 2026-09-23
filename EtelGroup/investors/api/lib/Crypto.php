<?php
// ============================================================
// Investor Relations — small reversible-encryption helper
// Only used for the read-only Delivery & Ride DB password (needs to be
// decrypted server-side to open the connection - a one-way hash won't
// do). Keyed by INV_CRYPTO_KEY in EtelGroup/.env, so the ciphertext in
// ir_settings is useless without that file - never plaintext at rest.
// ============================================================
declare(strict_types=1);

function ir_crypto_key(): string {
    $k = getenv('INV_CRYPTO_KEY') ?: '';
    if ($k === '') {
        throw new Exception('INV_CRYPTO_KEY is not configured in EtelGroup/.env');
    }
    return hash('sha256', $k, true); // 32 raw bytes for AES-256
}

function ir_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', ir_crypto_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) throw new Exception('Encryption failed');
    return base64_encode($iv . $cipher);
}

function ir_decrypt(string $encoded): string {
    if ($encoded === '') return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', ir_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
