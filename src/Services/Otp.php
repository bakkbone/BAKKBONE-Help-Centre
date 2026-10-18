<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;

final class Otp
{
    public const MAX_ATTEMPTS = 5;

    /** Returns false when rate-limited; callers show a generic message so nothing is revealed. */
    public static function issue(string $actor, int $actorId, string $purpose, string $email, string $name): bool
    {
        $db = App::db();
        $recent = (int)$db->val('SELECT COUNT(*) FROM otp_codes WHERE actor = ? AND actor_id = ? AND created_at > ?', [$actor, $actorId, gmdate('Y-m-d H:i:s', time() - 900)]);
        if ($recent >= 5) {
            return false;
        }
        $db->update('otp_codes', ['used_at' => DB::now()], 'actor = ? AND actor_id = ? AND purpose = ? AND used_at IS NULL', [$actor, $actorId, $purpose]);
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = max(3, Settings::int('otp_ttl_minutes'));
        $db->insert('otp_codes', [
            'actor' => $actor,
            'actor_id' => $actorId,
            'purpose' => $purpose,
            'code_hash' => self::hashCode($actor, $actorId, $purpose, $code),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
            'ip' => PHP_SAPI === 'cli' ? null : Request::ip(),
            'created_at' => DB::now(),
        ]);
        $template = match (true) {
            $purpose === 'reset' => 'password.reset',
            $purpose === 'verify' => 'user.verify',
            $actor === 'staff' => 'staff.otp',
            default => 'user.otp',
        };
        Notifier::simple($template, $email, $name, ['otp' => ['code' => $code, 'minutes' => $minutes, 'ip' => PHP_SAPI === 'cli' ? '' : Request::ip()]]);
        return true;
    }

    public static function verify(string $actor, int $actorId, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $db = App::db();
        $row = $db->row(
            'SELECT * FROM otp_codes WHERE actor = ? AND actor_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$actor, $actorId, $purpose, DB::now()]
        );
        if (!$row) {
            return false;
        }
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            $db->update('otp_codes', ['used_at' => DB::now()], 'id = ?', [$row['id']]);
            return false;
        }
        if (!hash_equals((string)$row['code_hash'], self::hashCode($actor, $actorId, $purpose, $code))) {
            $db->update('otp_codes', ['attempts' => (int)$row['attempts'] + 1], 'id = ?', [$row['id']]);
            return false;
        }
        $db->update('otp_codes', ['used_at' => DB::now()], 'id = ?', [$row['id']]);
        return true;
    }

    private static function hashCode(string $actor, int $id, string $purpose, string $code): string
    {
        return Crypto::hmac("otp|$actor|$id|$purpose|$code");
    }

    public static function cleanup(): void
    {
        App::db()->delete('otp_codes', 'expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        App::db()->delete('login_attempts', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    }
}
