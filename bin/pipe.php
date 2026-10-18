#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<'USAGE'
Email pipe: your mail server delivers each incoming message to this script on STDIN,
e.g. cPanel "Forwarders" → "Pipe to a Program": /path/to/BAKKBONE-Help-Centre/bin/pipe.php
USAGE . "\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Mail\InboundProcessor;

if (!App::$installed) {
    fwrite(STDERR, "Help desk is not installed\n");
    exit(75);
}

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "No message on STDIN\n");
    exit(65);
}
if (strlen($raw) > 50 * 1024 * 1024) {
    fwrite(STDERR, "Message too large\n");
    exit(65);
}

try {
    $result = InboundProcessor::process($raw, null, 'pipe');
    if (in_array('-v', $argv, true)) {
        fwrite(STDOUT, json_encode($result) . "\n");
    }
    exit(0);
} catch (\PDOException $e) {
    Log::error('Pipe: database error (message will be retried by the MTA)', $e->getMessage());
    fwrite(STDERR, "Temporary failure\n");
    exit(75);
} catch (\Throwable $e) {
    try {
        Log::error('Pipe: failed to process message', $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    } catch (\Throwable) {
    }
    fwrite(STDERR, "Temporary failure\n");
    exit(75);
}
