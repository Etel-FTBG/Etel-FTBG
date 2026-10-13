<?php
/**
 * ETEL Delivery  ETB Payment Configuration
 * Hawassa, Ethiopia  Telebirr + Abysinia + CBE
 */

//  TELEBIRR (PRIMARY PAYMENT) 
/* Read Telebirr credentials from .env (bypasses getenv cPanel issue) */
if (!function_exists('_etb_env')) {
    function _etb_env($key, $default = '') {
        static $env = null;
        if ($env === null) {
            $env = array();
            $f = __DIR__ . '/.env';
            if (file_exists($f)) {
                $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) foreach ($lines as $line) {
                    $line = ltrim($line, "\xEF\xBB\xBF");
                    $t = ltrim($line);
                    if ($t === '' || $t[0] === '#' || strpos($t,'=') === false) continue;
                    $p = explode('=', $t, 2);
                    $k = trim($p[0]); $v = isset($p[1]) ? trim($p[1]) : '';
                    if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) $env[$k] = $v;
                }
            }
        }
        if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
        $g = getenv($key); if ($g !== false && $g !== '') return $g;
        return $default;
    }
}
define('TELEBIRR_MERCHANT_ID',   _etb_env('TELEBIRR_MERCHANT_ID',  ''));
define('TELEBIRR_API_KEY',       _etb_env('TELEBIRR_API_KEY',      ''));
define('TELEBIRR_SECRET_KEY',    _etb_env('TELEBIRR_SECRET_KEY',   ''));
define('TELEBIRR_BASE_URL',      'https://api.telebirr.com/api/v1');
define('TELEBIRR_MERCHANT_NAME', 'ETEL Delivery Hawassa');

//  APP HOST (for building callback URLs sent to payment providers) 
/* Never build a callback URL from $_SERVER['HTTP_HOST'] -- that header is
   attacker-controlled, and a manipulated Host on a payment-initiation
   request would tell Telebirr to send the payment confirmation callback
   to a domain of the attacker's choosing. Fixed, trusted value only. */
if (!defined('CFG_APP_HOST')) define('CFG_APP_HOST', 'ethelmarket.com');

//  ABYSINIA BANK (SECONDARY)
define('ABYSINIA_MERCHANT_ID',   _etb_env('ABYSINIA_MERCHANT_ID',  ''));
define('ABYSINIA_API_KEY',       _etb_env('ABYSINIA_API_KEY',      ''));
define('ABYSINIA_ACCOUNT_NO',    _etb_env('ABYSINIA_ACCOUNT_NO',   ''));

//  CBE BANK (FALLBACK)
define('CBE_MERCHANT_ID',        _etb_env('CBE_MERCHANT_ID',       ''));
define('CBE_ACCOUNT_NO',         _etb_env('CBE_ACCOUNT_NO',        ''));

//  HAWASSA DELIVERY FEES (ETB) 
// FALLBACK ONLY as of migration 007/008 - the source of truth is the
// `delivery_zones` DB table (admin-editable via Admin -> Pricing).
// OrderController.php reads that table first and only falls back to
// this hardcoded array if the table is missing or the zone isn't in it.
$GLOBALS['HAWASSA_DELIVERY_FEES'] = $HAWASSA_DELIVERY_FEES = array(
    'Mehal Ketema'         => array('fee' => 50,  'time' => 20),
    'Tabor'                => array('fee' => 50,  'time' => 20),
    'Addis Ketema'         => array('fee' => 60,  'time' => 25),
    'Haik Dar'             => array('fee' => 70,  'time' => 30),
    'Tulu Dimtu'           => array('fee' => 70,  'time' => 30),
    'Misrak'               => array('fee' => 65,  'time' => 28),
    'Referral'             => array('fee' => 55,  'time' => 22),
    'Hawela Teklehaimanot' => array('fee' => 75,  'time' => 35),
    'Dore Bafana'          => array('fee' => 80,  'time' => 35),
    'Stadium'              => array('fee' => 45,  'time' => 15),
    'Liqe Mender'          => array('fee' => 50,  'time' => 18),
    'University'           => array('fee' => 60,  'time' => 25),
);

//  DELIVERY TIERS (moto / car / express cargo) 
// FALLBACK ONLY (migration 012) - the source of truth is the
// `delivery_tiers` DB table (admin-editable via Admin -> Pricing).
// OrderController.php reads that table first and only falls back to
// this hardcoded array if the table is missing or the tier isn't in it.
$GLOBALS['DELIVERY_TIERS'] = $DELIVERY_TIERS = array(
    'moto'          => array('label' => 'Delivery by moto', 'icon' => '',  'multiplier' => 1.00, 'eta_delta' => 0),
    'car'           => array('label' => 'Delivery by car',  'icon' => '',  'multiplier' => 1.12, 'eta_delta' => -2),
    'express_cargo' => array('label' => 'Express Cargo',    'icon' => '',  'multiplier' => 1.60, 'eta_delta' => 5),
);

//  SERVICE CHARGE 
define('SERVICE_CHARGE_PERCENT', 5);   // 5% of subtotal

//  SUPPORT CONTACTS 
define('SUPPORT_PHONE',   '+251911000000');
define('SUPPORT_EMAIL',   'support@etel-hawassa.com');
define('SUPPORT_WHATSAPP','+251911000001');
