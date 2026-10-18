<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;

final class ApiKeyController extends AdminController
{
    public function index(): void
    {
        $this->view('admin/apikeys', [
            'title' => __('API keys'), 'adminSection' => 'apikeys',
            'rows' => App::db()->all('SELECT * FROM api_keys ORDER BY name'),
            'newKey' => Session::get('new_api_key'),
            'apiUrl' => App::url('/api/tickets.json'),
            'pipeUrl' => App::url('/api/tickets.email'),
        ]);
        Session::forget('new_api_key');
    }

    public function store(): void
    {
        $name = Request::str('name');
        if ($name === '') {
            $this->failWith(__('Give the key a name.'), '/panel/admin/apikeys');
        }
        $key = 'bkhc_' . bin2hex(random_bytes(24));
        $ips = implode("\n", array_filter(array_map('trim', preg_split('/[\s,]+/', Request::str('ip_allow')) ?: []), fn($ip) => (bool)filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)));
        $id = App::db()->insert('api_keys', [
            'name' => $name,
            'key_hash' => hash('sha256', $key),
            'key_prefix' => substr($key, 0, 12),
            'ip_allow' => $ips ?: null,
            'can_create_tickets' => Request::bool('can_create_tickets') ? 1 : 0,
            'can_pipe' => Request::bool('can_pipe') ? 1 : 0,
            'active' => 1,
            'created_at' => DB::now(),
        ]);
        Audit::log('apikey.create', 'api_key', $id, ['name' => $name]);
        Session::set('new_api_key', $key);
        $this->redirect('/panel/admin/apikeys');
    }

    public function toggle(int $id): void
    {
        App::db()->run('UPDATE api_keys SET active = 1 - active WHERE id = ?', [$id]);
        $this->redirect('/panel/admin/apikeys');
    }

    public function delete(int $id): void
    {
        App::db()->delete('api_keys', 'id = ?', [$id]);
        Audit::log('apikey.delete', 'api_key', $id);
        $this->flash('success', __('API key revoked.'));
        $this->redirect('/panel/admin/apikeys');
    }
}
