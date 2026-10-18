<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Router
{
    /** @var list<array{0:list<string>,1:string,2:string|\Closure,3:array<string,mixed>}> */
    private array $routes = [];

    public function get(string $pattern, string $handler, array $opts = []): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $opts);
    }

    public function post(string $pattern, string $handler, array $opts = []): void
    {
        $this->add(['POST'], $pattern, $handler, $opts);
    }

    public function any(string $pattern, string $handler, array $opts = []): void
    {
        $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler, $opts);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $pattern, string|\Closure $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#', function ($m) {
            $re = $m[2] ?? '[^/]+';
            return '(?P<' . $m[1] . '>' . $re . ')';
        }, rtrim($pattern, '/') ?: '/') . '/?$#';
        $this->routes[] = [$methods, $regex, $handler, $opts];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as [$methods, $regex, $handler, $opts]) {
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            if (!in_array($method, $methods, true)) {
                $allowed = true;
                continue;
            }
            if ($method === 'POST' && empty($opts['nocsrf'])) {
                Csrf::verifyOrFail();
            }
            $raw = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $raw[] = $v;
                }
            }
            if ($handler instanceof \Closure) {
                $handler(...$raw);
                return;
            }
            [$class, $action] = explode('@', $handler);
            $fqcn = 'Bakkbone\\HelpCentre\\Controllers\\' . $class;
            $params = [];
            foreach ((new \ReflectionMethod($fqcn, $action))->getParameters() as $i => $p) {
                if (!array_key_exists($i, $raw)) {
                    break;
                }
                $type = $p->getType();
                $params[] = $type instanceof \ReflectionNamedType && $type->getName() === 'int' ? (int)$raw[$i] : $raw[$i];
            }
            $controller = new $fqcn();
            $controller->$action(...$params);
            return;
        }
        if ($allowed) {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }
        self::notFound();
    }

    public static function notFound(): never
    {
        http_response_code(404);
        if (str_starts_with(Request::path(), '/api/')) {
            View::json(['error' => 'Not found'], 404);
        }
        View::render('errors/404', ['title' => __('Page not found')], str_starts_with(Request::path(), '/panel') ? 'layouts/staff' : 'layouts/client');
        exit;
    }

    public static function forbidden(string $message = ''): never
    {
        $message = $message !== '' ? $message : __('You do not have permission to do that.');
        http_response_code(403);
        if (Request::wantsJson() || str_starts_with(Request::path(), '/api/')) {
            View::json(['error' => $message], 403);
        }
        View::render('errors/403', ['title' => __('Access denied'), 'message' => $message], str_starts_with(Request::path(), '/panel') ? 'layouts/staff' : 'layouts/client');
        exit;
    }
}
