<?php
// TEMPORARY DIAGNOSTIC - DELETE THIS FILE after checking.
// Shows only the last 6 characters of GEMINI_API_KEY as currently loaded
// by PHP - enough to confirm whether it matches the fixed key or a stale
// cached one, without re-exposing the full secret in a screenshot.
header('Content-Type: application/json');
require_once __DIR__ . '/secrets.php';

$key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

// Perform the EXACT same call etelai_call_gemini() in chat.php makes,
// so we see Google's raw response directly instead of guessing why the
// real code path fails when a direct browser test succeeds.
$modelName = 'gemini-flash-latest';
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelName)
     . ':generateContent?key=' . rawurlencode($key);

$payload = array(
    'contents' => array(array('role' => 'user', 'parts' => array(array('text' => 'Say hi in 3 words')))),
    'generationConfig' => array('maxOutputTokens' => 50),
);
$ctx = stream_context_create(array('http' => array(
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => json_encode($payload),
    'timeout' => 30,
    'ignore_errors' => true,
)));
$rawResp = @file_get_contents($url, false, $ctx);
$responseHeaders = isset($http_response_header) ? $http_response_header : array();

echo json_encode(array(
    'key_length'  => strlen($key),
    'key_last_6'  => substr($key, -6),
    'key_first_5' => substr($key, 0, 5),
    'has_double_prefix' => (strpos($key, 'AQ.AQ.') === 0),
    'opcache_enabled' => function_exists('opcache_get_status') && @opcache_get_status() !== false,
    'php_time' => date('Y-m-d H:i:s'),
    'test_call_status_line' => isset($responseHeaders[0]) ? $responseHeaders[0] : null,
    'test_call_raw_response' => $rawResp === false ? 'REQUEST FAILED (false)' : $rawResp,
    'allow_url_fopen' => ini_get('allow_url_fopen'),
));
