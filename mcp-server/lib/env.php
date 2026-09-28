<?php
/**
 * Minimal .env loader, matching the pattern already used by EtelGroup /
 * delivery / EtelTaxi / EtelGebeya (line by line, skip blank/# lines, split
 * on first =, putenv). Kept as its own file so every entrypoint can reuse it.
 */

function mcp_load_env($path) {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        $val = trim($val, "\"'");
        if ($key === '') {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}

function mcp_env($key, $default = null) {
    $val = getenv($key);
    if ($val === false) {
        return $default;
    }
    return $val;
}
