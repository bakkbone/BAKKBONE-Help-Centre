<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Mail\Fetcher;
use Bakkbone\HelpCentre\Mail\Mailer;

final class Cron
{
    /** @return array<string,mixed> */
    public static function run(): array
    {
        $lockFile = App::storage('cache/cron.lock');
        $fh = @fopen($lockFile, 'c');
        if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'Another cron run is in progress'];
        }
        $out = [];
        $jobs = [
            'mail_fetch' => fn() => Fetcher::run(),
            'mail_queue' => fn() => Mailer::processQueue(),
            'webhooks' => fn() => Webhooks::retryDue(),
            'overdue' => fn() => Tickets::markOverdue(),
            'auto_close' => fn() => Tickets::autoClose(),
            'cleanup' => function () {
                Otp::cleanup();
                $db = App::db();
                $db->delete('ticket_locks', 'expires_at < ?', [DB::now()]);
                $db->delete('system_log', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
                $db->delete('mail_seen', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 180 * 86400)]);
                return 'ok';
            },
        ];
        foreach (\Bakkbone\HelpCentre\Core\Addons::$cron as $name => $job) {
            $jobs['addon:' . $name] = $job['cb'];
        }
        if (\Bakkbone\HelpCentre\Core\Hooks::has('cron')) {
            $jobs['addon:hooks'] = function () {
                \Bakkbone\HelpCentre\Core\Hooks::fire('cron');
                return 'ok';
            };
        }
        foreach ($jobs as $name => $job) {
            try {
                $out[$name] = $job();
            } catch (\Throwable $e) {
                $out[$name] = 'ERROR: ' . $e->getMessage();
                Log::error("Cron job $name failed", $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
            }
        }
        Settings::set('cron_last_run', DB::now());
        if ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return $out;
    }
}
