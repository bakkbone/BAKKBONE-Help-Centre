<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Templates;
use Bakkbone\HelpCentre\Services\Webhooks;

final class WebhookController extends AdminController
{
    public function index(): void
    {
        $rows = App::db()->all(
            'SELECT w.*, (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.success = 0 AND d.next_attempt_at IS NULL) AS failed_count,
                    (SELECT MAX(created_at) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS last_at
             FROM webhooks w ORDER BY w.name'
        );
        $this->view('admin/webhooks', ['title' => __('Webhooks'), 'adminSection' => 'webhooks', 'rows' => $rows]);
    }

    public function form(?int $id = null): void
    {
        $w = $id ? App::db()->find('webhooks', $id) : null;
        if ($id && !$w) {
            $this->notFound();
        }
        $sample = Webhooks::defaultPayload('ticket.created', Webhooks::sampleContext());
        $this->view('admin/webhook_form', [
            'title' => $w ? __('Edit webhook') : __('New webhook'), 'adminSection' => 'webhooks', 'w' => $w,
            'depts' => App::db()->all('SELECT id, name FROM departments ORDER BY name'),
            'vars' => Templates::variables(),
            'sample' => json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function save(?int $id = null): void
    {
        $url = Request::str('url');
        $back = $id ? "/panel/admin/webhooks/$id" : '/panel/admin/webhooks/new';
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->failWith(__('A valid http(s) URL is required.'), $back);
        }
        $events = array_values(array_intersect((array)Request::post('events', []), array_keys(Webhooks::EVENTS)));
        if (!$events) {
            $this->failWith(__('Choose at least one event.'), $back);
        }
        $type = in_array(Request::str('content_type'), ['json', 'form', 'text'], true) ? Request::str('content_type') : 'json';
        $body = (string)Request::post('body_template', '');
        if ($type === 'json' && trim($body) !== '') {
            $test = Templates::merge($body, Webhooks::sampleContext(), 'json');
            json_decode($test);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->failWith(__('The JSON body template is not valid JSON: {error}', ['error' => json_last_error_msg()]), $back);
            }
        }
        $data = [
            'name' => Request::str('name') ?: parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'method' => in_array(Request::str('method'), ['POST', 'PUT', 'PATCH', 'GET'], true) ? Request::str('method') : 'POST',
            'content_type' => $type,
            'body_template' => trim($body) !== '' ? $body : null,
            'headers' => Request::str('headers') ?: null,
            'events' => implode(',', $events),
            'dept_ids' => implode(',', Request::intList('dept_ids')) ?: null,
            'active' => Request::bool('active') ? 1 : 0,
            'updated_at' => DB::now(),
        ];
        $secret = (string)Request::post('secret', '');
        if ($secret !== '') {
            $data['secret'] = Crypto::encrypt($secret);
        } elseif (Request::bool('clear_secret')) {
            $data['secret'] = null;
        }
        if ($id) {
            App::db()->update('webhooks', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            $id = App::db()->insert('webhooks', $data);
        }
        Audit::log('webhook.save', 'webhook', $id, ['url' => $url]);
        $this->flash('success', __('Webhook saved.'));
        $this->redirect('/panel/admin/webhooks/' . $id);
    }

    public function delete(int $id): void
    {
        App::db()->delete('webhook_deliveries', 'webhook_id = ?', [$id]);
        App::db()->delete('webhooks', 'id = ?', [$id]);
        $this->flash('success', __('Webhook deleted.'));
        $this->redirect('/panel/admin/webhooks');
    }

    public function test(int $id): void
    {
        $r = Webhooks::test($id);
        $this->flash($r['ok'] ? 'success' : 'error', $r['ok'] ? __('Test delivered: {result}', ['result' => $r['message']]) : __('Test failed: {result}', ['result' => $r['message']]));
        $this->redirect('/panel/admin/webhooks/' . $id . '/deliveries');
    }

    public function deliveries(int $id): void
    {
        $w = App::db()->find('webhooks', $id);
        if (!$w) {
            $this->notFound();
        }
        $rows = App::db()->all('SELECT d.*, t.number FROM webhook_deliveries d LEFT JOIN tickets t ON t.id = d.ticket_id WHERE d.webhook_id = ? ORDER BY d.id DESC LIMIT 100', [$id]);
        $this->view('admin/webhook_deliveries', ['title' => __('Deliveries: {name}', ['name' => $w['name']]), 'adminSection' => 'webhooks', 'w' => $w, 'rows' => $rows]);
    }

    public function retry(int $id): void
    {
        $d = App::db()->find('webhook_deliveries', $id);
        if (!$d) {
            $this->notFound();
        }
        $ok = Webhooks::attempt($id);
        $this->flash($ok ? 'success' : 'error', $ok ? __('Delivered.') : __('Delivery failed again.'));
        $this->redirect('/panel/admin/webhooks/' . $d['webhook_id'] . '/deliveries');
    }
}
