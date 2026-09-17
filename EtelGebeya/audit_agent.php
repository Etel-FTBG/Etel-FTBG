<?php
/**
 * ETEL self-audit agent (server-side, one-click)
 *
 * Calls Gemini/Claude/Kimi/OpenAI directly using the keys already
 * configured in EtelGebeya/audit/config.php - this file reads its own
 * files straight off disk, builds the same file-tree + content payload
 * the browser tool builds, and posts it straight to the provider's real
 * API.
 *
 * Previously this posted to a shared proxy (EtelGebeya/public/proxy.php
 * / audit/etelproxy.php) over HTTPS instead. That proxy kept returning
 * "Unauthorized" in production even with the exact same AUDIT_SECRET
 * value confirmed byte-for-byte identical in both audit/config.php and
 * .env's ETEL_AUDIT_SECRET - the same unresolved shared-secret failure
 * mode already hit (and worked around the same way) in
 * delivery/api/audit_agent.php. Switched to calling providers directly,
 * which removes that whole cross-hop failure mode entirely.
 *
 * This is a template: copy it into another project's api/ (or
 * equivalent) folder - it has no dependency on this specific project's
 * other files besides whatever calls etel_run_self_audit() with an
 * admin-gated action.
 */

if (!function_exists('etel_run_self_audit')) {

// The real keys live in audit/config.php (server-only, gitignored) -
// same file the old proxy read from, so nothing needs reconfiguring.
if (!defined('GEMINI_API_KEY')) {
    $__etel_audit_cfg = __DIR__ . '/audit/config.php';
    if (file_exists($__etel_audit_cfg)) require_once $__etel_audit_cfg;
}

// Fallback constants if audit/config.php doesn't define these (keeps
// this file safe to copy into a project that hasn't set up that file).
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'YOUR_GEMINI_KEY_HERE');
if (!defined('CLAUDE_API_KEY')) define('CLAUDE_API_KEY', 'YOUR_CLAUDE_KEY_HERE');
if (!defined('KIMI_API_KEY'))   define('KIMI_API_KEY',   'YOUR_KIMI_KEY_HERE');
if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', 'YOUR_OPENAI_KEY_HERE');

define('ETEL_AUDIT_GEMINI_MODELS', 'gemini-3.5-flash,gemini-3.1-flash-lite,gemini-3.1-pro');
define('ETEL_AUDIT_CLAUDE_MODEL',  'claude-sonnet-4-6');
define('ETEL_AUDIT_KIMI_MODEL',    'moonshot-v1-32k');
define('ETEL_AUDIT_OPENAI_MODEL',  'gpt-4o');

// Same extension allowlist as the browser tool's TEXT_EXTS, so results
// are consistent whether a project is audited via upload or via this
// button.
define('ETEL_AUDIT_TEXT_EXTS', 'php,js,ts,jsx,tsx,css,scss,sass,less,html,htm,xml,json,sql,md,txt,env,htaccess,yaml,yml,ini,conf,sh,py,rb,java,cs,go,rs,toml,vue,svelte,config,gitignore,lock');

// Directories never worth sending to the AI (dependencies, build
// output, VCS internals, logs/uploads that are pure runtime data, and
// this tool's own workspace) - walking into these wastes the character
// budget on noise instead of actual project code.
define('ETEL_AUDIT_EXCLUDE_DIRS', '.git,.claude,.vscode,.gstack,node_modules,vendor,storage,uploads,backups,cache,logs,dist,build,.svn');

// Keep well under the old proxy's hard 45000-char server-side cap so
// nothing gets silently cut off mid-request; leaves room for the
// file-tree/stats header the same way the browser tool's 58000
// client-side cap does.
define('ETEL_AUDIT_MAX_CONTENT', 42000);

/* Same system prompt the browser tool sends, verbatim, so the two
   report formats line up (same category keys, same JSON schema, same
   grading rubric) and either one can be reviewed the same way. */
function etel_audit_system_prompt() {
    return <<<PROMPT
You are EtelAudit Pro, the world's most advanced code review AI for Etel Group, Hawassa, Ethiopia. You analyze projects against global 2026 standards while understanding the Ethiopian context: mobile-first users, Telebirr/CBE Birr payments, Amharic/bilingual requirements, low bandwidth conditions, shared cPanel hosting with ModSecurity WAF constraints.

== CRITICAL PHP SECURITY ANALYSIS RULES (read carefully before scoring) ==

RULE 1 - SQL INJECTION (PDO parameterized queries are SAFE):
The following patterns are SAFE and must NOT be flagged as SQL injection:
  SAFE: \$db->fetch("SELECT ... WHERE email = ?", [\$email])
  SAFE: \$db->prepare("SELECT ... WHERE id = ?")->execute([\$id])
  SAFE: \$where = '1=1'; \$where .= ' AND status=?'; \$params[] = \$filter; \$db->query("SELECT ... WHERE \$where", \$params)
  -- The last pattern (dynamic \$where with separate \$params array) is standard PDO safe pattern. \$where only contains SQL fragments (column names, operators), ALL user values go through \$params as bound parameters.
  SAFE: LIMIT {\$this->perPage} OFFSET {\$this->offset(\$page)} when perPage is typed int and offset() returns int
UNSAFE (real injection): "SELECT ... WHERE email = '\$email'" or "SELECT ... WHERE id = " . \$id . " AND" (user value directly in string, no ? placeholder)
Only flag SQL injection if you can quote the EXACT line showing user input concatenated into the query string WITHOUT a ? placeholder.

RULE 2 - HTTPS ENFORCEMENT:
If .htaccess contains: RewriteCond %{HTTPS} off + RewriteRule ^ https:// this IS valid HTTPS enforcement. Do NOT flag as missing.
If .htaccess contains: Header always set Strict-Transport-Security this IS HSTS. Do NOT flag as missing.
Only flag HTTPS as missing if NEITHER of these patterns exist in the .htaccess file provided.

RULE 3 - NON-ASCII IN PHP FILES:
Only flag non-ASCII as WAF risk if the bytes appear in PHP code paths (string literals, variable values, output). Non-ASCII in PHP comments (// ... or /* ... */) is safe and must NOT be flagged. Count only files where non-ASCII appears outside comments. Report the exact count you verify -- do not guess "57 files" if you cannot confirm it.

RULE 4 - SESSION SECURITY:
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax']) is CORRECT and secure. Flag as passed, not as issue.

RULE 5 - RATE LIMITING:
If you see a login_attempts table query that blocks after N failed attempts, this IS brute force protection. Credit it as a security strength.

== WAF RULE (still applies to runtime code) ==
PHP files containing non-ASCII bytes IN CODE (not comments) get corrupted by ModSecurity WAF on shared cPanel hosting. Flag these as CRITICAL only if found in actual code paths.

Respond ONLY with a single valid JSON object. No markdown, no code fences, no explanation. Just raw JSON starting with { and ending with }.

Required JSON structure (ALL fields required):
{
  "project_name": "Detected project name",
  "tech_stack": ["PHP 8","MySQL","JavaScript","CSS"],
  "overall_score": 82,
  "overall_grade": "B",
  "executive_summary": "Professional 2-3 sentence overview of the project quality, what it does, and top concerns.",
  "categories": {
    "security": {
      "score": 75,
      "grade": "C+",
      "highlights": ["bcrypt password hashing implemented","CSRF tokens with hash_equals() timing-safe check"],
      "issues": [
        {"severity": "critical", "title": "Real SQL Injection", "detail": "file.php line N: \$db->query(\"SELECT ... WHERE id = \" . \$userId) -- user value directly concatenated, no ? placeholder"}
      ],
      "passed": ["password_hash() with bcrypt","Session cookies httponly + secure + samesite","CSRF token on every form","session_regenerate_id() on login","Rate limiting on login attempts"]
    },
    "code_quality": {"score": 80,"grade": "B","highlights": [],"issues": [{"severity": "medium","title": "Issue title","detail": "Specific details with file and line"}],"passed": []},
    "frontend_design": {"score": 85,"grade": "B+","highlights": [],"issues": [],"passed": []},
    "backend_architecture": {"score": 78,"grade": "C+","highlights": [],"issues": [],"passed": []},
    "performance": {"score": 70,"grade": "C","highlights": [],"issues": [],"passed": []},
    "ethiopian_market": {"score": 90,"grade": "A-","highlights": [],"issues": [],"passed": []},
    "premium_levelness": {"score": 75,"grade": "C+","highlights": [],"issues": [],"passed": []},
    "deployment_readiness": {"score": 80,"grade": "B","highlights": [],"issues": [],"passed": []}
  },
  "bugs": [
    {
      "file": "exact/file/path.php",
      "line": "45",
      "severity": "critical",
      "title": "Exact bug title",
      "description": "Quote the exact problematic code. Explain why it is a bug.",
      "fix": "Show the corrected code."
    }
  ],
  "recommendations": [
    {
      "priority": "immediate",
      "category": "security",
      "title": "Recommendation title",
      "description": "Specific actionable description referencing actual files.",
      "effort": "medium"
    }
  ],
  "stats": {
    "total_files": 0,
    "total_lines": 0,
    "php_files": 0,
    "js_files": 0,
    "css_files": 0,
    "html_files": 0,
    "sql_files": 0
  },
  "strengths": ["List only things confirmed present in the uploaded code"],
  "critical_issues": ["List only REAL verified issues with file:line references"]
}

Severity: critical (site-breaking or severe security hole), high (significant issue needing urgent fix), medium (should be improved), low (minor/cosmetic).
Priority: immediate (fix before any deploy), high (fix within 1 week), medium (fix this sprint), low (backlog).
Effort: small (under 2 hours), medium (half day), large (multiple days).
Grades: S (95-100), A+ (92-94), A (88-91), A- (85-87), B+ (82-84), B (78-81), B- (75-77), C+ (72-74), C (68-71), C- (65-67), D (50-64), F (under 50).
ACCURACY RULE: Only report bugs and issues you can directly quote from the uploaded code with exact file path and line number. If you cannot find the exact line, do not report it. Do not hallucinate issues based on patterns -- verify each finding in the actual uploaded content.
PROMPT;
}

/* Walks $root and returns [relativePath => contents] for every text file,
   skipping the excluded directories. Mirrors the browser tool's JSZip
   loop closely enough that results are comparable. */
function etel_audit_collect_files($root) {
    $textExts   = array_flip(explode(',', ETEL_AUDIT_TEXT_EXTS));
    $excludeDir = array_flip(explode(',', ETEL_AUDIT_EXCLUDE_DIRS));
    $files = array();

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $rel = substr($entry->getPathname(), strlen($root) + 1);
        if ($entry->isDir()) {
            if (isset($excludeDir[$entry->getFilename()])) {
                $it->getFlags(); // no-op, kept for clarity that dirs are pruned below
            }
            continue;
        }
        // Prune excluded directories cheaply by checking any path segment.
        $skip = false;
        foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
            if (isset($excludeDir[$seg])) { $skip = true; break; }
        }
        if ($skip) continue;

        $ext = strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
        $base = strtolower($entry->getFilename());
        // .env / .htaccess have no "extension" by pathinfo's rules - match by filename too.
        if (!isset($textExts[$ext]) && $base !== '.env' && $base !== '.htaccess' && $base !== '.gitignore') continue;
        if ($entry->getSize() > 400000) continue; // skip anything absurdly large (minified bundles etc.)

        $contents = @file_get_contents($entry->getPathname());
        if ($contents === false) continue;
        $files[str_replace('\\', '/', $rel)] = $contents;
    }
    return $files;
}

/* Builds the same "file tree + stats + truncated file contents" payload
   string the browser tool's buildPayload() builds, so results are
   directly comparable between the manual and one-click paths. */
function etel_audit_build_content($files) {
    $priority = array('php','sql','js','ts','json','css','html','md','txt','conf','yaml','ini');
    $names = array_keys($files);
    usort($names, function($a, $b) use ($priority) {
        $ea = strtolower(pathinfo($a, PATHINFO_EXTENSION));
        $eb = strtolower(pathinfo($b, PATHINFO_EXTENSION));
        $pa = array_search($ea, $priority); $pa = $pa === false ? 99 : $pa;
        $pb = array_search($eb, $priority); $pb = $pb === false ? 99 : $pb;
        return $pa - $pb;
    });

    $stats = array('total' => count($names), 'php' => 0, 'js' => 0, 'css' => 0, 'html' => 0, 'sql' => 0, 'lines' => 0);
    foreach ($names as $n) {
        $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if ($e === 'php') $stats['php']++;
        elseif ($e === 'js' || $e === 'ts') $stats['js']++;
        elseif ($e === 'css' || $e === 'scss') $stats['css']++;
        elseif ($e === 'html' || $e === 'htm') $stats['html']++;
        elseif ($e === 'sql') $stats['sql']++;
        $stats['lines'] += substr_count($files[$n], "\n") + 1;
    }

    $tree = '';
    foreach ($names as $n) { $tree .= '  ' . $n . ' (' . strlen($files[$n]) . 'B)' . "\n"; }

    $content = ''; $totalLen = 0;
    foreach ($names as $i => $n) {
        $body = $files[$n];
        $chunk = "\n\n=== FILE: $n ===\n" . substr($body, 0, 4500) . (strlen($body) > 4500 ? "\n[...truncated]" : '');
        if ($totalLen + strlen($chunk) > ETEL_AUDIT_MAX_CONTENT) {
            $content .= "\n\n[" . (count($names) - $i) . " remaining files omitted - size limit reached]";
            break;
        }
        $content .= $chunk;
        $totalLen += strlen($chunk);
    }

    $userMsg = "Analyze this project:\n\n=== FILE TREE ===\n" . $tree .
        "\n=== PROJECT STATS ===\nTotal Files: {$stats['total']} | PHP: {$stats['php']} | JS: {$stats['js']} | CSS: {$stats['css']} | SQL: {$stats['sql']} | Est. Lines: {$stats['lines']}" .
        "\n\n=== FILE CONTENTS ===" . $content;

    return $userMsg;
}

/* Never logs/returns the real key - just enough to tell "is this the key I
   think it is" apart from "did I paste something else" without needing
   another screenshot round-trip. Same idea as EtelAi's debug-key.php. */
function etel_audit_key_fingerprint($key) {
    $len = strlen($key);
    if ($len === 0) return 'empty';
    return $len . ' chars, ends "' . substr($key, -4) . '"';
}

/* Strips markdown code fences and stray leading/trailing prose the model
   sometimes wraps the JSON in, then parses it - shared by every provider
   caller below since they all need identical cleanup. Returns the
   decoded report array, or null if the text still isn't valid JSON. */
function etel_audit_parse_report_text($text) {
    $text = trim(preg_replace(array('/^```(?:json)?\s*/im', '/```\s*$/m'), '', (string)$text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    $report = json_decode($text, true);
    return is_array($report) ? $report : null;
}

/* Calls Gemini's native generateContent API directly, trying each model
   in ETEL_AUDIT_GEMINI_MODELS in turn until one returns 200 - a later
   model is only tried after a real failure (400/401/403 or a non-2xx),
   not after a successful-but-unwanted result. */
function etel_audit_call_gemini($system, $content) {
    // HARDENING: trim() the key before use - config.php is hand-edited via
    // cPanel's file manager (often from a phone), which can silently
    // introduce a trailing space/newline or smart-quote around the value.
    // A key that works fine everywhere else can then fail here alone with
    // a confusing "API key not valid" straight from Google, since the
    // actual bytes sent no longer match the real key.
    $geminiKey = defined('GEMINI_API_KEY') ? trim(GEMINI_API_KEY) : '';
    if (!$geminiKey || $geminiKey === 'YOUR_GEMINI_KEY_HERE') {
        return array('error' => 'Gemini API key not configured - set GEMINI_API_KEY in EtelGebeya/audit/config.php');
    }

    $payloadArr = array(
        'contents'          => array(array('role' => 'user', 'parts' => array(array('text' => $content)))),
        'systemInstruction' => array('parts' => array(array('text' => $system))),
        'generationConfig'  => array('maxOutputTokens' => 65536, 'temperature' => 0.1, 'topP' => 0.8),
        'safetySettings'    => array(
            array('category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'),
            array('category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'),
            array('category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'),
            array('category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'),
        ),
    );
    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $clean = function ($s) {
            $s = @iconv('UTF-8', 'UTF-8//IGNORE', (string)$s);
            return $s === false ? preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', (string)$s) : $s;
        };
        $payloadArr['contents'][0]['parts'][0]['text'] = $clean($content);
        $payloadArr['systemInstruction']['parts'][0]['text'] = $clean($system);
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
    }
    if ($payload === false) return array('error' => 'Could not encode audit payload (invalid file content found)');

    $models = array_map('trim', explode(',', ETEL_AUDIT_GEMINI_MODELS));
    $resp = ''; $http = 0; $err = '';
    foreach ($models as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
             . ':generateContent?key=' . rawurlencode($geminiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_TIMEOUT        => 280,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($http === 200) break;
        if (in_array($http, array(400, 401, 403), true)) break; // bad key/request - retrying a different model won't help
    }
    if ($err) return array('error' => 'Could not reach Gemini: ' . $err);
    if ($http !== 200) {
        $decoded = json_decode($resp, true);
        $msg = $decoded['error']['message'] ?? ('Gemini HTTP ' . $http);
        if (strpos($msg, 'API_KEY') !== false || $http === 401 || $http === 403) {
            return array('error' => 'Invalid Gemini API key (as loaded: ' . etel_audit_key_fingerprint($geminiKey) . ') - check EtelGebeya/audit/config.php.');
        }
        return array('error' => 'Gemini error: ' . $msg);
    }

    $decoded = json_decode($resp, true);
    $parts = $decoded['candidates'][0]['content']['parts'] ?? array();
    $text = '';
    foreach ($parts as $p) { if (!($p['thought'] ?? false) && isset($p['text'])) $text .= $p['text']; }
    if ($text === '') foreach ($parts as $p) $text .= ($p['text'] ?? '');

    $report = etel_audit_parse_report_text($text);
    if ($report === null) return array('error' => 'Gemini response was not valid JSON. First 200 chars: ' . substr(trim($text), 0, 200));
    return $report;
}

/* Calls Anthropic's Messages API directly. */
function etel_audit_call_claude($system, $content) {
    $claudeKey = defined('CLAUDE_API_KEY') ? trim(CLAUDE_API_KEY) : '';
    if (!$claudeKey || $claudeKey === 'YOUR_CLAUDE_KEY_HERE') {
        return array('error' => 'Claude API key not configured - set CLAUDE_API_KEY in EtelGebeya/audit/config.php');
    }

    $payload = json_encode(array(
        'model'      => ETEL_AUDIT_CLAUDE_MODEL,
        'max_tokens' => 16000,
        'system'     => $system,
        'messages'   => array(array('role' => 'user', 'content' => $content)),
    ), JSON_UNESCAPED_UNICODE);
    if ($payload === false) return array('error' => 'Could not encode audit payload (invalid file content found)');

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'x-api-key: ' . $claudeKey,
            'anthropic-version: 2023-06-01',
        ),
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('error' => 'Could not reach Claude: ' . $err);
    if ($http !== 200) {
        $decoded = json_decode($resp, true);
        $msg = $decoded['error']['message'] ?? ('Claude HTTP ' . $http);
        if ($http === 401) return array('error' => 'Invalid Claude API key (as loaded: ' . etel_audit_key_fingerprint($claudeKey) . ') - check EtelGebeya/audit/config.php.');
        return array('error' => 'Claude error: ' . $msg);
    }

    $decoded = json_decode($resp, true);
    $text = '';
    foreach (($decoded['content'] ?? array()) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $report = etel_audit_parse_report_text($text);
    if ($report === null) return array('error' => 'Claude response was not valid JSON. First 200 chars: ' . substr(trim($text), 0, 200));
    return $report;
}

/* Calls Kimi (Moonshot AI)'s OpenAI-compatible /chat/completions endpoint. */
function etel_audit_call_kimi($system, $content) {
    $kimiKey = defined('KIMI_API_KEY') ? trim(KIMI_API_KEY) : '';
    if (!$kimiKey || $kimiKey === 'YOUR_KIMI_KEY_HERE') {
        return array('error' => 'Kimi API key not configured - set KIMI_API_KEY in EtelGebeya/audit/config.php');
    }

    $messages = array();
    if ($system) $messages[] = array('role' => 'system', 'content' => $system);
    $messages[] = array('role' => 'user', 'content' => $content);

    $payload = json_encode(array(
        'model'       => ETEL_AUDIT_KIMI_MODEL,
        'max_tokens'  => 16000,
        'temperature' => 0.1,
        'messages'    => $messages,
    ), JSON_UNESCAPED_UNICODE);
    if ($payload === false) return array('error' => 'Could not encode audit payload (invalid file content found)');

    $ch = curl_init('https://api.moonshot.cn/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer ' . $kimiKey),
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('error' => 'Could not reach Kimi: ' . $err);
    if ($http !== 200) {
        $decoded = json_decode($resp, true);
        $msg = $decoded['error']['message'] ?? ('Kimi HTTP ' . $http);
        if ($http === 401) return array('error' => 'Invalid Kimi API key (as loaded: ' . etel_audit_key_fingerprint($kimiKey) . ') - check EtelGebeya/audit/config.php.');
        return array('error' => 'Kimi error: ' . $msg);
    }

    $text = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $report = etel_audit_parse_report_text($text);
    if ($report === null) return array('error' => 'Kimi response was not valid JSON. First 200 chars: ' . substr($text, 0, 200));
    return $report;
}

/* Calls OpenAI's /chat/completions endpoint directly. */
function etel_audit_call_openai($system, $content) {
    $openaiKey = defined('OPENAI_API_KEY') ? trim(OPENAI_API_KEY) : '';
    if (!$openaiKey || $openaiKey === 'YOUR_OPENAI_KEY_HERE') {
        return array('error' => 'OpenAI API key not configured - set OPENAI_API_KEY in EtelGebeya/audit/config.php');
    }

    $messages = array();
    if ($system) $messages[] = array('role' => 'system', 'content' => $system);
    $messages[] = array('role' => 'user', 'content' => $content);

    $payload = json_encode(array(
        'model'       => ETEL_AUDIT_OPENAI_MODEL,
        'max_tokens'  => 16000,
        'temperature' => 0.1,
        'messages'    => $messages,
    ), JSON_UNESCAPED_UNICODE);
    if ($payload === false) return array('error' => 'Could not encode audit payload (invalid file content found)');

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer ' . $openaiKey),
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('error' => 'Could not reach OpenAI: ' . $err);
    if ($http !== 200) {
        $decoded = json_decode($resp, true);
        $msg = $decoded['error']['message'] ?? ('OpenAI HTTP ' . $http);
        if ($http === 401) return array('error' => 'Invalid OpenAI API key (as loaded: ' . etel_audit_key_fingerprint($openaiKey) . ') - check EtelGebeya/audit/config.php.');
        return array('error' => 'OpenAI error: ' . $msg);
    }

    $text = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $report = etel_audit_parse_report_text($text);
    if ($report === null) return array('error' => 'OpenAI response was not valid JSON. First 200 chars: ' . substr($text, 0, 200));
    return $report;
}

/**
 * Run a full self-audit of $root and return the parsed report array, or
 * ['error' => '...'] on failure. Never throws - callers should just
 * check for the 'error' key.
 */
function etel_run_self_audit($root, $projectName, $provider = 'gemini') {
    // Shared cPanel hosting commonly defaults max_execution_time to 30s,
    // which would kill this request before a slower AI response comes
    // back. @ suppresses the notice on hosts where set_time_limit is
    // disabled entirely - the cURL timeout below is the real bound.
    @set_time_limit(300);
    if (!is_dir($root)) return array('error' => "Project root not found: $root");

    $files = etel_audit_collect_files($root);
    if (!$files) return array('error' => 'No text files found to audit under ' . $root);

    $content = etel_audit_build_content($files);
    $system  = etel_audit_system_prompt();

    switch ($provider) {
        case 'claude': $report = etel_audit_call_claude($system, $content); break;
        case 'kimi':   $report = etel_audit_call_kimi($system, $content);   break;
        case 'openai': $report = etel_audit_call_openai($system, $content); break;
        default:       $report = etel_audit_call_gemini($system, $content);
    }

    if (isset($report['error'])) return $report;

    $report['_project_name_override'] = $projectName;
    $report['_audited_at'] = date('c');
    return $report;
}

} // function_exists guard
