<?php
// TEMPORARY DIAGNOSTIC - DELETE THIS FILE after checking.
// Calls Delivery's agent_search_items directly (same function chat.php
// uses), bypassing Gemini entirely, so we see Delivery's exact response
// instead of Gemini's paraphrase of a failure.
header('Content-Type: application/json');
require_once __DIR__ . '/secrets.php';

function etelai_call_delivery_agent($action, $args, $extra = array()) {
    if (!defined('DELIVERY_SERVICE_KEY') || DELIVERY_SERVICE_KEY === '') {
        return array('error' => 'DELIVERY_SERVICE_KEY missing in secrets.php');
    }
    $payload = array_merge($args, $extra);
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . DELIVERY_SERVICE_KEY . "\r\n",
        'content' => json_encode($payload),
        'timeout' => 20,
        'ignore_errors' => true,
    )));
    $url = DELIVERY_API_BASE . '?action=' . rawurlencode($action);
    $resp = @file_get_contents($url, false, $ctx);
    $responseHeaders = isset($http_response_header) ? $http_response_header : array();
    return array(
        'raw_response'  => $resp,
        'status_line'   => isset($responseHeaders[0]) ? $responseHeaders[0] : null,
        'url_called'    => $url,
        'key_length'    => strlen(DELIVERY_SERVICE_KEY),
        'key_last_6'    => substr(DELIVERY_SERVICE_KEY, -6),
    );
}

echo json_encode(etelai_call_delivery_agent('agent_search_items', array('query' => 'coffee')), JSON_PRETTY_PRINT);
