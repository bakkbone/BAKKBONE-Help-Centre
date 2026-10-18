<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Mail\Mailer;
use Bakkbone\HelpCentre\Services\Cron;
use Bakkbone\HelpCentre\Services\Installer;
use Bakkbone\HelpCentre\Services\Migration;

final class SystemController extends AdminController
{
    public function index(): void
    {
        $db = App::db();
        $checks = [
            __('PHP version') => [PHP_VERSION, PHP_VERSION_ID >= 80100],
            __('Database') => [$db->driver . ' ' . $db->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), true],
            'OpenSSL' => [extension_loaded('openssl') ? __('loaded') : __('missing'), extension_loaded('openssl')],
            'cURL' => [extension_loaded('curl') ? __('loaded') : __('missing'), extension_loaded('curl')],
            'mbstring' => [extension_loaded('mbstring') ? __('loaded') : __('missing'), extension_loaded('mbstring')],
            __('iconv (optional)') => [function_exists('iconv') ? __('loaded') : __('missing'), true],
            __('fileinfo (optional)') => [extension_loaded('fileinfo') ? __('loaded') : __('missing'), true],
            __('Attachments folder writable') => [App::storage('attachments'), is_writable(App::storage('attachments'))],
            __('Cache folder writable') => [App::storage('cache'), is_writable(App::storage('cache'))],
            'upload_max_filesize' => [ini_get('upload_max_filesize'), true],
            'post_max_size' => [ini_get('post_max_size'), true],
        ];
        $cronLast = (string)Settings::get('cron_last_run');
        $this->view('admin/system', [
            'title' => __('System'), 'adminSection' => 'system', 'checks' => $checks, 'cronLast' => $cronLast,
            'cronUrl' => App::url('/api/cron', ['key' => App::config('cron_key')]),
            'cronEndpoint' => App::url('/api/cron'), 'cronKey' => (string)App::config('cron_key'),
            'schemaVersion' => Settings::get('schema_version'),
            'migrationExpires' => Migration::keyExpires(),
            'newMigrationKey' => (function () { $k = \Bakkbone\HelpCentre\Core\Session::get('new_migration_key'); \Bakkbone\HelpCentre\Core\Session::forget('new_migration_key'); return $k; })(),
            'counts' => [
                __('Tickets') => (int)$db->val('SELECT COUNT(*) FROM tickets'),
                __('Users') => (int)$db->val('SELECT COUNT(*) FROM users'),
                __('Agents') => (int)$db->val('SELECT COUNT(*) FROM staff'),
                __('Attachments') => (int)$db->val('SELECT COUNT(*) FROM attachments'),
                __('Queued emails') => (int)$db->val('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL AND failed = 0'),
                __('Failed emails') => (int)$db->val('SELECT COUNT(*) FROM mail_queue WHERE failed = 1'),
            ],
        ]);
    }

    public function migrationKey(): void
    {
        $key = Migration::createKey();
        \Bakkbone\HelpCentre\Core\Session::set('new_migration_key', $key);
        \Bakkbone\HelpCentre\Core\Audit::log('migration.key_created', 'system', null);
        $this->redirect('/panel/admin/system#copy');
    }

    public function revokeMigrationKey(): void
    {
        Migration::revokeKey();
        \Bakkbone\HelpCentre\Core\Audit::log('migration.key_revoked', 'system', null);
        $this->flash('success', __('Migration key removed. Nothing can copy this help desk now.'));
        $this->redirect('/panel/admin/system#copy');
    }

    public function export(): void
    {
        $key = Request::str('migration_key');
        if (!Migration::keyValid($key)) {
            $this->flash('error', __('Enter the current migration key to download an export. If you no longer have it, create a new one.'));
            $this->redirect('/panel/admin/system#copy');
        }
        \Bakkbone\HelpCentre\Core\Audit::log('migration.exported', 'system', null);
        session_write_close();
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="helpcentre-' . gmdate('Ymd-His') . '.' . Migration::FILE_EXT . '"');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        $out = fopen('php://output', 'wb');
        Migration::writeExport($out, $key, fn() => flush());
        fclose($out);
        exit;
    }

    public function upgrade(): void
    {
        try {
            $log = Installer::upgrade();
            $this->flash('success', $log ? __('Database upgraded: {changes}', ['changes' => implode('; ', $log)]) : __('Database is already up to date.'));
        } catch (\Throwable $e) {
            \Bakkbone\HelpCentre\Core\Settings::set('schema_upgrade_error', mb_substr($e->getMessage(), 0, 1000));
            $this->flash('error', __('The database upgrade failed: {error} Check that the database user is allowed to CREATE and ALTER tables, then try again.', ['error' => $e->getMessage()]));
        }
        $this->redirect('/panel/admin/system');
    }

    public function runCron(): void
    {
        $out = Cron::run();
        $summary = [];
        foreach ($out as $k => $v) {
            $summary[] = $k . ': ' . (is_array($v) ? (implode(', ', array_map(fn($a, $b) => "$a ($b)", array_keys($v), $v)) ?: 'nothing to do') : (string)$v);
        }
        $this->flash('success', __('Cron ran. {summary}', ['summary' => implode(' | ', $summary)]));
        $this->redirect('/panel/admin/system');
    }

    public function logs(): void
    {
        $db = App::db();
        [$page, $per, $offset] = $this->paging(50);
        $level = Request::str('level');
        $where = $level !== '' ? 'WHERE level = ?' : '';
        $params = $level !== '' ? [$level] : [];
        $total = (int)$db->val("SELECT COUNT(*) FROM system_log $where", $params);
        $rows = $db->all("SELECT * FROM system_log $where ORDER BY id DESC LIMIT $per OFFSET $offset", $params);
        $this->view('admin/logs', ['title' => __('System log'), 'adminSection' => 'logs', 'rows' => $rows, 'page' => $page, 'pages' => (int)ceil($total / $per), 'level' => $level]);
    }

    public function audit(): void
    {
        $db = App::db();
        [$page, $per, $offset] = $this->paging(50);
        $total = (int)$db->val('SELECT COUNT(*) FROM audit_log');
        $rows = $db->all(
            "SELECT a.*, s.first_name, s.last_name, s.username, u.email AS user_email FROM audit_log a
             LEFT JOIN staff s ON s.id = a.staff_id LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT $per OFFSET $offset"
        );
        $this->view('admin/audit', ['title' => __('Audit log'), 'adminSection' => 'audit', 'rows' => $rows, 'page' => $page, 'pages' => (int)ceil($total / $per)]);
    }

    public function mailQueue(): void
    {
        $rows = App::db()->all('SELECT * FROM mail_queue ORDER BY id DESC LIMIT 200');
        $this->view('admin/mailqueue', ['title' => __('Outgoing mail queue'), 'adminSection' => 'mailqueue', 'rows' => $rows]);
    }

    public function retryMail(int $id): void
    {
        App::db()->update('mail_queue', ['failed' => 0, 'attempts' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
        $ok = Mailer::deliverQueued($id);
        $this->flash($ok ? 'success' : 'error', $ok ? __('Email sent.') : __('Sending failed again - see the error column.'));
        $this->redirect('/panel/admin/mailqueue');
    }
}
