<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class View
{
    public static array $shared = [];

    public static function render(string $template, array $data = [], ?string $layout = null): void
    {
        echo self::fetch($template, $data, $layout);
    }

    public static function fetch(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::include($template, $data);
        if ($layout !== null) {
            $data['content'] = $content;
            $content = self::include($layout, $data);
        }
        return $content;
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::include($template, $data);
    }

    public static function fetchFile(string $file, array $data = [], ?string $layout = null): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . basename($file));
        }
        $content = self::includeFile($file, $data);
        if ($layout !== null) {
            $data['content'] = $content;
            $content = self::include($layout, $data);
        }
        return $content;
    }

    /** custom/templates/ wins so overrides survive updates. */
    public static function path(string $template): ?string
    {
        foreach ([HC_ROOT . '/custom/templates/', HC_ROOT . '/templates/'] as $dir) {
            if (is_file($dir . $template . '.php')) {
                return $dir . $template . '.php';
            }
        }
        return null;
    }

    private static function include(string $template, array $data): string
    {
        $file = self::path($template);
        if ($file === null || str_contains($template, '..')) {
            throw new \RuntimeException("Template not found: $template");
        }
        return self::includeFile($file, $data);
    }

    private static function includeFile(string $file, array $data): string
    {
        $data = array_merge(self::$shared, $data);
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string)ob_get_clean();
        })($file, $data);
    }

    public static function json(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function redirect(string $url, int $status = 302): never
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = App::url($url);
        }
        header('Location: ' . $url, true, $status);
        exit;
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $captcha = 'https://challenges.cloudflare.com https://hcaptcha.com https://*.hcaptcha.com https://www.google.com https://www.gstatic.com https://www.recaptcha.net';
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' $captcha; frame-src $captcha; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https: blob:; connect-src 'self' $captcha; "
            . "font-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
