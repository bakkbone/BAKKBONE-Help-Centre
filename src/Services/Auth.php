<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Core\View;

final class Auth
{
    private static ?array $staff = null;
    private static ?array $user = null;
    private static bool $staffLoaded = false;
    private static bool $userLoaded = false;

    public static function staff(): ?array
    {
        if (self::$staffLoaded) {
            return self::$staff;
        }
        self::$staffLoaded = true;
        $id = (int)Session::get('staff_id', 0);
        if (!$id) {
            return null;
        }
        $timeout = max(5, Settings::int('staff_session_minutes')) * 60;
        if (time() - (int)Session::get('staff_last_active', 0) > $timeout) {
            self::logoutStaff();
            Session::flash('warning', __('Your session expired. Please sign in again.'));
            return null;
        }
        $s = App::db()->find('staff', $id);
        if (!$s || !$s['active']) {
            self::logoutStaff();
            return null;
        }
        Session::set('staff_last_active', time());
        return self::$staff = $s;
    }

    public static function staffId(): ?int
    {
        return PHP_SAPI === 'cli' ? null : (self::staff()['id'] ?? null);
    }

    public static function loginStaff(array $s): void
    {
        Session::regenerate();
        Session::forget('staff_pending', 'staff_pending_2fa');
        Session::set('staff_id', (int)$s['id']);
        Session::set('staff_last_active', time());
        Staff::touchLogin((int)$s['id']);
        self::$staff = App::db()->find('staff', (int)$s['id']);
        self::$staffLoaded = true;
    }

    public static function logoutStaff(): void
    {
        Session::forget('staff_id', 'staff_last_active', 'staff_pending', 'staff_pending_2fa');
        self::$staff = null;
    }

    public static function requireStaff(): array
    {
        $s = self::staff();
        if (!$s) {
            if (Request::wantsJson()) {
                View::json(['error' => __('Not signed in')], 401);
            }
            Session::set('staff_return', Request::method() === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '');
            View::redirect('/panel/login');
        }
        if (Settings::bool('staff_require_2fa') && !$s['totp_enabled'] && !str_starts_with(Request::path(), '/panel/profile') && Request::path() !== '/panel/logout') {
            Session::flash('warning', __('Two-factor authentication is required. Please set it up to continue.'));
            View::redirect('/panel/profile#two-factor');
        }
        return $s;
    }

    public static function requireAdmin(): array
    {
        $s = self::requireStaff();
        if (!$s['is_admin']) {
            \Bakkbone\HelpCentre\Core\Router::forbidden(__('Administrator access is required.'));
        }
        return $s;
    }

    public static function requirePerm(string $perm): array
    {
        $s = self::requireStaff();
        if (!Staff::can($s, $perm)) {
            \Bakkbone\HelpCentre\Core\Router::forbidden();
        }
        return $s;
    }

    public static function user(): ?array
    {
        if (self::$userLoaded) {
            return self::$user;
        }
        self::$userLoaded = true;
        $id = (int)Session::get('user_id', 0);
        if (!$id) {
            return null;
        }
        $timeout = max(5, Settings::int('client_session_minutes')) * 60;
        if (time() - (int)Session::get('user_last_active', 0) > $timeout) {
            self::logoutUser();
            return null;
        }
        $u = App::db()->find('users', $id);
        if (!$u || $u['status'] !== 'active') {
            self::logoutUser();
            return null;
        }
        Session::set('user_last_active', time());
        return self::$user = $u;
    }

    public static function userId(): ?int
    {
        return PHP_SAPI === 'cli' ? null : (self::user()['id'] ?? null);
    }

    public static function loginUser(array $u): void
    {
        Session::regenerate();
        Session::forget('user_pending', 'guest_ticket');
        Session::set('user_id', (int)$u['id']);
        Session::set('user_last_active', time());
        App::db()->update('users', ['last_login_at' => DB::now(), 'verified' => 1], 'id = ?', [$u['id']]);
        self::$user = App::db()->find('users', (int)$u['id']);
        self::$userLoaded = true;
    }

    public static function logoutUser(): void
    {
        Session::forget('user_id', 'user_last_active', 'user_pending', 'guest_ticket');
        self::$user = null;
    }

    /** @return array{0:int,1:int}|null ticket id, user id */
    public static function guest(): ?array
    {
        $g = Session::get('guest_ticket');
        return is_array($g) ? $g : null;
    }

    public static function setGuest(int $ticketId, int $userId): void
    {
        Session::regenerate();
        Session::set('guest_ticket', ['ticket_id' => $ticketId, 'user_id' => $userId]);
        Session::set('user_last_active', time());
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            Session::set('user_return', Request::method() === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '');
            Session::flash('info', __('Please sign in to continue.'));
            View::redirect('/login');
        }
        return $u;
    }

    public static function throttled(string $scope, string $identifier): bool
    {
        $max = max(3, Settings::int('login_max_attempts'));
        $since = gmdate('Y-m-d H:i:s', time() - max(1, Settings::int('login_lockout_minutes')) * 60);
        $db = App::db();
        $byId = (int)$db->val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND success = 0 AND created_at > ?', [$scope, strtolower($identifier), $since]);
        $byIp = (int)$db->val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [Request::ip(), $since]);
        return $byId >= $max || $byIp >= $max * 4;
    }

    public static function recordAttempt(string $scope, string $identifier, bool $success): void
    {
        $db = App::db();
        $db->insert('login_attempts', [
            'scope' => $scope,
            'identifier' => mb_substr(strtolower($identifier), 0, 190),
            'ip' => Request::ip(),
            'success' => $success ? 1 : 0,
            'created_at' => DB::now(),
        ]);
        if ($success) {
            $db->delete('login_attempts', 'scope = ? AND identifier = ? AND success = 0', [$scope, strtolower($identifier)]);
        }
    }

    public static function lockoutMessage(): string
    {
        return _n('Too many attempts. Please wait {n} minute and try again.', 'Too many attempts. Please wait {n} minutes and try again.', Settings::int('login_lockout_minutes'));
    }

    public static function passwordError(string $pw, string $confirm): ?string
    {
        if (mb_strlen($pw) < 10) {
            return __('Passwords must be at least 10 characters long.');
        }
        if ($pw !== $confirm) {
            return __('The passwords do not match.');
        }
        if (preg_match('/^(.)\1+$/', $pw) || in_array(strtolower($pw), ['password123', '1234567890', 'qwertyuiop', 'helpdesk123'], true)) {
            return __('Please choose a less predictable password.');
        }
        return null;
    }

    public static function hash(string $pw): string
    {
        return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $table, array $row, string $pw): bool
    {
        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($pw, $hash)) {
            // Constant-ish time even for accounts without passwords
            if ($hash === '') {
                password_verify($pw, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            return false;
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash($hash, $algo)) {
            App::db()->update($table, ['password_hash' => self::hash($pw)], 'id = ?', [$row['id']]);
        }
        return true;
    }
}
