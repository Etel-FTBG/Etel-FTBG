<?php
/**
 * Etel Group unified MCP server - Streamable HTTP transport (JSON-RPC over
 * a single POST endpoint, no SSE required for the request/response tools
 * this server exposes). Deploy this whole mcp-server/ folder to the site
 * root's mcp/ path (see README.md) via whichever system's Updater is most
 * convenient - it has no dependency on any single system's codebase.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/etelid_client.php';
require_once __DIR__ . '/lib/tools.php';

header('Content-Type: application/json');

mcp_require_auth();

// Streamable HTTP: this server only answers POST. Clients may probe with GET
// (server-initiated stream) or DELETE (end session); the spec says to answer
// those with 405 rather than a parse error.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(array('error' => array('code' => -32000, 'message' => 'Method not allowed - use POST')));
    exit;
}

$raw = file_get_contents('php://input');
$request = json_decode($raw, true);

if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(array('error' => array('code' => -32700, 'message' => 'Parse error')));
    exit;
}

$id = isset($request['id']) ? $request['id'] : null;
$method = isset($request['method']) ? $request['method'] : '';
$params = isset($request['params']) && is_array($request['params']) ? $request['params'] : array();

function mcp_respond($id, $result) {
    echo json_encode(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result));
    exit;
}

function mcp_respond_error($id, $code, $message) {
    echo json_encode(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)));
    exit;
}

try {
    switch ($method) {
        case 'initialize':
            // Echo the client's protocol version when we know it; otherwise
            // offer the oldest one this server was written against.
            $known = array('2024-11-05', '2025-03-26', '2025-06-18');
            $wanted = isset($params['protocolVersion']) ? $params['protocolVersion'] : '';
            mcp_respond($id, array(
                'protocolVersion' => in_array($wanted, $known, true) ? $wanted : '2024-11-05',
                'capabilities' => array('tools' => new stdClass()),
                'serverInfo' => array('name' => 'etel-group-mcp', 'version' => '1.0.0'),
            ));
            break;

        case 'ping':
            mcp_respond($id, new stdClass());
            break;

        case 'notifications/initialized':
            http_response_code(202);
            exit;

        case 'tools/list':
            mcp_respond($id, array('tools' => mcp_tool_list()));
            break;

        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $arguments = isset($params['arguments']) ? $params['arguments'] : array();
            try {
                $result = mcp_tool_call($name, $arguments);
                mcp_respond($id, array(
                    'content' => array(array('type' => 'text', 'text' => json_encode($result))),
                    'isError' => false,
                ));
            } catch (Exception $e) {
                mcp_respond($id, array(
                    'content' => array(array('type' => 'text', 'text' => $e->getMessage())),
                    'isError' => true,
                ));
            }
            break;

        default:
            // Any other notification (no id) just gets acknowledged.
            if ($id === null && strpos($method, 'notifications/') === 0) {
                http_response_code(202);
                exit;
            }
            mcp_respond_error($id, -32601, "Method not found: $method");
    }
} catch (Throwable $e) {
    mcp_respond_error($id, -32603, 'Internal error: ' . $e->getMessage());
}
