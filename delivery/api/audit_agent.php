<?php
/**
 * ETEL self-audit agent (server-side, one-click)
 *
 * Calls Gemini or OpenRouter directly over HTTPS using a key configured
 * for THIS project (same direct-call pattern EtelAi's providers.php uses
 * for its own key-testing, not shared with any other project) - this
 * file reads its own files straight off disk, builds the same file-tree
 * + content payload the old EtelGebeya browser tool built, and posts it
 * straight to the provider's real API.
 *
 * Previously this posted to a shared proxy (EtelGebeya/public/proxy.php)
 * that held the real keys server-side so this file never needed one of
 * its own. Switched to calling providers directly after that proxy's
 * shared secret repeatedly failed to authenticate in production despite
 * matching byte-for-byte on both sides (see git history) - direct calls
 * remove that whole cross-project shared-secret failure mode, at the
 * cost of this project now needing its own Gemini/OpenRouter key(s),
 * configured from Settings > Code Audit > API Settings.
 *
 * This is a template: copy it into another project's api/ (or
 * equivalent) folder - it has no dependency on this specific project's
 * other files besides whatever calls etel_run_self_audit() with an
 * admin-gated action.
 */

if (!function_exists('etel_audit_clean_report')) {
/* Keeps the AI honest: findings that name a file that is not in the project are dropped (made up), the invented grade S becomes A, and the
   report says how much of the project the AI really read, so a high score on a small sample is not mistaken for a full review. */
function etel_audit_clean_report($report, $files, $content) {
    $known = array();
    foreach ($files as $path => $_) { $known[strtolower($path)] = true; $known[strtolower(basename($path))] = true; }
    $has = function ($ref) use ($known) { $ref = strtolower(trim((string) $ref)); if ($ref === '') return false; return isset($known[$ref]) || isset($known[strtolower(basename($ref))]); };
    $dropped = 0;
    if (isset($report['bugs']) && is_array($report['bugs'])) {
        $keep = array();
        foreach ($report['bugs'] as $b) { if (is_array($b) && $has(isset($b['file']) ? $b['file'] : '')) $keep[] = $b; else $dropped++; }
        $report['bugs'] = $keep;
    }
    if (isset($report['categories']) && is_array($report['categories'])) {
        foreach ($report['categories'] as $k => $c) {
            if (!is_array($c)) continue;
            if (isset($c['grade']) && strtoupper(trim((string) $c['grade'])) === 'S') $report['categories'][$k]['grade'] = 'A';
            if (isset($c['score']) && is_numeric($c['score']) && $c['score'] > 97) $report['categories'][$k]['score'] = 97;
            foreach (array('highlights', 'passed') as $lk) {   // a claim with no file in brackets is not evidence
                if (!isset($c[$lk]) || !is_array($c[$lk])) continue;
                $keep = array();
                foreach ($c[$lk] as $line) { if (preg_match('/\[([^\]]+)\]\s*$/', (string) $line, $m) && $has($m[1])) $keep[] = $line; else $dropped++; }
                $report['categories'][$k][$lk] = $keep;
            }
        }
    }
    if (isset($report['overall_grade']) && strtoupper(trim((string) $report['overall_grade'])) === 'S') $report['overall_grade'] = 'A';
    $report['coverage'] = array(
        'files_in_project' => count($files),
        'files_read' => substr_count($content, "\n=== FILE: "),
        'characters_read' => strlen($content),
        'claims_removed' => $dropped,
    );
    return $report;
}
}

if (!function_exists('etel_run_self_audit')) {

// Fallback keys if the DB table below is empty/unreachable - set these
// as real environment variables in api/.env if you'd rather not touch
// the DB at all. The admin panel (Settings > Code Audit > API Settings)
// is the normal way to set/rotate these without a code deploy.
define('ETEL_AUDIT_GEMINI_KEY',     getenv('GEMINI_API_KEY') ?: '');
define('ETEL_AUDIT_OPENROUTER_KEY', getenv('OPENROUTER_API_KEY') ?: '');

// Gemini tries these in order, falling back to the next on a 400/401/403
// (same resilience the old shared proxy had). OpenRouter uses a single
// configurable model since it already routes across many backends itself.
define('ETEL_AUDIT_GEMINI_MODELS', 'gemini-3.5-flash,gemini-3.1-flash-lite,gemini-3.1-pro');
define('ETEL_AUDIT_OPENROUTER_DEFAULT_MODEL', 'anthropic/claude-3.5-sonnet');

// Same extension allowlist as the old browser tool's TEXT_EXTS, so
// results are consistent with what that tool used to produce.
define('ETEL_AUDIT_TEXT_EXTS', 'php,js,ts,jsx,tsx,css,scss,sass,less,html,htm,xml,json,sql,md,txt,env,htaccess,yaml,yml,ini,conf,sh,py,rb,java,cs,go,rs,toml,vue,svelte,config,gitignore,lock');

// ── Admin-editable audit config ──────────────────────────────────────
// Keys/model live in a DB table an admin can edit from the panel; the
// constants above are only the fallback if this table can't be created
// or is empty (and what a fresh copy of this file in another project
// starts with).
if (!function_exists('etel_audit_ensure_settings_table')) {
function etel_audit_ensure_settings_table($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'audit_settings'")->fetch();
        if (!$exists) {
            $pdo->exec("CREATE TABLE audit_settings (
                id INT PRIMARY KEY DEFAULT 1,
                gemini_key VARCHAR(255) NOT NULL DEFAULT '',
                openrouter_key VARCHAR(255) NOT NULL DEFAULT '',
                openrouter_model VARCHAR(120) NOT NULL DEFAULT 'anthropic/claude-3.5-sonnet',
                default_provider VARCHAR(20) NOT NULL DEFAULT 'gemini',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $pdo->prepare("INSERT INTO audit_settings (id, gemini_key, openrouter_key, openrouter_model, default_provider) VALUES (1, ?, ?, ?, 'gemini')")
                ->execute(array(ETEL_AUDIT_GEMINI_KEY, ETEL_AUDIT_OPENROUTER_KEY, ETEL_AUDIT_OPENROUTER_DEFAULT_MODEL));
        } else {
            // Upgrade path: an existing installation of this table (from
            // before this file switched off the shared-proxy design) has
            // proxy_url/secret columns instead of these - add the new
            // ones without touching/dropping the old, now-unused columns.
            $existing = array();
            foreach ($pdo->query("SHOW COLUMNS FROM audit_settings")->fetchAll() as $c) {
                $existing[strtolower($c['Field'])] = true;
            }
            $add = array(
                'gemini_key'       => "gemini_key VARCHAR(255) NOT NULL DEFAULT ''",
                'openrouter_key'   => "openrouter_key VARCHAR(255) NOT NULL DEFAULT ''",
                'openrouter_model' => "openrouter_model VARCHAR(120) NOT NULL DEFAULT 'anthropic/claude-3.5-sonnet'",
            );
            foreach ($add as $col => $ddl) {
                if (!isset($existing[$col])) {
                    try { $pdo->exec("ALTER TABLE audit_settings ADD COLUMN $ddl"); }
                    catch (Exception $e) { error_log('[ETEL][audit] add col ' . $col . ': ' . $e->getMessage()); }
                }
            }
        }
    } catch (Exception $e) { /* best-effort - falls back to the hardcoded constants if this never succeeds */ }
}
}
if (!function_exists('etel_audit_get_config')) {
function etel_audit_get_config($pdo) {
    $fallback = array(
        'gemini_key'       => ETEL_AUDIT_GEMINI_KEY,
        'openrouter_key'   => ETEL_AUDIT_OPENROUTER_KEY,
        'openrouter_model' => ETEL_AUDIT_OPENROUTER_DEFAULT_MODEL,
        'default_provider' => 'gemini',
    );
    if (!$pdo) return $fallback;
    try {
        etel_audit_ensure_settings_table($pdo);
        $row = $pdo->query("SELECT gemini_key, openrouter_key, openrouter_model, default_provider FROM audit_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return array(
                'gemini_key'       => $row['gemini_key'] !== '' ? $row['gemini_key'] : $fallback['gemini_key'],
                'openrouter_key'   => $row['openrouter_key'] !== '' ? $row['openrouter_key'] : $fallback['openrouter_key'],
                'openrouter_model' => $row['openrouter_model'] !== '' ? $row['openrouter_model'] : $fallback['openrouter_model'],
                'default_provider' => $row['default_provider'] ?: 'gemini',
            );
        }
    } catch (Exception $e) { /* fall through to hardcoded fallback below */ }
    return $fallback;
}
}

// Directories never worth sending to the AI (dependencies, build
// output, VCS internals, logs/uploads that are pure runtime data, and
// this tool's own workspace) - walking into these wastes the character
// budget on noise instead of actual project code.
define('ETEL_AUDIT_EXCLUDE_DIRS', '.git,.claude,.vscode,.gstack,node_modules,vendor,storage,uploads,backups,cache,logs,dist,build,.svn');

// How much code the AI actually reads. The audit used to send only 42,000 characters (about 10 files, 4,500 characters each) of a project that
// has 185,000 lines, so it could only ever say "no issues". The calls go straight to Gemini / OpenRouter now, which take far more, so the sample is
// larger (roughly 60,000 tokens) and the files that matter most for risk (sign-in, tokens, money, uploads, admin actions) are sent FIRST.
define('ETEL_AUDIT_MAX_CONTENT', 220000);
define('ETEL_AUDIT_MAX_PER_FILE', 14000);

/* Same system prompt the browser tool sends, verbatim, so the two
   report formats line up (same category keys, same JSON schema, same
   grading rubric) and either one can be reviewed the same way. */
function etel_audit_system_prompt() {
    return <<<PROMPT
You are EtelAudit Pro, the world's most advanced code review AI for Etel Group, Hawassa, Ethiopia. You analyze projects against global 2026 standards while understanding the Ethiopian context: mobile-first users, Telebirr/CBE Birr payments, Amharic/bilingual requirements, low bandwidth conditions, shared cPanel hosting with ModSecurity WAF constraints.

== EVIDENCE RULES (these override everything below) ==
E1. You only see a SAMPLE of the project: the first part of the files listed under FILE CONTENTS, plus a file tree. Never say a protection exists, or that something "passed", unless you can name the file you saw it in. If you cannot, leave it out. Do not praise anything from the file tree alone.
E2. Every item in "passed" and "highlights" must end with the file in brackets, for example "password_hash() on sign-up [api/controllers/AuthController.php]". Items without a file will be deleted.
E3. Every entry in "bugs" and every entry in an "issues" list must name a file from the sample and quote the exact line. Entries that name a file you were not given will be deleted. Do not invent file names, tools, frameworks or features. Do not mention React Native, Expo, WebSockets, cookies, sessions or CSRF unless you saw them in the sample.
E4. A large real project always has weaknesses. Look for them in the sample: very long files, schema changes made while serving a request (ALTER TABLE or CREATE TABLE inside a controller), missing permission checks, unvalidated input, money maths without a transaction or lock, error messages that reveal internals, duplicated code. If you find none in the sample, say so in "limitations" instead of giving a high score.
E5. Grades are only A, A-, B+, B, B-, C+, C, C-, D, F. Never use S. A category you could not check from the sample must score no higher than 75 and must say so.
E6. Add a top-level "limitations" array of short sentences: what you could NOT check because it was outside the sample (payment flows, mobile apps, load behaviour, server settings, files you did not see).

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
      "highlights": ["<what is done well> [exact/file/path.php]"],
      "issues": [
        {"severity": "critical", "title": "Real SQL Injection", "detail": "file.php line N: \$db->query(\"SELECT ... WHERE id = \" . \$userId) -- user value directly concatenated, no ? placeholder"}
      ],
      "passed": ["<a check that passed> [exact/file/path.php]"]
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
  "limitations": ["<one thing you could not check>"],
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

/* True for files that hold secrets: .env and .env.*, *.env, keys, certificates, password files, database dumps with credentials. */
function etel_audit_is_secret_file($base) {
    if ($base === '.env' || strpos($base, '.env.') === 0 || substr($base, -4) === '.env') return true;
    if ($base === '.htpasswd' || $base === 'id_rsa' || $base === 'id_ed25519') return true;
    return (bool) preg_match('/\.(pem|key|crt|cer|p12|pfx|jks|keystore|sqlite|db)$/', $base);
}

/* Blanks the value of anything that looks like a hard-coded secret (password = '...', 'api_key' => '...', TOKEN_SECRET: ...) so a
   key that was pasted into a code file is never sent to the AI provider. The surrounding code is left as it was. */
function etel_audit_redact($text) {
    // define('SOME_SECRET', 'value')
    $text = preg_replace('/(define\(\s*[\'"][A-Z0-9_]*(?:PASS|SECRET|TOKEN|KEY)[A-Z0-9_]*[\'"]\s*,\s*[\'"])[^\'"\r\n]{8,}([\'"])/i', '$1[redacted]$2', $text);
    return preg_replace('/((?:pass(?:word)?|passwd|secret|token|api[_-]?key|apikey|private[_-]?key|auth[_-]?key|client[_-]?secret|bearer)[a-z0-9_]*[\'"]?\s*(?:=>|=|:)\s*[\'"])[^\'"\r\n]{8,}([\'"])/i', '$1[redacted]$2', $text);
}

/* Lower number = read first. Sign-in and tokens, money, uploads, permission checks and the router come before everything else. */
function etel_audit_risk_rank($name) {
    $n = strtolower($name);
    $hot = array('boot.php', 'auth', 'token', 'login', 'upload', 'private_file', 'wallet', 'money', 'ledger', 'payment', 'pay', 'chapa', 'telebirr', 'payout', 'earning', 'cash', 'refund', 'admin', 'permission', 'capab', 'migrate', 'cron', 'webhook', 'callback', 'documents', 'equb', 'equity');
    foreach ($hot as $i => $h) { if (strpos($n, $h) !== false) return ($i < 6 ? 0 : 1); }
    if (strpos($n, 'api/') === 0 || strpos($n, '/controllers/') !== false || strpos($n, '/lib/') !== false) return 2;
    return 3;
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
        // Secrets never leave the server: environment files, keys, certificates and password files are skipped outright.
        if (etel_audit_is_secret_file($base)) continue;
        // .htaccess / .gitignore have no "extension" by pathinfo's rules - match by filename too.
        if (!isset($textExts[$ext]) && $base !== '.htaccess' && $base !== '.gitignore') continue;
        if ($entry->getSize() > 400000) continue; // skip anything absurdly large (minified bundles etc.)

        $contents = @file_get_contents($entry->getPathname());
        if ($contents === false) continue;
        $contents = etel_audit_redact($contents);
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
        $ra = etel_audit_risk_rank($a); $rb = etel_audit_risk_rank($b);
        if ($ra !== $rb) return $ra - $rb;
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
        $chunk = "\n\n=== FILE: $n ===\n" . substr($body, 0, ETEL_AUDIT_MAX_PER_FILE) . (strlen($body) > ETEL_AUDIT_MAX_PER_FILE ? "\n[...truncated]" : '');
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

/* Strips markdown code fences and stray leading/trailing prose the model
   sometimes wraps the JSON in, then parses it - shared by both provider
   callers below since they need identical cleanup. Returns the decoded
   report array, or null if the text still isn't valid JSON after cleanup. */
function etel_audit_parse_report_text($text) {
    $text = trim(preg_replace(array('/^```(?:json)?\s*/im', '/```\s*$/m'), '', (string)$text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    $report = json_decode($text, true);
    return is_array($report) ? $report : null;
}

/* Calls Gemini's native generateContent API directly (same request shape
   EtelAi's providers.php test_key action and the old EtelGebeya proxy
   both use), trying each model in ETEL_AUDIT_GEMINI_MODELS in turn until
   one returns 200 - a later model is only tried after a real failure
   (400/401/403 or a non-2xx), not after a successful-but-unwanted result. */
function etel_audit_call_gemini($cfg, $system, $content) {
    if (!$cfg['gemini_key']) return array('error' => 'Gemini API key not configured - set it in Settings > Code Audit > API Settings');

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
        // See the UTF-8 note in etel_run_self_audit() - same recovery.
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
             . ':generateContent?key=' . rawurlencode($cfg['gemini_key']);
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
            return array('error' => 'Invalid Gemini API key - check Settings > Code Audit > API Settings.');
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

/* Calls OpenRouter's OpenAI-compatible /chat/completions endpoint
   directly - same Bearer-token + messages[] shape EtelAi's providers.php
   uses for every non-Gemini provider. */
function etel_audit_call_openrouter($cfg, $system, $content) {
    if (!$cfg['openrouter_key']) return array('error' => 'OpenRouter API key not configured - set it in Settings > Code Audit > API Settings');

    $model = $cfg['openrouter_model'] ?: ETEL_AUDIT_OPENROUTER_DEFAULT_MODEL;
    $payload = json_encode(array(
        'model'       => $model,
        'max_tokens'  => 16000,
        'temperature' => 0.1,
        'messages'    => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => $content),
        ),
    ), JSON_UNESCAPED_UNICODE);
    if ($payload === false) return array('error' => 'Could not encode audit payload (invalid file content found)');

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['openrouter_key'],
            'HTTP-Referer: https://ethelmarket.com/delivery/',
            'X-Title: ETEL Delivery & Ride - Code Audit',
        ),
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('error' => 'Could not reach OpenRouter: ' . $err);
    if ($http !== 200) {
        $decoded = json_decode($resp, true);
        $msg = $decoded['error']['message'] ?? ('OpenRouter HTTP ' . $http);
        if ($http === 401) return array('error' => 'Invalid OpenRouter API key - check Settings > Code Audit > API Settings.');
        return array('error' => 'OpenRouter error: ' . $msg);
    }

    $decoded = json_decode($resp, true);
    $text = trim($decoded['choices'][0]['message']['content'] ?? '');
    $report = etel_audit_parse_report_text($text);
    if ($report === null) return array('error' => 'OpenRouter response was not valid JSON. First 200 chars: ' . substr($text, 0, 200));
    return $report;
}

/**
 * Run a full self-audit of $root and return the parsed report array, or
 * ['error' => '...'] on failure. Never throws - callers should just
 * check for the 'error' key.
 */
function etel_run_self_audit($root, $projectName, $provider = 'gemini', $pdo = null) {
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
    $cfg     = etel_audit_get_config($pdo);

    $report = ($provider === 'openrouter')
        ? etel_audit_call_openrouter($cfg, $system, $content)
        : etel_audit_call_gemini($cfg, $system, $content);

    if (isset($report['error'])) return $report;

    $report = etel_audit_clean_report($report, $files, $content);
    $report['_project_name_override'] = $projectName;
    $report['_audited_at'] = date('c');
    return $report;
}

} // function_exists guard
