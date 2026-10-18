<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Setup;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\I18n;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\CloneImporter;
use Bakkbone\HelpCentre\Services\Installer;
use Bakkbone\HelpCentre\Services\OsTicketImporter;

final class InstallController extends Controller
{
    protected string $layout = 'layouts/setup';

    /** Setup has no settings table yet, so the language picked on the first screen is kept in the session. */
    private function useSetupLanguage(): void
    {
        $lang = Request::str('lang') ?: (string)Session::get('setup_lang', '');
        if ($lang !== '' && isset(I18n::available()[$lang])) {
            I18n::use($lang);
            Session::set('setup_lang', $lang);
        }
    }

    public function index(): void
    {
        $this->useSetupLanguage();
        if (App::$installed) {
            $this->cloneDone();
        }
        if (!empty(App::$config['cloning'])) {
            $this->cloneProgress();
            return;
        }
        if (!empty(App::$config['importing'])) {
            $this->importProgress();
            return;
        }
        $checks = [
            __('PHP 8.1+') => PHP_VERSION_ID >= 80100,
            __('PDO MySQL or SQLite') => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'OpenSSL' => extension_loaded('openssl'),
            'cURL' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            __('storage/ writable') => is_writable(App::storage()),
            __('config/ writable (or copy the file manually)') => is_writable(HC_ROOT . '/config'),
            __('zip or phar (for component downloads)') => class_exists(\ZipArchive::class) || class_exists(\PharData::class),
        ];
        if (!Request::isPost()) {
            $base = App::baseUrl();
            $probe = $this->probe($base . '/__hc-probe');
            $pretty = $probe === null ? !defined('HC_VIA_ROOT') : ($probe[0] === 200 && trim($probe[1]) === 'hc-ok');
            $warnings = [];
            foreach (['/config/config.sample.php', '/storage/.htaccess', '/src/bootstrap.php', '/addons/.htaccess', '/custom/.htaccess'] as $p) {
                $r = $this->probe($base . $p);
                if ($r !== null && $r[0] === 200 && trim($r[1]) !== 'hc-ok' && !str_contains($r[1], '<html')) {
                    $warnings[] = __('Private files are readable from the web (e.g. {url}). Point the web server\'s document root at the public/ folder, or use Apache so the included .htaccess files can protect them. Don\'t continue until this is fixed.', ['url' => $base . $p]);
                    break;
                }
            }
            $this->view('setup/install', [
                'title' => __('Install'), 'checks' => $checks, 'baseUrl' => $base, 'result' => null,
                'prettyDefault' => $pretty, 'warnings' => $warnings,
                'components' => \Bakkbone\HelpCentre\Core\Dependencies::usingComposer() ? __('Composer (vendor/)') : __('Built-in loader (downloaded & verified)'),
                'importFiles' => CloneImporter::availableFiles(),
                'languages' => I18n::available(),
            ]);
            return;
        }
        \Bakkbone\HelpCentre\Core\Csrf::verifyOrFail();

        $driver = Request::str('driver') === 'sqlite' ? 'sqlite' : 'mysql';
        $db = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => Request::str('sqlite_path') ?: App::storage('helpcentre.sqlite')]
            : ['driver' => 'mysql', 'host' => Request::str('db_host', 'localhost'), 'port' => Request::int('db_port', 3306), 'name' => Request::str('db_name'), 'user' => Request::str('db_user'), 'pass' => (string)Request::post('db_pass', '')];
        $admin = [
            'first_name' => Request::str('first_name'),
            'last_name' => Request::str('last_name'),
            'email' => strtolower(Request::str('email')),
            'username' => strtolower(Request::str('username')),
            'password' => (string)Request::post('password', ''),
        ];
        $errors = [];
        if ($driver === 'sqlite' && !self::sqlitePathAllowed((string)$db['path'])) {
            $errors[] = __('The SQLite database file must be inside the storage/ folder and end in .sqlite or .db.');
        }
        $cloning = Request::str('setup_mode') === 'clone';
        $importing = Request::str('setup_mode') === 'osticket';
        if (!$cloning) {
            if ($admin['first_name'] === '' || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9._\-]{2,64}$/', $admin['username'])) {
                $errors[] = __('Please complete the administrator details (valid email, username of letters/numbers).');
            }
            if ($err = Auth::passwordError($admin['password'], (string)Request::post('password_confirm', ''))) {
                $errors[] = $err;
            }
        }
        $baseUrl = rtrim(Request::str('base_url'), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $errors[] = __('Please enter the full URL of the help desk.');
        }
        if (!$errors) {
            try {
                $conn = new DB($db);
                if (Schema::tableExists($conn, 'tickets')) {
                    $errors[] = __('That database already contains a BAKKBONE Help Centre installation. Use an empty database, or restore config/config.php.');
                }
            } catch (\Throwable $e) {
                $errors[] = __('Database connection failed: {error}', ['error' => $e->getMessage()]);
            }
        }
        $source = [
            'mode' => Request::str('clone_mode') === 'file' ? 'file' : 'url',
            'url' => Request::str('clone_url'), 'path' => Request::str('clone_file'), 'key' => Request::str('clone_key'),
        ];
        if ($cloning && !$errors) {
            try {
                CloneImporter::probe($source); // Check the other help desk and the key before touching anything.
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $ostConn = null;
        if ($importing && !$errors) {
            try {
                $ostConn = Request::str('ost_mode') === 'db'
                    ? ['host' => Request::str('ost_db_host') ?: 'localhost', 'port' => Request::int('ost_db_port', 3306) ?: 3306, 'name' => Request::str('ost_db_name'),
                        'user' => Request::str('ost_db_user'), 'pass' => (string)Request::post('ost_db_pass', ''), 'prefix' => Request::str('ost_db_prefix', 'ost_')]
                    : OsTicketImporter::readConfig(Request::str('ost_path'));
                OsTicketImporter::inspect($ostConn); // Check osTicket is reachable before touching anything.
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            foreach ($errors as $e) {
                Session::flash('error', $e);
            }
            $input = $_POST;
            unset($input['clone_key'], $input['db_pass'], $input['ost_db_pass'], $input['password'], $input['password_confirm']);
            Session::flashInput($input);
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if ($cloning) {
            try {
                Installer::prepareClone($db, ['base_url' => $baseUrl, 'pretty_urls' => Request::bool('pretty_urls')]);
                CloneImporter::begin($source, ['pause' => Request::bool('pause_outbound')]);
                Session::set('clone_owner', true);
            } catch (\Throwable $e) {
                if (is_file(App::configPath()) && !empty(App::$config['cloning'])) {
                    @unlink(App::configPath());
                }
                Session::flash('error', $e->getMessage());
                View::redirect(App::baseUrl() . '/index.php/setup');
            }
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if ($importing) {
            try {
                $adminId = Installer::prepareOsTicketInstall($db, $admin, ['base_url' => $baseUrl, 'pretty_urls' => Request::bool('pretty_urls')]);
                OsTicketImporter::start($ostConn, Request::str('ost_files') ?: null, $adminId, ['templates' => Request::bool('ost_templates'), 'install' => true]);
                Session::set('clone_owner', true);
            } catch (\Throwable $e) {
                if (is_file(App::configPath()) && !empty(App::$config['importing'])) {
                    Installer::abandonClone();
                }
                Session::flash('error', $e->getMessage());
            }
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        $result = Installer::install($db, $admin, [
            'base_url' => $baseUrl,
            'helpdesk_name' => Request::str('helpdesk_name') ?: 'BAKKBONE Help Centre',
            'company_name' => Request::str('company_name') ?: 'BAKKBONE Australia',
            'timezone' => Request::str('timezone') ?: 'Australia/Sydney',
            'admin_email' => $admin['email'],
            'pretty_urls' => Request::bool('pretty_urls'),
            'locale' => I18n::locale(),
        ]);
        $this->view('setup/install', ['title' => __('Installed'), 'checks' => $checks, 'baseUrl' => $baseUrl, 'result' => $result]);
    }

    private function cloneProgress(): void
    {
        $state = CloneImporter::state();
        if (!$state) {
            @unlink(App::configPath());
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        $this->view('setup/clone', [
            'title' => __('Copying'), 'state' => $state, 'percent' => CloneImporter::percent($state),
            'owner' => (bool)Session::get('clone_owner'),
        ]);
    }

    public function cloneAction(): void
    {
        $this->useSetupLanguage();
        \Bakkbone\HelpCentre\Core\Csrf::verifyOrFail();
        if (empty(App::$config['cloning'])) {
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        $action = Request::str('action', 'step');
        if ($action === 'unlock') {
            if (CloneImporter::keyMatches(Request::str('clone_key'))) {
                Session::set('clone_owner', true);
            } else {
                Session::flash('error', __('That isn\'t the migration key this copy was started with.'));
            }
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if (!Session::get('clone_owner')) {
            Request::wantsJson() ? View::json(['error' => 'Not allowed'], 403) : View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if ($action === 'abandon') {
            Installer::abandonClone();
            Session::forget('clone_owner');
            Session::flash('success', __('The copy was cancelled and the database tables it created were removed. You can start again.'));
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        session_write_close();
        $state = CloneImporter::step(8.0, $action === 'resume');
        if (Request::wantsJson()) {
            $steps = [];
            foreach ($state['steps'] ?? [] as $key => $st) {
                $steps[] = ['key' => $key, 'label' => __($st['label']), 'done' => (int)$st['done'], 'total' => (int)$st['total'], 'complete' => (bool)$st['complete']];
            }
            View::json(['status' => $state['status'] ?? 'idle', 'percent' => $state ? CloneImporter::percent($state) : 0, 'current' => $state['current'] ?? null, 'steps' => $steps, 'error' => $state['error'] ?? null]);
        }
        View::redirect(App::baseUrl() . '/index.php/setup');
    }

    private function importProgress(): void
    {
        $state = OsTicketImporter::state();
        if (!$state) {
            Installer::abandonClone();
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        $this->view('setup/import', ['title' => __('Importing'), 'state' => $state, 'percent' => OsTicketImporter::percent($state), 'owner' => (bool)Session::get('clone_owner')]);
    }

    public function importAction(): void
    {
        $this->useSetupLanguage();
        \Bakkbone\HelpCentre\Core\Csrf::verifyOrFail();
        if (empty(App::$config['importing'])) {
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        $action = Request::str('action', 'step');
        if ($action === 'unlock') {
            $s = App::db()->row('SELECT * FROM staff WHERE is_admin = 1 AND (LOWER(username) = ? OR LOWER(email) = ?)', [strtolower(Request::str('login')), strtolower(Request::str('login'))]);
            if ($s && $s['password_hash'] && password_verify((string)Request::post('password', ''), (string)$s['password_hash'])) {
                Session::set('clone_owner', true);
            } else {
                Session::flash('error', __('That isn\'t the administrator account created for this install.'));
            }
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if (!Session::get('clone_owner')) {
            Request::wantsJson() ? View::json(['error' => 'Not allowed'], 403) : View::redirect(App::baseUrl() . '/index.php/setup');
        }
        if ($action === 'abandon') {
            Installer::abandonClone();
            Session::forget('clone_owner');
            Session::flash('success', __('Setup was cancelled and the tables it created were removed. osTicket wasn\'t changed. You can start again.'));
            View::redirect(App::baseUrl() . '/index.php/setup');
        }
        session_write_close();
        $state = OsTicketImporter::step(8.0, $action === 'resume');
        if (($state['status'] ?? '') === 'done') {
            \Bakkbone\HelpCentre\Core\Settings::set('clone_summary', json_encode(['kind' => 'osticket', 'status' => 'done']));
            Installer::finishClone();
        }
        if (Request::wantsJson()) {
            View::json(\Bakkbone\HelpCentre\Controllers\Admin\ImportController::summary($state));
        }
        View::redirect(App::baseUrl() . '/index.php/setup');
    }

    private function cloneDone(): never
    {
        $raw = (string)\Bakkbone\HelpCentre\Core\Settings::get('clone_summary', '');
        if ($raw === '' || !Session::get('clone_owner')) {
            $this->notFound();
        }
        $summary = json_decode($raw, true) ?: [];
        \Bakkbone\HelpCentre\Core\Settings::set('clone_summary', '');
        Session::forget('clone_owner');
        if (($summary['kind'] ?? '') === 'osticket') {
            $this->view('setup/import', ['title' => __('Imported'), 'state' => OsTicketImporter::state() ?? ['status' => 'done', 'steps' => []], 'percent' => 100, 'owner' => true]);
            exit;
        }
        $this->view('setup/clone', ['title' => __('Copied'), 'state' => $summary, 'percent' => 100, 'owner' => true]);
        exit;
    }

    // Before install anyone can submit this form; a database file in the web root with PHP in a field would run as code.
    private static function sqlitePathAllowed(string $path): bool
    {
        if (!preg_match('/\.(sqlite|sqlite3|db)$/i', $path) || str_contains($path, "\0")) {
            return false;
        }
        $storage = realpath(App::storage());
        $dir = realpath(dirname($path));
        return $storage !== false && $dir !== false
            && ($dir === $storage || str_starts_with($dir . DIRECTORY_SEPARATOR, $storage . DIRECTORY_SEPARATOR));
    }

    /** @return array{0:int,1:string}|null */
    private function probe(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status === 0) {
            return null;
        }
        return [$status === 206 ? 200 : $status, substr((string)$body, 0, 4096)];
    }
}
