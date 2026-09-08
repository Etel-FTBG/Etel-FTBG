<?php
declare(strict_types=1);
namespace App\Core;

/** Small helpers used across controllers. */
final class Str
{
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? '';
        return trim($s, '-');
    }

    public static function randomCode(string $prefix = '', int $len = 8): string
    {
        return $prefix . strtoupper(bin2hex(random_bytes((int)ceil($len / 2))));
    }
}
