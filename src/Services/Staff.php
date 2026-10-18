<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;

final class Staff
{
    public const PERMISSIONS = [
        'ticket.create' => 'Create tickets on behalf of users',
        'ticket.edit' => 'Edit ticket details',
        'ticket.assign' => 'Assign tickets',
        'ticket.transfer' => 'Transfer tickets between departments',
        'ticket.reply' => 'Post replies to users',
        'ticket.close' => 'Close / resolve tickets',
        'ticket.merge' => 'Merge tickets',
        'ticket.delete' => 'Delete tickets',
        'ticket.export' => 'Export tickets to CSV',
        'task.manage' => 'Create and manage tasks',
        'user.manage' => 'Create and edit users',
        'user.delete' => 'Delete users',
        'org.manage' => 'Create and edit organisations',
        'kb.manage' => 'Manage the knowledge base',
        'canned.manage' => 'Manage canned responses',
        'ban.manage' => 'Manage the ban list',
    ];

    /** @var array<int,array<string,mixed>|null> */
    private static array $cache = [];

    public static function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = App::db()->find('staff', $id);
        }
        return self::$cache[$id];
    }

    public static function name(?array $s): string
    {
        if (!$s) {
            return '';
        }
        $n = trim($s['first_name'] . ' ' . $s['last_name']);
        return $n !== '' ? $n : (string)$s['username'];
    }

    public static function nameById(?int $id): string
    {
        return self::name(self::find($id));
    }

    /** @return list<array<string,mixed>> */
    public static function active(): array
    {
        return App::db()->all('SELECT * FROM staff WHERE active = 1 ORDER BY first_name, last_name');
    }

    /** @return array<string,string> */
    public static function permissions(): array
    {
        return array_map('__', self::PERMISSIONS + \Bakkbone\HelpCentre\Core\Addons::$permissions);
    }

    public static function can(?array $staff, string $perm): bool
    {
        if (!$staff) {
            return false;
        }
        if ($staff['is_admin']) {
            return true;
        }
        static $roles = [];
        $rid = (int)$staff['role_id'];
        if (!isset($roles[$rid])) {
            $perms = App::db()->val('SELECT permissions FROM roles WHERE id = ?', [$rid]);
            $roles[$rid] = json_decode((string)$perms, true) ?: [];
        }
        return in_array($perm, $roles[$rid], true);
    }

    /** @return list<int> */
    public static function deptIds(array $staff): array
    {
        if ($staff['is_admin']) {
            return array_map('intval', App::db()->col('SELECT id FROM departments'));
        }
        $ids = array_map('intval', App::db()->col('SELECT dept_id FROM staff_dept_access WHERE staff_id = ?', [$staff['id']]));
        if ($staff['dept_id']) {
            $ids[] = (int)$staff['dept_id'];
        }
        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    public static function teamIds(int $staffId): array
    {
        return array_map('intval', App::db()->col('SELECT team_id FROM team_members WHERE staff_id = ?', [$staffId]));
    }

    public static function canAccessTicket(array $staff, array $ticket): bool
    {
        if ($staff['is_admin']) {
            return true;
        }
        if ((int)$ticket['staff_id'] === (int)$staff['id']) {
            return true;
        }
        if ($ticket['team_id'] && in_array((int)$ticket['team_id'], self::teamIds((int)$staff['id']), true)) {
            return true;
        }
        return in_array((int)$ticket['dept_id'], self::deptIds($staff), true);
    }

    /** @return array{0:string,1:list<mixed>} */
    public static function ticketScope(array $staff, string $alias = 't'): array
    {
        if ($staff['is_admin']) {
            return ['1=1', []];
        }
        $depts = self::deptIds($staff);
        $teams = self::teamIds((int)$staff['id']);
        $parts = ["$alias.staff_id = ?"];
        $params = [(int)$staff['id']];
        if ($depts) {
            $parts[] = "$alias.dept_id IN " . DB::in($depts);
            array_push($params, ...$depts);
        }
        if ($teams) {
            $parts[] = "$alias.team_id IN " . DB::in($teams);
            array_push($params, ...$teams);
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    public static function deptMembers(int $deptId): array
    {
        return App::db()->all(
            'SELECT DISTINCT s.* FROM staff s LEFT JOIN staff_dept_access a ON a.staff_id = s.id
             WHERE s.active = 1 AND s.onvacation = 0 AND (s.dept_id = ? OR a.dept_id = ?)',
            [$deptId, $deptId]
        );
    }

    public static function teamMembers(int $teamId): array
    {
        return App::db()->all(
            'SELECT s.* FROM staff s JOIN team_members m ON m.staff_id = s.id WHERE m.team_id = ? AND s.active = 1 AND s.onvacation = 0',
            [$teamId]
        );
    }

    public static function byEmail(string $email): ?array
    {
        return App::db()->row('SELECT * FROM staff WHERE LOWER(email) = ? AND active = 1', [strtolower(trim($email))]);
    }

    public static function byLogin(string $login): ?array
    {
        $login = strtolower(trim($login));
        return App::db()->row('SELECT * FROM staff WHERE LOWER(username) = ? OR LOWER(email) = ?', [$login, $login]);
    }

    public static function admins(): array
    {
        return App::db()->all('SELECT * FROM staff WHERE is_admin = 1 AND active = 1');
    }

    public static function touchLogin(int $id): void
    {
        App::db()->update('staff', ['last_login_at' => DB::now()], 'id = ?', [$id]);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
