<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Otp;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
use Bakkbone\HelpCentre\Services\Users;

final class UserController extends StaffController
{
    public function index(): void
    {
        $db = App::db();
        $q = Request::str('q');
        [$page, $per, $offset] = $this->paging(30);
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR o.name LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
        }
        if ($org = Request::int('org_id')) {
            $where .= ' AND u.org_id = ?';
            $params[] = $org;
        }
        [$sort, $dir] = self::sortFromRequest();
        $order = self::SORTS[$sort] . ' ' . strtoupper($dir) . ', u.name ASC, u.id ASC';
        $total = (int)$db->val("SELECT COUNT(*) FROM users u LEFT JOIN organizations o ON o.id = u.org_id WHERE $where", $params);
        $rows = $db->all(
            "SELECT u.*, o.name AS org_name, (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id AND t.merged_into IS NULL) AS ticket_count
             FROM users u LEFT JOIN organizations o ON o.id = u.org_id WHERE $where ORDER BY $order LIMIT $per OFFSET $offset",
            $params
        );
        $this->view('staff/users', ['title' => __('Users'), 'users' => $rows, 'q' => $q, 'page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total,
            'sort' => $sort, 'dir' => $dir, 'org' => Request::int('org_id') ?: null, 'canDelete' => Staff::can($this->staff, 'user.delete')]);
    }

    private const SORTS = [
        'name' => 'u.name', 'email' => 'u.email', 'org' => 'o.name', 'tickets' => 'ticket_count',
        'status' => "CASE WHEN u.status = 'locked' THEN 0 WHEN u.verified = 1 THEN 2 ELSE 1 END", 'created' => 'u.created_at',
    ];

    public const SORT_DESC_FIRST = ['tickets', 'created'];

    /** @return array{0:string,1:string} */
    private static function sortFromRequest(): array
    {
        $sort = Request::str('sort', 'name');
        $dir = Request::str('dir');
        if (str_contains($sort, ':')) {
            [$sort, $dir] = explode(':', $sort, 2);
        }
        if (!isset(self::SORTS[$sort])) {
            $sort = 'name';
        }
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = in_array($sort, self::SORT_DESC_FIRST, true) ? 'desc' : 'asc';
        }
        return [$sort, $dir];
    }

    public function cleanup(): void
    {
        $this->requirePerm('user.delete');
        $db = App::db();
        [$w, $p] = Users::unusedWhere(false);
        $unusedGuests = (int)$db->val("SELECT COUNT(*) FROM users u WHERE $w", $p);
        [$w, $p] = Users::unusedWhere(true);
        $unusedAll = (int)$db->val("SELECT COUNT(*) FROM users u WHERE $w", $p);
        $unusedSample = $db->all("SELECT u.id, u.name, u.email, u.verified, u.created_at FROM users u WHERE $w ORDER BY u.created_at, u.id LIMIT 25", $p);

        $banned = ['users' => 0, 'tickets' => 0, 'sample' => []];
        if ($b = Users::bannedWhere()) {
            [$w, $p] = $b;
            $banned['users'] = (int)$db->val("SELECT COUNT(*) FROM users u WHERE $w", $p);
            $banned['tickets'] = (int)$db->val("SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.user_id WHERE $w", $p);
            $banned['sample'] = $db->all("SELECT u.id, u.name, u.email, (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id) AS ticket_count FROM users u WHERE $w ORDER BY ticket_count DESC, u.email LIMIT 25", $p);
        }
        $this->view('staff/users_cleanup', [
            'title' => __('Clean up users'), 'unusedGuests' => $unusedGuests, 'unusedAll' => $unusedAll, 'unusedSample' => $unusedSample,
            'banned' => $banned, 'banCount' => (int)$db->val('SELECT COUNT(*) FROM banlist'),
        ]);
    }

    public function cleanupRun(): void
    {
        $this->requirePerm('user.delete');
        @set_time_limit(120);
        $db = App::db();
        $what = Request::str('what');
        $deadline = microtime(true) + 25;
        $users = 0;
        $tickets = 0;
        $more = false;

        if ($what === 'unused') {
            $registered = Request::bool('include_registered');
            [$w, $p] = Users::unusedWhere($registered);
            while (true) {
                $ids = array_map('intval', $db->col("SELECT u.id FROM users u WHERE $w ORDER BY u.id LIMIT 500", $p));
                if (!$ids) {
                    break;
                }
                $in = implode(',', $ids);
                $db->tx(function (DB $db) use ($in) {
                    $db->delete('ticket_collaborators', "user_id IN ($in)");
                    $db->delete('users', "id IN ($in)");
                });
                $users += count($ids);
                if (microtime(true) > $deadline) {
                    $more = (bool)$db->val("SELECT COUNT(*) FROM users u WHERE $w", $p);
                    break;
                }
            }
            if ($users) {
                Audit::log('user.cleanup', 'user', null, ['type' => 'no_tickets', 'users' => $users, 'include_registered' => $registered]);
            }
            $msg = $users ? _n('Deleted {n} user with no tickets.', 'Deleted {n} users with no tickets.', $users) : __('There were no users with no tickets to delete.');
        } elseif ($what === 'banned') {
            if (!Request::bool('confirm')) {
                $this->failWith(__('Tick the box to confirm that the tickets should be deleted too.'), '/panel/users/cleanup');
            }
            $b = Users::bannedWhere();
            while ($b) {
                [$w, $p] = $b;
                $batch = $db->all("SELECT u.id, u.email FROM users u WHERE $w ORDER BY u.id LIMIT 50", $p);
                if (!$batch) {
                    break;
                }
                foreach ($batch as $u) {
                    foreach ($db->all('SELECT * FROM tickets WHERE user_id = ?', [$u['id']]) as $t) {
                        Tickets::delete($t);
                        $tickets++;
                        if (microtime(true) > $deadline) {
                            $more = true;
                            break 3;
                        }
                    }
                    $db->delete('ticket_collaborators', 'user_id = ?', [$u['id']]);
                    $db->delete('users', 'id = ?', [$u['id']]);
                    Audit::log('user.delete', 'user', (int)$u['id'], ['email' => $u['email'], 'reason' => 'banned']);
                    $users++;
                }
                if (microtime(true) > $deadline) {
                    $more = (bool)$db->val("SELECT COUNT(*) FROM users u WHERE $w", $p);
                    break;
                }
            }
            $msg = $users || $tickets
                ? __('Deleted {users} and {tickets}.', ['users' => _n('{n} banned user', '{n} banned users', $users), 'tickets' => _n('{n} ticket', '{n} tickets', $tickets)])
                : __('There were no banned users to delete.');
        } else {
            $this->redirect('/panel/users/cleanup');
        }
        $this->flash($more ? 'warning' : 'success', $msg . ($more ? ' ' . __('There are more left. Run it again to continue.') : ''));
        $this->redirect('/panel/users/cleanup');
    }

    public function lookup(): void
    {
        $q = Request::str('q');
        if (mb_strlen($q) < 2) {
            $this->json([]);
        }
        $like = '%' . $q . '%';
        $rows = App::db()->all('SELECT id, name, email, phone FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 10', [$like, $like]);
        $this->json($rows);
    }

    public function create(): void
    {
        $this->requirePerm('user.manage');
        $this->view('staff/user_form', ['title' => __('New user'), 'u' => null, 'orgs' => App::db()->all('SELECT id, name FROM organizations ORDER BY name')]);
    }

    public function store(): void
    {
        $this->requirePerm('user.manage');
        $email = strtolower(Request::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failWith(__('A valid email address is required.'), '/panel/users/new');
        }
        if (Users::byEmail($email)) {
            $this->failWith(__('A user with that email address already exists.'), '/panel/users/new');
        }
        $u = Users::findOrCreate($email, Request::str('name'), Request::str('phone') ?: null);
        $org = Request::int('org_id');
        App::db()->update('users', ['org_id' => $org ?: $u['org_id'], 'notes' => Request::str('notes') ?: null], 'id = ?', [$u['id']]);
        Audit::log('user.create', 'user', (int)$u['id'], ['email' => $email]);
        $this->flash('success', __('User created.'));
        $this->redirect('/panel/users/' . $u['id']);
    }

    public function show(int $id): void
    {
        $u = Users::find($id);
        if (!$u) {
            $this->notFound();
        }
        $db = App::db();
        [$scope, $params] = Staff::ticketScope($this->staff);
        $tickets = $db->all(
            "SELECT t.* FROM tickets t WHERE $scope AND t.merged_into IS NULL AND (t.user_id = ? OR t.id IN (SELECT ticket_id FROM ticket_collaborators WHERE user_id = ?)) ORDER BY t.created_at DESC LIMIT 100",
            array_merge($params, [$id, $id])
        );
        $this->view('staff/user', [
            'title' => $u['name'], 'u' => $u, 'tickets' => $tickets,
            'orgs' => $db->all('SELECT id, name FROM organizations ORDER BY name'),
            'org' => Users::org($u['org_id'] ? (int)$u['org_id'] : null),
            'banned' => Users::isBanned((string)$u['email']),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePerm('user.manage');
        $u = Users::find($id);
        if (!$u) {
            $this->notFound();
        }
        $email = strtolower(Request::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failWith(__('A valid email address is required.'), '/panel/users/' . $id);
        }
        $other = Users::byEmail($email);
        if ($other && (int)$other['id'] !== $id) {
            $this->failWith(__('Another user already has that email address.'), '/panel/users/' . $id);
        }
        $data = [
            'name' => Request::str('name') ?: $email,
            'email' => $email,
            'phone' => Request::str('phone') ?: null,
            'org_id' => Request::int('org_id') ?: null,
            'status' => Request::str('status') === 'locked' ? 'locked' : 'active',
            'notes' => Request::str('notes') ?: null,
            'updated_at' => DB::now(),
        ];
        if (Request::bool('clear_password')) {
            $data['password_hash'] = null;
        }
        App::db()->update('users', $data, 'id = ?', [$id]);
        Audit::log('user.update', 'user', $id);
        $this->flash('success', __('User saved.'));
        $this->redirect('/panel/users/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePerm('user.delete');
        $u = Users::find($id);
        if (!$u) {
            $this->notFound();
        }
        $db = App::db();
        if (Request::bool('delete_tickets')) {
            foreach ($db->all('SELECT * FROM tickets WHERE user_id = ?', [$id]) as $t) {
                Tickets::delete($t);
            }
        } elseif ((int)$db->val('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$id]) > 0) {
            $this->failWith(__('This user still owns tickets. Tick "also delete their tickets" to remove them.'), '/panel/users/' . $id);
        }
        $db->delete('ticket_collaborators', 'user_id = ?', [$id]);
        $db->delete('users', 'id = ?', [$id]);
        Audit::log('user.delete', 'user', $id, ['email' => $u['email']]);
        $this->flash('success', __('User deleted.'));
        $this->redirect('/panel/users');
    }

    public function sendCode(int $id): void
    {
        $this->requirePerm('user.manage');
        $u = Users::find($id);
        if (!$u) {
            $this->notFound();
        }
        Otp::issue('user', $id, 'login', (string)$u['email'], (string)$u['name']);
        $this->flash('success', __('A sign-in code was emailed to {email}.', ['email' => $u['email']]));
        $this->redirect('/panel/users/' . $id);
    }
}
