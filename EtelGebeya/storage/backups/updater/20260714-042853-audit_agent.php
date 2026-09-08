<?php
/**
 * ETEL self-audit agent (server-side, one-click)
 *
 * Reuses the exact same backend as EtelGebeya/audit/index.html (the
 * manual "zip it, upload it, download the JSON" tool) but skips the
 * upload entirely: this file already lives inside the project it's
 * auditing, so it reads its own files straight off disk, builds the same
 * file-tree + content payload the browser tool builds, and POSTs it to
 * the shared AI proxy (EtelGebeya/audit/proxy.php) over HTTPS. That proxy
 * already holds the real Gemini/Claude/Kimi/OpenAI keys server-side (see
 * EtelGebeya/audit/config.php) - this file never touches or needs a key
 * of its own.
 *
 * This is a template: copy it into another project's api/ (or
 * equivalent) folder, and only PROJECT_ROOT/PROJECT_NAME below need to
 * change. It's kept as one self-contained function so it has no
 * dependency on this specific project's other files besides whatever
 * calls etel_run_self_audit() with an admin-gated action.
 */

if (!function_exists('etel_run_self_audit')) {

// Where the shared AI proxy lives - same one the manual browser tool
// (EtelGebeya/public/index.html) already posts to via its relative
// './proxy.php'. If you ever move EtelGebeya, update this one URL
// across every project's copy of this file.
define('ETEL_AUDIT_PROXY_URL', 'https://ethelmarket.com/EtelGebeya/public/proxy.php');
define('ETEL_AUDIT_SECRET',    'ROTATED-SECRET-REDACTED');

// Same extension allowlist as the browser tool's TEXT_EXTS, so results
// are consistent whether a project is audited via upload or via this
// button.
define('ETEL_AUDIT_TEXT_EXTS', 'php,js,ts,jsx,tsx,css,scss,sass,less,html,htm,xml,json,sql,md,txt,env,htaccess,yaml,yml,ini,conf,sh,py,rb,java,cs,go,rs,toml,vue,svelte,config,gitignore,lock');

// Directories never worth sending to the AI (dependencies, build
// output, VCS internals, logs/uploads that are pure runtime data, and
// this tool's own workspace) - walking into these wastes the character
// budget on noise instead of actual project code.
define('ETEL_AUDIT_EXCLUDE_DIRS', '.git,.claude,.vscode,.gstack,node_modules,vendor,storage,uploads,backups,cache,logs,dist,build,.svn');

// Keep well under api.php's hard 45000-char server-side cap so nothing
// gets silently cut off mid-request; leaves room for the file-tree/stats
// header the same way the browser tool's 58000 client-side cap does.
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

/**
 * Run a full self-audit of $root and return the parsed report array, or
 * ['error' => '...'] on failure. Never throws - callers should just
 * check for the 'error' key.
 */
function etel_run_self_audit($root, $projectName, $provider = 'gemini') {
    // Shared cPanel hosting commonly defaults max_execution_time to 30s,
    // which would kill this request before a slower AI response (the
    // manual browser tool's own loading UI budgets up to ~2 minutes)
    // comes back. @ suppresses the notice on hosts where set_time_limit
    // is disabled entirely - the cURL timeout below is the real bound.
    @set_time_limit(300);
    if (!is_dir($root)) return array('error' => "Project root not found: $root");

    $files = etel_audit_collect_files($root);
    if (!$files) return array('error' => 'No text files found to audit under ' . $root);

    $content = etel_audit_build_content($files);

    $payload = json_encode(array(
        'secret'   => ETEL_AUDIT_SECRET,
        'system'   => etel_audit_system_prompt(),
        'content'  => $content,
        'provider' => $provider,
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init(ETEL_AUDIT_PROXY_URL);
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

    if ($err)        return array('error' => 'Could not reach audit backend: ' . $err);
    $raw = json_decode($resp, true);
    if (!is_array($raw)) return array('error' => 'Audit backend returned an unreadable response (HTTP ' . $http . ')');
    if (isset($raw['error'])) {
        if ($raw['error'] === 'setup_required') return array('error' => 'Audit backend is not configured yet - see EtelGebeya/audit/config.php');
        return array('error' => $raw['error']);
    }

    $text = '';
    foreach (($raw['content'] ?? array()) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim(preg_replace(array('/^```(?:json)?\s*/im', '/```\s*$/m'), '', $text));
    if ($text !== '' && $text[0] !== '{') {
        $s = strpos($text, '{'); $e = strrpos($text, '}');
        if ($s !== false && $e > $s) $text = substr($text, $s, $e - $s + 1);
    }
    $report = json_decode($text, true);
    if (!is_array($report)) return array('error' => 'Audit backend response was not valid JSON');

    $report['_project_name_override'] = $projectName;
    $report['_audited_at'] = date('c');
    return $report;
}

} // function_exists guard
