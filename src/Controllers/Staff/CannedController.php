<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Templates;
use Bakkbone\HelpCentre\Services\Tickets;

final class CannedController extends StaffController
{
    public function index(): void
    {
        $rows = App::db()->all('SELECT c.*, d.name AS dept_name FROM canned_responses c LEFT JOIN departments d ON d.id = c.dept_id ORDER BY c.title');
        $this->view('staff/canned', ['title' => __('Canned responses'), 'rows' => $rows, 'canManage' => $this->can('canned.manage')]);
    }

    public function form(?int $id = null): void
    {
        $this->requirePerm('canned.manage');
        $c = $id ? App::db()->find('canned_responses', $id) : null;
        if ($id && !$c) {
            $this->notFound();
        }
        $this->view('staff/canned_form', [
            'title' => $c ? __('Edit canned response') : __('New canned response'), 'c' => $c,
            'depts' => App::db()->all('SELECT id, name FROM departments ORDER BY name'),
        ]);
    }

    public function save(?int $id = null): void
    {
        $this->requirePerm('canned.manage');
        $data = [
            'title' => Request::str('title'),
            'body' => Html::clean((string)Request::post('body', ''), 'signature'),
            'dept_id' => Request::int('dept_id') ?: null,
            'active' => Request::bool('active') ? 1 : 0,
            'updated_at' => DB::now(),
        ];
        if ($data['title'] === '') {
            $this->failWith(__('Title is required.'), $id ? "/panel/canned/$id" : '/panel/canned/new');
        }
        if ($id) {
            App::db()->update('canned_responses', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            App::db()->insert('canned_responses', $data);
        }
        $this->flash('success', __('Canned response saved.'));
        $this->redirect('/panel/canned');
    }

    public function delete(int $id): void
    {
        $this->requirePerm('canned.manage');
        App::db()->delete('canned_responses', 'id = ?', [$id]);
        $this->flash('success', __('Canned response deleted.'));
        $this->redirect('/panel/canned');
    }

    public function render(int $id): void
    {
        $c = App::db()->find('canned_responses', $id);
        if (!$c) {
            $this->json(['error' => __('Not found')], 404);
        }
        $body = (string)$c['body'];
        if ($tid = Request::int('ticket')) {
            $t = Tickets::find($tid);
            if ($t && Staff::canAccessTicket($this->staff, $t)) {
                $user = \Bakkbone\HelpCentre\Services\Users::find((int)$t['user_id']);
                $ctx = Tickets::context($t, Tickets::actorContext($this->staff) + ($user ? Tickets::recipientContext($t, $user['email'], $user['name'], (int)$user['id']) : []));
                $body = Templates::merge($body, $ctx, 'html');
            }
        }
        $this->json(['title' => $c['title'], 'html' => $body]);
    }
}
