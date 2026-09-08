<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Router -- simple, fast, no dependencies.
 * Routes are registered in public/index.php.
 * Pattern: /product/{slug}  ?  regex from {slug} captures
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:array, params:array<string,string>}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $params = [];
        $regex  = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$params) {
            $params[$m[1]] = 1;
            return '([^/]+)';
        }, $path);
        $this->routes[$method][] = [
            'pattern' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params'  => $params,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path   = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path   = urldecode($path); // support UTF-8/Amharic slugs
        $method = strtoupper($method);
        // HEAD is GET without a body per HTTP spec -- route it through the
        // same GET handlers (used by uptime/status pings) instead of 404ing.
        $list   = $this->routes[$method === 'HEAD' ? 'GET' : $method] ?? [];
        foreach ($list as $r) {
            if (preg_match($r['pattern'], $path, $m)) {
                // $m[0] = full match; $m[1], $m[2]... = captured params in order
                $args = [];
                $i = 1;
                foreach ($r['params'] as $_) {
                    $args[] = $m[$i++] ?? null;
                }
                [$class, $action] = $r['handler'];
                $ctrl = new $class();
                $ctrl->$action(...$args);
                return;
            }
        }
        http_response_code(404);
        try {
            View::render('errors/404', ['path' => $path]);
        } catch (\Throwable $_) {
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:3rem;text-align:center">'
               . '<h1>404 -- Page not found</h1><p><code>' . htmlspecialchars($path) . '</code></p>'
               . '</body></html>';
        }
    }
}
