<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class App
{
    /** @var array<string,mixed> */
    public static array $config = [];
    public static bool $installed = false;
    private static ?DB $db = null;

    public static function configPath(): string
    {
        return HC_ROOT . '/config/config.php';
    }

    public static function boot(): void
    {
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        if (is_file(self::configPath())) {
            $cfg = require self::configPath();
            if (is_array($cfg)) {
                self::$config = $cfg;
                self::$installed = !empty($cfg['installed']);
            }
        }

        $debug = (bool)(self::$config['debug'] ?? false);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        $logDir = self::storage('logs');
        if (is_dir($logDir) && is_writable($logDir)) {
            ini_set('error_log', $logDir . '/php-error.log');
        }

        set_exception_handler([self::class, 'handleException']);
    }

    public static function handleException(\Throwable $e): void
    {
        error_log('[HelpCentre] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
        if (self::$installed) {
            try {
                Log::error('Unhandled exception', $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
            } catch (\Throwable) {
            }
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        $debug = (bool)(self::$config['debug'] ?? false);
        try {
            $words = [lang_attr(), t('Error'), t('Something went wrong'), t('The error has been logged. Please try again shortly.')];
        } catch (\Throwable) {
            $words = ['', 'Error', 'Something went wrong', 'The error has been logged. Please try again shortly.'];
        }
        echo '<!doctype html><html' . $words[0] . '><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $words[1] . '</title><body style="font:400 15px/1.5 Roboto,system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1a1c1e;background:#f9f9fc;max-width:640px;margin:10vh auto;padding:0 16px">'
            . '<h1 style="font-size:28px;line-height:36px;font-weight:400">' . $words[2] . '</h1><p>' . $words[3] . '</p>';
        if ($debug) {
            echo '<pre style="white-space:pre-wrap;background:#ededf0;padding:12px 16px;border-radius:12px">'
                . htmlspecialchars($e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString())
                . '</pre>';
        }
        echo '</body>';
    }

    public static function db(): DB
    {
        if (self::$db === null) {
            if (empty(self::$config['db'])) {
                throw new \RuntimeException('Database is not configured.');
            }
            self::$db = new DB(self::$config['db']);
        }
        return self::$db;
    }

    public static function setDb(DB $db): void
    {
        self::$db = $db;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $node = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function debug(): bool
    {
        return (bool)(self::$config['debug'] ?? false);
    }

    public static function baseUrl(): string
    {
        $configured = (string)(self::$config['base_url'] ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        if (PHP_SAPI === 'cli') {
            return 'http://localhost';
        }
        $scheme = Request::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        // The root .htaccess rewrites /x to /public/x, so hide /public from generated URLs.
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_ends_with($dir, '/public') && !str_starts_with($uri, $dir . '/') && $uri !== $dir) {
            $dir = substr($dir, 0, -7);
        }
        return $scheme . '://' . $host . $dir;
    }

    public static function basePath(): string
    {
        $path = parse_url(self::baseUrl(), PHP_URL_PATH) ?: '';
        return rtrim($path, '/');
    }

    public static function url(string $path = '', array $query = []): string
    {
        $pretty = (bool)(self::$config['pretty_urls'] ?? self::$installed);
        $path = '/' . ltrim($path, '/');
        $url = self::baseUrl() . ($pretty ? '' : '/index.php') . ($path === '/' ? '/' : $path);
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    public static function asset(string $path): string
    {
        $file = HC_ROOT . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : HC_VERSION;
        // Without URL rewriting, requests come via the root index.php and static files live under /public.
        $prefix = defined('HC_VIA_ROOT') ? '/public' : '';
        return self::baseUrl() . $prefix . '/assets/' . ltrim($path, '/') . '?v=' . $v;
    }

    public static function storage(string $sub = ''): string
    {
        $base = rtrim((string)(self::$config['storage_path'] ?? (HC_ROOT . '/storage')), '/\\');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }
}
