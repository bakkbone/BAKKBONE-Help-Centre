<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;

final class Users
{
    public static function find(?int $id): ?array
    {
        return $id ? App::db()->find('users', $id) : null;
    }

    public static function byEmail(string $email): ?array
    {
        return App::db()->row('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    }

    public static function findOrCreate(string $email, string $name = '', ?string $phone = null): array
    {
        $email = strtolower(trim($email));
        $u = self::byEmail($email);
        if ($u) {
            $updates = [];
            if (trim($name) !== '' && ($u['name'] === '' || $u['name'] === $u['email'])) {
                $updates['name'] = trim($name);
            }
            if ($phone && !$u['phone']) {
                $updates['phone'] = $phone;
            }
            if ($updates) {
                $updates['updated_at'] = DB::now();
                App::db()->update('users', $updates, 'id = ?', [$u['id']]);
                $u = array_merge($u, $updates);
            }
            return $u;
        }
        $id = App::db()->insert('users', [
            'name' => trim($name) !== '' ? mb_substr(trim($name), 0, 190) : $email,
            'email' => $email,
            'phone' => $phone,
            'org_id' => self::orgForEmail($email),
            'status' => 'active',
            'verified' => 0,
            'created_at' => DB::now(),
            'updated_at' => DB::now(),
        ]);
        $user = (array)App::db()->find('users', $id);
        \Bakkbone\HelpCentre\Core\Hooks::fire('user.created', $user);
        return $user;
    }

    public static function orgForEmail(string $email): ?int
    {
        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return null;
        }
        foreach (App::db()->all("SELECT id, domains FROM organizations WHERE domains IS NOT NULL AND domains <> ''") as $o) {
            $domains = array_map(fn($d) => strtolower(trim(ltrim(trim($d), '@'))), explode(',', (string)$o['domains']));
            if (in_array($domain, $domains, true)) {
                return (int)$o['id'];
            }
        }
        return null;
    }

    // SQL condition on alias u.
    /** @return array{0:string,1:array<int,mixed>} */
    public static function unusedWhere(bool $includeRegistered = false): array
    {
        $sql = 'NOT EXISTS (SELECT 1 FROM tickets t WHERE t.user_id = u.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM ticket_collaborators c WHERE c.user_id = u.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM thread_entries te WHERE te.user_id = u.id)'
            . ' AND (u.created_at IS NULL OR u.created_at < ?)';
        $params = [gmdate('Y-m-d H:i:s', time() - 86400)];
        if (!$includeRegistered) {
            $sql .= ' AND u.verified = 0 AND (u.password_hash IS NULL OR u.password_hash = \'\')';
        }
        return [$sql, $params];
    }

    // SQL condition on alias u.
    /** @return array{0:string,1:array<int,mixed>}|null */
    public static function bannedWhere(): ?array
    {
        $emails = [];
        $domains = [];
        foreach (App::db()->col('SELECT pattern FROM banlist') as $p) {
            $p = strtolower(trim((string)$p));
            if ($p === '') {
                continue;
            }
            if (str_starts_with($p, '@') || !str_contains($p, '@')) {
                $domains[] = '%@' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], ltrim($p, '@'));
            } else {
                $emails[] = $p;
            }
        }
        $parts = [];
        $params = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            $parts[] = 'LOWER(u.email) IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            array_push($params, ...$chunk);
        }
        foreach ($domains as $d) {
            $parts[] = "LOWER(u.email) LIKE ? ESCAPE '!'";
            $params[] = $d;
        }
        return $parts ? ['(' . implode(' OR ', $parts) . ')', $params] : null;
    }

    public static function isBanned(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = '@' . substr(strrchr($email, '@') ?: '', 1);
        return (bool)App::db()->val('SELECT COUNT(*) FROM banlist WHERE pattern = ? OR pattern = ? OR pattern = ?', [$email, $domain, ltrim($domain, '@')]);
    }

    public static function firstName(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, '@')) {
            return '';
        }
        return explode(' ', $name)[0] ?? $name;
    }

    public static function org(?int $orgId): ?array
    {
        return $orgId ? App::db()->find('organizations', $orgId) : null;
    }
}
