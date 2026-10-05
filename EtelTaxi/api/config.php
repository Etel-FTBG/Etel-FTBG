<?php
// ============================================================
// ETEL TAXI - Config  v1.0
// Same pattern as Delivery/EtelAI: real values come from api/.env or
// server environment variables, never hardcoded here. Deliberately no
// guessable defaults for anything security-sensitive.
// ============================================================

// Rejects the .env.example template's own literal placeholder value
// ("CHANGE_ME") so a server whose .env was copied from the template but
// never actually edited never treats that placeholder as a real
// configured secret - same class of bug found (and fixed) in EtelPay's
// EP_SECRET/updater password and Etel Madeya's etelid_bridge.php this
// session. Case-insensitive and tolerant of "change-me"/"change me" too.
function etel_taxi_is_placeholder($v) {
    $norm = strtolower(str_replace(array('-', ' '), '_', trim((string) $v)));
    return $norm === 'change_me' || $norm === 'changeme';
}
function etel_taxi_env($envName, $fallback = '') {
    $v = getenv($envName);
    if ($v === false || $v === '' || etel_taxi_is_placeholder($v)) return $fallback;
    return $v;
}

// Load api/.env if present (same simple parser convention as
// Delivery's own setup - KEY=VALUE lines, # comments, no quoting).
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        if (getenv(trim($k)) === false) putenv(trim($k) . '=' . trim($v));
    }
}

if (!defined('DB_HOST'))   define('DB_HOST',   etel_taxi_env('DB_HOST', 'localhost'));
if (!defined('DB_NAME'))   define('DB_NAME',   etel_taxi_env('DB_NAME', ''));
if (!defined('DB_USER'))   define('DB_USER',   etel_taxi_env('DB_USER', ''));
if (!defined('DB_PASS'))   define('DB_PASS',   etel_taxi_env('DB_PASS', ''));
if (!defined('DB_PREFIX')) define('DB_PREFIX', etel_taxi_env('DB_PREFIX', ''));

// Shared cross-app SSO - same Etelid_sessions/Etelid_members tables
// every other Etel app reads, so a driver already logged into
// Delivery/Ride/EtelAI is recognized here automatically too. This
// requires EtelTaxi's database user to have SELECT access to those
// tables (same DB or a cross-DB grant), same as EtelAI's setup.
if (!defined('ETELID_DB_NAME'))   define('ETELID_DB_NAME',   etel_taxi_env('ETELID_DB_NAME', 'etelid'));
if (!defined('ETELID_DB_PREFIX')) define('ETELID_DB_PREFIX', etel_taxi_env('ETELID_DB_PREFIX', 'Etelid_'));

// EtelID Service API - lets a driver sign in with their real EtelID
// (same "one identity across every Etel company" login every other
// subsidiary offers), rather than only a separate phone+PIN account.
// ETELID_SERVICE_KEY must match the same shared secret already
// configured on EtelID's own plugin (SETUP-SSO-KEY.txt) and reused for
// Delivery<->EtelID and EtelAI<->Delivery calls - no hardcoded default,
// same discipline as every other shared secret in Etel Group.
if (!defined('ETELID_HOST'))         define('ETELID_HOST',         etel_taxi_env('ETELID_HOST', 'ethelmarket.com'));
if (!defined('ETELID_SERVICE_KEY'))  define('ETELID_SERVICE_KEY',  etel_taxi_env('ETELID_SERVICE_KEY', ''));

// Admin key for one-time maintenance endpoints - no default, same
// no-guessable-secret discipline as everywhere else in Etel.
if (!defined('TAXI_ADMIN_KEY')) define('TAXI_ADMIN_KEY', etel_taxi_env('TAXI_ADMIN_KEY', ''));

// Delivery -> EtelTaxi service bridge (ServiceController.php) - lets
// Delivery's NFC "driver details for traffic officer" check pull a
// driver's live association-dues status instead of a manually-toggled
// field. Different secret from ETELID_SERVICE_KEY - it protects a
// different pairing. Must match Delivery's own api/.env value exactly.
if (!defined('ETELTAXI_SERVICE_KEY')) define('ETELTAXI_SERVICE_KEY', etel_taxi_env('ETELTAXI_SERVICE_KEY', ''));

// Signs the daily verify-code shown as a QR code in the driver's app,
// so a traffic officer can check permit status by scanning it without
// a fully public "search any plate" lookup existing anywhere. Kept
// separate from TAXI_ADMIN_KEY since they protect different things.
// No hardcoded default - if unset, verify-code generation fails loudly
// rather than signing with a guessable fallback.
if (!defined('TAXI_QR_SIGNING_KEY')) define('TAXI_QR_SIGNING_KEY', etel_taxi_env('TAXI_QR_SIGNING_KEY', ''));

// Chapa (https://developer.chapa.co) - online dues payment. Optional:
// the "Chapa" method just reports "not configured" until the key is set.
if (!defined('CHAPA_SECRET_KEY')) define('CHAPA_SECRET_KEY', etel_taxi_env('CHAPA_SECRET_KEY', ''));
if (!defined('TAXI_BASE_URL'))    define('TAXI_BASE_URL',    rtrim(etel_taxi_env('TAXI_BASE_URL', 'https://ethelmarket.com/EtelTaxi'), '/'));

// Push notifications - standard Web Push (RFC 8291/8292), same VAPID
// identity Delivery & Ride already uses. Optional: reminders just won't
// send until these are set, same "no working default" rule as every
// other external credential in Etel.
if (!defined('VAPID_PUBLIC_KEY'))  define('VAPID_PUBLIC_KEY',  etel_taxi_env('VAPID_PUBLIC_KEY', ''));
if (!defined('VAPID_PRIVATE_KEY_PEM')) define('VAPID_PRIVATE_KEY_PEM', str_replace('\\n', "\n", etel_taxi_env('VAPID_PRIVATE_KEY_PEM', '')));
if (!defined('VAPID_SUBJECT')) define('VAPID_SUBJECT', etel_taxi_env('VAPID_SUBJECT', 'mailto:admin@ethelmarket.com'));
