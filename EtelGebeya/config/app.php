<?php
/**
 * Etel Gebeya -- Main configuration
 *
 * Copy this file to .env (or edit directly for dev) and adjust values.
 * For production, set APP_ENV=production and APP_DEBUG=false.
 */

/* Load .env into the environment BEFORE the getenv() calls below run.
 * Must live here (not bootstrap.php) because index.php requires this
 * file first. Existing real env vars are never overwritten. */
(function () {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
        }
    }
})();

return [

    'app' => [
        'name'    => 'Etel Gebeya',
        'name_am' => '???? ???',
        'tagline' => 'Ethiopia\'s Marketplace',
        'env'     => getenv('APP_ENV') ?: 'production',
        'debug'   => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL),  // set to 'false' after storefront works
        'url'     => getenv('APP_URL') ?: 'https://ethelmarket.com/EtelGebeya',
        'timezone'=> 'Africa/Addis_Ababa',
        'locale'  => 'en',
        'locales' => ['en', 'am'],
        'key'     => getenv('APP_KEY') ?: 'CHANGE_ME',
    ],

    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'EtelGebeya',   // cPanel DB name
        'user'    => getenv('DB_USER') ?: 'EtelGebeya',   // ? update if cPanel adds prefix e.g. ethel_EtelGebeya
        'pass'    => getenv('DB_PASS') ?: 'CHANGE_ME',
        'charset' => 'utf8mb4',
        'prefix'  => '',   // no prefix on live server (local dev uses eg_)
    ],

    'mail' => [
        'driver'    => getenv('MAIL_DRIVER') ?: 'log',   // log | smtp
        'from_name' => 'Etel Gebeya',
        'from_addr' => getenv('MAIL_FROM') ?: 'no-reply@etelgebeya.local',
        'smtp' => [
            'host'     => getenv('SMTP_HOST') ?: '',
            'port'     => getenv('SMTP_PORT') ?: 587,
            'user'     => getenv('SMTP_USER') ?: '',
            'pass'     => getenv('SMTP_PASS') ?: '',
            'secure'   => getenv('SMTP_SECURE') ?: 'tls',
        ],
    ],

    'storage' => [
        'uploads' => __DIR__ . '/../storage/uploads',
        'logs'    => __DIR__ . '/../storage/logs',
        'cache'   => __DIR__ . '/../storage/cache',
    ],

    'security' => [
        'password_algo'      => PASSWORD_BCRYPT,
        'bcrypt_cost'        => 12,
        'session_name'       => 'EG_SESS',
        'session_lifetime'   => 60 * 60 * 24 * 7,    // 7 days
        'csrf_token_name'    => '_token',
        'login_max_attempts' => 5,
        'login_lockout_secs' => 900,                  // 15 min
        'rate_limit_window'  => 60,                   // seconds
        'rate_limit_max'     => 120,                  // requests per window
    ],

    'currency' => [
        'code'   => 'ETB',
        'symbol' => 'Br',
        'name'   => 'Birr',
    ],

    'payments' => [
        // All sandbox-ready. Real keys go in env.
        'telebirr' => [
            // 'enabled' here means "show as a manual/instructional method at
            // checkout" -- no live merchant API is wired. Customer sees pay-
            // by-reference instructions and an admin confirms manually,
            // same pattern as Etel Pay's own top-up flow. Set 'mode' => 'live'
            // and fill in the API fields once you have real Ethio Telecom
            // merchant credentials to wire an actual gateway.
            'enabled'     => filter_var(getenv('EG_TELEBIRR_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
            'endpoint'    => getenv('EG_TELEBIRR_ENDPOINT') ?: 'https://developer.ethiotelecom.et:9443/api',
            'app_id'      => getenv('EG_TELEBIRR_APP_ID') ?: 'sandbox-app-id',
            'app_key'     => getenv('EG_TELEBIRR_APP_KEY') ?: 'sandbox-app-key',
            'merchant_id' => getenv('EG_TELEBIRR_MERCHANT_ID') ?: 'sandbox-merchant',
            'short_code'  => getenv('EG_TELEBIRR_SHORT_CODE') ?: '091',
            'mode'        => getenv('EG_TELEBIRR_MODE') ?: 'sandbox', // sandbox | live
        ],
        'cbe' => [
            // Same manual/instructional pattern as telebirr above.
            'enabled'     => filter_var(getenv('EG_CBE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
            'endpoint'    => getenv('EG_CBE_ENDPOINT') ?: 'https://testapi.cbebirr.com',
            'account'     => getenv('EG_CBE_ACCOUNT') ?: '1000123456789',
            'account_name'=> getenv('EG_CBE_ACCOUNT_NAME') ?: 'ETEL GROUP PLC',
            'merchant_id' => getenv('EG_CBE_MERCHANT_ID') ?: 'sandbox-merchant',
            'api_key'     => getenv('EG_CBE_API_KEY') ?: 'sandbox-key',
            'mode'        => getenv('EG_CBE_MODE') ?: 'sandbox',
        ],
        'chapa' => [
            'enabled'     => filter_var(getenv('EG_CHAPA_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
            'public_key'  => getenv('EG_CHAPA_PUBLIC') ?: 'CHAPUBK_TEST-xxxxxxxxxxxxxxxx',
            'secret_key'  => getenv('EG_CHAPA_SECRET') ?: 'CHASECK_TEST-xxxxxxxxxxxxxxxx',
            'endpoint'    => 'https://api.chapa.co/v1',
            'mode'        => getenv('EG_CHAPA_MODE') ?: 'sandbox',
        ],
    ],

    'courier' => [
        'default'  => 'internal',          // internal | sandbox
        'fee_per_kg_etb' => 50,            // default internal courier fee
        'fee_flat_etb'   => 30,            // base fee
    ],

    // .env actually sets ETELID_SERVICE_URL / ETELID_SERVICE_KEY (matching
    // api/etelid_service_bridge.php's own names) -- ETELID_URL / ETELID_KEY
    // are kept as an override for anyone who set those instead.
    'etelid' => [
        'url' => getenv('ETELID_URL') ?: (getenv('ETELID_SERVICE_URL') ?: 'https://ethelmarket.com/EtelId/wp-json/etelid/v1'),
        'key' => getenv('ETELID_KEY') ?: (getenv('ETELID_SERVICE_KEY') ?: 'CHANGE_ME'),
    ],

    // Google Maps JS API key for the vendor-panel address picker (map
    // rendering only -- search/reverse-geocode still goes through this
    // app's own free Nominatim proxy in GeocodeController, so no Places/
    // Geocoding billing is needed, just "Maps JavaScript API" enabled on
    // the key). Safe to reuse the same key already deployed for Delivery
    // & Ride (same ethelmarket.com referrer restriction).
    'maps' => [
        'google_api_key' => getenv('GOOGLE_MAPS_API_KEY') ?: '',
    ],

    // ኢE Pay wallet checkout -- server-to-server verification only, the
    // browser talks to Etel Pay's own API directly (login + send), this
    // secret is only used to independently verify the resulting tx_ref
    // before an order is ever marked paid. MUST match the EP_SECRET
    // already deployed in Etel.Pay/wallet/api/config.php -- copy that
    // exact value in here (or set EG_ETELPAY_SECRET in .env).
    'etel_pay' => [
        'api_url' => getenv('EG_ETELPAY_API_URL') ?: 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php',
        'secret'  => getenv('EG_ETELPAY_SECRET') ?: 'CHANGE_ME',
    ],
];
