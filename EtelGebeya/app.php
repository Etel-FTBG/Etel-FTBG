<?php
/**
 * Etel Gebeya -- Main configuration
 *
 * Copy this file to .env (or edit directly for dev) and adjust values.
 * For production, set APP_ENV=production and APP_DEBUG=false.
 */

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
        'pass'    => getenv('DB_PASS') ?: '',              // ? SET YOUR DB PASSWORD HERE
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
            'enabled'     => filter_var(getenv('EG_TELEBIRR_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
            'endpoint'    => getenv('EG_TELEBIRR_ENDPOINT') ?: 'https://developer.ethiotelecom.et:9443/api',
            'app_id'      => getenv('EG_TELEBIRR_APP_ID') ?: 'sandbox-app-id',
            'app_key'     => getenv('EG_TELEBIRR_APP_KEY') ?: 'sandbox-app-key',
            'merchant_id' => getenv('EG_TELEBIRR_MERCHANT_ID') ?: 'sandbox-merchant',
            'short_code'  => getenv('EG_TELEBIRR_SHORT_CODE') ?: '091',
            'mode'        => getenv('EG_TELEBIRR_MODE') ?: 'sandbox', // sandbox | live
        ],
        'cbe' => [
            'enabled'     => filter_var(getenv('EG_CBE_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
            'endpoint'    => getenv('EG_CBE_ENDPOINT') ?: 'https://testapi.cbebirr.com',
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
];
