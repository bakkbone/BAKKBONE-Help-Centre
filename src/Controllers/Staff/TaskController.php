<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Mail\Mailer;
use Bakkbone\HelpCentre\Services\EmailAccounts;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Templates;
use Bakkbone\HelpCentre\Services\Tickets;

final class TaskController extends StaffController
{
    public function index(): void
    {
        $db = App::db();
        $filter = Request::str('filter', 'mine');
        $where = ["t.status = 'open'"];
        $params = [];
        if ($filter === 'mine') {
            $where[] = 't.staff_id = ?';
            $params[] = (int)$this->staff['id'];
        } elseif ($filter === 'closed') {
            $where = ["t.status = 'closed'"];
        } elseif ($filter === 'overdue') {
            $where[] = 't.due_at IS NOT NULL AND t.due_at < ?';
            $params[] = DB::now();
        }
        if (!$this->staff['is_admin']) {
            $depts = Staff::deptIds($this->staff) ?: [0];
            $where[] = '(t.staff_id = ? OR t.created_by = ? OR t.dept_id IN ' . DB::in($depts) . ')';
            array_push($params, (int)$this->staff['id'], (int)$this->staff['id'], ...$depts);
        }
        $rows = $db->all(
            'SELECT t.*, k.number AS ticket_number FROM tasks t LEFT JOIN tickets k ON k.id = t.ticket_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.due_at IS NULL, t.due_at, t.created_at DESC LIMIT 300',
            $params
        );
        $this->view('staff/tasks', ['title' => __('Tasks'), 'tasks' => $rows, 'filter' => $filter]);
    }

    public function create(): void
    {
        $this->requirePerm('task.manage');
        $ticket = ($tid = Request::int('ticket_id')) ? Tickets::find($tid) : null;
        $this->view('staff/task_form', [
            'title' => __('New task'), 'task' => null, 'ticket' => $ticket,
            'agents' => Staff::active(), 'depts' => App::db()->all('SELECT id, name FROM departments ORDER BY name'),
        ]);
    }

    public function store(): void
    {
        $this->requirePerm('task.manage');
        $title = Request::str('title');
        if ($title === '') {
            $this->failWith(__('Title is required.'), '/panel/tasks/new');
        }
        $ticketId = Request::int('ticket_id') ?: null;
        if ($ticketId) {
            $t = Tickets::find($ticketId);
            if (!$t || !Staff::canAccessTicket($this->staff, $t)) {
                $ticketId = null;
            }
        }
        $id = App::db()->insert('tasks', [
            'ticket_id' => $ticketId,
            'title' => mb_substr($title, 0, 250),
            'description' => Html::clean((string)Request::post('description', '')),
            'dept_id' => Request::int('dept_id') ?: null,
            'staff_id' => Request::int('staff_id') ?: null,
            'status' => 'open',
            'due_at' => Request::str('due_at') !== '' ? to_utc(str_replace('T', ' ', Request::str('due_at'))) : null,
            'created_by' => (int)$this->staff['id'],
            'created_at' => DB::now(),
        ]);
        if ($ticketId) {
            Tickets::event($ticketId, 'task_created', ['task_id' => $id, 'title' => $title], (int)$this->staff['id']);
        }
        $this->alertAssignee($id);
        Audit::log('task.create', 'task', $id);
        $this->flash('success', __('Task created.'));
        $this->redirect($ticketId && Request::bool('back_to_ticket') ? '/panel/tickets/' . $ticketId : '/panel/tasks/' . $id);
    }

    private function alertAssignee(int $taskId): void
    {
        $task = App::db()->find('tasks', $taskId);
        $assignee = Staff::find($task['staff_id'] ? (int)$task['staff_id'] : null);
        if (!$assignee || (int)$assignee['id'] === (int)$this->staff['id']) {
            return;
        }
        $ctx = [
            'helpdesk' => ['name' => setting('helpdesk_name'), 'url' => App::url('/'), 'brand_color' => valid_hex_color((string)setting('brand_color'))],
            'recipient' => ['name' => Staff::name($assignee), 'first_name' => $assignee['first_name'], 'email' => $assignee['email']],
            'staff' => ['name' => Staff::name($this->staff)],
            'task' => ['title' => $task['title'], 'due' => fmt_dt($task['due_at']) ?: __('No due date'), 'url' => App::url('/panel/tasks/' . $taskId)],
        ];
        $mail = Templates::render('task.alert', $ctx);
        Mailer::send(['to' => [['email' => $assignee['email'], 'name' => Staff::name($assignee)]], 'subject' => $mail['subject'], 'html' => $mail['html'], 'auto' => true], EmailAccounts::alerts()['id'] ?? null);
    }

    private function taskOr404(int $id): array
    {
        $task = App::db()->find('tasks', $id);
        if (!$task) {
            $this->notFound();
        }
        if (!$this->staff['is_admin'] && (int)$task['staff_id'] !== (int)$this->staff['id'] && (int)$task['created_by'] !== (int)$this->staff['id']
            && !in_array((int)$task['dept_id'], Staff::deptIds($this->staff), true)) {
            Router::forbidden();
        }
        return $task;
    }

    public function show(int $id): void
    {
        $task = $this->taskOr404($id);
        $this->view('staff/task_form', [
            'title' => $task['title'], 'task' => $task, 'ticket' => $task['ticket_id'] ? Tickets::find((int)$task['ticket_id']) : null,
            'agents' => Staff::active(), 'depts' => App::db()->all('SELECT id, name FROM departments ORDER BY name'),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePerm('task.manage');
        $task = $this->taskOr404($id);
        $status = Request::str('status') === 'closed' ? 'closed' : 'open';
        $newStaff = Request::int('staff_id') ?: null;
        App::db()->update('tasks', [
            'title' => mb_substr(Request::str('title') ?: $task['title'], 0, 250),
            'description' => Html::clean((string)Request::post('description', '')),
            'dept_id' => Request::int('dept_id') ?: null,
            'staff_id' => $newStaff,
            'status' => $status,
            'due_at' => Request::str('due_at') !== '' ? to_utc(str_replace('T', ' ', Request::str('due_at'))) : null,
            'closed_at' => $status === 'closed' ? ($task['closed_at'] ?: DB::now()) : null,
        ], 'id = ?', [$id]);
        if ($newStaff && $newStaff !== (int)$task['staff_id']) {
            $this->alertAssignee($id);
        }
        if ($task['ticket_id'] && $status !== $task['status']) {
            Tickets::event((int)$task['ticket_id'], $status === 'closed' ? 'task_closed' : 'task_reopened', ['task_id' => $id, 'title' => $task['title']], (int)$this->staff['id']);
        }
        $this->flash('success', __('Task saved.'));
        $this->redirect('/panel/tasks/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePerm('task.manage');
        $this->taskOr404($id);
        App::db()->delete('tasks', 'id = ?', [$id]);
        $this->flash('success', __('Task deleted.'));
        $this->redirect('/panel/tasks');
    }
}
