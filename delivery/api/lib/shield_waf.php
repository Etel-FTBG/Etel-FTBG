<?php
/**
 * Etel Shield - attack patterns in what a request sends (the "web application firewall" part).
 * Looks at the address query and every text value of the JSON body (not passwords, tokens, codes) for the marks of the common attacks:
 * SQL injection, script injection (XSS), reading server files (path traversal), PHP / command injection, and the hacking tools' own User-Agent.
 * The patterns are specific on purpose (a customer note or a dish name must never match). Starts in "monitor" mode: it only writes the security log,
 * so the owner can read what it would have blocked before turning blocking on. PHP 5 style, ASCII only.
 */
if (!function_exists('etel_shield_waf_rules')) {

/* rule name => regular expression, tested on the lower-cased, url-decoded value */
function etel_shield_waf_rules() {
    return array(
        'sqli_union'    => '/\bunion\b[\s\/\*\(]{1,20}(all[\s\/\*]+)?select\b/',
        'sqli_schema'   => '/\b(information_schema|mysql\.user|pg_catalog|sqlite_master)\b/',
        'sqli_time'     => '/\b(sleep|benchmark|pg_sleep|load_file)\s*\(|waitfor\s+delay\b/',
        'sqli_tautology' => '/[\'"]\s*(or|and)\s*[\'"]?[a-z0-9_]+[\'"]?\s*=\s*[\'"]?[a-z0-9_]+/',
        'sqli_stack'    => '/;\s*(drop|alter|truncate|insert|update|delete|create)\s+(table|database|user|into|from)\b/',
        'sqli_outfile'  => '/\binto\s+(out|dump)file\b/',
        'xss_script'    => '/<\s*script\b|<\s*\/\s*script\s*>/',
        'xss_handler'   => '/<[a-z][^>]{0,40}\bon(error|load|click|mouseover|focus|toggle|start)\s*=/',
        'xss_url'       => '/\bjavascript\s*:|\bvbscript\s*:|<\s*(iframe|object|embed)\b/',
        'path_traversal' => '/(\.\.[\/\\\\])|(\.\.%2f)|(%2e%2e)/',
        'file_read'     => '/\b(etc\/(passwd|shadow)|boot\.ini|win\.ini|proc\/self)\b/',
        'php_wrapper'   => '/\b(php|phar|expect|zip|glob):\/\/|\b(file|gopher|dict|ldap):\/\//',
        'php_code'      => '/<\?(php|=)|\b(eval|assert|base64_decode|shell_exec|passthru|proc_open|popen)\s*\(|\bsystem\s*\(\s*[\'"$]/',
        'cmd_inject'    => '/[;&|`]\s*(cat|ls|wget|curl|nc|ncat|bash|sh|powershell|chmod|rm)\s+[\/\-a-z]/'
    );
}

/* the tools attackers run (their User-Agent says so); an ordinary browser, phone app, curl or a script is never matched */
function etel_shield_scanner_ua() {
    return '/(sqlmap|nikto|nessus|acunetix|netsparker|openvas|masscan|nmap|zgrab|dirbuster|gobuster|feroxbuster|wpscan|havij|hydra|metasploit|nuclei|w3af|arachni|skipfish|appscan|burp ?suite|jaeles|commix)/i';
}

/* all text values of the request, flattened: array of array(where, text). Skips secrets. */
function etel_shield_waf_values($get, $body) {
    $out = array();
    $skip = '/pass|token|secret|signature|otp|pin$|code$|hash|card/i';
    $walk = function ($v, $key, $depth) use (&$walk, &$out, $skip) {
        if (count($out) >= 300 || $depth > 4) return;
        if (is_array($v)) { foreach ($v as $k => $x) $walk($x, (string) $k, $depth + 1); return; }
        if (!is_string($v) || $v === '' || preg_match($skip, (string) $key)) return;
        $out[] = array((string) $key, substr($v, 0, 2000));
    };
    $walk($get, 'get', 0);
    $walk($body, 'body', 0);
    return $out;
}

/* Is this action exempt from the pattern check (admin screens that save documents / code on purpose)? Names may end with *. */
function etel_shield_waf_exempt($action, $list) {
    foreach ($list as $e) {
        if (substr($e, -1) === '*') { if (strpos($action, substr($e, 0, -1)) === 0) return true; }
        elseif ($e === $action) return true;
    }
    return false;
}

/* array(rule, where, snippet) of the first attack mark found, or null */
function etel_shield_waf_scan($get, $body) {
    $rules = etel_shield_waf_rules();
    foreach (etel_shield_waf_values($get, $body) as $kv) {
        $t = strtolower(rawurldecode($kv[1]));
        foreach ($rules as $name => $rx) {
            if (@preg_match($rx, $t)) return array($name, $kv[0], substr(preg_replace('/\s+/', ' ', $kv[1]), 0, 60));
        }
    }
    return null;
}

}
