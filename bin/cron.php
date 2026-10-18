#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only. For web cron use /api/cron?key=...\n");
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'USAGE'
Run scheduled tasks (mail fetching, SLA checks, retries). Schedule it every 1-5 minutes:

  */2 * * * * php /path/to/BAKKBONE-Help-Centre/bin/cron.php >/dev/null 2>&1
  Windows Task Scheduler: php.exe "D:\path\to\BAKKBONE-Help-Centre\bin\cron.php"

Options: -v, --verbose   print what each job did
USAGE . "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Services\Cron;

if (!App::$installed) {
    fwrite(STDERR, "Not installed yet.\n");
    exit(1);
}
@set_time_limit(300);
$result = Cron::run();
if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
exit(0);
