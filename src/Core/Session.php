<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $savePath = App::storage('sessions');
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0770, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');
        session_name('BKHCSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (App::basePath() ?: '') . '/',
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $k) {
            unset($_SESSION[$k]);
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function flashInput(array $input): void
    {
        unset($input['_csrf'], $input['password'], $input['password_confirm'], $input['current_password']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_view'][$key] ?? $default;
    }

    public static function ageOldInput(): void
    {
        $_SESSION['_old_view'] = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
}
