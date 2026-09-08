<?php
declare(strict_types=1);
namespace App\Core;

/**
 * View -- minimal PHP-template renderer.
 * Views live in app/Views/. Layouts wrap a main view via $__content.
 *
 * Usage:
 *   View::render('storefront/home', $data);           // no layout
 *   View::render('storefront/home', $data, 'main');   // with layout
 *
 * Closure-style layout (preferred):
 *   View::render('_layouts/main', $data, function() {
 *       // view content here
 *   });
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];
    private static array $sections = [];
    private static ?string $currentSection = null;

    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a view with optional layout.
     * $layout can be:
     *   - string  ? load app/Views/{$layout}.php as wrapper
     *   - Closure ? render the view, call closure with view output
     *   - null    ? just output the view
     */
    public static function render(string $view, array $data = [], $layout = null): void
    {
        $merged = array_merge(self::$shared, $data);

        if ($layout instanceof \Closure) {
            // Capture the closure's output as page content, then render $view (the layout) around it
            ob_start();
            $layout($merged);
            $merged['__content'] = ob_get_clean();
            echo self::renderFile($view, $merged);
            return;
        }

        $content = self::renderFile($view, $merged);
        if ($layout !== null) {
            $merged['__content'] = $content;
            echo self::renderFile('_layouts/' . $layout, $merged);
        } else {
            echo $content;
        }
    }

    /** Render a view to a string (for emails, etc). */
    public static function capture(string $view, array $data = []): string
    {
        return self::renderFile($view, array_merge(self::$shared, $data));
    }

    private static function renderFile(string $view, array $data): string
    {
        $file = __DIR__ . '/../Views/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $view (looked for $file)");
        }
        extract($data, EXTR_SKIP);
        extract(self::$shared, EXTR_PREFIX_SAME, 'shared_');
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }

    public static function url(string $path = '/'): string
    {
        $base = rtrim($GLOBALS['__config']['app']['url'] ?? '', '/');
        return $base . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    public static function asset(string $path): string
    {
        return self::url('/public/assets/' . ltrim($path, '/'));
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function money(float $amount): string
    {
        $cur = $GLOBALS['__config']['currency'] ?? ['symbol' => 'Br', 'code' => 'ETB'];
        return number_format($amount, 2) . ' ' . $cur['symbol'];
    }

    // ---- Section / slot helpers for layouts ----

    /** Start a section (used in views: View::section('title', 'My Title') */
    public static function section(string $name, $content = null): void
    {
        if ($content === null) {
            self::$currentSection = $name;
            ob_start();
        } else {
            self::$sections[$name] = (string)$content;
        }
    }

    public static function endSection(): void
    {
        if (self::$currentSection !== null) {
            self::$sections[self::$currentSection] = ob_get_clean();
            self::$currentSection = null;
        }
    }

    /** Render a section in the layout: View::yield('title') */
    public static function yield(string $name): void
    {
        echo self::$sections[$name] ?? '';
    }
}
