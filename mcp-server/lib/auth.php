<?php
/**
 * Shared-key auth for inbound requests to this MCP server.
 *
 * The key (MCP_SHARED_KEY) is accepted in any ONE of these places, checked
 * in this order:
 *   1. X-Mcp-Key: <key>              (original header; custom name)
 *   2. X-API-Key: <key>              (common API-key header name)
 *   3. Authorization: Bearer <key>   (standard header; use this one when a
 *                                     client UI only offers standard names,
 *                                     e.g. claude.ai connectors)
 *   4. ?key=<key> in the URL         (last resort: URLs end up in access
 *                                     logs, so prefer a header)
 *
 * Some FastCGI configs on this host strip custom X-* and even Authorization
 * headers before PHP sees them, so besides getallheaders() this also reads
 * the $_SERVER copies (including REDIRECT_HTTP_AUTHORIZATION, which the
 * RewriteRule in .htaccess populates).
 */
function mcp_request_header($name) {
    $want = strtolower($name);
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (strtolower($k) === $want) {
                    return $v;
                }
            }
        }
    }
    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server_key])) {
        return $_SERVER[$server_key];
    }
    if ($want === 'authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    return null;
}

function mcp_provided_key() {
    $v = mcp_request_header('X-Mcp-Key');
    if ($v) {
        return trim($v);
    }
    $v = mcp_request_header('X-Api-Key');
    if ($v) {
        return trim($v);
    }
    $v = mcp_request_header('Authorization');
    if ($v) {
        $v = trim($v);
        if (stripos($v, 'Bearer ') === 0) {
            return trim(substr($v, 7));
        }
        return $v;
    }
    if (isset($_GET['key'])) {
        return trim($_GET['key']);
    }
    return null;
}

function mcp_require_auth() {
    $provided = mcp_provided_key();

    if (!$provided || !MCP_SHARED_KEY || !hash_equals(MCP_SHARED_KEY, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(array('error' => array('code' => -32001, 'message' => 'Unauthorized')));
        exit;
    }
}
