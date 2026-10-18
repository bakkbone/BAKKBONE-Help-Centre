#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'USAGE'
Import an osTicket help desk (same as Admin → Import, without browser time limits).

  php bin/import-osticket.php --ost-path=/var/www/osticket
  php bin/import-osticket.php --db-host=localhost --db-name=osticket --db-user=ost --db-pass=secret [--db-prefix=ost_]

Options:
  --files=/path/to/osticket/attachments   only for osTicket's filesystem attachment plugin
  --templates                             also replace the email templates with osTicket's (variables are translated)
  --resume                                continue an import that was started in the browser or stopped
USAGE . "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Services\OsTicketImporter;

if (!App::$installed) {
    fwrite(STDERR, "Install BAKKBONE Help Centre first.\n");
    exit(1);
}
$o = getopt('', ['ost-path:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'db-prefix:', 'files:', 'resume', 'templates']);
$adminId = (int)App::db()->val('SELECT id FROM staff WHERE is_admin = 1 ORDER BY id LIMIT 1');

try {
    $state = OsTicketImporter::state();
    if (!isset($o['resume']) || !$state) {
        if (!empty($o['ost-path'])) {
            $conn = OsTicketImporter::readConfig((string)$o['ost-path']);
        } elseif (!empty($o['db-name'])) {
            $conn = ['host' => $o['db-host'] ?? 'localhost', 'port' => (int)($o['db-port'] ?? 3306), 'name' => $o['db-name'],
                'user' => $o['db-user'] ?? '', 'pass' => $o['db-pass'] ?? '', 'prefix' => $o['db-prefix'] ?? 'ost_'];
        } else {
            fwrite(STDERR, "Give --ost-path or the --db-* options. Run with --help for usage.\n");
            exit(1);
        }
        $state = OsTicketImporter::start($conn, $o['files'] ?? null, $adminId, ['templates' => isset($o['templates'])]);
        foreach ($state['warnings'] as $w) {
            echo "Warning: $w\n";
        }
    }
    $last = '';
    do {
        $state = OsTicketImporter::step(30.0, true);
        $cur = $state['current'] ?? null;
        $line = sprintf("%3d%%  %s", OsTicketImporter::percent($state), $cur ? $state['steps'][$cur]['label'] . ' ' . $state['steps'][$cur]['done'] . '/' . $state['steps'][$cur]['total'] : '');
        if ($line !== $last) {
            echo $line, "\n";
            $last = $line;
        }
    } while (($state['status'] ?? '') === 'running');
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($state['status'] === 'failed') {
    fwrite(STDERR, 'Import stopped: ' . $state['error'] . "\nFix the problem, then run again with --resume.\n");
    exit(1);
}
echo "Done. " . (int)($state['skipped_count'] ?? 0) . " items skipped (see Admin -> Import from osTicket for details).\n";
