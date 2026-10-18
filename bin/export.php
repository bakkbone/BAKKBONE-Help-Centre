#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'USAGE'
Export this help desk to an encrypted file, for a new server that can't reach this one.

  php bin/export.php --out=/path/to/helpcentre.bhcx

Prints a migration key (valid 24 hours). On the new server, put the file in storage/import and choose
"A copy of another BAKKBONE Help Centre" during setup, or run:
  php bin/install.php --url=https://new.example.com --file=/path/to/helpcentre.bhcx --key=bkhc_...
USAGE . "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Services\Migration;

if (!App::$installed) {
    fwrite(STDERR, "This help desk isn't installed yet.\n");
    exit(1);
}
$o = getopt('', ['out:']);
$out = (string)($o['out'] ?? ('helpcentre-' . gmdate('Ymd-His') . '.' . Migration::FILE_EXT));
$fh = @fopen($out, 'wb');
if (!$fh) {
    fwrite(STDERR, "Can't write $out\n");
    exit(1);
}
$key = Migration::createKey();
$last = '';
Migration::writeExport($fh, $key, function (string $what) use (&$last) {
    if ($what !== $last) {
        echo "  $what\n";
        $last = $what;
    }
});
fclose($fh);
echo "\nExport written to $out (" . number_format((int)filesize($out)) . " bytes).\n\nMigration key (valid for 24 hours, keep it private):\n\n  $key\n\n";
