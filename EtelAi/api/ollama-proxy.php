<?php
// ─────────────────────────────────────────────────────────────
// ETEL OS — Ollama Server Proxy  v1.0
// Solves the HTTPS→HTTP mixed-content block by proxying from
// your domain (HTTPS) to Ollama running locally on the same server.
//
// Install: upload this file to EtelAi/api/ollama-proxy.php
// Then in ETEL Settings → Engine → "Own Server AI"
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Ollama runs on the same machine as this PHP file
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434');

$action = $_GET['action'] ?? 'chat';

// ── HEALTH CHECK ──────────────────────────────────────────────
if ($action === 'ping') {
    $r = @file_get_contents(OLLAMA_URL . '/api/tags', false,
        stream_context_create(['http' => ['timeout' => 3]]));
    if ($r === false) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Ollama not running. SSH in and run: ollama serve']);
        exit;
    }
    $data = json_decode($r, true);
    echo json_encode(['ok' => true, 'models' => array_map(fn($m) => $m['name'], $data['models'] ?? [])]);
    exit;
}

// ── CHAT ─────────────────────────────────────────────────────
if ($action === 'chat') {
    $body = file_get_contents('php://input');
    if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No body']); exit; }

    $payload = json_decode($body, true);
    if (empty($payload['model'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No model specified']); exit; }

    // Always stream=false for proxy simplicity (client still gets fast response)
    $payload['stream'] = false;

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 120,
    ]]);

    $resp = @file_get_contents(OLLAMA_URL . '/api/chat', false, $ctx);
    if ($resp === false) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Ollama not responding. Run: ollama serve && ollama pull ' . htmlspecialchars($payload['model'])]);
        exit;
    }
    // Forward response directly
    echo $resp;
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
