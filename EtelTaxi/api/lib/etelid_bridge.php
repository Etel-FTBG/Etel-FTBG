<?php
// ============================================================
// EtelTaxi — EtelID employee-role bridge
// Calls EtelID's /service/check_role endpoint (added alongside the
// EtelGroup Employees panel) so a phone number's Etel-Group-assigned
// role at this company can grant access here too, without EtelTaxi
// having to manage a second copy of "who works here."
//
// This company's canonical slug in EtelID is 'etel-transport' - the
// same slug used everywhere else on ethelmarket.com/EtelGroup for this
// product (nav, footer, launcher grid).
// ============================================================

if (!defined('ETELTAXI_COMPANY_SLUG')) define('ETELTAXI_COMPANY_SLUG', 'etel-transport');

/**
 * Returns the member's role_at string if this phone has an active
 * EtelGroup-assigned role at ETELTAXI_COMPANY_SLUG, or null if not
 * (including if EtelID is unreachable - fails closed, never silently
 * grants access on a network hiccup).
 */
function taxi_egid_check_role(string $phone): ?array {
    if (!defined('ETELID_SERVICE_KEY') || ETELID_SERVICE_KEY === '') return null;
    $url = 'https://' . ETELID_HOST . '/EtelId/wp-json/etelid/v1/service/check_role';
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . ETELID_SERVICE_KEY . "\r\n",
        'content' => json_encode(array('company_slug' => ETELTAXI_COMPANY_SLUG, 'phone' => $phone)),
        'timeout' => 10,
        'ignore_errors' => true,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['ok'])) return null;
    return $decoded; // { ok, phone, full_name, etel_id, role_at }
}

/** A random, never-guessable placeholder for pin_hash on accounts that
 *  only ever sign in via EtelID - satisfies the NOT NULL column
 *  without creating a usable password-based login path. */
function taxi_unusable_pin_hash(): string {
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
}
