<?php
// Test helper (not a web file): marks the session of a token the way an EtelID sign-in with a checked authenticator code does.   php tests/mfa_mark_cli.php <user_id> <token>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('APP_VER')) define('APP_VER', 'test');   // the lib files refuse to run on their own without it
require __DIR__ . '/../api/lib/shield_store.php';
require __DIR__ . '/../api/lib/auth_sessions.php';
$pdo = etel_shield_pdo_any();
if (!$pdo) { fwrite(STDERR, "no database\n"); exit(2); }
echo etel_sess_mark_mfa($pdo, intval($argv[1]), $argv[2]) ? "marked\n" : "not marked\n";
echo etel_sess_mfa_ok($pdo, intval($argv[1]), $argv[2]) ? "ok\n" : "not ok\n";
