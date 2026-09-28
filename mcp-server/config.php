<?php
require_once __DIR__ . '/lib/env.php';

mcp_load_env(__DIR__ . '/.env');

define('MCP_SHARED_KEY', mcp_env('MCP_SHARED_KEY', ''));
define('MCP_ETELID_SERVICE_KEY', mcp_env('ETELID_SERVICE_KEY', ''));
define('MCP_ETELID_BASE_URL', rtrim(mcp_env('ETELID_BASE_URL', 'https://ethelmarket.com/EtelId'), '/'));

/**
 * Per-system DB definitions. host/user/pass fall back to the shared
 * DB_HOST/DB_USER/DB_PASS since one MySQL user usually covers every DB on
 * this cPanel account; each system can still override its own if it lives
 * on a separate MySQL user.
 */
function mcp_db_registry() {
    $host = mcp_env('DB_HOST', 'localhost');
    $user = mcp_env('DB_USER', '');
    $pass = mcp_env('DB_PASS', '');

    return array(
        'etelid' => array(
            'label'  => 'EtelID',
            'host'   => mcp_env('ETELID_DB_HOST', $host),
            'name'   => mcp_env('ETELID_DB_NAME', ''),
            'user'   => mcp_env('ETELID_DB_USER', $user),
            'pass'   => mcp_env('ETELID_DB_PASS', $pass),
            'prefix' => mcp_env('ETELID_DB_PREFIX', 'Etelid_'),
        ),
        'etelgroup' => array(
            'label'  => 'EtelGroup',
            'host'   => mcp_env('ETELGROUP_DB_HOST', $host),
            'name'   => mcp_env('ETELGROUP_DB_NAME', ''),
            'user'   => mcp_env('ETELGROUP_DB_USER', $user),
            'pass'   => mcp_env('ETELGROUP_DB_PASS', $pass),
            'prefix' => '',
        ),
        'etelpay' => array(
            'label'  => 'Etel.Pay',
            'host'   => mcp_env('ETELPAY_DB_HOST', $host),
            'name'   => mcp_env('ETELPAY_DB_NAME', ''),
            'user'   => mcp_env('ETELPAY_DB_USER', $user),
            'pass'   => mcp_env('ETELPAY_DB_PASS', $pass),
            'prefix' => mcp_env('ETELPAY_DB_PREFIX', 'Etelpay_'),
        ),
        'delivery' => array(
            'label'  => 'Delivery & Ride',
            'host'   => mcp_env('DELIVERY_DB_HOST', $host),
            'name'   => mcp_env('DELIVERY_DB_NAME', ''),
            'user'   => mcp_env('DELIVERY_DB_USER', $user),
            'pass'   => mcp_env('DELIVERY_DB_PASS', $pass),
            'prefix' => '',
        ),
        'eteltaxi' => array(
            'label'  => 'EtelTaxi',
            'host'   => mcp_env('ETELTAXI_DB_HOST', $host),
            'name'   => mcp_env('ETELTAXI_DB_NAME', ''),
            'user'   => mcp_env('ETELTAXI_DB_USER', $user),
            'pass'   => mcp_env('ETELTAXI_DB_PASS', $pass),
            'prefix' => mcp_env('ETELTAXI_DB_PREFIX', ''),
        ),
        'etelgebeya' => array(
            'label'  => 'EtelGebeya',
            'host'   => mcp_env('ETELGEBEYA_DB_HOST', $host),
            'name'   => mcp_env('ETELGEBEYA_DB_NAME', ''),
            'user'   => mcp_env('ETELGEBEYA_DB_USER', $user),
            'pass'   => mcp_env('ETELGEBEYA_DB_PASS', $pass),
            'prefix' => mcp_env('ETELGEBEYA_DB_PREFIX', ''),
        ),
        'etelmadeya' => array(
            'label'  => 'EtelMadeya',
            'host'   => mcp_env('ETELMADEYA_DB_HOST', $host),
            'name'   => mcp_env('ETELMADEYA_DB_NAME', ''),
            'user'   => mcp_env('ETELMADEYA_DB_USER', $user),
            'pass'   => mcp_env('ETELMADEYA_DB_PASS', $pass),
            'prefix' => '',
        ),
    );
}
