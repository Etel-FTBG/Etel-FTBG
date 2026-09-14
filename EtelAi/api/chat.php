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
          . '(Etel Gebeya, Etel Delivery, Etel Pay, Etel Ride, Etel Taxi, Etel-ID, and others), built in Hawassa, Ethiopia. '
          . 'You are currently embedded on the "' . $project . '" page. Be warm, concise, and professional. '
          . 'Reply in the language the member writes in - you speak both English and Amharic. '
          . 'Your whole point is to let a member get real things done just by talking to you, instead of tapping '
          . 'through screens themselves. Follow this exact protocol for anything that takes a real action '
          . '(booking a ride, placing an order, cancelling something): '
          . '1) ASK - if you are missing any detail needed to do the thing (destination, items, vehicle '
          . 'type, payment method, etc.), ask for it conversationally, one or two questions at a time - never '
          . 'guess at a detail that changes price or outcome. '
          . '2) CONFIRM - once you have everything, read the whole plan back to them in plain language BEFORE '
          . 'doing anything real (e.g. "So that\'s a bajaj to Haile Resort, about ETB 85, paid cash - shall I '
          . 'book it?") and wait for a clear yes. A vague or unclear reply is not a yes - ask again plainly. '
          . '3) EXECUTE - once they confirm, call the real tool yourself and report back what actually happened '
          . '(booked, price, driver searching, etc.) - the member should never need to touch anything manually '
          . 'after they say yes. '
          . 'Never skip straight from question 1 to executing - always show the confirm step first, and never '
          . 'execute on a first mention of wanting something before they have confirmed the specifics. '
          . 'For signed-in members, you CAN actually get a real ride fare quote and book a real ETEL Ride - '
          . 'either to a named place near Hawassa (e.g. "Haile Resort") or by distance if there is no specific '
          . 'destination. Pickup is always their current device location automatically - never ask for it. '
          . 'Once they have an active ride, you can also check its live status (driver, vehicle, distance, '
          . 'current fare) or cancel it if they clearly ask to - you never need a ride ID for either of those, '
          . 'it always means their own current ride. '
          . 'You can ALSO place a real Etel Delivery order from the marketplace (food, groceries, pharmacy, etc.) - '
          . 'when the member names a specific item they want, use find_and_quote (NOT search_items) to look it up '
          . 'and get the full price in a single step, then confirm, then place it. '
          . 'The delivery fee is calculated automatically from the member\'s current GPS location (distance-based) - '
          . 'NEVER ask them which zone or neighborhood they are in, that is unnecessary and most people do not know '
          . 'their official zone name anyway. Just call get_order_quote with the items - location is handled '
          . 'automatically in the background. Only if the tool result itself says location is unavailable should '
          . 'you then ask them to name their general area. '
          . 'Recipient defaults to the member themself unless they say it is for someone else. You can '
          . 'also check the status of their most recent order (courier, total, zone) - again, never ask for an '
          . 'order ID or code, it always means their own most recent order. '
          . 'You cannot yet cancel an order, check Etel Pay balances, or do anything in Etel Gebeya or Etel Taxi '
          . 'directly - for those, tell the member which company page to use instead, be honest about the limit, '
          . 'never pretend to do it.';


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

    // The member's own Etel-ID/FIN/status were already fetched in
    // memberFromToken() but never actually reached the prompt before -
    // meaning a simple "what's my Etel ID number" always required a
    // tool call or got guessed at, when the answer was sitting right
    // here the whole time.
    $p = $base . ' You are talking to ' . $name . ', an authenticated Etel-ID member. '
       . 'Their Etel-ID is ' . (isset($member['etel_id']) ? $member['etel_id'] : 'unknown')
       . ', FIN is ' . (isset($member['fin']) ? $member['fin'] : 'unknown')
       . ', and their account status is "' . (isset($member['status']) ? $member['status'] : 'unknown') . '". '
       . 'You can answer questions about these directly without any tool call. '
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

// ---- action=list_providers: safe fields only (no keys, no base URLs) so
// the chat UI can offer a model picker without ever seeing a secret ----
if ($action === 'list_providers') {
    $cfg = etelai_load_providers();
    $out = array();
    foreach ($cfg['providers'] as $p) {
        $out[] = array(
            'id'      => $p['id'],
            'name'    => isset($p['name']) ? $p['name'] : $p['id'],
            'enabled' => !empty($p['enabled']),
        );
    }
    ok(array('providers' => $out));
}

// ---- action=history: restore recent conversation for the widget ----
if ($action === 'history') {
    if (!$member) ok(array('messages' => array(), 'signed_in' => false));
    $pdoH = db();
    $convId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : (isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0);
    if (!$convId) {
        $convId = etelai_conv_most_recent($pdoH, intval($member['member_id']));
    }
    $msgs = etelai_mem_recent($pdoH, intval($member['member_id']), 14, $convId ?: null);
    ok(array(
        'messages'        => $msgs,
        'signed_in'       => true,
        'member_name'     => $member['full_name'],
        'conversation_id' => $convId,
    ));
}

// ---- action=list_conversations: sidebar/history list ----
if ($action === 'list_conversations') {
    if (!$member) err('Not signed in.', 401);
    $pdoL = db();
    etelai_conv_ensure_table($pdoL);
    $stmt = $pdoL->prepare('SELECT id, title, pinned, starred, created_at, updated_at FROM ' . etelai_conv_tbl() . ' WHERE member_id = ? ORDER BY pinned DESC, updated_at DESC LIMIT 100');
    $stmt->execute(array(intval($member['member_id'])));
    ok(array('conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---- action=toggle_pin / action=toggle_star ----
if ($action === 'toggle_pin' || $action === 'toggle_star') {
    if (!$member) err('Not signed in.', 401);
    $convId = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
    $value  = !empty($body['value']) ? 1 : 0;
    if (!$convId) err('conversation_id is required.');
    $field = ($action === 'toggle_pin') ? 'pinned' : 'starred';
    $pdoT = db();
    etelai_conv_ensure_table($pdoT);
    $stmt = $pdoT->prepare('UPDATE ' . etelai_conv_tbl() . " SET {$field} = ? WHERE id = ? AND member_id = ?");
    $stmt->execute(array($value, $convId, intval($member['member_id'])));
    ok(array($field => (bool) $value));
}

// ---- action=rename_conversation ----
if ($action === 'rename_conversation') {
    if (!$member) err('Not signed in.', 401);
    $convId = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
    $title  = isset($body['title']) ? trim($body['title']) : '';
    if (!$convId || $title === '') err('conversation_id and title are required.');
    $pdoR = db();
    etelai_conv_ensure_table($pdoR);
    $stmt = $pdoR->prepare('UPDATE ' . etelai_conv_tbl() . ' SET title = ? WHERE id = ? AND member_id = ?');
    $stmt->execute(array(substr($title, 0, 160), $convId, intval($member['member_id'])));
    ok(array('renamed' => $stmt->rowCount() > 0));
}

// ---- action=delete_conversation ----
if ($action === 'delete_conversation') {
    if (!$member) err('Not signed in.', 401);
    $convId = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
    if (!$convId) err('conversation_id is required.');
    $pdoD = db();
    etelai_conv_ensure_table($pdoD);
    etelai_mem_ensure_conversation_columns($pdoD);
    $check = $pdoD->prepare('SELECT id FROM ' . etelai_conv_tbl() . ' WHERE id = ? AND member_id = ?');
    $check->execute(array($convId, intval($member['member_id'])));
    if (!$check->fetch()) err('Conversation not found.', 404);
    $pdoD->prepare('DELETE FROM ' . etelai_mem_tbl($pdoD, 'messages') . ' WHERE conversation_id = ? AND member_id = ?')->execute(array($convId, intval($member['member_id'])));
    $pdoD->prepare('DELETE FROM ' . etelai_conv_tbl() . ' WHERE id = ? AND member_id = ?')->execute(array($convId, intval($member['member_id'])));
    ok(array('deleted' => true));
}

// ---- action=clear_history: "start fresh chat" button in the widget ----
if ($action === 'clear_history') {
    if (!$member) err('Not signed in.', 401);
    $done = etelai_mem_clear(db(), intval($member['member_id']));
    ok(array('cleared' => $done));
}

// ---- action=chat (default) ----
$message = isset($body['message']) ? trim($body['message']) : '';
if ($message === '') err('message is required');

// Client-detected skill category ('coding' or 'general', see
// SKILL_REGISTRY's cat field in index.html) - used only to prefer a
// tagged OpenRouter key suited to the task; never trusted for
// anything else, so an unrecognized/missing value just means no
// preference (falls through to the provider's normal key order).
$taskTag = isset($body['skill_category']) ? trim((string)$body['skill_category']) : '';
if ($taskTag !== 'coding' && $taskTag !== 'general') $taskTag = '';

$pdo   = db();
$facts = $member ? etelai_mem_get_facts($pdo, intval($member['member_id'])) : array();

// Resolve which conversation this turn belongs to - auto-creates a new
// one (titled from the first message) if the frontend didn't specify
// one, e.g. the "New Chat" button was pressed and this is its first
// message since then.
$convId = null;
if ($member) {
    $convId = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
    if (!$convId) {
        $convId = etelai_conv_create($pdo, intval($member['member_id']), $message);
    } else {
        etelai_conv_touch($pdo, $convId);
    }
}

// Explicit memory commands are handled directly - deterministic,
// no model round-trip, so "remember X" always actually persists.
if ($member) {
    $cmd = etelai_mem_extract_command($message);
    if ($cmd && $cmd['op'] === 'remember') {
        $keyName = etelai_mem_note_key($cmd['text']);
        $saved = etelai_mem_set_fact($pdo, intval($member['member_id']), $keyName, $cmd['text']);
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message, null, null, $convId);
        $reply = $saved
            ? 'Got it - I will remember that: "' . $cmd['text'] . '"'
            : 'I could not save that just now (memory tables may not be installed yet). Please try again later.';
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply, null, null, $convId);
        ok(array('reply' => $reply, 'member_name' => $member['full_name'], 'companies' => $member['companies'], 'memory' => $saved ? 'saved' : 'failed', 'conversation_id' => $convId));
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
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message, null, null, $convId);
        $reply = $removed ? 'Done - I have forgotten that.' : 'I could not find that in my memory of you.';
        etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply, null, null, $convId);
        ok(array('reply' => $reply, 'member_name' => $member['full_name'], 'companies' => $member['companies'], 'memory' => $removed ? 'forgot' : 'not-found', 'conversation_id' => $convId));
    }
}

// ---- Provider resolution: server decides, not the client ----
// Replaces the old per-message model/effort picker entirely. Whoever is
// toggled ON in Settings (api/providers.json) is who answers - in
// priority order if more than one is on. No model choice is read from
// the request body at all anymore.
function etelai_load_providers() {
    $raw = @file_get_contents(__DIR__ . '/providers.json');
    $cfg = $raw ? json_decode($raw, true) : null;
    return (is_array($cfg) && isset($cfg['providers'])) ? $cfg : array('providers' => array(), 'priority' => array());
}
function etelai_active_provider($cfg) {
    $byId = array();
    foreach ($cfg['providers'] as $p) $byId[$p['id']] = $p;
    foreach ($cfg['priority'] as $id) {
        if (isset($byId[$id]) && !empty($byId[$id]['enabled'])) return $byId[$id];
    }
    // No configured priority match - fall back to first enabled provider.
    foreach ($cfg['providers'] as $p) {
        if (!empty($p['enabled'])) return $p;
    }
    return null;
}

// Automatic task-based routing between whichever providers are enabled.
// Gemini is the one with a proven, working tool-calling loop (see
// etelai_call_gemini) - anything that smells transactional (booking,
// ordering, checking/cancelling a ride or order) goes there so the real
// actions keep working reliably. Everything else (advice, explanations,
// general conversation) goes to Kimi K2 when it's on, since it's the
// stronger general-reasoning model and doesn't need to touch any tool.
// Falls back to plain priority order if only one provider is enabled,
// or if neither Gemini nor Kimi is available at all.
function etelai_pick_provider($cfg, $message) {
    $byId = array();
    foreach ($cfg['providers'] as $p) $byId[$p['id']] = $p;
    $geminiOn = isset($byId['gemini']) && !empty($byId['gemini']['enabled']);
    $kimiOn   = isset($byId['kimi'])   && !empty($byId['kimi']['enabled']);

    if ($geminiOn && $kimiOn) {
        $transactional = '/\b(book|cancel|order|track|status|quote|place an? order|ride|deliver|where is my|how much is my|confirm)\b|(ቡክ|ትዕዛዝ|ካንስል|ውሰድልኝ|አዘዝ|ግዛልኝ|ታክሲ|መኪና)/iu';
        if (preg_match($transactional, $message)) return $byId['gemini'];
        return $byId['kimi'];
    }
    return etelai_active_provider($cfg);
}

$providersCfg = etelai_load_providers();
$activeProvider = etelai_pick_provider($providersCfg, $message);

// Optional client preference from the chat UI's model picker (list_providers
// above). Purely a preference, same trust level as $taskTag below - if the
// requested id doesn't exist or isn't enabled, silently ignored and the
// normal automatic pick above stands. This does not reintroduce a
// client-trusted model choice; it only reorders which already-enabled
// server-configured provider goes first.
$preferredProvider = isset($body['preferred_provider']) ? trim((string)$body['preferred_provider']) : '';
if ($preferredProvider !== '') {
    foreach ($providersCfg['providers'] as $p) {
        if ($p['id'] === $preferredProvider && !empty($p['enabled'])) {
            $activeProvider = $p;
            break;
        }
    }
}

if (!$activeProvider) {
    err('No AI provider is turned on. Go to Settings and switch one on.', 503);
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

// ---- Provider dispatch ----
// Every provider function returns a plain reply string, or calls err()
// itself with a specific, honest message - never a silent generic failure.
// Fixed, sensible generation defaults - no user-facing "effort" dial
// anymore, kept internal and simple per the simplification.
function etelai_call_ollama($modelName, $messages) {
    $payload = array('model' => $modelName, 'messages' => $messages, 'stream' => false,
                      'options' => array('num_predict' => 400));
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 60,
    )));
    $resp = @file_get_contents(OLLAMA_URL . '/api/chat', false, $ctx);
    if ($resp === false) {
        err('Ollama is not responding. Make sure "ollama serve" is running on the server.', 503);
    }
    $decoded = json_decode($resp, true);
    $reply = isset($decoded['message']['content']) ? $decoded['message']['content'] : null;
    if ($reply === null) err('Ollama returned an unexpected response.', 503);
    return $reply;
}

// ---- Agent tools: real actions the AI can actually take, not just talk
// about (see api/controllers/AgentController.php on the Delivery side).
// Scoped on purpose to ride-by-distance booking only for now - see that
// file's header for why point-to-point ("take me to X") isn't built yet.
// Only offered to signed-in members (an anonymous visitor has no
// Delivery account to book against) and only when Gemini is the active
// provider (tinyllama doesn't reliably support function calling).
// Converts the Gemini-format tool list below into OpenAI-compatible
// "tools" format (used by Kimi K2 and any other OpenAI-compatible
// provider) - same underlying JSON Schema either way, just wrapped
// differently, so the 8 tool specs only need to be written once.
function etelai_tools_to_openai_format($geminiTools) {
    if (!$geminiTools) return null;
    $out = array();
    foreach ($geminiTools[0]['functionDeclarations'] as $fn) {
        $out[] = array('type' => 'function', 'function' => array(
            'name'        => $fn['name'],
            'description' => $fn['description'],
            'parameters'  => $fn['parameters'],
        ));
    }
    return $out;
}

function etelai_agent_tools() {
    return array(array('functionDeclarations' => array(
        array(
            'name' => 'get_ride_quote',
            'description' => 'Get a fare estimate in ETB for an ETEL Ride. Give EITHER a destination place name (e.g. "Haile Resort", "Yanet Hospital") OR a distance in km if there is no specific destination. Always call this and show the member the price BEFORE ever calling book_ride.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'vehicle_type'     => array('type' => 'string', 'enum' => array('bajaj', 'moto', 'car', 'minibus'), 'description' => 'bajaj (tuk-tuk, cheapest, default), moto, car, or minibus'),
                    'destination_name' => array('type' => 'string', 'description' => 'A place name or address near Hawassa, e.g. "Haile Resort" or "Yanet Hospital". Prefer this whenever the member names a place.'),
                    'distance_km'      => array('type' => 'number', 'description' => 'Only use this if the member gives a distance instead of a named place (e.g. "just drive me around 5km").'),
                ),
            ),
        ),
        array(
            'name' => 'book_ride',
            'description' => 'Actually books a real ETEL Ride and notifies nearby drivers. Only call this AFTER get_ride_quote has shown a price AND the member has clearly said yes/confirm/book it. Never call this on the first mention of wanting a ride. Uses the member\'s current device location automatically as pickup - you do not need to ask for coordinates. Give EITHER destination_name OR distance_km, matching whichever you used for the quote.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'vehicle_type'     => array('type' => 'string', 'enum' => array('bajaj', 'moto', 'car', 'minibus')),
                    'destination_name' => array('type' => 'string', 'description' => 'A place name or address near Hawassa - same one used in the quote.'),
                    'distance_km'      => array('type' => 'number'),
                    'payment_method'   => array('type' => 'string', 'enum' => array('cash', 'telebirr', 'wallet'), 'description' => 'Defaults to cash if the member has not said.'),
                ),
            ),
        ),
        array(
            'name' => 'search_items',
            'description' => 'Search Etel Delivery\'s marketplace (food, groceries, pharmacy, etc.) for something the member wants to order, e.g. "coffee" or "paracetamol". Returns matching items with their item_id, name, price, and which shop/zone they come from. Call this before order_quote so you have real item_ids to use.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'What the member wants, in a few words.'),
                ),
                'required' => array('query'),
            ),
        ),
        array(
            'name' => 'find_and_quote',
            'description' => 'THE PREFERRED way to handle "order X" requests - finds the item(s) by name AND gets the full price quote in ONE call (saves a round-trip vs search_items + get_order_quote separately). Use this whenever the member names specific item(s) they want, e.g. "order Doro wet" or "2 doro wet and a coke". Only fall back to search_items separately if the member is browsing/asking what is available rather than ordering something specific. If the response comes back with needs_clarification (item not found, or multiple different matches), ask the member which one they meant, then call this again with the exact name.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'items' => array(
                        'type' => 'array',
                        'description' => 'The items the member named, in their own words.',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'name'     => array('type' => 'string', 'description' => 'The item name as the member said it, e.g. "doro wet".'),
                                'quantity' => array('type' => 'integer', 'description' => 'Defaults to 1 if not said.'),
                            ),
                        ),
                    ),
                    'to_zone' => array('type' => 'string', 'description' => 'Only set this if the member explicitly names a specific zone themself. Otherwise leave it out - GPS-based distance pricing handles the fee automatically using their current location.'),
                ),
                'required' => array('items'),
            ),
        ),
        array(
            'name' => 'get_order_quote',
            'description' => 'Get a real price for an order (items subtotal + delivery fee + service charge) before placing it. Always call this and show the member the total BEFORE ever calling place_order. Delivery fee is calculated directly from the member\'s current GPS location (distance-based) - always prefer that over asking for a zone name, since most people do not know their official zone name. Only ask them to name a specific area if location genuinely is not available.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'items' => array(
                        'type' => 'array',
                        'description' => 'List of items to order, using real item_id values from search_items.',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'item_id'  => array('type' => 'integer'),
                                'quantity' => array('type' => 'integer'),
                            ),
                        ),
                    ),
                    'to_zone' => array('type' => 'string', 'description' => 'Only set this if the member explicitly names a specific zone themself. Otherwise leave it out entirely - GPS-based distance pricing handles the fee automatically using their current location.'),
                ),
                'required' => array('items'),
            ),
        ),
        array(
            'name' => 'place_order',
            'description' => 'Actually places a real order for delivery. Only call this AFTER get_order_quote has shown a total AND the member has clearly confirmed. Never call this on the first mention of wanting to order something. Recipient defaults to the member themself unless they say it is for someone else. Same rule as get_order_quote: leave to_zone out entirely and let GPS-based distance pricing handle it.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'items'           => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('item_id' => array('type' => 'integer'), 'quantity' => array('type' => 'integer')))),
                    'to_zone'         => array('type' => 'string', 'description' => 'Only set this if the member explicitly names a zone themself - leave out for GPS-based distance pricing.'),
                    'payment_method'  => array('type' => 'string', 'enum' => array('cash', 'telebirr', 'wallet'), 'description' => 'Defaults to cash if the member has not said.'),
                    'recipient_name'  => array('type' => 'string', 'description' => 'Only set this if the member says the order is for someone else.'),
                    'recipient_phone' => array('type' => 'string', 'description' => 'Only set this if the member says the order is for someone else.'),
                ),
                'required' => array('items'),
            ),
        ),
        array(
            'name' => 'get_ride_status',
            'description' => 'Checks on the member\'s current active ETEL Ride, if they have one - status, driver name/phone/rating, vehicle, distance traveled, and current fare. Use this when they ask "where is my driver", "how much is my ride so far", or similar. Takes no destination/vehicle info - it automatically finds their own active ride, never ask them for a ride ID.',
            'parameters' => array('type' => 'object', 'properties' => new stdClass()),
        ),
        array(
            'name' => 'cancel_ride',
            'description' => 'Cancels the member\'s current active ETEL Ride. Only call this after they clearly confirm they want to cancel (not just complaining about wait time). Automatically finds their own active ride - never ask for a ride ID.',
            'parameters' => array('type' => 'object', 'properties' => new stdClass()),
        ),
        array(
            'name' => 'get_order_status',
            'description' => 'Checks on the member\'s most recent Etel Delivery order - status, courier/service-giver info, total, and destination zone. Use this when they ask "where is my order" or "has my food arrived". Automatically finds their own order, never ask for an order ID or code.',
            'parameters' => array('type' => 'object', 'properties' => new stdClass()),
        ),
    )));
}

// Executes a tool call against Delivery's agent connector. $extra carries
// server-known values (the caller's own session token, the browser's
// reported GPS position) that the model itself never sees or supplies -
// keeping location handling out of the model's hands entirely.
//
// IMPORTANT: the names Gemini sees (get_ride_quote, book_ride, etc) are
// deliberately friendlier/shorter than Delivery's own action names
// (agent_ride_quote, agent_book_ride, etc - see boot.php's router and
// AgentController.php). Those two sets of names were NEVER the same
// string, and nothing translated between them - every tool call has
// been sent as e.g. ?action=get_ride_quote, which the router doesn't
// recognize at all, silently failing every AI-driven booking/order
// attempt. This map is the fix.
function etelai_tool_to_backend_action($fnName) {
    $map = array(
        'get_ride_quote'   => 'agent_ride_quote',
        'book_ride'        => 'agent_book_ride',
        'get_ride_status'  => 'agent_ride_status',
        'cancel_ride'      => 'agent_cancel_ride',
        'search_items'     => 'agent_search_items',
        'get_order_quote'  => 'agent_order_quote',
        'find_and_quote'   => 'agent_find_and_quote',
        'place_order'      => 'agent_place_order',
        'get_order_status' => 'agent_order_status',
    );
    return isset($map[$fnName]) ? $map[$fnName] : $fnName;
}

function etelai_call_delivery_agent($action, $args, $extra = array()) {
    if (!defined('DELIVERY_SERVICE_KEY') || DELIVERY_SERVICE_KEY === '') {
        return array('error' => 'Ride booking is not configured yet on this server (DELIVERY_SERVICE_KEY missing in secrets.php).');
    }
    $backendAction = etelai_tool_to_backend_action($action);
    $payload = array_merge($args, $extra);
    $url = DELIVERY_API_BASE . '?action=' . rawurlencode($backendAction);

    // cURL instead of file_get_contents: distinguishable error info
    // (timeout vs connection failure vs bad response) instead of a bare
    // `false`, and a MUCH shorter timeout than the previous 20s. This
    // one function is called for every single tool the AI uses (search,
    // quote, book, order, status, cancel) - a conversation needing 2-3
    // of these back to back could previously add up to 40-60+ seconds,
    // which risks PHP's own script execution limit (commonly 30s on
    // shared hosting) killing the ENTIRE chat.php request mid-flight -
    // showing up as "did not get a final answer in time" or a broken
    // connection, with no useful error to act on. Failing each
    // individual call fast (6s) keeps a multi-tool-call conversation
    // comfortably under that ceiling.
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'X-Etel-Service-Key: ' . DELIVERY_SERVICE_KEY),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ));
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        error_log('[ETEL AI] delivery agent call failed (' . $backendAction . '): ' . $curlErr);
        return array('error' => 'Could not reach the Delivery service right now (please try again in a moment).');
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        error_log('[ETEL AI] delivery agent call (' . $backendAction . ') returned non-JSON, HTTP ' . $httpCode . ': ' . substr($resp, 0, 300));
        return array('error' => 'Delivery service returned an unexpected response.');
    }
    if (empty($decoded['success']) && empty($decoded['ok'])) {
        return array('error' => isset($decoded['message']) ? $decoded['message'] : (isset($decoded['error']) ? $decoded['error'] : 'Request failed.'));
    }
    return isset($decoded['data']) ? $decoded['data'] : $decoded;
}


function etelai_call_gemini($modelName, $apiKey, $messages, $tools = null, $toolExtra = array()) {
    if ($apiKey === '') {
        throw new Exception('This Gemini key is empty.');
    }
    // Gemini's REST API takes system instruction separately and uses
    // "contents" with role user/model (not user/assistant).
    $systemText = '';
    $contents = array();
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $systemText = $m['content']; continue; }
        $contents[] = array(
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => array(array('text' => $m['content'])),
        );
    }

    // Up to 4 round trips. A single tool call only needs 2 (call it, then
    // get the final reply) - but a real order needs search_items THEN
    // get_order_quote before Gemini can write anything back to the
    // member, which is 2 tool calls + 1 final reply = 3 rounds. The old
    // cap of 2 silently executed the second tool call and then gave up
    // without ever asking Gemini to turn that result into an actual
    // reply - exactly the "did not get a final answer in time" bug this
    // fixes. 4 leaves headroom for one more step without letting a
    // confused model loop forever.
    for ($round = 0; $round < 5; $round++) {
        $payload = array('contents' => $contents, 'generationConfig' => array('maxOutputTokens' => 1024, 'temperature' => 0.6));
        if ($systemText !== '') $payload['systemInstruction'] = array('parts' => array(array('text' => $systemText)));
        if ($tools) $payload['tools'] = $tools;

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelName)
             . ':generateContent?key=' . rawurlencode($apiKey);
        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 60,
            'ignore_errors' => true,
        )));
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) throw new Exception('Could not reach Gemini API.');
        $decoded = json_decode($resp, true);

        $parts = isset($decoded['candidates'][0]['content']['parts']) ? $decoded['candidates'][0]['content']['parts'] : null;
        if ($parts === null) {
            $apiMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Gemini returned an unexpected response.';
            throw new Exception($apiMsg);
        }

        $functionCall = null;
        $thoughtSignature = null;
        $textReply = null;
        foreach ($parts as $p) {
            if (isset($p['functionCall'])) {
                $functionCall = $p['functionCall'];
                if (isset($p['thoughtSignature'])) $thoughtSignature = $p['thoughtSignature'];
            }
            if (isset($p['text'])) $textReply = $p['text'];
        }

        if (!$functionCall) {
            if (isset($decoded['usageMetadata'])) {
                $GLOBALS['etelai_last_usage'] = array(
                    'in'  => isset($decoded['usageMetadata']['promptTokenCount']) ? (int) $decoded['usageMetadata']['promptTokenCount'] : null,
                    'out' => isset($decoded['usageMetadata']['candidatesTokenCount']) ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : null,
                );
            }
            return $textReply !== null ? $textReply : 'Sorry, I could not come up with a reply that time.';
        }

        // Model wants to call a tool - run it for real, then loop once
        // more with the result appended so it can write the actual reply.
        $fnName = isset($functionCall['name']) ? $functionCall['name'] : '';
        $fnArgs = isset($functionCall['args']) && is_array($functionCall['args']) ? $functionCall['args'] : array();
        $toolResult = etelai_call_delivery_agent($fnName, $fnArgs, $toolExtra);

        // thoughtSignature must be echoed back on the SAME functionCall
        // part in the next turn's history, or Gemini's thinking models
        // reject the follow-up with "Function call is missing a
        // thought_signature". It's opaque - we never inspect it, just
        // pass it straight through.
        $modelPart = array('functionCall' => $functionCall);
        if ($thoughtSignature !== null) $modelPart['thoughtSignature'] = $thoughtSignature;

        $contents[] = array('role' => 'model', 'parts' => array($modelPart));
        $contents[] = array('role' => 'user', 'parts' => array(array('functionResponse' => array(
            'name' => $fnName,
            'response' => $toolResult,
        ))));
    }
    return 'I tried to take an action but did not get a final answer in time - please try again.';
}

// Kimi K2 (Moonshot AI) - OpenAI-compatible chat completions API.
// Key/base now come from providers.json (multiple keys supported, see
// etelai_try_provider_keys) instead of a single hardcoded constant.
// Some OpenAI-compatible proxies (OmniRoute confirmed) stream Server-Sent
// Events - "data: {...}" lines ending in "data: [DONE]" - even when the
// request explicitly sends "stream": false. Reconstructs the normal
// single {choices:[{message:{...}}]} shape from those chunks (including
// best-effort reassembly of streamed tool_calls, which arrive as
// per-index name/arguments fragments across multiple chunks) so the
// rest of this function doesn't need to know or care whether the
// response arrived as one object or a stream.
function etelai_reconstruct_from_sse($raw) {
    $content = '';
    $toolCallsByIndex = array();
    $usage = null;
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'data:') !== 0) continue; // skip blanks + ": comment" lines
        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') continue;
        $chunk = json_decode($payload, true);
        if (!is_array($chunk)) continue;
        if (isset($chunk['usage'])) $usage = $chunk['usage'];
        $delta = isset($chunk['choices'][0]['delta']) ? $chunk['choices'][0]['delta'] : array();
        if (isset($delta['content'])) $content .= $delta['content'];
        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $i = isset($tc['index']) ? $tc['index'] : 0;
                if (!isset($toolCallsByIndex[$i])) {
                    $toolCallsByIndex[$i] = array('id' => isset($tc['id']) ? $tc['id'] : ('call_' . $i), 'type' => 'function', 'function' => array('name' => '', 'arguments' => ''));
                }
                if (isset($tc['id'])) $toolCallsByIndex[$i]['id'] = $tc['id'];
                if (isset($tc['function']['name'])) $toolCallsByIndex[$i]['function']['name'] .= $tc['function']['name'];
                if (isset($tc['function']['arguments'])) $toolCallsByIndex[$i]['function']['arguments'] .= $tc['function']['arguments'];
            }
        }
    }
    $message = array('role' => 'assistant', 'content' => $content !== '' ? $content : null);
    if (!empty($toolCallsByIndex)) {
        ksort($toolCallsByIndex);
        $message['tool_calls'] = array_values($toolCallsByIndex);
    }
    $out = array('choices' => array(array('message' => $message)));
    if ($usage) $out['usage'] = $usage;
    return $out;
}

function etelai_call_kimi($modelName, $apiKey, $apiBase, $messages, $tools = null, $toolExtra = array(), $providerLabel = 'Kimi') {
    if ($apiKey === '') {
        throw new Exception("This $providerLabel key is empty.");
    }
    $base = $apiBase !== '' ? $apiBase : 'https://api.moonshot.ai/v1';
    $openaiTools = etelai_tools_to_openai_format($tools);

    // OpenAI-style messages are used as-is (system/user/assistant all
    // supported directly, unlike Gemini's separate systemInstruction).
    $msgs = $messages;

    // Same round-trip budget and same reasoning as Gemini's loop above -
    // a real order needs 2 tool calls (search_items, get_order_quote)
    // before the model can write back a final reply.
    for ($round = 0; $round < 5; $round++) {
        $payload = array('model' => $modelName, 'messages' => $msgs, 'temperature' => 0.6, 'max_tokens' => 1024);
        if ($openaiTools) $payload['tools'] = $openaiTools;

        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
            'content' => json_encode($payload),
            'timeout' => 60,
            'ignore_errors' => true,
        )));
        $resp = @file_get_contents($base . '/chat/completions', false, $ctx);
        if ($resp === false) throw new Exception("Could not reach $providerLabel API (tried: $base).");
        $decoded = json_decode($resp, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0])) {
            if (strpos($resp, 'data:') !== false) {
                $decoded = etelai_reconstruct_from_sse($resp);
            }
        }

        $choice = isset($decoded['choices'][0]) ? $decoded['choices'][0] : null;
        if ($choice === null) {
            $apiMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : "$providerLabel returned an unexpected response.";
            throw new Exception($apiMsg);
        }
        $msg = $choice['message'];
        $toolCalls = isset($msg['tool_calls']) && is_array($msg['tool_calls']) ? $msg['tool_calls'] : null;

        if (!$toolCalls) {
            if (isset($decoded['usage'])) {
                $GLOBALS['etelai_last_usage'] = array(
                    'in'  => isset($decoded['usage']['prompt_tokens']) ? (int) $decoded['usage']['prompt_tokens'] : null,
                    'out' => isset($decoded['usage']['completion_tokens']) ? (int) $decoded['usage']['completion_tokens'] : null,
                );
            }
            return isset($msg['content']) && $msg['content'] !== null ? $msg['content'] : 'Sorry, I could not come up with a reply that time.';
        }

        // Kimi can technically request several tool calls in one turn -
        // execute each for real, then loop with all their results appended
        // so it can write the actual reply (or chain into another call).
        $msgs[] = array('role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls);
        foreach ($toolCalls as $tc) {
            $fnName = isset($tc['function']['name']) ? $tc['function']['name'] : '';
            $fnArgs = array();
            if (isset($tc['function']['arguments'])) {
                $decodedArgs = json_decode($tc['function']['arguments'], true);
                if (is_array($decodedArgs)) $fnArgs = $decodedArgs;
            }
            $toolResult = etelai_call_delivery_agent($fnName, $fnArgs, $toolExtra);
            $msgs[] = array(
                'role'         => 'tool',
                'tool_call_id' => isset($tc['id']) ? $tc['id'] : '',
                'content'      => json_encode($toolResult),
            );
        }
    }
    return 'I tried to take an action but did not get a final answer in time - please try again.';
}

// Recognizes the common ways a provider says "this key is out of quota /
// credit / rate-limited" across Gemini and OpenAI-compatible (Kimi)
// error text, so a bad key gets a long cooldown (quotas usually reset
// daily/monthly) while a genuine outage or network hiccup only gets a
// short one and gets retried again soon.
function etelai_is_quota_error($msg) {
    $msg = strtolower($msg);
    $signals = array('quota', 'insufficient balance', 'rate limit', 'resource_exhausted',
                      'exceeded', 'billing', 'suspended', '429');
    foreach ($signals as $s) {
        if (strpos($msg, $s) !== false) return true;
    }
    return false;
}

// Tries every enabled, not-in-cooldown key for one provider in order,
// persisting cooldowns/errors back to providers.json as it goes so the
// admin dashboard shows real, current status. Returns the reply on the
// first key that works; throws if every key for this provider failed
// (letting the caller fall through to the next provider entirely).
// The generic OpenAI-compatible caller (etelai_call_kimi) defaults an
// empty base URL to Moonshot's endpoint - correct for Kimi, wrong for
// any other provider sharing that same caller if an admin ever leaves
// the base field blank when adding a key. Known providers get their
// real default here instead of silently inheriting Kimi's; anything
// else (a genuinely custom OpenAI-compatible endpoint, e.g. OmniRoute)
// has no safe default and must have its base URL set explicitly.
function etelai_provider_default_base($providerId) {
    if ($providerId === 'openrouter') return 'https://openrouter.ai/api/v1';
    return '';
}

// $preferredTag lets a caller ask "try a key tagged for this task first"
// (e.g. a key tagged 'coding' for the Debugger/Code Generator/Code
// Reviewer/SQL Generator skills) without making a tag a hard
// requirement - untagged keys, and keys with a different tag, are
// still tried afterward in their normal order if no tagged key works
// or none exists. This is what lets one provider (e.g. OpenRouter)
// host several free models, each suited to a different kind of task,
// instead of picking one model for everything.
function etelai_try_provider_keys(&$providersCfg, $providerId, $messages, $tools, $toolExtra, $preferredTag = null) {
    $now = time();
    $lastError = null;
    $anyTried = false;
    // Temporary diagnostic trail - records what this function actually saw
    // for every provider block matching $providerId, so a "no keys" error
    // can be traced without live server file access. Remove once the
    // OpenRouter routing issue reported after PR #90/#91 is resolved.
    $diag = array();

    foreach ($providersCfg['providers'] as &$prov) {
        if ($prov['id'] !== $providerId) continue;
        $diag[] = 'provider(enabled=' . (!empty($prov['enabled']) ? '1' : '0') . ',keys=' . count($prov['keys']) . ')';
        foreach ($prov['keys'] as $kk) {
            $diag[] = (isset($kk['id']) ? $kk['id'] : '?') . ':enabled=' . (!empty($kk['enabled']) ? '1' : '0')
                . ',cooldown=' . (isset($kk['cooldown_until']) ? $kk['cooldown_until'] : 'none');
        }
        $orderedKeys = &$prov['keys'];
        if ($preferredTag !== null && $preferredTag !== '') {
            $tagged = array();
            $untagged = array();
            foreach ($prov['keys'] as $idx => $k) {
                if (isset($k['tag']) && $k['tag'] === $preferredTag) $tagged[] = $idx;
                else $untagged[] = $idx;
            }
            $orderedIdx = array_merge($tagged, $untagged);
            $orderedKeys = array();
            foreach ($orderedIdx as $idx) $orderedKeys[] = &$prov['keys'][$idx];
        }
        $diag[] = 'preferredTag=' . json_encode($preferredTag) . ',orderedKeys=' . count($orderedKeys);
        foreach ($orderedKeys as &$k) {
            $diag[] = 'check ' . (isset($k['id']) ? $k['id'] : '?') . ': enabled_raw=' . json_encode(isset($k['enabled']) ? $k['enabled'] : null)
                . ' empty_enabled=' . (empty($k['enabled']) ? '1' : '0')
                . ' cooldown_raw=' . json_encode(isset($k['cooldown_until']) ? $k['cooldown_until'] : null);
            if (empty($k['enabled'])) continue;
            if (!empty($k['cooldown_until']) && strtotime($k['cooldown_until']) > $now) continue;
            $anyTried = true;
            $keyId = $k['id'];
            try {
                $keyBase = !empty($k['base']) ? $k['base'] : etelai_provider_default_base($providerId);
                $reply = ($providerId === 'gemini')
                    ? etelai_call_gemini($k['model'], $k['key'], $messages, $tools, $toolExtra)
                    : etelai_call_kimi($k['model'], $k['key'], $keyBase, $messages, $tools, $toolExtra, $prov['name']);
                // Success - clear any past trouble on this key. Targeted
                // update against a FRESH read of the file (see
                // etelai_update_key_status below) so this can never
                // clobber a model/label edit made via the admin UI in
                // between this request starting and finishing - this
                // was silently reverting model changes before.
                etelai_update_key_status($providerId, $keyId, array(
                    'cooldown_until' => null, 'last_error' => null, 'last_used' => gmdate('Y-m-d H:i:s'),
                ));
                return $reply;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $isQuota = etelai_is_quota_error($lastError);
                // Quota/billing errors: long cooldown (24h) since free
                // tiers typically reset daily and hammering it every
                // request wastes calls for nothing. Anything else
                // (network blip, bad response): short cooldown (5 min)
                // since it might just be transient.
                $cooldownSeconds = $isQuota ? 86400 : 300;
                etelai_update_key_status($providerId, $keyId, array(
                    'cooldown_until' => gmdate('Y-m-d H:i:s', $now + $cooldownSeconds), 'last_error' => $lastError,
                ));
                error_log('[ETEL AI] ' . $providerId . ' key "' . $k['label'] . '" failed (' . ($isQuota ? 'quota' : 'error') . '): ' . $lastError);
            }
        }
        unset($k);
    }
    unset($prov);

    if (!$anyTried) throw new Exception('No enabled, available keys for ' . $providerId . '. [diag: ' . ($diag ? implode(' | ', $diag) : 'no matching provider block found') . ']');
    throw new Exception($lastError ?: ('All ' . $providerId . ' keys failed.'));
}

// Re-reads providers.json fresh (not the possibly-stale copy loaded at
// the start of this request) and updates ONLY the given fields on ONE
// specific key, leaving everything else - including a model/label
// change made via the admin UI while this request was in flight -
// completely untouched. This is what fixes the race condition where a
// chat request's own status write-back was silently reverting an
// admin's model edit made moments earlier.
function etelai_update_key_status($providerId, $keyId, $fields) {
    $path = __DIR__ . '/providers.json';
    $raw = @file_get_contents($path);
    $cfg = $raw ? json_decode($raw, true) : null;
    if (!is_array($cfg)) return;
    foreach ($cfg['providers'] as &$p) {
        if ($p['id'] !== $providerId) continue;
        foreach ($p['keys'] as &$k) {
            if ($k['id'] !== $keyId) continue;
            foreach ($fields as $fk => $fv) $k[$fk] = $fv;
            break;
        }
        unset($k);
        break;
    }
    unset($p);
    @file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT));
}

if ($activeProvider['id'] === 'gemini' || $activeProvider['id'] === 'kimi') {
    // Tools only offered to signed-in members (with a real Delivery
    // account to book against) and only pass the pickup GPS position the
    // browser reported - the model itself never sees or requests it.
    $toolsForThisCall = null;
    $toolExtra = array();
    if ($member) {
        $toolsForThisCall = etelai_agent_tools();
        $toolExtra = array(
            'etel_sso_token' => $token,
            'pickup_lat'     => isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : null,
            'pickup_lng'     => isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : null,
        );
    }

    // Two layers of failover: within a provider, try each of its keys in
    // turn (etelai_try_provider_keys); across providers, if EVERY key for
    // the auto-picked provider is exhausted/broken, fall through to the
    // other enabled cloud provider entirely - same reasoning as before,
    // the member doesn't care which model or which key answers, only
    // that the assistant actually works.
    $tryOrder = array($activeProvider['id']);
    $otherId = $activeProvider['id'] === 'gemini' ? 'kimi' : 'gemini';
    $byId = array();
    foreach ($providersCfg['providers'] as $p) $byId[$p['id']] = $p;
    if (isset($byId[$otherId]) && !empty($byId[$otherId]['enabled'])) $tryOrder[] = $otherId;

    // Last-resort fallback only - OpenRouter is deliberately not part of
    // the transactional gemini/kimi routing split above, and only enters
    // the chain here, after both of those have already been tried and
    // failed. Uses the same generic OpenAI-compatible caller (see
    // etelai_try_provider_keys) - no code path is OpenRouter-specific.
    if (isset($byId['openrouter']) && !empty($byId['openrouter']['enabled']) && !in_array('openrouter', $tryOrder, true)) {
        $tryOrder[] = 'openrouter';
    }

    $reply = null;
    $lastError = null;
    foreach ($tryOrder as $i => $pid) {
        try {
            $reply = etelai_try_provider_keys($providersCfg, $pid, $messages, $toolsForThisCall, $toolExtra, $taskTag);
            break;
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            error_log('[ETEL AI] all ' . $pid . ' keys failed' . ($i + 1 < count($tryOrder) ? ' - trying next provider' : '') . ': ' . $lastError);
        }
    }
    if ($reply === null) err($lastError ?: 'All configured AI providers failed.', 503);
} elseif ($activeProvider['id'] === 'ollama') {
    $ollamaModel = !empty($activeProvider['keys'][0]['model']) ? $activeProvider['keys'][0]['model'] : 'tinyllama';
    $reply = etelai_call_ollama($ollamaModel, $messages);
} else {
    // Generic OpenAI-compatible provider (OmniRoute, or any future one) -
    // NOT the same thing as literal Ollama. This used to fall into the
    // Ollama branch above by default, so OmniRoute (or anything else
    // added later) silently tried to reach a local Ollama server instead
    // of its own configured base URL/key, and failed with a confusing
    // "Ollama is not responding" error regardless of what was actually
    // configured. etelai_try_provider_keys() already calls
    // etelai_call_kimi() (the generic OpenAI-compatible caller) for any
    // provider id that isn't literally 'gemini' - same key rotation and
    // cooldown handling gemini/kimi get above.
    try {
        $reply = etelai_try_provider_keys($providersCfg, $activeProvider['id'], $messages, null, array(), $taskTag);
    } catch (Exception $e) {
        err($e->getMessage(), 503);
    }
}

// Persist both turns for signed-in members - this is the moment the
// assistant actually starts accumulating a relationship with them.
if ($member) {
    $usage = isset($GLOBALS['etelai_last_usage']) ? $GLOBALS['etelai_last_usage'] : array();
    etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'user', $message, null, null, $convId);
    etelai_mem_save_turn($pdo, intval($member['member_id']), $project, 'assistant', $reply,
        isset($usage['in']) ? $usage['in'] : null, isset($usage['out']) ? $usage['out'] : null, $convId);
}

ok(array(
    'reply'           => $reply,
    'member_name'     => $member ? $member['full_name'] : null,
    'companies'       => $member ? $member['companies'] : array(),
    'provider'        => $activeProvider['name'],
    'provider_id'     => $activeProvider['id'],
    // Real usage from the provider's own response when it reports one
    // (Gemini and the OpenAI-compatible providers both do) - not an
    // estimate, so the chat UI can show an honest token count.
    'usage'           => isset($usage) ? $usage : array(),
    'conversation_id' => $convId,
));
