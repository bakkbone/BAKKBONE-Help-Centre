<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\Http;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;

final class Captcha
{
    public static function provider(): string
    {
        $p = (string)Settings::get('captcha_provider');
        return in_array($p, ['turnstile', 'hcaptcha', 'recaptcha'], true) && Settings::get('captcha_site_key') ? $p : 'none';
    }

    public static function widget(): string
    {
        $key = e(Settings::get('captcha_site_key'));
        $token = bin2hex(random_bytes(8));
        Session::set('form_ts_' . $token, time());
        $honeypot = '<div class="hp" aria-hidden="true"><label>' . t('Leave this empty') . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="_ft" value="' . $token . '">';
        return $honeypot . match (self::provider()) {
            'turnstile' => '<div class="cf-turnstile captcha" data-sitekey="' . $key . '"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
            'hcaptcha' => '<div class="h-captcha captcha" data-sitekey="' . $key . '"></div><script src="https://js.hcaptcha.com/1/api.js" async defer></script>',
            'recaptcha' => '<div class="g-recaptcha captcha" data-sitekey="' . $key . '"></div><script src="https://www.google.com/recaptcha/api.js" async defer></script>',
            default => '',
        };
    }

    public static function verify(): bool
    {
        if (trim((string)Request::post('website', '')) !== '') {
            return false;
        }
        $ft = preg_replace('/[^a-f0-9]/', '', (string)Request::post('_ft', ''));
        $ts = (int)Session::get('form_ts_' . $ft, 0);
        Session::forget('form_ts_' . $ft);
        if ($ts && time() - $ts < 2) {
            return false;
        }
        $provider = self::provider();
        if ($provider === 'none') {
            return true;
        }
        [$field, $url] = match ($provider) {
            'turnstile' => ['cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'],
            'hcaptcha' => ['h-captcha-response', 'https://hcaptcha.com/siteverify'],
            'recaptcha' => ['g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify'],
        };
        $response = (string)Request::post($field, '');
        if ($response === '') {
            return false;
        }
        try {
            [$status, $body] = Http::request('POST', $url, http_build_query([
                'secret' => (string)Settings::get('captcha_secret'),
                'response' => $response,
                'remoteip' => Request::ip(),
            ]), ['Content-Type: application/x-www-form-urlencoded'], 10);
            $json = json_decode($body, true);
            return $status === 200 && !empty($json['success']);
        } catch (\Throwable) {
            return false;
        }
    }
}
