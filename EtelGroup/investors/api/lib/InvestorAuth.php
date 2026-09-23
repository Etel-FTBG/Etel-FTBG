<?php
// ============================================================
// Investor Relations — investor-side auth
// Admin access reuses EtelGroup's OWN existing admin auth as-is
// (eg_require_admin()/eg_current_actor() from EtelGroup/api/auth.php,
// already required by boot.php) - the admin login here IS the same
// EtelGroup admin/owner login, not a second admin system to maintain.
//
// Investors are a different population entirely (external people, not
// EtelGroup staff) so they get their OWN stateless bearer-token scheme,
// same HMAC pattern as EtelGroup's admin tokens but signed with a
// separate secret (INV_TOKEN_SECRET) and carrying an investor_id
// instead of an EtelID identity.
// ============================================================
declare(strict_types=1);

define('IR_TOKEN_TTL', 60 * 60 * 12); // 12 hours, same as EtelGroup admin

function ir_token_secret(): string {
    $s = getenv('INV_TOKEN_SECRET') ?: '';
    if ($s === '') throw new Exception('INV_TOKEN_SECRET is not configured in EtelGroup/.env');
    return $s;
}

function ir_issue_investor_token(int $investorId, string $name): string {
    $expires = time() + IR_TOKEN_TTL;
    $payload = 'investor|' . $investorId . '|' . urlencode($name) . '|' . $expires;
    $sig = hash_hmac('sha256', $payload, ir_token_secret());
    return base64_encode($payload . '|' . $sig);
}

function ir_decode_investor_token(?string $token): ?array {
    if (!$token) return null;
    $decoded = base64_decode($token, true);
    if ($decoded === false) return null;
    $parts = explode('|', $decoded);
    if (count($parts) !== 5 || $parts[0] !== 'investor') return null;
    [$who, $id, $nameEnc, $expires, $sig] = $parts;
    $payload = $who . '|' . $id . '|' . $nameEnc . '|' . $expires;
    $expected = hash_hmac('sha256', $payload, ir_token_secret());
    if (!hash_equals($expected, $sig)) return null;
    if ((int)$expires < time()) return null;
    return ['investor_id' => (int)$id, 'name' => urldecode($nameEnc), 'expires' => (int)$expires];
}

function ir_bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
    if (!empty($_GET['token'])) return (string)$_GET['token'];
    return null;
}

// Returns the authenticated investor's id, or calls fail()/exit()
// (same convention as eg_require_admin()) if the token is missing/invalid.
function ir_require_investor(): array {
    $decoded = ir_decode_investor_token(ir_bearer_token());
    if ($decoded === null) {
        fail('Not authenticated - please sign in again', 401);
    }
    return $decoded;
}
