<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\Csrf;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Services\BhcSync;
use Bakkbone\HelpCentre\Services\OsTicketImporter;

final class ImportController extends AdminController
{
    public function index(): void
    {
        $state = OsTicketImporter::state();
        $this->view('admin/import', [
            'title' => __('Import from osTicket'), 'adminSection' => 'import',
            'state' => $state, 'percent' => $state ? OsTicketImporter::percent($state) : 0,
            'preview' => Session::get('osticket_preview'),
        ]);
    }

    public function check(): void
    {
        Csrf::verifyOrFail();
        Session::flashInput($this->formInput());
        try {
            [$conn, $files] = $this->resolve();
            $info = OsTicketImporter::inspect($conn);
            Session::set('osticket_conn', Crypto::encrypt((string)json_encode($conn)));
            Session::set('osticket_preview', $info + ['files' => $files ?: $info['files_path']]);
            $this->flash('success', $info['helpdesk'] !== '' ? __('Connected to osTicket ("{name}"). Check the numbers below, then start the import.', ['name' => $info['helpdesk']]) : __('Connected to osTicket. Check the numbers below, then start the import.'));
        } catch (\Throwable $e) {
            Session::forget('osticket_preview');
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/panel/admin/import');
    }

    public function start(): void
    {
        Csrf::verifyOrFail();
        Session::flashInput($this->formInput());
        try {
            [$conn, $files] = $this->resolve();
            OsTicketImporter::start($conn, $files, (int)$this->staff['id'], ['templates' => Request::bool('import_templates')]);
            Session::forget('osticket_preview', 'osticket_conn');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/panel/admin/import');
    }

    public function step(): void
    {
        Csrf::verifyOrFail();
        session_write_close(); // Release the session lock so the rest of the panel isn't blocked while importing.
        $state = OsTicketImporter::step(8.0, Request::bool('resume'));
        if (Request::wantsJson()) {
            $this->json(self::summary($state));
        }
        $this->redirect('/panel/admin/import');
    }

    public function reset(): void
    {
        Csrf::verifyOrFail();
        $state = OsTicketImporter::state();
        if ($state && $state['status'] === 'running' && !Request::bool('confirm')) {
            $this->flash('error', __('An import is still running.'));
            $this->redirect('/panel/admin/import');
        }
        OsTicketImporter::reset();
        Session::forget('osticket_preview', 'osticket_conn');
        $this->flash('success', __('Import cleared. Anything already imported stays, and running the import again skips it.'));
        $this->redirect('/panel/admin/import');
    }

    /** @return array<string,mixed> */
    public static function summary(array $state): array
    {
        $steps = [];
        foreach ($state['steps'] ?? [] as $key => $s) {
            $steps[] = ['key' => $key, 'label' => __($s['label']), 'done' => min((int)$s['done'], (int)$s['total']) ?: (int)$s['done'], 'total' => (int)$s['total'], 'complete' => (bool)$s['complete']];
        }
        return [
            'status' => $state['status'] ?? 'idle', 'percent' => $state ? OsTicketImporter::percent($state) : 0,
            'current' => $state['current'] ?? null, 'steps' => $steps, 'error' => $state['error'] ?? null,
            'skipped' => (int)($state['skipped_count'] ?? 0),
        ];
    }

    /** @return array{0:array<string,mixed>,1:?string} */
    private function resolve(): array
    {
        $files = Request::str('files_path') ?: null;
        if (Request::str('mode') === 'path') {
            $path = Request::str('ost_path');
            if ($path === '') {
                throw new \RuntimeException(__('Enter the folder osTicket is installed in, or switch to "Enter database details".'));
            }
            return [OsTicketImporter::readConfig($path), $files];
        }
        $conn = [
            'host' => Request::str('db_host') ?: 'localhost', 'port' => Request::int('db_port', 3306) ?: 3306,
            'name' => Request::str('db_name'), 'user' => Request::str('db_user'),
            'pass' => (string)Request::post('db_pass', ''), 'prefix' => Request::str('db_prefix', 'ost_'),
        ];
        if ($conn['pass'] === '' && ($saved = Session::get('osticket_conn'))) {
            $prev = json_decode((string)Crypto::decrypt($saved), true) ?: [];
            if (($prev['name'] ?? '') === $conn['name'] && ($prev['user'] ?? '') === $conn['user']) {
                $conn['pass'] = (string)($prev['pass'] ?? '');
            }
        }
        if ($conn['name'] === '') {
            throw new \RuntimeException(__('Enter the osTicket database name.'));
        }
        return [$conn, $files];
    }

    private function formInput(): array
    {
        $in = $_POST;
        unset($in['db_pass'], $in['_csrf']);
        return $in;
    }

    public function bhc(): void
    {
        $state = BhcSync::state();
        $this->view('admin/import_bhc', [
            'title' => __('Import from another BAKKBONE Help Centre'), 'adminSection' => 'import',
            'state' => $state, 'percent' => $state ? BhcSync::percent($state) : 0, 'sources' => BhcSync::sources(),
        ]);
    }

    public function bhcStart(): void
    {
        Csrf::verifyOrFail();
        Session::flashInput(['bhc_url' => Request::str('bhc_url')]);
        $state = BhcSync::state();
        if ($state && $state['status'] === 'running') {
            $this->redirect('/panel/admin/import/bhc');
        }
        try {
            BhcSync::start(Request::str('bhc_url'), Request::str('bhc_key'), (int)$this->staff['id'], Request::bool('full'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/panel/admin/import/bhc');
    }

    public function bhcStep(): void
    {
        Csrf::verifyOrFail();
        session_write_close();
        $state = BhcSync::step(8.0, Request::bool('resume'));
        if (Request::wantsJson()) {
            $steps = [];
            foreach ($state['steps'] ?? [] as $key => $st) {
                $steps[] = ['key' => $key, 'label' => __($st['label']), 'done' => (int)$st['done'], 'total' => (int)$st['total'], 'complete' => (bool)$st['complete']];
            }
            $this->json(['status' => $state['status'] ?? 'idle', 'percent' => $state ? BhcSync::percent($state) : 0, 'current' => $state['current'] ?? null,
                'steps' => $steps, 'error' => $state['error'] ?? null]);
        }
        $this->redirect('/panel/admin/import/bhc');
    }

    public function bhcReset(): void
    {
        Csrf::verifyOrFail();
        $state = BhcSync::state();
        if (!$state || $state['status'] !== 'running') {
            BhcSync::reset();
        }
        $this->redirect('/panel/admin/import/bhc');
    }
}
