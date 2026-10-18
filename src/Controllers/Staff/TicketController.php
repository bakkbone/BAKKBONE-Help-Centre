<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Attachments;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
use Bakkbone\HelpCentre\Services\Users;

final class TicketController extends StaffController
{
    public const QUEUES = [
        'open' => 'Open', 'mine' => 'My tickets', 'unassigned' => 'Unassigned', 'overdue' => 'Overdue',
        'answered' => 'Answered', 'closed' => 'Closed', 'all' => 'All tickets',
    ];

    private const FILTER_KEYS = ['q', 'dept_id', 'status_id', 'priority_id', 'assignee', 'topic_id', 'source', 'from', 'to', 'org_id', 'user_id', 'deep'];

    /** @return array{0:string,1:list<mixed>} */
    private function buildQuery(string $queue, array $f): array
    {
        [$scope, $params] = Staff::ticketScope($this->staff);
        $where = [$scope, 't.merged_into IS NULL'];
        $open = Tickets::statusesByState('open') ?: [0];
        $closed = Tickets::statusesByState('closed') ?: [0];
        $add = function (string $sql, array $p = []) use (&$where, &$params) {
            $where[] = $sql;
            array_push($params, ...$p);
        };
        switch ($queue) {
            case 'open':
                $add('t.status_id IN ' . DB::in($open), $open);
                break;
            case 'mine':
                $add('t.staff_id = ? AND t.status_id IN ' . DB::in($open), array_merge([(int)$this->staff['id']], $open));
                break;
            case 'unassigned':
                $add('t.staff_id IS NULL AND t.team_id IS NULL AND t.status_id IN ' . DB::in($open), $open);
                break;
            case 'overdue':
                $add('t.is_overdue = 1 AND t.status_id IN ' . DB::in($open), $open);
                break;
            case 'answered':
                $add('t.is_answered = 1 AND t.status_id IN ' . DB::in($open), $open);
                break;
            case 'closed':
                $add('t.status_id IN ' . DB::in($closed), $closed);
                break;
        }
        if (($f['q'] ?? '') !== '') {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$f['q']) . '%';
            $parts = ['t.number LIKE ?', 't.subject LIKE ?', 'u.email LIKE ?', 'u.name LIKE ?'];
            $p = [$q, $q, $q, $q];
            if (!empty($f['deep'])) {
                $parts[] = 't.id IN (SELECT ticket_id FROM thread_entries WHERE body LIKE ?)';
                $p[] = $q;
            }
            $add('(' . implode(' OR ', $parts) . ')', $p);
        }
        foreach (['dept_id', 'status_id', 'priority_id', 'topic_id', 'user_id'] as $k) {
            if (!empty($f[$k])) {
                $add("t.$k = ?", [(int)$f[$k]]);
            }
        }
        if (!empty($f['org_id'])) {
            $add('u.org_id = ?', [(int)$f['org_id']]);
        }
        if (!empty($f['source'])) {
            $add('t.source = ?', [(string)$f['source']]);
        }
        $assignee = (string)($f['assignee'] ?? '');
        if ($assignee === 'none') {
            $add('t.staff_id IS NULL AND t.team_id IS NULL');
        } elseif (preg_match('/^s:(\d+)$/', $assignee, $m)) {
            $add('t.staff_id = ?', [(int)$m[1]]);
        } elseif (preg_match('/^t:(\d+)$/', $assignee, $m)) {
            $add('t.team_id = ?', [(int)$m[1]]);
        }
        if (!empty($f['from']) && ($d = to_utc($f['from'] . ' 00:00'))) {
            $add('t.created_at >= ?', [$d]);
        }
        if (!empty($f['to']) && ($d = to_utc($f['to'] . ' 23:59:59'))) {
            $add('t.created_at <= ?', [$d]);
        }
        return [implode(' AND ', $where), $params];
    }

    private function filters(): array
    {
        $f = [];
        foreach (self::FILTER_KEYS as $k) {
            $v = Request::str($k);
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        return $f;
    }

    public function index(): void
    {
        $db = App::db();
        $queue = Request::str('queue', 'open');
        if (!isset(self::QUEUES[$queue]) && $queue !== 'search') {
            $queue = 'open';
        }
        $f = $this->filters();
        [$where, $params] = $this->buildQuery($queue, $f);
        $sorts = [
            'updated' => 't.updated_at DESC', 'created' => 't.created_at DESC', 'oldest' => 't.created_at ASC',
            'due' => 't.due_at IS NULL, t.due_at ASC', 'priority' => 'p.urgency DESC, t.updated_at DESC', 'number' => 't.number ASC',
        ];
        $sort = Request::str('sort', 'updated');
        $order = $sorts[$sort] ?? $sorts['updated'];
        [$page, $per, $offset] = $this->paging(25);
        $from = 'FROM tickets t JOIN users u ON u.id = t.user_id LEFT JOIN priorities p ON p.id = t.priority_id';
        $total = (int)$db->val("SELECT COUNT(*) $from WHERE $where", $params);
        $rows = $db->all(
            "SELECT t.*, u.name AS user_name, u.email AS user_email, p.name AS priority_name, p.color AS priority_color,
                    (SELECT COUNT(*) FROM thread_entries e WHERE e.ticket_id = t.id AND e.type <> 'N') AS entry_count,
                    (SELECT COUNT(*) FROM attachments a WHERE a.ticket_id = t.id AND a.inline = 0) AS att_count
             $from WHERE $where ORDER BY $order LIMIT $per OFFSET $offset",
            $params
        );

        $counts = [];
        foreach (array_keys(self::QUEUES) as $qk) {
            if ($qk === 'all' || $qk === 'closed') {
                continue;
            }
            [$w, $p] = $this->buildQuery($qk, []);
            $counts[$qk] = (int)$db->val("SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.user_id WHERE $w", $p);
        }

        $this->view('staff/tickets', [
            'title' => $queue === 'search' ? __('Search tickets') : __(self::QUEUES[$queue]),
            'queue' => $queue, 'tickets' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per),
            'f' => $f, 'sort' => $sort, 'counts' => $counts,
            'searches' => $db->all('SELECT * FROM saved_searches WHERE staff_id = ? ORDER BY name', [(int)$this->staff['id']]),
        ] + $this->lookups());
    }

    private function lookups(): array
    {
        $db = App::db();
        return [
            'depts' => $db->all('SELECT id, name FROM departments ORDER BY name'),
            'statuses' => $db->all('SELECT * FROM ticket_statuses ORDER BY sort, id'),
            'priorities' => $db->all('SELECT * FROM priorities ORDER BY urgency'),
            'topics' => $db->all('SELECT id, name FROM help_topics WHERE active = 1 ORDER BY sort, name'),
            'agents' => Staff::active(),
            'teams' => $db->all('SELECT id, name FROM teams WHERE active = 1 ORDER BY name'),
            'slas' => $db->all('SELECT id, name FROM sla WHERE active = 1 ORDER BY name'),
        ];
    }

    public function export(): void
    {
        $this->requirePerm('ticket.export');
        $queue = Request::str('queue', 'all');
        [$where, $params] = $this->buildQuery(isset(self::QUEUES[$queue]) ? $queue : 'all', $this->filters());
        $rows = App::db()->all(
            "SELECT t.*, u.name AS user_name, u.email AS user_email FROM tickets t JOIN users u ON u.id = t.user_id WHERE $where ORDER BY t.created_at DESC LIMIT 20000",
            $params
        );
        Audit::log('ticket.export', null, null, ['count' => count($rows)]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tickets-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Number'), __('Created'), __('Subject'), __('From'), __('Email'), __('Department'), __('Help topic'), __('Priority'), __('Status'), __('Assigned'), __('Source'), __('Due'), __('Closed'), __('Overdue')]);
        foreach ($rows as $t) {
            fputcsv($out, [
                $t['number'], fmt_dt($t['created_at']), $t['subject'], $t['user_name'], $t['user_email'],
                Tickets::lookupName('departments', $t['dept_id']), Tickets::lookupName('help_topics', $t['topic_id']),
                Tickets::lookupName('priorities', $t['priority_id']), Tickets::lookupName('ticket_statuses', $t['status_id']),
                $t['staff_id'] ? Staff::nameById((int)$t['staff_id']) : Tickets::lookupName('teams', $t['team_id']),
                $t['source'], fmt_dt($t['due_at']), fmt_dt($t['closed_at']), $t['is_overdue'] ? __('Yes') : __('No'),
            ]);
        }
        fclose($out);
        exit;
    }

    public function saveSearch(): void
    {
        $name = Request::str('name');
        $query = Request::str('query');
        if ($name === '' || $query === '') {
            $this->failWith(__('Please give the search a name.'), '/panel/tickets');
        }
        App::db()->insert('saved_searches', ['staff_id' => (int)$this->staff['id'], 'name' => mb_substr($name, 0, 120), 'query' => mb_substr($query, 0, 2000), 'created_at' => DB::now()]);
        $this->flash('success', __('Search saved.'));
        $this->back('/panel/tickets');
    }

    public function deleteSearch(int $id): void
    {
        App::db()->delete('saved_searches', 'id = ? AND staff_id = ?', [$id, (int)$this->staff['id']]);
        $this->redirect('/panel/tickets');
    }

    private function ticketOr404(int $id): array
    {
        $t = Tickets::find($id);
        if (!$t) {
            $this->notFound();
        }
        if (!Staff::canAccessTicket($this->staff, $t)) {
            Router::forbidden(__('You do not have access to this ticket.'));
        }
        return $t;
    }

    public function show(int $id): void
    {
        $t = $this->ticketOr404($id);
        $db = App::db();
        $lockedBy = Tickets::lockedBy($id, (int)$this->staff['id']);
        if (!$lockedBy && $this->can('ticket.reply') && Tickets::isOpen($t)) {
            Tickets::lock($id, (int)$this->staff['id']);
        }
        $entries = Tickets::entries($id, true);
        $events = $db->all('SELECT * FROM ticket_events WHERE ticket_id = ? ORDER BY created_at, id', [$id]);
        $timeline = [];
        foreach ($entries as $e) {
            $timeline[] = ['kind' => 'entry', 'at' => $e['created_at'], 'id' => (int)$e['id'], 'data' => $e];
        }
        foreach ($events as $ev) {
            if ($ev['event'] === 'created') {
                continue;
            }
            $timeline[] = ['kind' => 'event', 'at' => $ev['created_at'], 'id' => (int)$ev['id'], 'data' => $ev];
        }
        usort($timeline, fn($a, $b) => [$a['at'], $a['kind'] === 'event' ? 1 : 0, $a['id']] <=> [$b['at'], $b['kind'] === 'event' ? 1 : 0, $b['id']]);

        $user = Users::find((int)$t['user_id']);
        $canned = $db->all('SELECT id, title FROM canned_responses WHERE active = 1 AND (dept_id IS NULL OR dept_id = ?) ORDER BY title', [(int)$t['dept_id']]);
        $userTickets = $db->all('SELECT id, number, subject, status_id FROM tickets WHERE user_id = ? AND id <> ? AND merged_into IS NULL ORDER BY created_at DESC LIMIT 10', [$t['user_id'], $id]);
        $tasks = $db->all('SELECT * FROM tasks WHERE ticket_id = ? ORDER BY status DESC, due_at', [$id]);
        $banInfo = $user && $this->can('ban.manage') ? $this->banInfo($user) : null;

        $this->view('staff/ticket', [
            'title' => '#' . $t['number'] . ' ' . $t['subject'],
            't' => $t, 'timeline' => $timeline, 'user' => $user, 'org' => $user ? Users::org($user['org_id'] ? (int)$user['org_id'] : null) : null,
            'collaborators' => Tickets::collaborators($id, false), 'fields' => Tickets::fieldValues($id),
            'canned' => $canned, 'userTickets' => $userTickets, 'tasks' => $tasks, 'lockedBy' => $lockedBy,
            'isOpen' => Tickets::isOpen($t), 'me' => $this->staff, 'banInfo' => $banInfo,
            'perm' => fn(string $p) => $this->can($p),
        ] + $this->lookups());
    }

    public function printView(int $id): void
    {
        $t = $this->ticketOr404($id);
        View::render('staff/ticket_print', [
            'title' => '#' . $t['number'], 't' => $t, 'entries' => Tickets::entries($id, Request::bool('notes')),
            'user' => Users::find((int)$t['user_id']), 'fields' => Tickets::fieldValues($id),
        ], 'layouts/print');
    }

    public function lock(int $id): void
    {
        $this->ticketOr404($id);
        $ok = Tickets::lock($id, (int)$this->staff['id']);
        $by = $ok ? null : Tickets::lockedBy($id, (int)$this->staff['id']);
        $this->json(['ok' => $ok, 'locked_by' => $by ? Staff::name($by) : null]);
    }

    public function post(int $id): void
    {
        $t = $this->ticketOr404($id);
        $type = Request::str('type', 'reply');
        $message = (string)Request::post('message', '');
        $errors = [];
        if (trim(strip_tags($message, '<img>')) === '') {
            $errors[] = __('Please enter a message.');
        }
        if ($type === 'reply' && !$this->can('ticket.reply')) {
            $errors[] = __('You do not have permission to reply to tickets.');
        }
        $lockedBy = Tickets::lockedBy($id, (int)$this->staff['id']);
        if ($type === 'reply' && $lockedBy && !Request::bool('force')) {
            $errors[] = __('{name} is currently working on this ticket. Tick "post anyway" to reply regardless.', ['name' => Staff::name($lockedBy)]);
        }
        [$files, $fileErrors] = Attachments::validateUploads(Request::files('attachments'));
        $errors = array_merge($errors, $fileErrors);
        $statusId = Request::int('status_id') ?: null;
        if ($statusId && (Tickets::lookup('ticket_statuses', $statusId)['state'] ?? '') === 'closed' && !$this->can('ticket.close')) {
            $errors[] = __('You do not have permission to close tickets.');
        }
        if ($errors) {
            $this->failWith($errors, '/panel/tickets/' . $id);
        }
        if ($type === 'note') {
            Tickets::postNote($t, $this->staff, $message, $files, ['status_id' => $statusId, 'ip' => Request::ip()]);
            $this->flash('success', __('Internal note added.'));
        } else {
            Tickets::postResponse($t, $this->staff, $message, $files, [
                'signature' => Request::str('signature', 'mine'),
                'status_id' => $statusId,
                'notify' => !Request::bool('no_email'),
                'collaborators' => !Request::bool('no_collaborators'),
                'ip' => Request::ip(),
            ]);
            $this->flash('success', Request::bool('no_email') ? __('Reply posted (no email sent).') : __('Reply sent.'));
        }
        Tickets::unlock($id, (int)$this->staff['id']);
        if ($type === 'reply' && $this->staff['reply_redirect'] === 'queue') {
            $this->redirect('/panel/tickets');
        }
        $this->redirect('/panel/tickets/' . $id);
    }

    public function assign(int $id): void
    {
        $t = $this->ticketOr404($id);
        $target = Request::str('assignee');
        $isClaim = $target === 's:' . $this->staff['id'] && !$t['staff_id'];
        if (!$isClaim && !$this->can('ticket.assign')) {
            Router::forbidden();
        }
        $staffId = null;
        $teamId = null;
        if (preg_match('/^s:(\d+)$/', $target, $m)) {
            $staffId = (int)$m[1];
            if (!Staff::find($staffId)) {
                $this->failWith(__('Unknown agent.'), '/panel/tickets/' . $id);
            }
        } elseif (preg_match('/^t:(\d+)$/', $target, $m)) {
            $teamId = (int)$m[1];
        }
        Tickets::assign($t, $staffId, $teamId, $this->staff, Request::str('comment'));
        $this->flash('success', $staffId || $teamId ? __('Ticket assigned.') : __('Ticket unassigned.'));
        $this->redirect('/panel/tickets/' . $id);
    }

    public function transfer(int $id): void
    {
        $this->requirePerm('ticket.transfer');
        $t = $this->ticketOr404($id);
        $dept = Request::int('dept_id');
        if (!Tickets::lookup('departments', $dept)) {
            $this->failWith(__('Please choose a department.'), '/panel/tickets/' . $id);
        }
        Tickets::transfer($t, $dept, $this->staff, Request::str('comment'));
        $this->flash('success', __('Ticket transferred to {dept}.', ['dept' => Tickets::lookupName('departments', $dept)]));
        $t = Tickets::find($id);
        $this->redirect(Staff::canAccessTicket($this->staff, $t) ? '/panel/tickets/' . $id : '/panel/tickets');
    }

    public function status(int $id): void
    {
        $t = $this->ticketOr404($id);
        $statusId = Request::int('status_id');
        $status = Tickets::lookup('ticket_statuses', $statusId);
        if (!$status) {
            $this->failWith(__('Unknown status.'), '/panel/tickets/' . $id);
        }
        if ($status['state'] === 'closed' && !$this->can('ticket.close')) {
            Router::forbidden(__('You do not have permission to close tickets.'));
        }
        Tickets::setStatus($t, $statusId, $this->staff, Request::bool('notify'));
        if (($comment = Request::str('comment')) !== '') {
            Tickets::postNote($t, $this->staff, \Bakkbone\HelpCentre\Core\Html::fromText($comment), [], ['alert' => false]);
        }
        $this->flash('success', __('Status changed to {status}.', ['status' => $status['name']]));
        $this->redirect('/panel/tickets/' . $id);
    }

    public function edit(int $id): void
    {
        $this->requirePerm('ticket.edit');
        $t = $this->ticketOr404($id);
        $values = [];
        foreach (Tickets::fieldValues($id) as $f) {
            $values[(int)$f['id']] = $f['value'];
        }
        $this->view('staff/ticket_edit', [
            'title' => __('Edit #{number}', ['number' => $t['number']]), 't' => $t, 'user' => Users::find((int)$t['user_id']),
            'fields' => App::db()->all('SELECT * FROM custom_fields WHERE active = 1 ORDER BY sort, id'), 'values' => $values,
        ] + $this->lookups());
    }

    public function update(int $id): void
    {
        $this->requirePerm('ticket.edit');
        $t = $this->ticketOr404($id);
        $subject = Request::str('subject');
        if ($subject === '') {
            $this->failWith(__('Subject is required.'), "/panel/tickets/$id/edit");
        }
        $data = [
            'subject' => mb_substr($subject, 0, 250),
            'priority_id' => Request::int('priority_id') ?: null,
            'topic_id' => Request::int('topic_id') ?: null,
            'sla_id' => Request::int('sla_id') ?: null,
            'source' => array_key_exists(Request::str('source'), Tickets::SOURCES) ? Request::str('source') : $t['source'],
        ];
        if (Request::str('due_at') !== '') {
            $data['due_at'] = to_utc(str_replace('T', ' ', Request::str('due_at')));
        } elseif (Request::bool('clear_due')) {
            $data['due_at'] = null;
        }
        $ownerEmail = strtolower(Request::str('owner_email'));
        if ($ownerEmail !== '') {
            if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $this->failWith(__('Owner email is not valid.'), "/panel/tickets/$id/edit");
            }
            $owner = Users::findOrCreate($ownerEmail, Request::str('owner_name'));
            $data['user_id'] = (int)$owner['id'];
        }
        [$values, $errors] = Tickets::validateFields(Tickets::customFields($data['topic_id']), (array)Request::post('field', []));
        if ($errors) {
            $this->failWith($errors, "/panel/tickets/$id/edit");
        }
        Tickets::updateDetails($t, $data, $this->staff);
        Tickets::saveFields($id, $values);
        $this->flash('success', __('Ticket updated.'));
        $this->redirect('/panel/tickets/' . $id);
    }

    public function collaborators(int $id): void
    {
        $t = $this->ticketOr404($id);
        $db = App::db();
        if ($remove = Request::int('remove')) {
            $db->delete('ticket_collaborators', 'ticket_id = ? AND user_id = ?', [$id, $remove]);
            Tickets::event($id, 'collaborator_removed', ['user_id' => $remove], (int)$this->staff['id']);
            $this->flash('success', __('Collaborator removed.'));
        } elseif ($toggle = Request::int('toggle')) {
            $db->run('UPDATE ticket_collaborators SET active = 1 - active WHERE ticket_id = ? AND user_id = ?', [$id, $toggle]);
        } else {
            $email = strtolower(Request::str('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->failWith(__('Enter a valid email address.'), '/panel/tickets/' . $id);
            }
            $u = Users::findOrCreate($email, Request::str('name'));
            Tickets::addCollaborator($id, (int)$u['id']);
            Tickets::event($id, 'collaborator_added', ['email' => $email], (int)$this->staff['id']);
            $this->flash('success', __('{email} added as a collaborator (CC).', ['email' => $email]));
        }
        $this->redirect('/panel/tickets/' . $id);
    }

    public function merge(int $id): void
    {
        $this->requirePerm('ticket.merge');
        $parent = $this->ticketOr404($id);
        $ids = [];
        foreach (preg_split('/[\s,]+/', Request::str('numbers')) ?: [] as $num) {
            $num = trim($num, " #");
            if ($num === '') {
                continue;
            }
            $c = Tickets::byNumber($num);
            if (!$c || !Staff::canAccessTicket($this->staff, $c)) {
                $this->failWith(__('Ticket #{number} not found.', ['number' => $num]), '/panel/tickets/' . $id);
            }
            $ids[] = (int)$c['id'];
        }
        if (!$ids) {
            $this->failWith(__('Enter at least one ticket number to merge into this ticket.'), '/panel/tickets/' . $id);
        }
        $n = Tickets::merge($parent, $ids, $this->staff);
        $this->flash('success', _n('{n} ticket merged into #{number}.', '{n} tickets merged into #{number}.', $n, ['number' => $parent['number']]));
        $this->redirect('/panel/tickets/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePerm('ticket.delete');
        $t = $this->ticketOr404($id);
        Tickets::delete($t);
        $this->flash('success', __('Ticket #{number} deleted.', ['number' => $t['number']]));
        $this->redirect('/panel/tickets');
    }

    public function ban(int $id): void
    {
        $this->requirePerm('ban.manage');
        $t = $this->ticketOr404($id);
        $user = Users::find((int)$t['user_id']);
        if (!$user) {
            $this->redirect('/panel/tickets/' . $id);
        }
        $db = App::db();
        if (!$db->val('SELECT COUNT(*) FROM banlist WHERE pattern = ?', [strtolower((string)$user['email'])])) {
            $db->insert('banlist', ['pattern' => strtolower((string)$user['email']), 'notes' => __('Banned from ticket #{number}', ['number' => $t['number']]), 'created_at' => DB::now()]);
        }
        Audit::log('ban.add', 'user', (int)$user['id'], ['email' => $user['email']]);

        if (Request::str('then') === 'delete') {
            $info = $this->banInfo($user);
            if (!$info['canDelete']) {
                $this->flash('warning', $info['hidden'] ? __('{email} has been banned, but you can\'t delete this user because some of their tickets are in departments you can\'t access.', ['email' => $user['email']]) : __('{email} has been banned, but you can\'t delete this user.', ['email' => $user['email']]));
                $this->redirect('/panel/tickets/' . $id);
            }
            @set_time_limit(120);
            $n = 0;
            foreach ($db->all('SELECT * FROM tickets WHERE user_id = ?', [$user['id']]) as $ut) {
                Tickets::delete($ut);
                $n++;
            }
            $db->delete('ticket_collaborators', 'user_id = ?', [$user['id']]);
            $db->delete('users', 'id = ?', [$user['id']]);
            Audit::log('user.delete', 'user', (int)$user['id'], ['email' => $user['email'], 'reason' => 'banned', 'tickets' => $n]);
            $this->flash('success', _n('{email} has been banned and deleted, along with {n} ticket.', '{email} has been banned and deleted, along with {n} tickets.', $n, ['email' => $user['email']]));
            $this->redirect('/panel/tickets');
        }

        $closed = (int)Settings::get('closed_status_id');
        if ($closed) {
            Tickets::setStatus($t, $closed, $this->staff, false);
        }
        $this->flash('success', __('{email} has been banned and the ticket closed.', ['email' => $user['email']]));
        $this->redirect('/panel/tickets/' . $id);
    }

    /** @return array{tickets:int,hidden:int,canDelete:bool} */
    private function banInfo(array $user): array
    {
        $all = App::db()->all('SELECT * FROM tickets WHERE user_id = ?', [$user['id']]);
        $hidden = count(array_filter($all, fn($ut) => !Staff::canAccessTicket($this->staff, $ut)));
        return [
            'tickets' => count($all), 'hidden' => $hidden,
            'canDelete' => $hidden === 0 && $this->can('ticket.delete') && $this->can('user.delete'),
        ];
    }

    public function bulk(): void
    {
        $ids = Request::intList('ids');
        $action = Request::str('action');
        if (!$ids) {
            $this->failWith(__('Select at least one ticket.'), '/panel/tickets');
        }
        $tickets = [];
        foreach ($ids as $tid) {
            $t = Tickets::find($tid);
            if ($t && Staff::canAccessTicket($this->staff, $t)) {
                $tickets[] = $t;
            }
        }
        $n = 0;
        // Menu buttons carry their value in the action: status:5, priority:2.
        if (preg_match('/^(status|priority):(\d+)$/', $action, $m)) {
            $action = $m[1];
            $_POST[$m[1] . '_id'] = $m[2];
        }
        if ($action === 'close') {
            $action = 'status';
            $_POST['status_id'] = (string)(int)Settings::get('closed_status_id');
        }
        $done = fn(int $n) => _n('{n} ticket updated.', '{n} tickets updated.', $n);
        switch ($action) {
            case 'claim':
                $skipped = 0;
                foreach ($tickets as $t) {
                    if ((int)$t['staff_id'] === (int)$this->staff['id']) {
                        continue;
                    }
                    if ($t['staff_id'] && !$this->can('ticket.assign')) {
                        $skipped++;
                        continue;
                    }
                    Tickets::assign($t, (int)$this->staff['id'], null, $this->staff);
                    $n++;
                }
                $this->flash($skipped ? 'warning' : 'success', trim(($n ? _n('{n} ticket claimed.', '{n} tickets claimed.', $n) : __('Nothing to claim.'))
                    . ($skipped ? ' ' . _n('{n} ticket already assigned to someone else was left alone.', '{n} tickets already assigned to someone else were left alone.', $skipped) : '')));
                $this->back('/panel/tickets');
            case 'assign':
                $this->requirePerm('ticket.assign');
                $target = Request::str('assignee');
                $sid = preg_match('/^s:(\d+)$/', $target, $m) ? (int)$m[1] : null;
                $tid = preg_match('/^t:(\d+)$/', $target, $m) ? (int)$m[1] : null;
                if ($sid && !Staff::find($sid)) {
                    $this->failWith(__('Unknown agent.'), '/panel/tickets');
                }
                foreach ($tickets as $t) {
                    if ((int)$t['staff_id'] === (int)$sid && (int)$t['team_id'] === (int)$tid) {
                        continue;
                    }
                    Tickets::assign($t, $sid, $tid, $this->staff);
                    $n++;
                }
                $who = $sid ? Staff::nameById($sid) : ($tid ? Tickets::lookupName('teams', $tid) : null);
                $done = fn(int $n) => $who !== null ? _n('{n} ticket assigned to {name}.', '{n} tickets assigned to {name}.', $n, ['name' => $who]) : _n('{n} ticket unassigned.', '{n} tickets unassigned.', $n);
                break;
            case 'status':
                $sid = Request::int('status_id');
                $status = Tickets::lookup('ticket_statuses', $sid);
                if (!$status) {
                    $this->failWith(__('Choose a status.'), '/panel/tickets');
                }
                if ($status['state'] === 'closed') {
                    $this->requirePerm('ticket.close');
                }
                foreach ($tickets as $t) {
                    if ((int)$t['status_id'] === $sid) {
                        continue;
                    }
                    Tickets::setStatus($t, $sid, $this->staff, false);
                    $n++;
                }
                $done = fn(int $n) => _n('{n} ticket set to {status}.', '{n} tickets set to {status}.', $n, ['status' => $status['name']]);
                break;
            case 'transfer':
                $this->requirePerm('ticket.transfer');
                $dept = Request::int('dept_id');
                if (!Tickets::lookup('departments', $dept)) {
                    $this->failWith(__('Choose a department.'), '/panel/tickets');
                }
                foreach ($tickets as $t) {
                    if ((int)$t['dept_id'] === $dept) {
                        continue;
                    }
                    Tickets::transfer($t, $dept, $this->staff);
                    $n++;
                }
                $done = fn(int $n) => _n('{n} ticket transferred to {dept}.', '{n} tickets transferred to {dept}.', $n, ['dept' => Tickets::lookupName('departments', $dept)]);
                break;
            case 'priority':
                $this->requirePerm('ticket.edit');
                $pid = Request::int('priority_id');
                if (!Tickets::lookup('priorities', $pid)) {
                    $this->failWith(__('Choose a priority.'), '/panel/tickets');
                }
                $done = fn(int $n) => _n('{n} ticket set to {priority} priority.', '{n} tickets set to {priority} priority.', $n, ['priority' => Tickets::lookupName('priorities', $pid)]);
                foreach ($tickets as $t) {
                    Tickets::updateDetails($t, ['priority_id' => $pid], $this->staff);
                    $n++;
                }
                break;
            case 'merge':
                $this->requirePerm('ticket.merge');
                if (count($tickets) < 2) {
                    $this->failWith(__('Select at least two tickets to merge.'), '/panel/tickets');
                }
                usort($tickets, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
                $parent = array_shift($tickets);
                $n = Tickets::merge($parent, array_map(fn($t) => (int)$t['id'], $tickets), $this->staff);
                $this->flash('success', _n('{n} ticket merged into #{number} (the oldest).', '{n} tickets merged into #{number} (the oldest).', $n, ['number' => $parent['number']]));
                $this->redirect('/panel/tickets/' . $parent['id']);
            case 'delete':
                $this->requirePerm('ticket.delete');
                foreach ($tickets as $t) {
                    Tickets::delete($t);
                    $n++;
                }
                $done = fn(int $n) => _n('{n} ticket deleted.', '{n} tickets deleted.', $n);
                break;
            default:
                $this->failWith(__('Choose an action.'), '/panel/tickets');
        }
        $this->flash('success', $n ? $done($n) : __('No changes were needed.'));
        $this->back('/panel/tickets');
    }

    public function create(): void
    {
        $this->requirePerm('ticket.create');
        $prefill = null;
        if ($uid = Request::int('user_id')) {
            $prefill = Users::find($uid);
        }
        $db = App::db();
        $this->view('staff/ticket_new', [
            'title' => __('New ticket'), 'prefill' => $prefill, 'me' => $this->staff,
            'fields' => $db->all('SELECT * FROM custom_fields WHERE active = 1 ORDER BY sort, id'),
            'canned' => $db->all('SELECT id, title FROM canned_responses WHERE active = 1 ORDER BY title'),
        ] + $this->lookups());
    }

    public function store(): void
    {
        $this->requirePerm('ticket.create');
        $email = strtolower(Request::str('email'));
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('A valid user email address is required.');
        }
        $subject = Request::str('subject');
        if ($subject === '') {
            $errors[] = __('Subject is required.');
        }
        $message = (string)Request::post('message', '');
        if (trim(strip_tags($message, '<img>')) === '') {
            $errors[] = __('Please describe the issue.');
        }
        $topicId = Request::int('topic_id') ?: null;
        [$values, $fieldErrors] = Tickets::validateFields(Tickets::customFields($topicId), (array)Request::post('field', []));
        [$files, $fileErrors] = Attachments::validateUploads(Request::files('attachments'));
        $errors = array_merge($errors, $fieldErrors, $fileErrors);
        if ($errors) {
            $this->failWith($errors, '/panel/tickets/new');
        }
        $target = Request::str('assignee');
        $result = Tickets::create([
            'email' => $email,
            'name' => Request::str('name'),
            'phone' => Request::str('phone') ?: null,
            'subject' => $subject,
            'message' => $message,
            'source' => array_key_exists(Request::str('source'), Tickets::SOURCES) ? Request::str('source') : 'phone',
            'topic_id' => $topicId,
            'dept_id' => Request::int('dept_id') ?: null,
            'priority_id' => Request::int('priority_id') ?: null,
            'sla_id' => Request::int('sla_id') ?: null,
            'staff_id' => preg_match('/^s:(\d+)$/', $target, $m) ? (int)$m[1] : null,
            'team_id' => preg_match('/^t:(\d+)$/', $target, $m) ? (int)$m[1] : null,
            'due_at' => Request::str('due_at') !== '' ? to_utc(str_replace('T', ' ', Request::str('due_at'))) : null,
            'fields' => $values,
            'attachments' => $files,
            'creator' => $this->staff,
            'notify_user' => Request::bool('notify_user'),
            'response' => trim(strip_tags((string)Request::post('response', ''), '<img>')) !== '' ? (string)Request::post('response') : null,
            'signature' => Request::str('signature', 'mine'),
            'ip' => Request::ip(),
        ]);
        if (!$result['ok']) {
            $this->failWith($result['error'] ?? __('Could not create ticket.'), '/panel/tickets/new');
        }
        if ($statusId = Request::int('status_id')) {
            Tickets::setStatus($result['ticket'], $statusId, $this->staff, false);
        }
        $this->flash('success', __('Ticket #{number} created.', ['number' => $result['ticket']['number']]));
        $this->redirect('/panel/tickets/' . $result['ticket']['id']);
    }
}
