<?php
/**
 * ETEL Delivery - shared .env parser
 *
 * Extracted from boot.php's inline parser 2026-07-12, after an audit found
 * diagnose.php and etel-os.php were each still using parse_ini_file() to
 * read api/.env instead of this logic. parse_ini_file() silently fails
 * (returns false, or drops the offending line) when the .env file contains
 * any non-ASCII byte anywhere in it - including in a comment, e.g. an
 * Amharic note or a unicode dash pasted from a chat client. When that
 * happens here, every secret in .env (DB password, TOKEN_SECRET,
 * ETEL_OS_KEY, TG_BOT_TOKEN, ...) silently fails to load in whichever
 * file made that call, rather than raising a visible error - the worst
 * kind of bug, because it only shows up the day someone's editor happens
 * to auto-format a comment with a fancier dash.
 *
 * boot.php's own parser never had this problem (plain line-by-line
 * `file()` read, not parse_ini_file()), so every file that reads api/.env
 * should call etel_parse_env_file() below instead of rolling its own.
 */

if (!function_exists('etel_parse_env_file')) {
    function etel_parse_env_file($path) {
        $out = array();
        if (!is_file($path)) return $out;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $out;
        foreach ($lines as $line) {
            // Strip BOM if present on first line
            $line = ltrim($line, "\xEF\xBB\xBF");
            // Skip comment lines (may contain unicode)
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') continue;
            // Must contain = to be a key=value pair
            if (strpos($trimmed, '=') === false) continue;
            $parts = explode('=', $trimmed, 2);
            $k = trim($parts[0]);
            $v = isset($parts[1]) ? trim($parts[1]) : '';
            // Strip inline comments (unquoted # after value)
            if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
                $hash = strpos($v, ' #');
                if ($hash !== false) $v = rtrim(substr($v, 0, $hash));
            }
            // Strip surrounding quotes
            if (strlen($v) >= 2 &&
                (($v[0] === '"'  && $v[strlen($v) - 1] === '"') ||
                 ($v[0] === "'"  && $v[strlen($v) - 1] === "'"))) {
                $v = substr($v, 1, strlen($v) - 2);
            }
            // Only store valid KEY names
            if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
