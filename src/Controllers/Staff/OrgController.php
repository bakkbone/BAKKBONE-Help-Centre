<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Staff;

final class OrgController extends StaffController
{
    public function index(): void
    {
        $q = Request::str('q');
        $params = [];
        $where = '1=1';
        if ($q !== '') {
            $where = '(o.name LIKE ? OR o.domains LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $rows = App::db()->all(
            "SELECT o.*, (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id) AS user_count FROM organizations o WHERE $where ORDER BY o.name LIMIT 500",
            $params
        );
        $this->view('staff/orgs', ['title' => __('Organisations'), 'orgs' => $rows, 'q' => $q]);
    }

    public function create(): void
    {
        $this->requirePerm('org.manage');
        $this->view('staff/org_form', ['title' => __('New organisation'), 'o' => null]);
    }

    private function data(): array
    {
        $domains = array_filter(array_map(fn($d) => strtolower(trim(ltrim(trim($d), '@'))), explode(',', Request::str('domains'))));
        return [
            'name' => Request::str('name'),
            'domains' => $domains ? implode(', ', $domains) : null,
            'sharing' => Request::bool('sharing') ? 1 : 0,
            'phone' => Request::str('phone') ?: null,
            'website' => Request::str('website') ?: null,
            'notes' => Request::str('notes') ?: null,
            'updated_at' => DB::now(),
        ];
    }

    public function store(): void
    {
        $this->requirePerm('org.manage');
        $d = $this->data();
        if ($d['name'] === '') {
            $this->failWith(__('Name is required.'), '/panel/orgs/new');
        }
        $d['created_at'] = DB::now();
        $id = App::db()->insert('organizations', $d);
        $this->linkByDomain($id, $d['domains']);
        Audit::log('org.create', 'org', $id, ['name' => $d['name']]);
        $this->flash('success', __('Organisation created.'));
        $this->redirect('/panel/orgs/' . $id);
    }

    private function linkByDomain(int $orgId, ?string $domains): void
    {
        if (!$domains || !Request::bool('link_existing')) {
            return;
        }
        foreach (array_map('trim', explode(',', $domains)) as $d) {
            if ($d !== '') {
                App::db()->run('UPDATE users SET org_id = ? WHERE org_id IS NULL AND email LIKE ?', [$orgId, '%@' . $d]);
            }
        }
    }

    public function show(int $id): void
    {
        $db = App::db();
        $o = $db->find('organizations', $id);
        if (!$o) {
            $this->notFound();
        }
        [$scope, $params] = Staff::ticketScope($this->staff);
        $this->view('staff/org', [
            'title' => $o['name'], 'o' => $o,
            'users' => $db->all('SELECT * FROM users WHERE org_id = ? ORDER BY name', [$id]),
            'tickets' => $db->all("SELECT t.*, u.name AS user_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE $scope AND u.org_id = ? AND t.merged_into IS NULL ORDER BY t.created_at DESC LIMIT 50", array_merge($params, [$id])),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePerm('org.manage');
        $d = $this->data();
        if ($d['name'] === '') {
            $this->failWith(__('Name is required.'), '/panel/orgs/' . $id);
        }
        App::db()->update('organizations', $d, 'id = ?', [$id]);
        $this->linkByDomain($id, $d['domains']);
        Audit::log('org.update', 'org', $id);
        $this->flash('success', __('Organisation saved.'));
        $this->redirect('/panel/orgs/' . $id);
    }

    public function delete(int $id): void
    {
        $this->requirePerm('org.manage');
        App::db()->update('users', ['org_id' => null], 'org_id = ?', [$id]);
        App::db()->delete('organizations', 'id = ?', [$id]);
        Audit::log('org.delete', 'org', $id);
        $this->flash('success', __('Organisation deleted. Its users were kept.'));
        $this->redirect('/panel/orgs');
    }
}
