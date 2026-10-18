<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Otp;
use Bakkbone\HelpCentre\Services\Staff;

final class StaffController extends AdminController
{
    public function index(): void
    {
        $rows = App::db()->all(
            'SELECT s.*, d.name AS dept_name, r.name AS role_name FROM staff s LEFT JOIN departments d ON d.id = s.dept_id LEFT JOIN roles r ON r.id = s.role_id ORDER BY s.active DESC, s.first_name, s.last_name'
        );
        $this->view('admin/staff', ['title' => __('Agents'), 'adminSection' => 'staff', 'rows' => $rows]);
    }

    public function form(?int $id = null): void
    {
        $s = $id ? App::db()->find('staff', $id) : null;
        if ($id && !$s) {
            $this->notFound();
        }
        $db = App::db();
        $this->view('admin/staff_form', [
            'title' => $s ? Staff::name($s) : __('New agent'), 'adminSection' => 'staff', 's' => $s,
            'depts' => $db->all('SELECT id, name FROM departments ORDER BY name'),
            'roles' => $db->all('SELECT id, name FROM roles ORDER BY id'),
            'teams' => $db->all('SELECT id, name FROM teams ORDER BY name'),
            'access' => $s ? array_map('intval', $db->col('SELECT dept_id FROM staff_dept_access WHERE staff_id = ?', [$id])) : [],
            'memberOf' => $s ? array_map('intval', $db->col('SELECT team_id FROM team_members WHERE staff_id = ?', [$id])) : [],
        ]);
    }

    public function save(?int $id = null): void
    {
        $db = App::db();
        $back = $id ? "/panel/admin/staff/$id" : '/panel/admin/staff/new';
        $username = strtolower(Request::str('username'));
        $email = strtolower(Request::str('email'));
        $errors = [];
        if (!preg_match('/^[a-z0-9._\-]{2,64}$/', $username)) {
            $errors[] = __('Username must be 2-64 characters: letters, numbers, dot, dash or underscore.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('A valid email address is required.');
        }
        if ($db->val('SELECT COUNT(*) FROM staff WHERE (LOWER(username) = ? OR LOWER(email) = ?) AND id <> ?', [$username, $email, (int)$id])) {
            $errors[] = __('Another agent already uses that username or email.');
        }
        $pw = (string)Request::post('password', '');
        if ($pw !== '' && ($err = Auth::passwordError($pw, (string)Request::post('password_confirm', '')))) {
            $errors[] = $err;
        }
        if (Request::str('first_name') === '') {
            $errors[] = __('First name is required.');
        }
        $isAdmin = Request::bool('is_admin') ? 1 : 0;
        $active = Request::bool('active') ? 1 : 0;
        if ($id === (int)$this->staff['id'] && (!$isAdmin || !$active)) {
            $errors[] = __('You cannot remove your own administrator access or deactivate yourself.');
        }
        if ($errors) {
            $this->failWith($errors, $back);
        }
        $data = [
            'username' => $username,
            'email' => $email,
            'first_name' => Request::str('first_name'),
            'last_name' => Request::str('last_name'),
            'phone' => Request::str('phone') ?: null,
            'dept_id' => Request::int('dept_id') ?: null,
            'role_id' => Request::int('role_id') ?: null,
            'is_admin' => $isAdmin,
            'active' => $active,
            'onvacation' => Request::bool('onvacation') ? 1 : 0,
            'signature' => Html::clean((string)Request::post('signature', ''), 'signature'),
            'updated_at' => DB::now(),
        ];
        if (\Bakkbone\HelpCentre\Core\Schema::hasColumn($db, 'staff', 'default_signature')) {
            $sig = Request::str('default_signature');
            $data['default_signature'] = in_array($sig, ['mine', 'dept', 'none'], true) ? $sig : null;
        }
        if ($pw !== '') {
            $data['password_hash'] = Auth::hash($pw);
        }
        if ($id) {
            $db->update('staff', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            $id = $db->insert('staff', $data);
        }
        $db->delete('staff_dept_access', 'staff_id = ?', [$id]);
        foreach (Request::intList('access') as $d) {
            if ($d !== (int)$data['dept_id']) {
                $db->insert('staff_dept_access', ['staff_id' => $id, 'dept_id' => $d]);
            }
        }
        $db->delete('team_members', 'staff_id = ?', [$id]);
        foreach (Request::intList('teams') as $t) {
            $db->insert('team_members', ['team_id' => $t, 'staff_id' => $id]);
        }
        Staff::clearCache();
        Audit::log('staff.save', 'staff', $id, ['username' => $username]);
        if (Request::bool('send_welcome')) {
            Otp::issue('staff', $id, 'login', $email, $data['first_name']);
            $this->flash('info', __('A sign-in code was emailed to {email}.', ['email' => $email]));
        }
        $this->flash('success', __('Agent saved.'));
        $this->redirect('/panel/admin/staff/' . $id);
    }

    public function delete(int $id): void
    {
        if ($id === (int)$this->staff['id']) {
            $this->failWith(__('You cannot delete yourself.'), '/panel/admin/staff');
        }
        $db = App::db();
        $db->update('tickets', ['staff_id' => null], 'staff_id = ?', [$id]);
        $db->update('tasks', ['staff_id' => null], 'staff_id = ?', [$id]);
        $db->update('departments', ['manager_id' => null], 'manager_id = ?', [$id]);
        $db->delete('staff_dept_access', 'staff_id = ?', [$id]);
        $db->delete('team_members', 'staff_id = ?', [$id]);
        $db->delete('ticket_locks', 'staff_id = ?', [$id]);
        $db->delete('staff', 'id = ?', [$id]);
        Audit::log('staff.delete', 'staff', $id);
        $this->flash('success', __('Agent deleted. Their tickets are now unassigned; thread history is kept.'));
        $this->redirect('/panel/admin/staff');
    }

    public function reset2fa(int $id): void
    {
        App::db()->update('staff', ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_counter' => null], 'id = ?', [$id]);
        Audit::log('staff.2fa_reset', 'staff', $id);
        $this->flash('success', __('Two-factor authentication was reset for this agent.'));
        $this->redirect('/panel/admin/staff/' . $id);
    }
}
