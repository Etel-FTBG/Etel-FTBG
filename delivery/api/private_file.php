<?php
// Serves a private upload (see lib/private_files.php) when the link carries a valid, unexpired signature.
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/lib/private_files.php';

function pf_deny($code, $msg) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$env = function_exists('etel_parse_env_file') ? etel_parse_env_file(__DIR__ . '/.env') : array();
$secret = isset($env['TOKEN_SECRET']) ? $env['TOKEN_SECRET'] : (string) getenv('TOKEN_SECRET');
if ($secret === '') pf_deny(503, 'Not configured');

$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
$e = isset($_GET['e']) ? intval($_GET['e']) : 0;
$s = isset($_GET['s']) ? (string) $_GET['s'] : '';
$parts = explode(':', $f, 2);
if (count($parts) !== 2) pf_deny(400, 'Bad link');
$folder = $parts[0]; $name = $parts[1];
if (!in_array($folder, etel_pf_folders(), true)) pf_deny(400, 'Bad link');
if (!preg_match('/^[A-Za-z0-9_.\-]+\.(jpg|jpeg|png|gif|webp)$/i', $name) || strpos($name, '..') !== false) pf_deny(400, 'Bad link');
if ($e < time()) pf_deny(410, 'This link has expired. Go back and refresh the list to get a new one.');
if ($e > time() + 7200) pf_deny(400, 'Bad link');
if (!hash_equals(etel_pf_sign($secret, $folder, $name, $e), $s)) pf_deny(403, 'Not allowed');

$path = dirname(__DIR__) . '/uploads/' . $folder . '/' . $name;
if (!is_file($path)) pf_deny(404, 'File not found');

$types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
