#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Services\Installer;

if (!App::$installed) {
    fwrite(STDERR, "Not installed.\n");
    exit(1);
}
$log = Installer::upgrade();
echo $log ? implode("\n", $log) . "\n" : "Database is up to date.\n";
