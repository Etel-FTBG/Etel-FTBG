<?php
/**
 * Shared-key auth for inbound requests to this MCP server, matching the
 * header+query-param-fallback pattern already used elsewhere in this
 * codebase (e.g. EtelGebeya/api/service_nfc_order_list.php) because some
 * FastCGI configs on this host strip custom X-* headers before PHP sees them.
 */
function mcp_require_auth() {
    $provided = null;

    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'X-Mcp-Key') === 0) {
            $provided = $value;
            break;
        }
    }

    if (!$provided && isset($_GET['key'])) {
        $provided = $_GET['key'];
    }

    if (!$provided || !MCP_SHARED_KEY || !hash_equals(MCP_SHARED_KEY, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(array('error' => array('code' => -32001, 'message' => 'Unauthorized')));
        exit;
    }
}
