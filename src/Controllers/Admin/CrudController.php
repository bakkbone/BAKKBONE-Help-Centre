<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;

final class CrudController extends AdminController
{
    private function entities(): array
    {
        $db = App::db();
        $list = fn(string $table, string $order = 'name', string $label = 'name') => array_column($db->all("SELECT id, $label AS label FROM $table ORDER BY $order"), 'label', 'id');
        $staff = fn() => array_combine(array_column(Staff::active(), 'id'), array_map([Staff::class, 'name'], Staff::active())) ?: [];

        return [
            'departments' => [
                'table' => 'departments', 'title' => __('Departments'), 'singular' => 'department',
                'words' => ['add' => __('Add department'), 'new' => __('New department'), 'edit' => __('Edit department'), 'saved' => __('Department saved.'), 'deleted' => __('Department deleted.'), 'confirm' => __('Delete this department?')],
                'columns' => ['name' => __('Name'), 'public' => __('Public')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'email_account_id' => ['label' => __('Outgoing mailbox'), 'type' => 'select', 'options' => fn() => $list('email_accounts', 'email', 'email'), 'hint' => __('Replies on tickets in this department are sent from this mailbox.')],
                    'manager_id' => ['label' => __('Manager'), 'type' => 'select', 'options' => $staff],
                    'sla_id' => ['label' => __('Default SLA plan'), 'type' => 'select', 'options' => fn() => $list('sla')],
                    'public' => ['label' => __('Public (shown to end users)'), 'type' => 'checkbox', 'default' => 1],
                    'autoresp_enabled' => ['label' => __('Send new-ticket auto-responses'), 'type' => 'checkbox', 'default' => 1],
                    'alert_members' => ['label' => __('Alert department members'), 'type' => 'checkbox', 'default' => 1],
                    'signature' => ['label' => __('Department signature (HTML)'), 'type' => 'html', 'hint' => __('Agents can choose to sign replies with this instead of their personal signature.')],
                ],
                'inUse' => fn(int $id) => (int)$db->val('SELECT COUNT(*) FROM tickets WHERE dept_id = ?', [$id]) ? __('Tickets still belong to this department. Transfer them first.') : null,
            ],
            'topics' => [
                'table' => 'help_topics', 'title' => __('Help topics'), 'singular' => 'help topic',
                'words' => ['add' => __('Add help topic'), 'new' => __('New help topic'), 'edit' => __('Edit help topic'), 'saved' => __('Help topic saved.'), 'deleted' => __('Help topic deleted.'), 'confirm' => __('Delete this help topic?')],
                'order' => 'sort, name',
                'columns' => ['name' => __('Name'), 'dept_id' => __('Department'), 'public' => __('Public'), 'active' => __('Active')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'dept_id' => ['label' => __('Department'), 'type' => 'select', 'options' => fn() => $list('departments')],
                    'priority_id' => ['label' => __('Priority'), 'type' => 'select', 'options' => fn() => $list('priorities', 'urgency')],
                    'sla_id' => ['label' => __('SLA plan'), 'type' => 'select', 'options' => fn() => $list('sla')],
                    'staff_id' => ['label' => __('Auto-assign to agent'), 'type' => 'select', 'options' => $staff],
                    'team_id' => ['label' => __('Auto-assign to team'), 'type' => 'select', 'options' => fn() => $list('teams')],
                    'status_id' => ['label' => __('Initial status'), 'type' => 'select', 'options' => fn() => array_column($db->all("SELECT id, name FROM ticket_statuses WHERE state = 'open' ORDER BY sort"), 'name', 'id')],
                    'public' => ['label' => __('Public (selectable in the portal)'), 'type' => 'checkbox', 'default' => 1],
                    'active' => ['label' => __('Active'), 'type' => 'checkbox', 'default' => 1],
                    'disable_autoresponse' => ['label' => __('Disable auto-response'), 'type' => 'checkbox'],
                    'sort' => ['label' => __('Sort order'), 'type' => 'number'],
                    'notes' => ['label' => __('Internal notes'), 'type' => 'textarea'],
                ],
            ],
            'sla' => [
                'table' => 'sla', 'title' => __('SLA plans'), 'singular' => 'SLA plan',
                'words' => ['add' => __('Add SLA plan'), 'new' => __('New SLA plan'), 'edit' => __('Edit SLA plan'), 'saved' => __('SLA plan saved.'), 'deleted' => __('SLA plan deleted.'), 'confirm' => __('Delete this SLA plan?')],
                'columns' => ['name' => __('Name'), 'grace_hours' => __('Grace (hours)'), 'active' => __('Active')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'grace_hours' => ['label' => __('Grace period (hours)'), 'type' => 'number', 'required' => true, 'default' => 24, 'hint' => __('Open, unanswered tickets become overdue after this many hours (the clock restarts when the customer replies).')],
                    'active' => ['label' => __('Active'), 'type' => 'checkbox', 'default' => 1],
                    'disable_overdue_alerts' => ['label' => __('Disable overdue alerts'), 'type' => 'checkbox'],
                    'notes' => ['label' => __('Notes'), 'type' => 'textarea'],
                ],
            ],
            'statuses' => [
                'table' => 'ticket_statuses', 'title' => __('Ticket statuses'), 'singular' => 'status',
                'words' => ['add' => __('Add status'), 'new' => __('New status'), 'edit' => __('Edit status'), 'saved' => __('Status saved.'), 'deleted' => __('Status deleted.'), 'confirm' => __('Delete this status?')],
                'order' => 'sort, id',
                'columns' => ['name' => __('Name'), 'state' => __('State'), 'client_name' => __('Shown to users as')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'state' => ['label' => __('State'), 'type' => 'select', 'options' => fn() => ['open' => __('Open'), 'closed' => __('Closed')], 'required' => true],
                    'client_name' => ['label' => __('Name shown to end users'), 'type' => 'text'],
                    'sort' => ['label' => __('Sort order'), 'type' => 'number'],
                ],
                'inUse' => fn(int $id) => (int)$db->val('SELECT COUNT(*) FROM tickets WHERE status_id = ?', [$id]) ? __('Tickets use this status.') : null,
            ],
            'priorities' => [
                'table' => 'priorities', 'title' => __('Priorities'), 'singular' => 'priority',
                'words' => ['add' => __('Add priority'), 'new' => __('New priority'), 'edit' => __('Edit priority'), 'saved' => __('Priority saved.'), 'deleted' => __('Priority deleted.'), 'confirm' => __('Delete this priority?')],
                'order' => 'urgency',
                'columns' => ['name' => __('Name'), 'color' => __('Colour'), 'urgency' => __('Urgency')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'color' => ['label' => __('Colour'), 'type' => 'color', 'default' => '#64748b'],
                    'urgency' => ['label' => __('Urgency (higher = more urgent)'), 'type' => 'number'],
                    'public' => ['label' => __('Visible to end users'), 'type' => 'checkbox', 'default' => 1],
                ],
                'inUse' => fn(int $id) => (int)$db->val('SELECT COUNT(*) FROM tickets WHERE priority_id = ?', [$id]) ? __('Tickets use this priority.') : null,
            ],
            'teams' => [
                'table' => 'teams', 'title' => __('Teams'), 'singular' => 'team',
                'words' => ['add' => __('Add team'), 'new' => __('New team'), 'edit' => __('Edit team'), 'saved' => __('Team saved.'), 'deleted' => __('Team deleted.'), 'confirm' => __('Delete this team?')],
                'columns' => ['name' => __('Name'), 'active' => __('Active')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'lead_id' => ['label' => __('Team lead'), 'type' => 'select', 'options' => $staff],
                    'active' => ['label' => __('Active'), 'type' => 'checkbox', 'default' => 1],
                    'alert_members' => ['label' => __('Alert members on assignment'), 'type' => 'checkbox', 'default' => 1],
                    'members' => ['label' => __('Members'), 'type' => 'relation', 'table' => 'team_members', 'fk' => 'team_id', 'other' => 'staff_id', 'options' => $staff],
                ],
            ],
            'roles' => [
                'table' => 'roles', 'title' => __('Roles'), 'singular' => 'role',
                'words' => ['add' => __('Add role'), 'new' => __('New role'), 'edit' => __('Edit role'), 'saved' => __('Role saved.'), 'deleted' => __('Role deleted.'), 'confirm' => __('Delete this role?')],
                'order' => 'id',
                'columns' => ['name' => __('Name')],
                'fields' => [
                    'name' => ['label' => __('Name'), 'type' => 'text', 'required' => true],
                    'permissions' => ['label' => __('Permissions'), 'type' => 'checkboxes', 'options' => fn() => Staff::permissions(), 'hint' => __('Administrators always have every permission.')],
                ],
                'inUse' => fn(int $id) => (int)$db->val('SELECT COUNT(*) FROM staff WHERE role_id = ?', [$id]) ? __('Agents still have this role.') : null,
            ],
            'fields' => [
                'table' => 'custom_fields', 'title' => __('Custom ticket fields'), 'singular' => 'field',
                'words' => ['add' => __('Add field'), 'new' => __('New field'), 'edit' => __('Edit field'), 'saved' => __('Field saved.'), 'deleted' => __('Field deleted.'), 'confirm' => __('Delete this field?')],
                'order' => 'sort, id',
                'columns' => ['label' => __('Label'), 'name' => __('Merge tag'), 'type' => __('Type'), 'active' => __('Active')],
                'fields' => [
                    'label' => ['label' => __('Label'), 'type' => 'text', 'required' => true],
                    'name' => ['label' => __('Variable name'), 'type' => 'text', 'hint' => __('Lower-case letters, numbers and underscores. Used as %{ticket.field.NAME} in templates and webhooks. Left blank, it is generated from the label.')],
                    'type' => ['label' => __('Type'), 'type' => 'select', 'required' => true, 'options' => fn() => [
                        'text' => __('Short text'), 'textarea' => __('Long text'), 'select' => __('Drop-down'), 'radio' => __('Choice (radio)'), 'checkbox' => __('Checkbox'),
                        'date' => __('Date'), 'number' => __('Number'), 'email' => __('Email'), 'phone' => __('Phone'), 'url' => __('Website URL'),
                    ]],
                    'options' => ['label' => __('Choices (one per line)'), 'type' => 'textarea', 'hint' => __('For drop-down and choice fields.')],
                    'hint' => ['label' => __('Help text'), 'type' => 'text'],
                    'required' => ['label' => __('Required'), 'type' => 'checkbox'],
                    'client_visible' => ['label' => __('Shown to end users (portal form & ticket view)'), 'type' => 'checkbox', 'default' => 1],
                    'topic_ids' => ['label' => __('Only for these help topics (none = all)'), 'type' => 'multiselect', 'options' => fn() => $list('help_topics', 'sort, name')],
                    'sort' => ['label' => __('Sort order'), 'type' => 'number'],
                    'active' => ['label' => __('Active'), 'type' => 'checkbox', 'default' => 1],
                ],
            ],
            'banlist' => [
                'table' => 'banlist', 'title' => __('Ban list'), 'singular' => 'ban',
                'words' => ['add' => __('Add ban'), 'new' => __('New ban'), 'edit' => __('Edit ban'), 'saved' => __('Ban saved.'), 'deleted' => __('Ban deleted.'), 'confirm' => __('Delete this ban?')],
                'order' => 'pattern',
                'columns' => ['pattern' => __('Email or @domain'), 'notes' => __('Notes')],
                'fields' => [
                    'pattern' => ['label' => __('Email address or @domain'), 'type' => 'text', 'required' => true, 'hint' => __('e.g. spammer@example.com or @spam-domain.com')],
                    'notes' => ['label' => __('Notes'), 'type' => 'text'],
                ],
            ],
        ];
    }

    private function entity(string $name): array
    {
        $all = $this->entities();
        if (!isset($all[$name])) {
            $this->notFound();
        }
        return $all[$name] + ['key' => $name];
    }

    public function index(string $entity): void
    {
        $e = $this->entity($entity);
        $rows = App::db()->all('SELECT * FROM ' . $e['table'] . ' ORDER BY ' . ($e['order'] ?? 'name'));
        $this->view('admin/crud_list', ['title' => $e['title'], 'e' => $e, 'rows' => $rows, 'adminSection' => $entity]);
    }

    public function form(string $entity, ?int $id = null): void
    {
        $e = $this->entity($entity);
        $row = $id ? App::db()->find($e['table'], $id) : null;
        if ($id && !$row) {
            $this->notFound();
        }
        foreach ($e['fields'] as $k => &$f) {
            if (isset($f['options']) && is_callable($f['options'])) {
                $f['options'] = ($f['options'])();
            }
            if ($f['type'] === 'relation') {
                $f['value'] = $id ? array_map('intval', App::db()->col("SELECT {$f['other']} FROM {$f['table']} WHERE {$f['fk']} = ?", [$id])) : [];
            }
        }
        unset($f);
        $this->view('admin/crud_form', [
            'title' => $row ? $e['words']['edit'] : $e['words']['new'], 'e' => $e, 'row' => $row, 'adminSection' => $entity,
        ]);
    }

    public function save(string $entity, ?int $id = null): void
    {
        $e = $this->entity($entity);
        $db = App::db();
        $back = '/panel/admin/' . $entity . ($id ? '/' . $id : '/new');
        $data = [];
        $relations = [];
        $errors = [];
        foreach ($e['fields'] as $k => $f) {
            $type = $f['type'];
            $options = isset($f['options']) && is_callable($f['options']) ? ($f['options'])() : ($f['options'] ?? []);
            switch ($type) {
                case 'checkbox':
                    $v = Request::bool($k) ? 1 : 0;
                    break;
                case 'number':
                    $v = Request::int($k);
                    break;
                case 'select':
                    $raw = Request::str($k);
                    $v = $raw !== '' && array_key_exists($raw, $options) ? $raw : null;
                    if ($v !== null && ctype_digit((string)$v)) {
                        $v = (int)$v;
                    }
                    break;
                case 'multiselect':
                    $v = implode(',', array_intersect(Request::intList($k), array_map('intval', array_keys($options)))) ?: null;
                    break;
                case 'checkboxes':
                    $v = json_encode(array_values(array_intersect((array)Request::post($k, []), array_keys($options))));
                    break;
                case 'relation':
                    $relations[$k] = array_intersect(Request::intList($k), array_map('intval', array_keys($options)));
                    continue 2;
                case 'html':
                    $v = Html::clean((string)Request::post($k, ''), 'signature');
                    break;
                case 'color':
                    $v = valid_hex_color(Request::str($k));
                    break;
                default:
                    $v = Request::str($k);
                    $v = $v === '' ? null : $v;
            }
            if (!empty($f['required']) && ($v === null || $v === '')) {
                $errors[] = __('{field} is required.', ['field' => $f['label']]);
            }
            $data[$k] = $v;
        }

        if ($entity === 'fields') {
            $name = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', (string)($data['name'] ?: $data['label'])) ?? '');
            $data['name'] = trim($name, '_') ?: 'field';
            if ($db->val('SELECT COUNT(*) FROM custom_fields WHERE name = ? AND id <> ?', [$data['name'], (int)$id])) {
                $errors[] = __('Another field already uses the variable name "{name}".', ['name' => $data['name']]);
            }
        }
        if ($entity === 'banlist') {
            $data['pattern'] = strtolower(trim((string)$data['pattern']));
            if (!filter_var($data['pattern'], FILTER_VALIDATE_EMAIL) && !preg_match('/^@?[a-z0-9.\-]+\.[a-z]{2,}$/', $data['pattern'])) {
                $errors[] = __('Enter an email address or a domain like @example.com.');
            }
            if (!str_contains($data['pattern'], '@') || str_starts_with($data['pattern'], '@')) {
                $data['pattern'] = '@' . ltrim($data['pattern'], '@');
            }
            if ($db->val('SELECT COUNT(*) FROM banlist WHERE pattern = ? AND id <> ?', [$data['pattern'], (int)$id])) {
                $errors[] = __('That pattern is already banned.');
            }
        }
        if ($errors) {
            $this->failWith($errors, $back);
        }

        $cols = array_keys(Schema::definition()[$e['table']]['columns']);
        if (in_array('updated_at', $cols, true)) {
            $data['updated_at'] = DB::now();
        }
        if ($id) {
            $db->update($e['table'], $data, 'id = ?', [$id]);
        } else {
            if (in_array('created_at', $cols, true)) {
                $data['created_at'] = DB::now();
            }
            $id = $db->insert($e['table'], $data);
        }
        foreach ($relations as $k => $values) {
            $f = $e['fields'][$k];
            $db->delete($f['table'], "{$f['fk']} = ?", [$id]);
            foreach ($values as $v) {
                $db->insert($f['table'], [$f['fk'] => $id, $f['other'] => $v]);
            }
        }
        Tickets::clearLookupCache();
        Audit::log($entity . '.save', $entity, $id);
        $this->flash('success', $e['words']['saved']);
        $this->redirect('/panel/admin/' . $entity);
    }

    public function delete(string $entity, int $id): void
    {
        $e = $this->entity($entity);
        if (isset($e['inUse']) && ($msg = ($e['inUse'])($id))) {
            $this->failWith(__('Cannot delete: {reason}', ['reason' => $msg]), '/panel/admin/' . $entity);
        }
        $db = App::db();
        foreach ($e['fields'] as $f) {
            if ($f['type'] === 'relation') {
                $db->delete($f['table'], "{$f['fk']} = ?", [$id]);
            }
        }
        if ($entity === 'fields') {
            $db->delete('ticket_field_values', 'field_id = ?', [$id]);
        }
        $db->delete($e['table'], 'id = ?', [$id]);
        Audit::log($entity . '.delete', $entity, $id);
        $this->flash('success', $e['words']['deleted']);
        $this->redirect('/panel/admin/' . $entity);
    }
}
