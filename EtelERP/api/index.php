<?php
/**
 * EtelERP API entry point.   POST/GET  /EtelERP/api/index.php?action=invoices.list
 * Cookie session (HttpOnly, SameSite=Strict) + header X-Business: <id> + header X-Requested-With: etelerp on every POST.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Africa/Addis_Ababa');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$app = dirname(__DIR__) . '/app/';
foreach (array('ApiError', 'Env', 'Db', 'Money', 'Calc', 'Perm', 'Plans', 'RateLimit', 'Audit', 'EtelId', 'Auth', 'Businesses', 'Customers', 'Items', 'Payments', 'Invoices', 'Expenses', 'Reports', 'Router') as $c) {
    require_once $app . $c . '.php';
}

$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw !== false && strlen($raw) > 1048576) {
    http_response_code(413);
    echo json_encode(array('ok' => false, 'error' => 'Request too large.'));
    exit;
}
$body = array();
if ($raw !== false && $raw !== '') {
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Invalid request body.'));
        exit;
    }
}

$res = Router::dispatch(array(
    'method'   => $_SERVER['REQUEST_METHOD'],
    'action'   => isset($_GET['action']) ? (string) $_GET['action'] : '',
    'query'    => $_GET,
    'body'     => $body,
    'token'    => isset($_COOKIE[Router::COOKIE]) ? $_COOKIE[Router::COOKIE] : null,
    'business' => isset($_SERVER['HTTP_X_BUSINESS']) ? (int) $_SERVER['HTTP_X_BUSINESS'] : 0,
    'ip'       => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    'csrf'     => isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '',
));

if ($res['cookie']) {
    $path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    setcookie(Router::COOKIE, $res['cookie']['token'], array(
        'expires' => time() + Auth::SESSION_DAYS * 86400,
        'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ));
}
if (isset($_GET['action']) && $_GET['action'] === 'auth.logout' && $res['status'] === 200) {
    $path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    setcookie(Router::COOKIE, '', array('expires' => time() - 3600, 'path' => $path, 'httponly' => true, 'samesite' => 'Strict'));
}
http_response_code($res['status']);
echo json_encode($res['body']);
