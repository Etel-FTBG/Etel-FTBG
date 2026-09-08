<?php
// ============================================================
// EtelAudit Pro -- Powered by Google Gemini (100% FREE)
// ============================================================
// STEP 1: Get your FREE Gemini API key:
//   1. Go to https://aistudio.google.com/app/apikey
//   2. Sign in with Google (free)
//   3. Click "Create API key" -- copy it below
// ============================================================
define('GEMINI_API_KEY', 'CHANGE_ME');

define('AUDIT_SECRET', 'EtelAudit-2026-Dagi');
define('GEMINI_MODELS', 'gemini-2.5-flash,gemini-2.0-flash,gemini-1.5-flash');

// DO NOT EDIT BELOW THIS LINE
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(400); echo json_encode(['error' => 'Empty request body']); exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON in request body']); exit; }

if (($data['secret'] ?? '') !== AUDIT_SECRET) { http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit; }

// Test-mode: skip JSON validation, just confirm the key works
$test_mode = (bool)($data['test'] ?? false);

if (GEMINI_API_KEY === 'PASTE_YOUR_GEMINI_KEY_HERE') {
    http_response_code(503);
    echo json_encode(['error' => 'setup_required', 'message' => 'Paste your Gemini key in audit/proxy.php line 11']);
    exit;
}

if (!function_exists('curl_init')) { http_response_code(500); echo json_encode(['error' => 'cURL not available']); exit; }

// ----------------------------------------------------------
// SANITIZE CONTENT
// Fix 1: Strip invalid UTF-8 sequences that cause json_encode to fail.
// This is the "contents is not specified" bug -- when json_encode()
// returns false (invalid bytes), PHP sends an empty body to Gemini.
// ----------------------------------------------------------
function clean_utf8(string $s): string {
    // Convert to UTF-8, dropping invalid sequences
    $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    if ($s === false) {
        // iconv not available -- manually strip non-ASCII + non-UTF8
        $s = preg_replace('/[\x80-\x9F]/u', '?', $s ?: '');
        $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/u', '?', $s ?: '') ?: '';
    }
    return (string)$s;
}

$system_prompt = clean_utf8((string)($data['system'] ?? ''));
$user_content  = clean_utf8((string)($data['content'] ?? ''));

// Limit size (prevents Gemini input token overflow)
if (strlen($user_content) > 45000) {
    $user_content = substr($user_content, 0, 45000) . "\n\n[Content truncated at 45000 chars to fit model limits]";
}
if (strlen($system_prompt) > 8000) {
    $system_prompt = substr($system_prompt, 0, 8000);
}

// ----------------------------------------------------------
// BUILD PAYLOAD
// Fix 2: Removed responseMimeType -- not universally supported
//         and can cause silent failures on some models.
// Fix 3: Increased maxOutputTokens to 8192.
// ----------------------------------------------------------
$payload_array = [
    'contents' => [
        ['role' => 'user', 'parts' => [['text' => $user_content]]]
    ],
    'systemInstruction' => [
        'parts' => [['text' => $system_prompt]]
    ],
    'generationConfig' => [
        'maxOutputTokens' => 8192,
        'temperature'     => 0.1,
        'topP'            => 0.8
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
    ]
];

// Fix 4: Validate json_encode succeeded before sending
$payload = json_encode($payload_array, JSON_UNESCAPED_UNICODE);
if ($payload === false) {
    // Fallback: strip to pure ASCII and try again
    $user_content  = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $user_content);
    $system_prompt = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $system_prompt);
    $payload_array['contents'][0]['parts'][0]['text']  = $user_content;
    $payload_array['systemInstruction']['parts'][0]['text'] = $system_prompt;
    $payload = json_encode($payload_array);
    if ($payload === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to encode request: ' . json_last_error_msg()]);
        exit;
    }
}

// ----------------------------------------------------------
// CALL GEMINI -- try each model until one succeeds
// ----------------------------------------------------------
$models      = array_map('trim', explode(',', GEMINI_MODELS));
$response    = '';
$http_code   = 0;
$curl_err    = '';
$tried_model = '';

foreach ($models as $model) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GEMINI_API_KEY;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    $tried_model = $model;
    if ($http_code === 200) break;
    if ($http_code === 400 || $http_code === 401 || $http_code === 403) break;
}

if ($curl_err) { http_response_code(502); echo json_encode(['error' => 'Network error: ' . $curl_err]); exit; }

if ($http_code !== 200) {
    $err_data = json_decode($response, true);
    $msg = $err_data['error']['message'] ?? ('Gemini error ' . $http_code . ' (tried: ' . $tried_model . ')');
    if (strpos($msg, 'API_KEY') !== false || $http_code === 401) {
        echo json_encode(['error' => 'Invalid Gemini API key. Check proxy.php.']);
    } elseif ($http_code === 404) {
        echo json_encode(['error' => 'All Gemini models returned 404. Models tried: ' . implode(', ', $models)]);
    } else {
        http_response_code($http_code);
        echo json_encode(['error' => $msg, 'model' => $tried_model]);
    }
    exit;
}

// ----------------------------------------------------------
// PARSE RESPONSE
// Fix 5: Thinking models return parts[0] as thought text.
//         Collect only real answer parts (thought !== true).
// ----------------------------------------------------------
$gemini = json_decode($response, true);
$parts  = $gemini['candidates'][0]['content']['parts'] ?? [];
$text   = '';
foreach ($parts as $part) {
    if (!($part['thought'] ?? false) && isset($part['text'])) {
        $text .= $part['text'];
    }
}
if ($text === '') {
    foreach ($parts as $part) { $text .= ($part['text'] ?? ''); }
}

// Strip any markdown Gemini might add
$text = preg_replace('/^```(?:json)?\s*/im', '', $text);
$text = preg_replace('/```\s*$/m', '', $text);
$text = trim($text);

// Extract JSON object if there is preamble text
if ($text !== '' && $text[0] !== '{') {
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }
}

// Validate JSON -- skip in test mode (test sends plain text ping, not JSON)
if (!$test_mode && (!$text || json_decode($text) === null)) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Gemini returned non-JSON. Model: ' . $tried_model . '. First 200 chars: ' . substr($text, 0, 200)
    ]);
    exit;
}

echo json_encode([
    'content' => [['type' => 'text', 'text' => $text]],
    'model'   => $tried_model,
    'role'    => 'assistant'
]);
