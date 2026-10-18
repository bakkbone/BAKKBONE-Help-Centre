<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Crypto
{
    private const PREFIX = 'enc1:';

    public static function key(): string
    {
        $raw = (string)(App::$config['app_key'] ?? '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = (string)base64_decode(substr($raw, 7), true);
        }
        if (strlen($raw) < 32) {
            throw new \RuntimeException('app_key is missing or too short in config/config.php');
        }
        return substr(hash('sha256', $raw, true), 0, 32);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $bin = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    public static function randomToken(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
