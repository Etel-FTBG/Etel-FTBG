<?php
// ─────────────────────────────────────────────────────────────
// ETEL OS — Web Search Proxy  v1.0
// Keeps API keys server-side; normalises results from multiple providers.
// Providers (in priority order):
//   1. Serper.dev  — Google results, 2 500 free/month  (SERPER_API_KEY env var)
//   2. DuckDuckGo  — Instant Answer + abstracts        (no key)
//   3. Wikipedia   — Factual fallback                  (no key)
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$query = trim($_GET['q'] ?? $_POST['q'] ?? '');
if (!$query) {
    echo json_encode(['ok' => false, 'error' => 'No query']); exit;
}
if (strlen($query) > 400) {
    echo json_encode(['ok' => false, 'error' => 'Query too long']); exit;
}

// ── 1. SERPER.DEV (Google results — best quality) ─────────────
$serperKey = getenv('SERPER_API_KEY');
if ($serperKey) {
    $results = search_serper($query, $serperKey);
    if ($results) {
        echo json_encode(['ok' => true, 'results' => $results, 'source' => 'google', 'query' => $query]);
        exit;
    }
}

// ── 2. DUCKDUCKGO Instant Answer ─────────────────────────────
$results = search_ddg($query);

// ── 3. WIKIPEDIA supplemental results ────────────────────────
if (count($results) < 3) {
    $results = array_merge($results, search_wikipedia($query));
}

echo json_encode(['ok' => true, 'results' => array_slice($results, 0, 6), 'source' => 'ddg+wiki', 'query' => $query]);

// ─────────────────────────────────────────────────────────────
function search_serper(string $query, string $key): ?array {
    $payload = json_encode(['q' => $query, 'num' => 6, 'gl' => 'et']);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-API-KEY: {$key}\r\n",
        'content' => $payload,
        'timeout' => 6,
    ]]);
    $resp = @file_get_contents('https://google.serper.dev/search', false, $ctx);
    if (!$resp) return null;
    $d = json_decode($resp, true);
    if (empty($d['organic'])) return null;
    $out = [];
    foreach (array_slice($d['organic'], 0, 6) as $r) {
        $out[] = [
            'title'   => $r['title']   ?? '',
            'url'     => $r['link']    ?? '',
            'snippet' => $r['snippet'] ?? '',
        ];
    }
    // prepend answer box if present
    if (!empty($d['answerBox']['answer'])) {
        array_unshift($out, [
            'title'   => $d['answerBox']['title']  ?? 'Quick Answer',
            'url'     => $out[0]['url'] ?? '',
            'snippet' => $d['answerBox']['answer'],
        ]);
    }
    return $out ?: null;
}

function search_ddg(string $query): array {
    $q   = urlencode($query);
    $url = "https://api.duckduckgo.com/?q={$q}&format=json&no_html=1&skip_disambig=1&t=etelai";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: ETEL-OS/3.1\r\n"]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    if (!$d) return [];
    $out = [];
    if (!empty($d['AbstractText'])) {
        $out[] = [
            'title'   => $d['Heading'] ?? $query,
            'url'     => $d['AbstractURL'] ?? '',
            'snippet' => $d['AbstractText'],
        ];
    }
    foreach (array_slice($d['RelatedTopics'] ?? [], 0, 4) as $t) {
        if (!empty($t['Text']) && !empty($t['FirstURL'])) {
            $out[] = [
                'title'   => substr($t['Text'], 0, 80),
                'url'     => $t['FirstURL'],
                'snippet' => $t['Text'],
            ];
        }
    }
    return $out;
}

function search_wikipedia(string $query): array {
    $q   = urlencode($query);
    $url = "https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch={$q}&format=json&srlimit=4&utf8=1";
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    $out = [];
    foreach ($d['query']['search'] ?? [] as $r) {
        $title = $r['title'] ?? '';
        $out[] = [
            'title'   => $title,
            'url'     => 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $title),
            'snippet' => strip_tags($r['snippet'] ?? ''),
        ];
    }
    return $out;
}
