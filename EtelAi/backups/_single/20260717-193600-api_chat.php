<?php
// ============================================================
// ETEL AI - Cross-Company Chat Endpoint  v2.0 (memory-aware)
// Deploy as: EtelAi/api/chat.php  (replaces v1)
//
// v2 changes vs v1:
//  - Real per-member memory (MySQL, keyed to EtelID member id):
//    facts persist across sessions and devices; conversation
//    history is stored server-side, not trusted from the browser.
//  - "remember ..." / "forget ..." commands handled explicitly.
//  - New action=history: widget restores the member's recent
//    conversation on open, so the assistant greets them mid-story
//    instead of as a stranger.
//  - Signed-out visitors still work, just without memory.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/memory.php';

define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434');
define('DEFAULT_MODEL', 'tinyllama');

function ok($d)            { echo json_encode(array('ok' => true,  'data' => $d)); exit; }
function err($m, $c = 400) { http_response_code($c); echo json_encode(array('ok' => false, 'error' => $m)); exit; }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (Exception $e) {
        err('Database error: ' . $e->getMessage(), 500);
    }
    return $pdo;
}
function tbl($t) { return DB_PREFIX . 'etelid_' . $t; }

// ---- Resolve the member + memberships from the EtelID token ----
function memberFromToken($token) {
    if (!$token) return null;
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT s.member_id, m.etel_id, m.fin, m.full_name, m.phone, m.status
         FROM ' . tbl('sessions') . ' s
         JOIN ' . tbl('members') . ' m ON m.id = s.member_id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(array($token));
    $member = $stmt->fetch();
    if (!$member || $member['status'] !== 'active') return null;

    $stmt2 = $pdo->prepare('SELECT company_slug, company_name, role_at AS role FROM ' . tbl('companies') . ' WHERE member_id = ?');
    $stmt2->execute(array($member['member_id']));
    $member['companies'] = $stmt2->fetchAll();
    return $member;
}

function buildSystemPrompt($member, $project, $facts) {
    $base = 'You are ETEL AI, the personal assistant that runs across all Etel Group companies '
          . '(Etel Gebeya, Etel Delivery, Etel Pay, Etel Ride, Etel-ID, and others), built in Hawassa, Ethiopia. '
          . 'You are currently embedded on the "' . $project . '" page. Be warm, concise, and professional. '
          . 'Reply in the language the member writes in - you speak both English and Amharic. '
          . 'You cannot yet perform actions in other companies\' systems directly - '
          . 'if asked to do something in another company (place an order, check a balance, etc.), tell the '
          . 'member which company page to go to, and mention direct integration is coming.';

    if (!$member) {
        return $base . ' The visitor is not signed in with Etel-ID - invite them to sign in for personalized help and memory.';
    }

    $name = isset($member['full_name']) ? $member['full_name'] : 'there';
    $companies = array();
    foreach ((array)$member['companies'] as $c) {
        $cn = isset($c['company_name']) && $c['company_name'] !== '' ? $c['company_name'] : $c['company_slug'];
        $companies[] = $cn . ' (' . (!empty($c['role']) ? $c['role'] : 'Member') . ')';
    }
    $companyList = $companies ? implode(', ', $companies) : 'no linked companies yet';

    $p = $base . ' You are talking to ' . $name . ', an authenticated Etel-ID member. '
       . 'Their company memberships are: ' . $companyList . '. '
       . 'You DO remember this member between conversations - your memory of them is below. '
       . 'If they ask you to remember something, confirm it warmly.';

    $p .= etelai_mem_prompt_block($facts);
    return $p;
}

// ---- Routing ----
$body    = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();
$action  = isset($_GET['action']) ? $_GET['action'] : (isset($body['action']) ? $body['action'] : 'chat');
$token   = isset($body['token']) ? $body['token'] : (isset($_SERVER['HTTP_X_ETEL_TOKEN']) ? $_SERVER['HTTP_X_ETEL_TOKEN'] : '');
if ($token === '' && isset($_GET['token'])) $token = $_GET['token'];
$project = isset($body['project']) ? $body['project'] : (isset($_GET['project']) ? $_GET['project'] : 'unknown');

$member = memberFromToken($token);

// ---- action=history: restore recent conversation for the widget ----
if ($action === 'history') {
    if (!$member) ok(array('messages' => array(), 'signed_in' => false));
    $msgs = etelai_mem_recent(db(), intval($member['member_id']), 14);
    ok(array(
        'messages'    => $msgs,
        'signed_in'   => true,
        'member_name' => $member['full_name'],
    ));
}

// ---- action=chat (default) ----
$message = isset($body['message']) ? trim($body['message']) : '';
$model   = isset($body['model']) ? $body['model'] : DEFAULT_MODEL;
if ($message === '') err('message is required');

$pdo   = db();
$facts = $member ? etelai_mem_get_facts($pdo, intval($member['member_id'])) : array();

// Explicit memory commands are handled directly - deterministic,
// no model round-trip, so "remember X" always actually persists.
if ($member) {
    $cmd = etelai_mem_extract_command($message);
    if ($cmd && $cmd['op'] === 'remember') {
        $keyName = etelai_mem_note_key($cmd['text']);
        $saved = etelai_mem_set_fact($pdo, intval($member['member_id']), $keyName, $cmd['text']);
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message);
        $reply = $saved
            ? 'Got it - I will remember that: "' . $cmd['text'] . '"'
            : 'I could not save that just now (memory tables may not be installed yet). Please try again later.';
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply);
        ok(array('reply' => $reply, 'member_name' => $member['full_name'], 'companies' => $member['companies'], 'memory' => $saved ? 'saved' : 'failed'));
    }
    if ($cmd && $cmd['op'] === 'forget') {
        $keyName = etelai_mem_note_key($cmd['text']);
        $removed = etelai_mem_forget_fact($pdo, intval($member['member_id']), $keyName);
        if (!$removed) {
            // also try matching against stored note values
            foreach ($facts as $fk => $fv) {
                if (stripos($fv, $cmd['text']) !== false) {
                    $removed = etelai_mem_forget_fact($pdo, intval($member['member_id']), $fk);
                    if ($removed) break;
                }
            }
        }
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message);
        $reply = $removed ? 'Done - I have forgotten that.' : 'I could not find that in my memory of you.';
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply);
        ok(array('reply' => $reply, 'member_name' => $member['full_name'], 'companies' => $member['companies'], 'memory' => $removed ? 'forgot' : 'not-found'));
    }
}

// History: server-side for signed-in members (trustworthy, cross-device);
// falls back to browser-supplied history for anonymous visitors.
$histMsgs = array();
if ($member) {
    $histMsgs = etelai_mem_recent($pdo, intval($member['member_id']), 12);
} else {
    $clientHistory = isset($body['history']) && is_array($body['history']) ? $body['history'] : array();
    foreach (array_slice($clientHistory, -10) as $h) {
        if (!empty($h['role']) && isset($h['content'])) {
            $histMsgs[] = array('role' => $h['role'], 'content' => (string)$h['content']);
        }
    }
}

$messages = array(array('role' => 'system', 'content' => buildSystemPrompt($member, $project, $facts)));
foreach ($histMsgs as $h) {
    $messages[] = array('role' => $h['role'], 'content' => $h['content']);
}
$messages[] = array('role' => 'user', 'content' => $message);

$payload = array('model' => $model, 'messages' => $messages, 'stream' => false);

$ctx = stream_context_create(array('http' => array(
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => json_encode($payload),
    'timeout' => 60,
)));

$resp = @file_get_contents(OLLAMA_URL . '/api/chat', false, $ctx);
if ($resp === false) {
    err('AI engine not responding. Make sure Ollama is running on the server (ollama serve).', 503);
}

$decoded = json_decode($resp, true);
$reply   = isset($decoded['message']['content']) ? $decoded['message']['content'] : null;
if ($reply === null) err('AI engine returned an unexpected response.', 503);

// Persist both turns for signed-in members - this is the moment the
// assistant actually starts accumulating a relationship with them.
if ($member) {
    etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message);
    etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply);
}

ok(array(
    'reply'       => $reply,
    'member_name' => $member ? $member['full_name'] : null,
    'companies'   => $member ? $member['companies'] : array(),
));
