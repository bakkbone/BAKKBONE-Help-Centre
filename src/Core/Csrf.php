<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function valid(): bool
    {
        $sent = (string)($_POST['_csrf'] ?? Request::header('X-CSRF-Token') ?? '');
        return $sent !== '' && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
    }

    public static function verifyOrFail(): void
    {
        if (!self::valid()) {
            http_response_code(419);
            if (Request::wantsJson()) {
                View::json(['error' => __('Your session expired. Please reload the page.')], 419);
            }
            echo '<!doctype html><html' . lang_attr() . '><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . t('Session expired') . '</title>'
                . '<body style="font:400 15px/1.5 Roboto,system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1a1c1e;background:#f9f9fc;max-width:560px;margin:10vh auto;padding:0 16px">'
                . '<h1 style="font-size:28px;line-height:36px;font-weight:400">' . t('Session expired') . '</h1><p>' . t('Your form session expired or was invalid. Please go back, reload the page and try again.') . '</p>'
                . '<p><a href="' . htmlspecialchars(App::url('/')) . '">' . t('Home') . '</a></p></body>';
            exit;
        }
    }
}
