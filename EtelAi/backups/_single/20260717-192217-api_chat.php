<?php
// ============================================================
// ETEL AI — Cross-Company Chat Endpoint  v1.0
// Deploy as: EtelAi/api/chat.php
//
// Powers the embeddable etel-ai-widget.js on every Etel company
// site. Verifies the member's Etel-ID session, builds a system
// prompt aware of which companies they belong to (the "brain"),
// and forwards the conversation to the local Ollama model.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Etel-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/secrets.php';
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434');
define('DEFAULT_MODEL', 'tinyllama');

function ok($d)            { echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (\Throwable $e) {
        err('Database error: ' . $e->getMessage(), 500);
    }
    return $pdo;
}
function tbl($t) { return DB_PREFIX . 'etelid_' . $t; }

// ── Resolve the member + their company memberships from the token ──
function memberFromToken(string $token): ?array {
    if (!$token) return null;
    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT s.member_id, m.etel_id, m.fin, m.full_name, m.phone, m.status
         FROM " . tbl('sessions') . " s
         JOIN " . tbl('members') . " m ON m.id = s.member_id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([$token]);
    $member = $stmt->fetch();
    if (!$member || $member['status'] !== 'active') return null;

    $stmt2 = $pdo->prepare("SELECT company_slug, company_name, role_at AS role FROM " . tbl('companies') . " WHERE member_id = ?");
    $stmt2->execute([$member['member_id']]);
    $member['companies'] = $stmt2->fetchAll();
    return $member;
}

function buildSystemPrompt(?array $member, string $project): string {
    $base = "You are ETEL AI, the assistant that runs across all Etel Group companies "
          . "(Etel Gebeya, Etel Delivery, Etel Pay, Etel Ride, Etel-ID, and others). "
          . "You are currently embedded on the \"$project\" page. Be concise, helpful, and multilingual "
          . "(English and Amharic). You cannot yet perform actions in other companies' systems directly — "
          . "if asked to do something in another company (place an order, check a balance, etc.), tell the "
          . "member which company page to go to, and mention you're working on direct integration.";

    if (!$member) {
        return $base . " The visitor is not signed in with Etel-ID — invite them to sign in for personalized help.";
    }

    $name = $member['full_name'] ?? 'there';
    $companies = array_map(fn($c) => ($c['company_name'] ?? $c['company_slug']) . ' (' . ($c['role'] ?: 'Member') . ')', $member['companies'] ?? []);
    $companyList = $companies ? implode(', ', $companies) : 'no linked companies yet';

    return $base . " You are talking to $name, an authenticated Etel-ID member. "
         . "Their company memberships are: $companyList. Use this to personalize your answers — "
         . "e.g. if they ask about an order, and they're linked to Etel Gebeya, assume that's where it is.";
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$token   = $body['token'] ?? $_SERVER['HTTP_X_ETEL_TOKEN'] ?? '';
$project = $body['project'] ?? 'unknown';
$message = trim($body['message'] ?? '');
$history = is_array($body['history'] ?? null) ? $body['history'] : [];
$model   = $body['model'] ?? DEFAULT_MODEL;

if ($message === '') err('message is required');

$member = memberFromToken($token);

$messages = [['role' => 'system', 'content' => buildSystemPrompt($member, $project)]];
foreach (array_slice($history, -10) as $h) {
    if (!empty($h['role']) && isset($h['content'])) {
        $messages[] = ['role' => $h['role'], 'content' => (string)$h['content']];
    }
}
$messages[] = ['role' => 'user', 'content' => $message];

$payload = ['model' => $model, 'messages' => $messages, 'stream' => false];

$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => json_encode($payload),
    'timeout' => 60,
]]);

$resp = @file_get_contents(OLLAMA_URL . '/api/chat', false, $ctx);
if ($resp === false) {
    err('AI engine not responding. Make sure Ollama is running on the server (ollama serve).', 503);
}

$decoded = json_decode($resp, true);
$reply   = $decoded['message']['content'] ?? null;
if ($reply === null) err('AI engine returned an unexpected response.', 503);

ok([
    'reply'       => $reply,
    'member_name' => $member['full_name'] ?? null,
    'companies'   => $member['companies'] ?? [],
]);
