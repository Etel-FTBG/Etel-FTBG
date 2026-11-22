<?php
// Helper for tests/server_errors_smoke.py: writes server errors through the real api/lib/server_errors.php into the test database.
require_once __DIR__ . '/../api/env_loader.php';
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
function db() { global $env; static $p = null; if (!$p) $p = new PDO('mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4', $env['DB_USER'], $env['DB_PASS'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)); return $p; }
require_once __DIR__ . '/../api/lib/server_errors.php';
$tag = isset($argv[1]) ? $argv[1] : 'T';
$a = etel_log_server_error('fatal', 'customer_create_order', 'Call to undefined function foo_' . $tag . '() 123456789abc', '/home/x/lib/delivery_order_core.php', 321);
$b = etel_log_server_error('fatal', 'customer_create_order', 'Call to undefined function foo_' . $tag . '() 987654321def', '/home/x/lib/delivery_order_core.php', 321);   // same error, other number: one row
$c = etel_log_server_error('error', 'customer_create_order', str_repeat('x', 500), '/a/b/c.php', 7);                                              // long message is cut
echo ($a && $b && $c) ? "written\n" : "FAILED\n";
