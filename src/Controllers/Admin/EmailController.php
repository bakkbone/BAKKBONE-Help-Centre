<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Mail\Fetcher;
use Bakkbone\HelpCentre\Mail\Mailer;
use Bakkbone\HelpCentre\Services\EmailAccounts;

final class EmailController extends AdminController
{
    private const SECRETS = ['in_password', 'smtp_password', 'ms_client_secret'];

    public function index(): void
    {
        $rows = App::db()->all('SELECT e.*, d.name AS dept_name FROM email_accounts e LEFT JOIN departments d ON d.id = e.dept_id ORDER BY e.email');
        $this->view('admin/emails', [
            'title' => __('Mailboxes'), 'adminSection' => 'emails', 'rows' => $rows,
            'defaultId' => (int)Settings::get('default_email_account_id'),
            'pipeCmd' => PHP_BINARY . ' ' . HC_ROOT . '/bin/pipe.php',
            'pipeUrl' => App::url('/api/tickets.email'),
        ]);
    }

    public function form(?int $id = null): void
    {
        $acc = $id ? App::db()->find('email_accounts', $id) : null;
        if ($id && !$acc) {
            $this->notFound();
        }
        $db = App::db();
        $this->view('admin/email_form', [
            'title' => $acc ? __('Edit mailbox') : __('Add mailbox'), 'adminSection' => 'emails', 'acc' => $acc,
            'depts' => $db->all('SELECT id, name FROM departments ORDER BY name'),
            'priorities' => $db->all('SELECT id, name FROM priorities ORDER BY urgency'),
            'topics' => $db->all('SELECT id, name FROM help_topics ORDER BY name'),
        ]);
    }

    public function save(?int $id = null): void
    {
        $email = strtolower(Request::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failWith(__('A valid email address is required.'), $id ? "/panel/admin/emails/$id" : '/panel/admin/emails/new');
        }
        $dupe = App::db()->val('SELECT id FROM email_accounts WHERE LOWER(email) = ? AND id <> ?', [$email, (int)$id]);
        if ($dupe) {
            $this->failWith(__('That address is already configured.'), $id ? "/panel/admin/emails/$id" : '/panel/admin/emails/new');
        }
        $pick = fn(string $k, array $allowed, string $default) => in_array(Request::str($k), $allowed, true) ? Request::str($k) : $default;
        $data = [
            'name' => Request::str('name') ?: $email,
            'email' => $email,
            'from_name' => Request::str('from_name') ?: null,
            'auth_type' => $pick('auth_type', ['password', 'oauth_ms'], 'password'),
            'ms_tenant' => Request::str('ms_tenant') ?: null,
            'ms_client_id' => Request::str('ms_client_id') ?: null,
            'inbound_protocol' => $pick('inbound_protocol', ['none', 'imap', 'pop3', 'graph'], 'none'),
            'in_host' => Request::str('in_host') ?: null,
            'in_port' => Request::int('in_port') ?: null,
            'in_encryption' => $pick('in_encryption', ['ssl', 'tls', 'none'], 'ssl'),
            'in_username' => Request::str('in_username') ?: null,
            'in_folder' => Request::str('in_folder') ?: 'INBOX',
            'in_post_action' => $pick('in_post_action', ['seen', 'delete', 'move'], 'seen'),
            'in_archive_folder' => Request::str('in_archive_folder') ?: null,
            'outbound_protocol' => $pick('outbound_protocol', ['smtp', 'graph', 'mail'], 'mail'),
            'smtp_host' => Request::str('smtp_host') ?: null,
            'smtp_port' => Request::int('smtp_port') ?: null,
            'smtp_encryption' => $pick('smtp_encryption', ['tls', 'ssl', 'none'], 'tls'),
            'smtp_auth' => Request::bool('smtp_auth') ? 1 : 0,
            'smtp_username' => Request::str('smtp_username') ?: null,
            'dept_id' => Request::int('dept_id') ?: null,
            'priority_id' => Request::int('priority_id') ?: null,
            'topic_id' => Request::int('topic_id') ?: null,
            'active' => Request::bool('active') ? 1 : 0,
            'updated_at' => DB::now(),
        ];
        foreach (self::SECRETS as $s) {
            $v = (string)Request::post($s, '');
            if ($v !== '') {
                $data[$s] = Crypto::encrypt($v);
            } elseif (Request::bool('clear_' . $s)) {
                $data[$s] = null;
            }
        }
        $errors = [];
        if (in_array($data['inbound_protocol'], ['imap', 'pop3'], true) && !$data['in_host']) {
            $errors[] = __('Incoming server host is required for IMAP/POP3.');
        }
        if ($data['outbound_protocol'] === 'smtp' && !$data['smtp_host']) {
            $errors[] = __('SMTP host is required.');
        }
        $needsMs = $data['auth_type'] === 'oauth_ms' || $data['inbound_protocol'] === 'graph' || $data['outbound_protocol'] === 'graph';
        if ($needsMs && (!$data['ms_tenant'] || !$data['ms_client_id'])) {
            $errors[] = __('Microsoft 365 tenant ID and client ID are required for Graph / OAuth.');
        }
        if ($errors) {
            $this->failWith($errors, $id ? "/panel/admin/emails/$id" : '/panel/admin/emails/new');
        }
        if ($id) {
            App::db()->update('email_accounts', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            $id = App::db()->insert('email_accounts', $data);
            if (!Settings::get('default_email_account_id')) {
                Settings::set('default_email_account_id', (string)$id);
            }
        }
        if (Request::bool('make_default')) {
            Settings::set('default_email_account_id', (string)$id);
        }
        EmailAccounts::clearCache();
        Audit::log('email_account.save', 'email_account', $id, ['email' => $email]);
        $this->flash('success', __('Mailbox saved. Use the test buttons to check the connection.'));
        $this->redirect('/panel/admin/emails/' . $id);
    }

    public function delete(int $id): void
    {
        App::db()->delete('email_accounts', 'id = ?', [$id]);
        App::db()->update('departments', ['email_account_id' => null], 'email_account_id = ?', [$id]);
        if ((int)Settings::get('default_email_account_id') === $id) {
            Settings::set('default_email_account_id', '');
        }
        $this->flash('success', __('Mailbox removed.'));
        $this->redirect('/panel/admin/emails');
    }

    public function testInbound(int $id): void
    {
        $acc = EmailAccounts::find($id);
        try {
            $this->flash('success', Fetcher::testInbound((array)$acc));
        } catch (\Throwable $e) {
            $this->flash('error', __('Inbound test failed: {error}', ['error' => $e->getMessage()]));
        }
        $this->redirect('/panel/admin/emails/' . $id);
    }

    public function testOutbound(int $id): void
    {
        $acc = EmailAccounts::find($id);
        $to = strtolower(Request::str('to')) ?: (string)$this->staff['email'];
        try {
            Mailer::deliver([
                'to' => [['email' => $to, 'name' => '']],
                'subject' => __('Test email from {helpdesk}', ['helpdesk' => Settings::get('helpdesk_name')]),
                'html' => '<p>' . t('This is a test message sent via <strong>{mailbox}</strong> using {method}.', ['mailbox' => $acc['email'], 'method' => strtoupper((string)$acc['outbound_protocol'])]) . '</p><p>' . t('If you can read this, outgoing email works.') . '</p>',
                'message_id' => Mailer::newMessageId(),
            ], $acc);
            $this->flash('success', __('Test email sent to {email}.', ['email' => $to]));
        } catch (\Throwable $e) {
            $this->flash('error', __('Outbound test failed: {error}', ['error' => $e->getMessage()]));
        }
        $this->redirect('/panel/admin/emails/' . $id);
    }

    public function fetchNow(int $id): void
    {
        $acc = EmailAccounts::find($id);
        $res = Fetcher::fetchAccount((array)$acc);
        $this->flash(str_starts_with($res, 'ERROR') ? 'error' : 'success', __('Fetch: {result}', ['result' => $res]));
        $this->redirect('/panel/admin/emails/' . $id);
    }
}
