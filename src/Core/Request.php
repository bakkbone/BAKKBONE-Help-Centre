<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function path(): string
    {
        if (!empty($_SERVER['PATH_INFO'])) {
            $path = (string)$_SERVER['PATH_INFO'];
        } else {
            $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $base = App::basePath();
            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            if (str_starts_with($uri, '/index.php')) {
                $uri = substr($uri, strlen('/index.php'));
            }
            $path = $uri;
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return $path;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function bool(string $key): bool
    {
        $v = self::input($key);
        return in_array($v, ['1', 1, true, 'on', 'yes', 'true'], true);
    }

    /** @return list<int> */
    public static function intList(string $key): array
    {
        $v = self::input($key, []);
        if (!is_array($v)) {
            $v = $v === '' || $v === null ? [] : explode(',', (string)$v);
        }
        return array_values(array_unique(array_filter(array_map('intval', $v))));
    }

    /** @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}> */
    public static function files(string $key): array
    {
        if (empty($_FILES[$key])) {
            return [];
        }
        $f = $_FILES[$key];
        $out = [];
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[] = ['name' => (string)$name, 'type' => (string)$f['type'][$i], 'tmp_name' => (string)$f['tmp_name'][$i], 'error' => (int)$f['error'][$i], 'size' => (int)$f['size'][$i]];
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $out[] = ['name' => (string)$f['name'], 'type' => (string)$f['type'], 'tmp_name' => (string)$f['tmp_name'], 'error' => (int)$f['error'], 'size' => (int)$f['size']];
        }
        return $out;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string)$_SERVER[$key];
        }
        if (strtolower($name) === 'content-type' && isset($_SERVER['CONTENT_TYPE'])) {
            return (string)$_SERVER['CONTENT_TYPE'];
        }
        return null;
    }

    public static function body(): string
    {
        return (string)file_get_contents('php://input');
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (self::fromTrustedProxy() && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private static function fromTrustedProxy(): bool
    {
        $trusted = (array)(App::$config['trusted_proxies'] ?? []);
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' && (in_array($remote, $trusted, true) || in_array('*', $trusted, true));
    }

    public static function ip(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::fromTrustedProxy()) {
            $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
            $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
        }
        return $remote;
    }

    public static function userAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function wantsJson(): bool
    {
        return str_contains((string)self::header('Accept'), 'application/json')
            || strtolower((string)self::header('X-Requested-With')) === 'xmlhttprequest';
    }
}
