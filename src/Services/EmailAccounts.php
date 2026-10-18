<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\Settings;

final class EmailAccounts
{
    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    public static function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = App::db()->find('email_accounts', $id);
        }
        return self::$cache[$id];
    }

    /** @return list<array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        return App::db()->all('SELECT * FROM email_accounts' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name');
    }

    public static function default(): ?array
    {
        $acc = self::find((int)Settings::get('default_email_account_id'));
        if ($acc && $acc['active']) {
            return $acc;
        }
        $first = App::db()->row('SELECT * FROM email_accounts WHERE active = 1 ORDER BY id LIMIT 1');
        return $first ?: null;
    }

    public static function alerts(): ?array
    {
        return self::find((int)Settings::get('alert_email_account_id')) ?? self::default();
    }

    public static function forDept(?int $deptId): ?array
    {
        if ($deptId) {
            $id = (int)App::db()->val('SELECT email_account_id FROM departments WHERE id = ?', [$deptId]);
            $acc = self::find($id);
            if ($acc && $acc['active']) {
                return $acc;
            }
        }
        return self::default();
    }

    public static function byAddress(string $email): ?array
    {
        return App::db()->row('SELECT * FROM email_accounts WHERE LOWER(email) = ? LIMIT 1', [strtolower(trim($email))]);
    }

    /** Used for loop prevention. */
    public static function addresses(): array
    {
        return array_map('strtolower', App::db()->col('SELECT email FROM email_accounts'));
    }

    public static function secret(array $acc, string $field): string
    {
        return (string)Crypto::decrypt($acc[$field] ?? null);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
