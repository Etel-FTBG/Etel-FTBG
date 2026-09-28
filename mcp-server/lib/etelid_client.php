<?php
/**
 * Thin client for EtelID's Service API (etelid/v1/service/*), reusing the
 * shared ETELID_SERVICE_KEY the whole codebase already relies on. Sends the
 * key both as a header and as ?key= (per the FastCGI X-* header stripping
 * note in the ops notes) since EtelID's own receiver currently only reads
 * the header - the query param costs nothing and protects against future
 * host config changes.
 */
function mcp_etelid_call($route, array $query = array(), $method = 'GET') {
    if (!MCP_ETELID_SERVICE_KEY) {
        throw new RuntimeException('ETELID_SERVICE_KEY is not configured in mcp-server/.env');
    }

    $query['key'] = MCP_ETELID_SERVICE_KEY;
    $url = MCP_ETELID_BASE_URL . '/wp-json/etelid/v1/service/' . ltrim($route, '/');
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array('X-Etel-Service-Key: ' . MCP_ETELID_SERVICE_KEY),
    ));

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("EtelID request failed: $err");
    }

    $decoded = json_decode($body, true);
    if ($decoded === null && $body !== 'null') {
        throw new RuntimeException("EtelID returned non-JSON response (HTTP $status)");
    }

    return array('http_status' => $status, 'body' => $decoded);
}
