<?php
declare(strict_types=1);
namespace App\Core;

final class Request
{
    public static function input(string $key, mixed $default = null, ?array $source = null): mixed
    {
        $source ??= array_merge($_GET, $_POST);
        return $source[$key] ?? $default;
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function intInput(string $key, int $default = 0): int
    {
        return (int)($_POST[$key] ?? $_GET[$key] ?? $default);
    }

    public static function redirect(string $url, int $code = 302): void
    {
        header('Location: ' . $url, true, $code);
        exit;
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
