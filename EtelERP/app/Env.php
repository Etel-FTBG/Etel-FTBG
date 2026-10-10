<?php
/** Minimal .env reader (KEY=VALUE per line). Real environment variables win. */
class Env
{
    private static $vars = null;

    public static function load($file)
    {
        self::$vars = array();
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            self::$vars[trim($k)] = trim($v, " \t\"'");
        }
    }

    public static function get($key, $default = '')
    {
        if (self::$vars === null) self::load(dirname(__DIR__) . '/.env');
        $real = getenv($key);
        if ($real !== false && $real !== '') return $real;
        return isset(self::$vars[$key]) && self::$vars[$key] !== '' ? self::$vars[$key] : $default;
    }
}
