<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Api;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Migration;

/** Request and response bodies are encrypted with a key derived from the migration key. */
final class MigrateController extends Controller
{
    public function handle(): void
    {
        $db = App::db();
        $ip = Request::ip();
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        if ((int)$db->val("SELECT COUNT(*) FROM login_attempts WHERE scope = 'migrate' AND ip = ? AND success = 0 AND created_at > ?", [$ip, $since]) >= 10) {
            $this->fail(429, 'Too many attempts with a wrong migration key. Try again in an hour.');
        }
        $key = trim((string)Request::header('X-Migration-Key'));
        if ($key === '' || !Migration::keyValid($key)) {
            $db->insert('login_attempts', ['scope' => 'migrate', 'identifier' => 'migrate', 'ip' => $ip, 'success' => 0, 'created_at' => DB::now()]);
            Log::warning('Migration request rejected', "Wrong or expired migration key from $ip");
            $this->fail(403, 'The migration key is wrong or has expired. Create a new one under Admin → System on this help desk.');
        }
        try {
            $req = Migration::open($key, Request::body());
        } catch (\Throwable $e) {
            $this->fail(400, $e->getMessage());
        }
        @set_time_limit(120);
        $op = (string)($req['op'] ?? '');
        $data = match ($op) {
            'manifest' => Migration::manifest(),
            'rows' => Migration::rows((string)($req['table'] ?? ''), (int)($req['cursor'] ?? 0), max(1, min(1000, (int)($req['limit'] ?? 300))), isset($req['since']) ? (string)$req['since'] : null),
            'row' => ['row' => Migration::row((string)($req['table'] ?? ''), (int)($req['id'] ?? 0))],
            'file' => Migration::fileChunk((int)($req['id'] ?? 0), max(0, (int)($req['offset'] ?? 0))),
            'attachment_ids' => ['ids' => array_map('intval', $db->col('SELECT id FROM attachments WHERE id > ? ORDER BY id LIMIT 500', [(int)($req['after'] ?? 0)]))],
            'finished' => ['ok' => true],
            default => null,
        };
        if ($data === null) {
            $this->fail(400, 'Unknown operation.');
        }
        if ($op === 'manifest') {
            Audit::log('migration.started', 'system', null, ['ip' => $ip]);
        } elseif ($op === 'finished') {
            Audit::log('migration.finished', 'system', null, ['ip' => $ip]);
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo Migration::seal($key, $data);
        exit;
    }

    private function fail(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }
}
