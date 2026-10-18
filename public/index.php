<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\View;

// PHP's built-in server: serve real files directly.
if (PHP_SAPI === 'cli-server') {
    $file = (defined('HC_VIA_ROOT') ? dirname(__DIR__) : __DIR__) . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

View::securityHeaders();
$path = Request::path();

// Tiny probe used by the installer to detect URL rewriting.
if ($path === '/__hc-probe') {
    header('Content-Type: text/plain');
    exit('hc-ok');
}
$isApi = str_starts_with($path, '/api/');

// custom/ and add-on assets live outside public/, so they're served here.
if (App::$installed && preg_match('#^/(?:custom/(?<cf>.+)|addons/(?<slug>[a-z0-9-]+)/assets/(?<af>.+))$#', $path, $m)) {
    $file = !empty($m['cf'])
        ? (str_starts_with($m['cf'], 'templates/') ? null : \Bakkbone\HelpCentre\Core\Addons::safeFile(HC_ROOT . '/custom', rawurldecode($m['cf'])))
        : \Bakkbone\HelpCentre\Core\Addons::assetFile($m['slug'], rawurldecode($m['af']));
    if ($file !== null) {
        \Bakkbone\HelpCentre\Core\Addons::sendFile($file);
    }
    Router::notFound();
}

if (!$isApi) {
    Session::start();
    Session::ageOldInput();
}

$r = new Router();

if (!App::$installed) {
    $r->any('/setup', 'Setup\InstallController@index', ['nocsrf' => false]);
    $r->post('/setup/clone', 'Setup\InstallController@cloneAction');
    $r->post('/setup/import', 'Setup\InstallController@importAction');
    if (!in_array($path, ['/setup', '/setup/clone', '/setup/import'], true)) {
        View::redirect('/setup');
    }
    $r->dispatch(Request::method(), $path);
    exit;
}

$r->post('/api/tickets.json', 'Api\ApiController@createTicket', ['nocsrf' => true]);
$r->post('/api/tickets', 'Api\ApiController@createTicket', ['nocsrf' => true]);
$r->post('/api/tickets.email', 'Api\ApiController@pipe', ['nocsrf' => true]);
// osTicket's API addresses, so existing website forms only need the domain changed
$r->post('/api/http.php/tickets.json', 'Api\ApiController@createTicket', ['nocsrf' => true]);
$r->post('/api/http.php/tickets.email', 'Api\ApiController@pipe', ['nocsrf' => true]);
$r->any('/api/cron', 'Api\ApiController@cron', ['nocsrf' => true]);
$r->post('/api/migrate', 'Api\MigrateController@handle', ['nocsrf' => true]);
$r->get('/setup', 'Setup\InstallController@index');

$r->get('/file/{id:\d+}', 'Client\FileController@show');

$r->get('/', 'Client\HomeController@index');
$r->get('/kb', 'Client\KbController@index');
$r->get('/kb/category/{id:\d+}', 'Client\KbController@category');
$r->get('/kb/{id:\d+}', 'Client\KbController@article');
$r->get('/open', 'Client\TicketController@create');
$r->post('/open', 'Client\TicketController@store');
$r->get('/submitted', 'Client\TicketController@submitted');
$r->get('/tickets', 'Client\TicketController@index');
$r->get('/tickets/{number}', 'Client\TicketController@show');
$r->post('/tickets/{number}/reply', 'Client\TicketController@reply');
$r->post('/tickets/{number}/close', 'Client\TicketController@close');
$r->get('/view/{number}/{token}', 'Client\TicketController@guestLink');
$r->get('/login', 'Client\AuthController@login');
$r->post('/login', 'Client\AuthController@loginPassword');
$r->post('/login/code', 'Client\AuthController@sendCode');
$r->get('/login/verify', 'Client\AuthController@verifyForm');
$r->post('/login/verify', 'Client\AuthController@verify');
$r->get('/logout', 'Client\AuthController@logout');
$r->get('/register', 'Client\AuthController@register');
$r->post('/register', 'Client\AuthController@registerSubmit');
$r->get('/register/verify', 'Client\AuthController@registerVerifyForm');
$r->post('/register/verify', 'Client\AuthController@registerVerify');
$r->get('/reset', 'Client\AuthController@reset');
$r->post('/reset', 'Client\AuthController@resetSubmit');
$r->get('/reset/verify', 'Client\AuthController@resetVerifyForm');
$r->post('/reset/verify', 'Client\AuthController@resetVerify');
$r->get('/status', 'Client\AuthController@status');
$r->post('/status', 'Client\AuthController@statusSubmit');
$r->get('/profile', 'Client\ProfileController@edit');
$r->post('/profile', 'Client\ProfileController@update');

$r->get('/panel/login', 'Staff\AuthController@login');
$r->post('/panel/login', 'Staff\AuthController@loginPassword');
$r->post('/panel/login/code', 'Staff\AuthController@sendCode');
$r->get('/panel/login/verify', 'Staff\AuthController@verifyForm');
$r->post('/panel/login/verify', 'Staff\AuthController@verify');
$r->get('/panel/login/2fa', 'Staff\AuthController@twoFactorForm');
$r->post('/panel/login/2fa', 'Staff\AuthController@twoFactor');
$r->get('/panel/logout', 'Staff\AuthController@logout');
$r->get('/panel/reset', 'Staff\AuthController@reset');
$r->post('/panel/reset', 'Staff\AuthController@resetSubmit');
$r->get('/panel/reset/verify', 'Staff\AuthController@resetVerifyForm');
$r->post('/panel/reset/verify', 'Staff\AuthController@resetVerify');

$r->get('/panel', 'Staff\DashboardController@index');

$r->get('/panel/tickets', 'Staff\TicketController@index');
$r->get('/panel/tickets/export', 'Staff\TicketController@export');
$r->post('/panel/tickets/bulk', 'Staff\TicketController@bulk');
$r->get('/panel/tickets/new', 'Staff\TicketController@create');
$r->post('/panel/tickets/new', 'Staff\TicketController@store');
$r->get('/panel/tickets/{id:\d+}', 'Staff\TicketController@show');
$r->get('/panel/tickets/{id:\d+}/print', 'Staff\TicketController@printView');
$r->post('/panel/tickets/{id:\d+}/post', 'Staff\TicketController@post');
$r->post('/panel/tickets/{id:\d+}/assign', 'Staff\TicketController@assign');
$r->post('/panel/tickets/{id:\d+}/transfer', 'Staff\TicketController@transfer');
$r->post('/panel/tickets/{id:\d+}/status', 'Staff\TicketController@status');
$r->get('/panel/tickets/{id:\d+}/edit', 'Staff\TicketController@edit');
$r->post('/panel/tickets/{id:\d+}/edit', 'Staff\TicketController@update');
$r->post('/panel/tickets/{id:\d+}/collaborators', 'Staff\TicketController@collaborators');
$r->post('/panel/tickets/{id:\d+}/merge', 'Staff\TicketController@merge');
$r->post('/panel/tickets/{id:\d+}/delete', 'Staff\TicketController@delete');
$r->post('/panel/tickets/{id:\d+}/lock', 'Staff\TicketController@lock');
$r->post('/panel/tickets/{id:\d+}/ban', 'Staff\TicketController@ban');
$r->post('/panel/searches', 'Staff\TicketController@saveSearch');
$r->post('/panel/searches/{id:\d+}/delete', 'Staff\TicketController@deleteSearch');

$r->get('/panel/users', 'Staff\UserController@index');
$r->get('/panel/users/lookup', 'Staff\UserController@lookup');
$r->get('/panel/users/cleanup', 'Staff\UserController@cleanup');
$r->post('/panel/users/cleanup', 'Staff\UserController@cleanupRun');
$r->get('/panel/users/new', 'Staff\UserController@create');
$r->post('/panel/users/new', 'Staff\UserController@store');
$r->get('/panel/users/{id:\d+}', 'Staff\UserController@show');
$r->post('/panel/users/{id:\d+}', 'Staff\UserController@update');
$r->post('/panel/users/{id:\d+}/delete', 'Staff\UserController@delete');
$r->post('/panel/users/{id:\d+}/send-code', 'Staff\UserController@sendCode');

$r->get('/panel/orgs', 'Staff\OrgController@index');
$r->get('/panel/orgs/new', 'Staff\OrgController@create');
$r->post('/panel/orgs/new', 'Staff\OrgController@store');
$r->get('/panel/orgs/{id:\d+}', 'Staff\OrgController@show');
$r->post('/panel/orgs/{id:\d+}', 'Staff\OrgController@update');
$r->post('/panel/orgs/{id:\d+}/delete', 'Staff\OrgController@delete');

$r->get('/panel/tasks', 'Staff\TaskController@index');
$r->get('/panel/tasks/new', 'Staff\TaskController@create');
$r->post('/panel/tasks/new', 'Staff\TaskController@store');
$r->get('/panel/tasks/{id:\d+}', 'Staff\TaskController@show');
$r->post('/panel/tasks/{id:\d+}', 'Staff\TaskController@update');
$r->post('/panel/tasks/{id:\d+}/delete', 'Staff\TaskController@delete');

$r->get('/panel/kb', 'Staff\KbController@index');
$r->get('/panel/kb/categories/new', 'Staff\KbController@categoryForm');
$r->post('/panel/kb/categories/new', 'Staff\KbController@categorySave');
$r->get('/panel/kb/categories/{id:\d+}', 'Staff\KbController@categoryForm');
$r->post('/panel/kb/categories/{id:\d+}', 'Staff\KbController@categorySave');
$r->post('/panel/kb/categories/{id:\d+}/delete', 'Staff\KbController@categoryDelete');
$r->get('/panel/kb/articles/new', 'Staff\KbController@articleForm');
$r->post('/panel/kb/articles/new', 'Staff\KbController@articleSave');
$r->get('/panel/kb/articles/{id:\d+}', 'Staff\KbController@articleForm');
$r->post('/panel/kb/articles/{id:\d+}', 'Staff\KbController@articleSave');
$r->post('/panel/kb/articles/{id:\d+}/delete', 'Staff\KbController@articleDelete');
$r->get('/panel/kb/search', 'Staff\KbController@search');

$r->get('/panel/canned', 'Staff\CannedController@index');
$r->get('/panel/canned/new', 'Staff\CannedController@form');
$r->post('/panel/canned/new', 'Staff\CannedController@save');
$r->get('/panel/canned/{id:\d+}', 'Staff\CannedController@form');
$r->post('/panel/canned/{id:\d+}', 'Staff\CannedController@save');
$r->post('/panel/canned/{id:\d+}/delete', 'Staff\CannedController@delete');
$r->get('/panel/canned/{id:\d+}/render', 'Staff\CannedController@render');

$r->get('/panel/profile', 'Staff\ProfileController@edit');
$r->post('/panel/profile', 'Staff\ProfileController@update');
$r->post('/panel/profile/password', 'Staff\ProfileController@password');
$r->post('/panel/profile/2fa/start', 'Staff\ProfileController@twoFactorStart');
$r->post('/panel/profile/2fa/enable', 'Staff\ProfileController@twoFactorEnable');
$r->post('/panel/profile/2fa/disable', 'Staff\ProfileController@twoFactorDisable');

$r->get('/panel/admin', 'Admin\SettingsController@home');
$r->get('/panel/admin/settings/{tab}', 'Admin\SettingsController@edit');
$r->post('/panel/admin/settings/{tab}', 'Admin\SettingsController@save');
$r->get('/panel/admin/system', 'Admin\SystemController@index');
$r->post('/panel/admin/system/upgrade', 'Admin\SystemController@upgrade');
$r->post('/panel/admin/system/cron', 'Admin\SystemController@runCron');
$r->post('/panel/admin/system/migration-key', 'Admin\SystemController@migrationKey');
$r->post('/panel/admin/system/migration-key/revoke', 'Admin\SystemController@revokeMigrationKey');
$r->post('/panel/admin/system/export', 'Admin\SystemController@export');
$r->get('/panel/admin/logs', 'Admin\SystemController@logs');
$r->get('/panel/admin/audit', 'Admin\SystemController@audit');
$r->get('/panel/admin/import', 'Admin\ImportController@index');
$r->post('/panel/admin/import/check', 'Admin\ImportController@check');
$r->post('/panel/admin/import/start', 'Admin\ImportController@start');
$r->post('/panel/admin/import/step', 'Admin\ImportController@step');
$r->post('/panel/admin/import/reset', 'Admin\ImportController@reset');
$r->get('/panel/admin/import/bhc', 'Admin\ImportController@bhc');
$r->post('/panel/admin/import/bhc/start', 'Admin\ImportController@bhcStart');
$r->post('/panel/admin/import/bhc/step', 'Admin\ImportController@bhcStep');
$r->post('/panel/admin/import/bhc/reset', 'Admin\ImportController@bhcReset');
$r->get('/panel/admin/mailqueue', 'Admin\SystemController@mailQueue');
$r->post('/panel/admin/mailqueue/{id:\d+}/retry', 'Admin\SystemController@retryMail');

$r->get('/panel/admin/emails', 'Admin\EmailController@index');
$r->get('/panel/admin/emails/new', 'Admin\EmailController@form');
$r->post('/panel/admin/emails/new', 'Admin\EmailController@save');
$r->get('/panel/admin/emails/{id:\d+}', 'Admin\EmailController@form');
$r->post('/panel/admin/emails/{id:\d+}', 'Admin\EmailController@save');
$r->post('/panel/admin/emails/{id:\d+}/delete', 'Admin\EmailController@delete');
$r->post('/panel/admin/emails/{id:\d+}/test-inbound', 'Admin\EmailController@testInbound');
$r->post('/panel/admin/emails/{id:\d+}/test-outbound', 'Admin\EmailController@testOutbound');
$r->post('/panel/admin/emails/{id:\d+}/fetch', 'Admin\EmailController@fetchNow');

$r->get('/panel/admin/templates', 'Admin\TemplateController@index');
$r->get('/panel/admin/templates/{code}', 'Admin\TemplateController@form');
$r->post('/panel/admin/templates/{code}', 'Admin\TemplateController@save');
$r->post('/panel/admin/templates/{code}/reset', 'Admin\TemplateController@reset');

$r->get('/panel/admin/filters', 'Admin\FilterController@index');
$r->get('/panel/admin/filters/new', 'Admin\FilterController@form');
$r->post('/panel/admin/filters/new', 'Admin\FilterController@save');
$r->get('/panel/admin/filters/{id:\d+}', 'Admin\FilterController@form');
$r->post('/panel/admin/filters/{id:\d+}', 'Admin\FilterController@save');
$r->post('/panel/admin/filters/{id:\d+}/delete', 'Admin\FilterController@delete');

$r->get('/panel/admin/webhooks', 'Admin\WebhookController@index');
$r->get('/panel/admin/webhooks/new', 'Admin\WebhookController@form');
$r->post('/panel/admin/webhooks/new', 'Admin\WebhookController@save');
$r->get('/panel/admin/webhooks/{id:\d+}', 'Admin\WebhookController@form');
$r->post('/panel/admin/webhooks/{id:\d+}', 'Admin\WebhookController@save');
$r->post('/panel/admin/webhooks/{id:\d+}/delete', 'Admin\WebhookController@delete');
$r->post('/panel/admin/webhooks/{id:\d+}/test', 'Admin\WebhookController@test');
$r->get('/panel/admin/webhooks/{id:\d+}/deliveries', 'Admin\WebhookController@deliveries');
$r->post('/panel/admin/webhooks/deliveries/{id:\d+}/retry', 'Admin\WebhookController@retry');

$r->get('/panel/admin/apikeys', 'Admin\ApiKeyController@index');
$r->post('/panel/admin/apikeys', 'Admin\ApiKeyController@store');
$r->post('/panel/admin/apikeys/{id:\d+}/toggle', 'Admin\ApiKeyController@toggle');
$r->post('/panel/admin/apikeys/{id:\d+}/delete', 'Admin\ApiKeyController@delete');

$r->get('/panel/admin/staff', 'Admin\StaffController@index');
$r->get('/panel/admin/staff/new', 'Admin\StaffController@form');
$r->post('/panel/admin/staff/new', 'Admin\StaffController@save');
$r->get('/panel/admin/staff/{id:\d+}', 'Admin\StaffController@form');
$r->post('/panel/admin/staff/{id:\d+}', 'Admin\StaffController@save');
$r->post('/panel/admin/staff/{id:\d+}/delete', 'Admin\StaffController@delete');
$r->post('/panel/admin/staff/{id:\d+}/reset-2fa', 'Admin\StaffController@reset2fa');

// Before the generic admin routes, which would otherwise match /panel/admin/addons.
$r->get('/panel/admin/addons', 'Admin\AddonController@index');
$r->post('/panel/admin/addons/upload', 'Admin\AddonController@upload');
$r->post('/panel/admin/addons/{slug:[a-z0-9-]+}/enable', 'Admin\AddonController@enable');
$r->post('/panel/admin/addons/{slug:[a-z0-9-]+}/disable', 'Admin\AddonController@disable');
$r->get('/panel/admin/addons/{slug:[a-z0-9-]+}', 'Admin\AddonController@settings');
$r->post('/panel/admin/addons/{slug:[a-z0-9-]+}', 'Admin\AddonController@saveSettings');
\Bakkbone\HelpCentre\Core\Addons::registerRoutes($r);

$r->get('/panel/admin/{entity:[a-z]+}', 'Admin\CrudController@index');
$r->get('/panel/admin/{entity:[a-z]+}/new', 'Admin\CrudController@form');
$r->post('/panel/admin/{entity:[a-z]+}/new', 'Admin\CrudController@save');
$r->get('/panel/admin/{entity:[a-z]+}/{id:\d+}', 'Admin\CrudController@form');
$r->post('/panel/admin/{entity:[a-z]+}/{id:\d+}', 'Admin\CrudController@save');
$r->post('/panel/admin/{entity:[a-z]+}/{id:\d+}/delete', 'Admin\CrudController@delete');

$r->dispatch(Request::method(), $path);
