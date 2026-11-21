<?php
// Test helper (CI / throwaway database only): prints etel_courier_statement_for() for a courier id and an optional week as JSON.
require_once __DIR__ . '/../api/env_loader.php';
date_default_timezone_set('Africa/Addis_Ababa');
$env = etel_parse_env_file(__DIR__ . '/../api/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
$pdo = new PDO('mysql:host=' . $e('DB_HOST', '127.0.0.1') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4',
    $e('DB_USER'), $e('DB_PASS'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
require_once __DIR__ . '/../api/lib/courier_statement.php';
$r = etel_courier_statement_for($pdo, intval($argv[1]), isset($argv[2]) ? $argv[2] : '');
echo json_encode($r[0] === null ? array('error' => $r[1]) : $r[0]), "\n";
