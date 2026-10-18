#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'USAGE'
Command-line installer (alternative to the web installer at /setup).

Fresh install, MySQL:
  php bin/install.php --url=https://help.example.com --db-host=localhost --db-name=helpcentre \
      --db-user=helpcentre --db-pass=secret --admin-email=you@example.com --admin-user=admin \
      --admin-pass='a long password' --admin-first=Sam --admin-last=Smith
Fresh install, SQLite:
  php bin/install.php --driver=sqlite --url=http://localhost:8080 --admin-email=... --admin-pass=...

Copy of another BAKKBONE Help Centre (instead of the --admin-* options):
  php bin/install.php --url=https://help.example.com --db-name=... --from=https://staging.example.com --key=bkhc_...
  php bin/install.php --url=https://help.example.com --db-name=... --file=/path/helpcentre.bhcx --key=bkhc_...
  Add --keep-active to leave mailboxes and webhooks switched on in the copy.

Moving from osTicket (with the --admin-* options; osTicket's departments, topics etc. replace the starter ones):
  php bin/install.php --url=... --db-name=... --admin-email=... --admin-pass=... --osticket-path=/var/www/osticket [--osticket-templates]
  or --osticket-db-host= --osticket-db-name= --osticket-db-user= --osticket-db-pass= [--osticket-db-prefix=ost_]

Add --lang=fr (a language pack in lang/ or custom/lang/) to install in another language.

If a copy or import is interrupted, run php bin/install.php again to continue it.
USAGE . "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\CloneImporter;
use Bakkbone\HelpCentre\Services\Installer;

$runClone = function (): never {
    $last = '';
    do {
        $state = CloneImporter::step(30.0, true);
        $cur = $state['current'] ?? null;
        $line = sprintf('%3d%%  %s', CloneImporter::percent($state), $cur ? ($state['steps'][$cur]['label'] ?? $cur) : '');
        if ($line !== $last) {
            echo $line, "\n";
            $last = $line;
        }
    } while (($state['status'] ?? '') === 'running');
    if (($state['status'] ?? '') !== 'done') {
        fwrite(STDERR, 'Copy stopped: ' . ($state['error'] ?? 'unknown error') . "\nFix the problem, then run php bin/install.php again to continue.\n");
        exit(1);
    }
    foreach ($state['warnings'] ?? [] as $w) {
        echo "Note: $w\n";
    }
    echo "Copied. Sign in at " . App::url('/panel') . " with an agent account from the other help desk.\n";
    exit(0);
};
if (!App::$installed && !empty(App::$config['cloning'])) {
    echo "Continuing the copy that was already started...\n";
    $runClone();
}

if (App::$installed) {
    fwrite(STDERR, "Already installed (config/config.php exists).\n");
    exit(1);
}

$o = getopt('', [
    'driver::', 'url:', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'sqlite-path::',
    'admin-email:', 'admin-user::', 'admin-pass:', 'admin-first::', 'admin-last::',
    'name::', 'company::', 'timezone::', 'lang::', 'no-pretty-urls', 'from::', 'file::', 'key::', 'keep-active',
    'osticket-path::', 'osticket-db-host::', 'osticket-db-name::', 'osticket-db-user::', 'osticket-db-pass::', 'osticket-db-prefix::', 'osticket-templates',
]);
if (!empty($o['lang'])) {
    if (!isset(\Bakkbone\HelpCentre\Core\I18n::available()[$o['lang']])) {
        fwrite(STDERR, "There's no language pack for \"{$o['lang']}\" in lang/ or custom/lang/.\n");
        exit(1);
    }
    \Bakkbone\HelpCentre\Core\I18n::use((string)$o['lang']);
}
$cloning = !empty($o['from']) || !empty($o['file']);
$need = $cloning ? ['url', 'key'] : ['url', 'admin-email', 'admin-pass'];
foreach ($need as $n) {
    if (empty($o[$n])) {
        fwrite(STDERR, "Missing --$n. Run with --help for usage.\n");
        exit(1);
    }
}
if (!$cloning && $err = Auth::passwordError((string)$o['admin-pass'], (string)$o['admin-pass'])) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}
$driver = ($o['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
$db = $driver === 'sqlite'
    ? ['driver' => 'sqlite', 'path' => $o['sqlite-path'] ?? App::storage('helpcentre.sqlite')]
    : ['driver' => 'mysql', 'host' => $o['db-host'] ?? 'localhost', 'port' => (int)($o['db-port'] ?? 3306), 'name' => $o['db-name'] ?? 'helpcentre', 'user' => $o['db-user'] ?? '', 'pass' => $o['db-pass'] ?? ''];

if ($cloning) {
    $source = !empty($o['file'])
        ? ['mode' => 'file', 'path' => (string)$o['file'], 'key' => (string)$o['key']]
        : ['mode' => 'url', 'url' => (string)$o['from'], 'key' => (string)$o['key']];
    try {
        $probe = CloneImporter::probe($source);
        echo "Copying \"" . ($probe['manifest']['helpdesk'] ?? '') . "\" from " . ($probe['manifest']['base_url'] ?? '') . "\n";
        Installer::prepareClone($db, ['base_url' => (string)$o['url'], 'pretty_urls' => !isset($o['no-pretty-urls'])]);
        CloneImporter::begin($source, ['pause' => !isset($o['keep-active'])]);
    } catch (\Throwable $e) {
        if (!empty(App::$config['cloning'])) {
            @unlink(App::configPath());
        }
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $runClone();
}

if (!empty($o['osticket-path']) || !empty($o['osticket-db-name'])) {
    try {
        $conn = !empty($o['osticket-path'])
            ? \Bakkbone\HelpCentre\Services\OsTicketImporter::readConfig((string)$o['osticket-path'])
            : ['host' => $o['osticket-db-host'] ?? 'localhost', 'name' => $o['osticket-db-name'], 'user' => $o['osticket-db-user'] ?? '',
                'pass' => $o['osticket-db-pass'] ?? '', 'prefix' => $o['osticket-db-prefix'] ?? 'ost_'];
        \Bakkbone\HelpCentre\Services\OsTicketImporter::inspect($conn);
        $adminId = Installer::prepareOsTicketInstall($db, [
            'first_name' => $o['admin-first'] ?? 'Admin', 'last_name' => $o['admin-last'] ?? '', 'email' => strtolower((string)$o['admin-email']),
            'username' => strtolower((string)($o['admin-user'] ?? 'admin')), 'password' => (string)$o['admin-pass'],
        ], ['base_url' => (string)$o['url'], 'pretty_urls' => !isset($o['no-pretty-urls'])]);
        \Bakkbone\HelpCentre\Services\OsTicketImporter::start($conn, null, $adminId, ['templates' => isset($o['osticket-templates']), 'install' => true]);
        $last = '';
        do {
            $state = \Bakkbone\HelpCentre\Services\OsTicketImporter::step(30.0, true);
            $line = sprintf('%3d%%  %s', \Bakkbone\HelpCentre\Services\OsTicketImporter::percent($state), $state['current'] ? $state['steps'][$state['current']]['label'] : '');
            if ($line !== $last) {
                echo $line, "\n";
                $last = $line;
            }
        } while ($state['status'] === 'running');
        if ($state['status'] !== 'done') {
            throw new \RuntimeException('Import stopped: ' . $state['error']);
        }
        Installer::finishClone();
        foreach ($state['warnings'] as $w) {
            echo "Note: $w\n";
        }
        echo "Installed from osTicket. Sign in at " . App::url('/panel') . "\n";
        exit(0);
    } catch (\Throwable $e) {
        if (!empty(App::$config['importing'])) {
            Installer::abandonClone();
        }
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$result = Installer::install($db, [
    'first_name' => $o['admin-first'] ?? 'Admin',
    'last_name' => $o['admin-last'] ?? '',
    'email' => strtolower((string)$o['admin-email']),
    'username' => strtolower((string)($o['admin-user'] ?? 'admin')),
    'password' => (string)$o['admin-pass'],
], [
    'base_url' => (string)$o['url'],
    'helpdesk_name' => $o['name'] ?? 'BAKKBONE Help Centre',
    'company_name' => $o['company'] ?? 'BAKKBONE Australia',
    'timezone' => $o['timezone'] ?? 'Australia/Sydney',
    'admin_email' => strtolower((string)$o['admin-email']),
    'pretty_urls' => !isset($o['no-pretty-urls']),
    'locale' => \Bakkbone\HelpCentre\Core\I18n::locale(),
]);

if (!$result['written']) {
    echo "Could not write config/config.php - create it with this content:\n\n" . $result['config'] . "\n";
    exit(2);
}
echo "Installed. Sign in at " . rtrim((string)$o['url'], '/') . "/panel\n";
