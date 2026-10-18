<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\FilterEngine;
use Bakkbone\HelpCentre\Services\Staff;

final class FilterController extends AdminController
{
    public function index(): void
    {
        $this->view('admin/filters', ['title' => __('Ticket filters'), 'adminSection' => 'filters', 'rows' => App::db()->all('SELECT * FROM filters ORDER BY sort, id')]);
    }

    public function form(?int $id = null): void
    {
        $f = $id ? App::db()->find('filters', $id) : null;
        if ($id && !$f) {
            $this->notFound();
        }
        $db = App::db();
        $this->view('admin/filter_form', [
            'title' => $f ? __('Edit filter') : __('New filter'), 'adminSection' => 'filters', 'f' => $f,
            'rules' => $f ? (json_decode((string)$f['rules'], true) ?: []) : [],
            'actions' => $f ? (json_decode((string)$f['actions'], true) ?: []) : [],
            'lists' => [
                'dept_id' => array_column($db->all('SELECT id, name FROM departments ORDER BY name'), 'name', 'id'),
                'topic_id' => array_column($db->all('SELECT id, name FROM help_topics ORDER BY name'), 'name', 'id'),
                'priority_id' => array_column($db->all('SELECT id, name FROM priorities ORDER BY urgency'), 'name', 'id'),
                'sla_id' => array_column($db->all('SELECT id, name FROM sla ORDER BY name'), 'name', 'id'),
                'status_id' => array_column($db->all('SELECT id, name FROM ticket_statuses ORDER BY sort'), 'name', 'id'),
                'staff_id' => array_combine(array_column(Staff::active(), 'id'), array_map([Staff::class, 'name'], Staff::active())) ?: [],
                'team_id' => array_column($db->all('SELECT id, name FROM teams ORDER BY name'), 'name', 'id'),
            ],
        ]);
    }

    public function save(?int $id = null): void
    {
        $rules = [];
        $fields = (array)Request::post('rule_field', []);
        $ops = (array)Request::post('rule_op', []);
        $values = (array)Request::post('rule_value', []);
        foreach ($fields as $i => $field) {
            $field = (string)$field;
            $op = (string)($ops[$i] ?? '');
            $val = trim((string)($values[$i] ?? ''));
            if (isset(FilterEngine::FIELDS[$field], FilterEngine::OPS[$op]) && ($val !== '' || in_array($op, ['equals', 'not_equals'], true))) {
                $rules[] = ['field' => $field, 'op' => $op, 'value' => $val];
            }
        }
        $actions = [];
        foreach (array_keys(FilterEngine::ACTIONS) as $a) {
            if (in_array($a, ['reject', 'disable_autoresponse'], true)) {
                if (Request::bool('action_' . $a)) {
                    $actions[$a] = true;
                }
            } elseif ($v = Request::int('action_' . $a)) {
                $actions[$a] = $v;
            }
        }
        $sources = array_intersect((array)Request::post('sources', []), ['email', 'web', 'api', 'phone', 'other']);
        $data = [
            'name' => Request::str('name'),
            'active' => Request::bool('active') ? 1 : 0,
            'sort' => Request::int('sort'),
            'match_all' => Request::str('match') === 'any' ? 0 : 1,
            'stop_on_match' => Request::bool('stop_on_match') ? 1 : 0,
            'sources' => $sources ? implode(',', $sources) : null,
            'rules' => json_encode($rules),
            'actions' => json_encode($actions),
            'notes' => Request::str('notes') ?: null,
        ];
        if ($data['name'] === '' || !$rules) {
            $this->failWith(__('A name and at least one rule are required.'), $id ? "/panel/admin/filters/$id" : '/panel/admin/filters/new');
        }
        if ($id) {
            App::db()->update('filters', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            $id = App::db()->insert('filters', $data);
        }
        $this->flash('success', __('Filter saved.'));
        $this->redirect('/panel/admin/filters/' . $id);
    }

    public function delete(int $id): void
    {
        App::db()->delete('filters', 'id = ?', [$id]);
        $this->flash('success', __('Filter deleted.'));
        $this->redirect('/panel/admin/filters');
    }
}
