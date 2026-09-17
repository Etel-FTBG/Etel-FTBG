<?php
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); opcache_reset(); }
// ============================================================
// EtelAudit Pro -- Multi-AI Proxy
// Keys live in config.php (server only, never in git)
// ============================================================

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    define('GEMINI_API_KEY', 'YOUR_GEMINI_KEY_HERE');
    define('CLAUDE_API_KEY', 'YOUR_CLAUDE_KEY_HERE');
    define('KIMI_API_KEY',   'YOUR_KIMI_KEY_HERE');
    define('OPENAI_API_KEY', 'YOUR_OPENAI_KEY_HERE');
    define('AUDIT_SECRET',   'YOUR_AUDIT_SECRET_HERE');
}

define('GEMINI_MODELS',  'gemini-3.5-flash,gemini-3.1-flash-lite,gemini-3.1-pro');
define('CLAUDE_MODEL',   'claude-sonnet-4-6');
define('KIMI_MODEL',     'moonshot-v1-32k');
define('OPENAI_MODEL',   'gpt-4o');

// ── HEADERS ──────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(400); echo json_encode(['error' => 'Empty request body']); exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }
// HARDENING: fail closed if AUDIT_SECRET was never actually configured -
// 'YOUR_AUDIT_SECRET_HERE' is the documented placeholder default above,
// so treating it as a usable secret would let anyone who reads this
// source (or just guesses the well-known placeholder) call every AI
// provider through this proxy on Etel Group's own billed API keys.
// Also switched to hash_equals() - the previous !== check is not
// constant-time.
if (!defined('AUDIT_SECRET') || AUDIT_SECRET === '' || AUDIT_SECRET === 'YOUR_AUDIT_SECRET_HERE' || AUDIT_SECRET === 'CHANGE_ME') {
    http_response_code(503); echo json_encode(['error' => 'Audit secret not configured on the server.']); exit;
}
if (!hash_equals(AUDIT_SECRET, (string)($data['secret'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit; }

$provider  = strtolower(trim($data['provider'] ?? 'gemini'));
$test_mode = (bool)($data['test'] ?? false);

// ── HEALTH CHECK (empty ping from init) ──────────────────────
if (($data['content'] ?? '') === '' && ($data['system'] ?? '') === '' && !$test_mode) {
    echo json_encode([
        'status'  => 'ok',
        'gemini'  => defined('GEMINI_API_KEY') && GEMINI_API_KEY !== 'YOUR_GEMINI_KEY_HERE',
        'claude'  => defined('CLAUDE_API_KEY') && CLAUDE_API_KEY !== 'YOUR_CLAUDE_KEY_HERE',
        'kimi'    => defined('KIMI_API_KEY')   && KIMI_API_KEY   !== 'YOUR_KIMI_KEY_HERE',
        'openai'  => defined('OPENAI_API_KEY') && OPENAI_API_KEY !== 'YOUR_OPENAI_KEY_HERE',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'role'    => 'assistant'
    ]);
    exit;
}

if (!function_exists('curl_init')) { http_response_code(500); echo json_encode(['error' => 'cURL not available']); exit; }

// ── UTF-8 CLEANER ─────────────────────────────────────────────
function clean_utf8(string $s): string {
    $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    if ($s === false) {
        $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/u', '?', $s ?: '') ?: '';
    }
    return (string)$s;
}

$system  = clean_utf8((string)($data['system']  ?? ''));
$content = clean_utf8((string)($data['content'] ?? ''));

if (strlen($content) > 45000)  $content = substr($content, 0, 45000) . "\n\n[truncated]";
if (strlen($system)  > 8000)   $system  = substr($system,  0, 8000);

// ── CURL HELPER ───────────────────────────────────────────────
function do_curl(string $url, string $payload, array $headers, int $timeout = 280): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$resp, $code, $err];
}

// ── UNIFIED RESPONSE WRAPPER ──────────────────────────────────
function send_ok(string $text, string $model): void {
    echo json_encode([
        'content' => [['type' => 'text', 'text' => $text]],
        'model'   => $model,
        'role'    => 'assistant'
    ]);
    exit;
}

function send_err(string $msg, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// GEMINI
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($provider === 'gemini') {
    if (GEMINI_API_KEY === 'YOUR_GEMINI_KEY_HERE') send_err('setup_required', 503);

    $payload_arr = [
        'contents'          => [['role' => 'user', 'parts' => [['text' => $content]]]],
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'generationConfig'  => ['maxOutputTokens' => 65536, 'temperature' => 0.1, 'topP' => 0.8],
        'safetySettings'    => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
        ]
    ];

    $payload = json_encode($payload_arr, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $content = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $content);
        $system  = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $system);
        $payload_arr['contents'][0]['parts'][0]['text'] = $content;
        $payload_arr['systemInstruction']['parts'][0]['text'] = $system;
        $payload = json_encode($payload_arr);
    }

    $models = array_map('trim', explode(',', GEMINI_MODELS));
    $tried  = ''; $resp = ''; $http = 0; $cerr = '';
    foreach ($models as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GEMINI_API_KEY;
        [$resp, $http, $cerr] = do_curl($url, $payload, ['Content-Type: application/json']);
        $tried = $model;
        if ($http === 200) break;
        if (in_array($http, [400, 401, 403])) break;
    }
    if ($cerr) send_err('Network error: ' . $cerr, 502);
    if ($http !== 200) {
        $msg = json_decode($resp, true)['error']['message'] ?? ('Gemini HTTP ' . $http);
        if (strpos($msg, 'API_KEY') !== false || $http === 401) send_err('Invalid Gemini API key. Check proxy.php.');
        send_err($msg, $http);
    }
    $parts = json_decode($resp, true)['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    foreach ($parts as $p) { if (!($p['thought'] ?? false) && isset($p['text'])) $text .= $p['text']; }
    if ($text === '') foreach ($parts as $p) $text .= ($p['text'] ?? '');
    $text = trim(preg_replace(['/^```(?:json)?\s*/im', '/```\s*$/m'], '', $text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    if (!$test_mode && (!$text || json_decode($text) === null)) send_err('Gemini non-JSON. First 200: ' . substr($text, 0, 200));
    send_ok($text, $tried);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// CLAUDE (Anthropic)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($provider === 'claude') {
    if (CLAUDE_API_KEY === 'YOUR_CLAUDE_KEY_HERE') send_err('Claude API key not set. Add CLAUDE_API_KEY in proxy.php.', 503);

    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 16000,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]]
    ], JSON_UNESCAPED_UNICODE);

    [$resp, $http, $cerr] = do_curl(
        'https://api.anthropic.com/v1/messages',
        $payload,
        [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ]
    );
    if ($cerr) send_err('Network error: ' . $cerr, 502);
    if ($http !== 200) {
        $msg = json_decode($resp, true)['error']['message'] ?? ('Claude HTTP ' . $http);
        send_err('Claude error: ' . $msg, $http);
    }
    $decoded = json_decode($resp, true);
    $text = '';
    foreach (($decoded['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim(preg_replace(['/^```(?:json)?\s*/im', '/```\s*$/m'], '', $text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    if (!$test_mode && (!$text || json_decode($text) === null)) send_err('Claude non-JSON response. First 200: ' . substr($text, 0, 200));
    send_ok($text, CLAUDE_MODEL);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// KIMI (Moonshot AI -- OpenAI-compatible)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($provider === 'kimi') {
    if (KIMI_API_KEY === 'YOUR_KIMI_KEY_HERE') send_err('Kimi API key not set. Add KIMI_API_KEY in proxy.php.', 503);

    $messages = [];
    if ($system) $messages[] = ['role' => 'system', 'content' => $system];
    $messages[] = ['role' => 'user', 'content' => $content];

    $payload = json_encode([
        'model'       => KIMI_MODEL,
        'max_tokens'  => 16000,
        'temperature' => 0.1,
        'messages'    => $messages
    ], JSON_UNESCAPED_UNICODE);

    [$resp, $http, $cerr] = do_curl(
        'https://api.moonshot.cn/v1/chat/completions',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . KIMI_API_KEY]
    );
    if ($cerr) send_err('Network error: ' . $cerr, 502);
    if ($http !== 200) {
        $msg = json_decode($resp, true)['error']['message'] ?? ('Kimi HTTP ' . $http);
        send_err('Kimi error: ' . $msg, $http);
    }
    $text = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $text = trim(preg_replace(['/^```(?:json)?\s*/im', '/```\s*$/m'], '', $text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    if (!$test_mode && (!$text || json_decode($text) === null)) send_err('Kimi non-JSON response. First 200: ' . substr($text, 0, 200));
    send_ok($text, KIMI_MODEL);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// OPENAI (GPT-4o / Codex)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($provider === 'openai') {
    if (OPENAI_API_KEY === 'YOUR_OPENAI_KEY_HERE') send_err('OpenAI API key not set. Add OPENAI_API_KEY in proxy.php.', 503);

    $messages = [];
    if ($system) $messages[] = ['role' => 'system', 'content' => $system];
    $messages[] = ['role' => 'user', 'content' => $content];

    $payload = json_encode([
        'model'       => OPENAI_MODEL,
        'max_tokens'  => 16000,
        'temperature' => 0.1,
        'messages'    => $messages
    ], JSON_UNESCAPED_UNICODE);

    [$resp, $http, $cerr] = do_curl(
        'https://api.openai.com/v1/chat/completions',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY]
    );
    if ($cerr) send_err('Network error: ' . $cerr, 502);
    if ($http !== 200) {
        $msg = json_decode($resp, true)['error']['message'] ?? ('OpenAI HTTP ' . $http);
        send_err('OpenAI error: ' . $msg, $http);
    }
    $text = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $text = trim(preg_replace(['/^```(?:json)?\s*/im', '/```\s*$/m'], '', $text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    if (!$test_mode && (!$text || json_decode($text) === null)) send_err('OpenAI non-JSON response. First 200: ' . substr($text, 0, 200));
    send_ok($text, OPENAI_MODEL);
}

// Unknown provider -- default to gemini check so init ping works
send_err(GEMINI_API_KEY === 'YOUR_GEMINI_KEY_HERE' ? 'setup_required' : 'unknown_provider:' . htmlspecialchars($provider), 400);
