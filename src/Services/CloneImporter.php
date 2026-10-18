<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Http;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Core\Settings;

/** Time-boxed, resumable steps so it fits within shared-hosting time limits. */
final class CloneImporter
{
    private const STATE_KEY = 'clone_state';

    /** @var array<string,mixed> */
    private array $state;
    private DB $db;
    private string $key;
    private float $deadline;
    /** @var array<string,list<string>> */
    private array $columns = [];

    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $url = (string)preg_replace('~/(index\.php/?)?(panel|setup|api)(/.*)?$~i', '', rtrim($url, '/'));
        return rtrim((string)preg_replace('~/index\.php$~i', '', $url), '/');
    }

    /** @param array{mode:string,url?:string,path?:string,key:string} $source @return array{manifest:array<string,mixed>,endpoint:?string} */
    public static function probe(array $source): array
    {
        $key = trim((string)$source['key']);
        if (!str_starts_with($key, 'bkhc_')) {
            throw new \RuntimeException(__('That doesn\'t look like a migration key. Create one on the other help desk under Admin → System → "Copy this help desk".'));
        }
        if ($source['mode'] === 'file') {
            $path = self::filePath((string)($source['path'] ?? ''));
            $fh = fopen($path, 'rb');
            $head = json_decode((string)fgets($fh), true);
            $line = (string)fgets($fh);
            fclose($fh);
            if (($head['format'] ?? '') !== Migration::FORMAT) {
                throw new \RuntimeException(__('That file isn\'t a BAKKBONE Help Centre export.'));
            }
            $m = Migration::open($key, $line)['m'] ?? null;
            if (!is_array($m)) {
                throw new \RuntimeException(__('The export file is damaged.'));
            }
            return ['manifest' => self::checkManifest($m), 'endpoint' => null];
        }
        $base = self::normaliseUrl((string)($source['url'] ?? ''));
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException(__('Enter the address of the other help desk, e.g. https://staging.help.example.com'));
        }
        $lastError = '';
        foreach ([$base . '/api/migrate', $base . '/index.php/api/migrate'] as $endpoint) {
            try {
                $m = self::call($endpoint, $key, ['op' => 'manifest']);
                return ['manifest' => self::checkManifest($m), 'endpoint' => $endpoint];
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
                if (!str_contains($lastError, '(404)')) {
                    break;
                }
            }
        }
        throw new \RuntimeException($lastError);
    }

    private static function checkManifest(array $m): array
    {
        if (($m['format'] ?? '') !== Migration::FORMAT) {
            throw new \RuntimeException(__('The other side didn\'t answer like a BAKKBONE Help Centre.'));
        }
        if ((int)($m['schema'] ?? 0) > HC_SCHEMA_VERSION) {
            throw new \RuntimeException(__('The other help desk runs a newer version ({version}). Upload the same or a newer version of BAKKBONE Help Centre here first.', ['version' => $m['version'] ?? '?']));
        }
        return $m;
    }

    public static function filePath(string $name): string
    {
        $name = trim($name);
        $path = PHP_SAPI === 'cli' && is_file($name) ? $name : App::storage('import/' . basename($name));
        if ($name === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(__('Couldn\'t find the export file. Upload it into the storage/import folder, then choose it here.'));
        }
        return $path;
    }

    /** @return list<string> */
    public static function availableFiles(): array
    {
        $dir = App::storage('import');
        $files = is_dir($dir) ? glob($dir . '/*.' . Migration::FILE_EXT) : [];
        return array_map('basename', $files ?: []);
    }

    /** @param array{mode:string,url?:string,path?:string,key:string} $source @param array{pause?:bool} $options */
    public static function begin(array $source, array $options): array
    {
        $probe = self::probe($source);
        $m = $probe['manifest'];
        $db = App::db();
        if ((int)$db->val('SELECT COUNT(*) FROM staff') || (int)$db->val('SELECT COUNT(*) FROM tickets')) {
            throw new \RuntimeException(__('This database already has help desk data in it. Use an empty database to copy into.'));
        }
        $steps = [];
        foreach (Migration::tables() as $t) {
            if (array_key_exists($t, $m['tables'])) {
                $steps[$t] = ['label' => self::label($t), 'total' => (int)$m['tables'][$t], 'done' => 0, 'cursor' => 0, 'complete' => false];
            }
        }
        $steps['files'] = ['label' => __('Attachment files'), 'total' => (int)$m['attachments'], 'done' => 0, 'cursor' => 0, 'offset' => 0, 'complete' => false];
        $steps['finish'] = ['label' => __('Finishing up'), 'total' => 0, 'done' => 0, 'cursor' => 0, 'complete' => false];
        $state = [
            'status' => 'running', 'mode' => $source['mode'], 'endpoint' => $probe['endpoint'],
            'path' => $source['mode'] === 'file' ? self::filePath((string)$source['path']) : null, 'file_offset' => 0,
            'key' => Crypto::encrypt(trim((string)$source['key'])), 'manifest' => $m, 'steps' => $steps,
            'pause' => !empty($options['pause']), 'current' => null, 'error' => null, 'warnings' => [], 'missing_files' => 0,
            'started_at' => DB::now(),
        ];
        self::save($state);
        return $state;
    }

    public static function state(): ?array
    {
        try {
            $raw = (string)App::db()->val('SELECT value FROM settings WHERE name = ?', [self::STATE_KEY]);
        } catch (\Throwable) {
            return null;
        }
        $s = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($s) ? $s : null;
    }

    private static function save(array $state): void
    {
        $db = App::db();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($db->val('SELECT 1 FROM settings WHERE name = ?', [self::STATE_KEY])) {
            $db->update('settings', ['value' => $json], 'name = ?', [self::STATE_KEY]);
        } else {
            $db->insert('settings', ['name' => self::STATE_KEY, 'value' => $json]);
        }
    }

    public static function keyMatches(string $key): bool
    {
        $s = self::state();
        return $s && hash_equals((string)Crypto::decrypt($s['key']), trim($key));
    }

    public static function percent(array $state): int
    {
        $total = 0;
        $done = 0;
        foreach ($state['steps'] ?? [] as $k => $s) {
            $w = $k === 'files' ? max(1, (int)(($state['manifest']['attachment_bytes'] ?? 0) / 50000)) / max(1, (int)$s['total']) : 1;
            $t = max(1, (int)$s['total']);
            $total += $t * $w;
            $done += ($s['complete'] ? $t : min($t, (int)$s['done'])) * $w;
        }
        return $total > 0 ? (int)floor($done / $total * 100) : 0;
    }

    public static function step(float $seconds = 8.0, bool $resume = false): array
    {
        $state = self::state();
        if (!$state) {
            return [];
        }
        if ($resume && $state['status'] === 'failed') {
            $state['status'] = 'running';
            $state['error'] = null;
        }
        if ($state['status'] !== 'running') {
            return $state;
        }
        $lockFile = App::storage('cache/clone.lock');
        @mkdir(dirname($lockFile), 0770, true);
        $lock = fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $state;
        }
        @set_time_limit(max(60, (int)$seconds * 4));
        @ini_set('memory_limit', '512M');
        try {
            $run = new self($state, $seconds);
            $state = $run->state['mode'] === 'file' ? $run->runFile() : $run->runUrl();
        } catch (\Throwable $e) {
            $state = self::state() ?? $state;
            $state['status'] = 'failed';
            $state['error'] = $e->getMessage();
            error_log('[HelpCentre] Copy failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (($state['status'] ?? '') !== 'done') {
            self::save($state);
        }
        return $state;
    }

    private function __construct(array $state, float $seconds)
    {
        $this->state = $state;
        $this->db = App::db();
        $this->key = (string)Crypto::decrypt($state['key']);
        $this->deadline = microtime(true) + $seconds;
    }

    private function outOfTime(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function runUrl(): array
    {
        $endpoint = (string)$this->state['endpoint'];
        foreach ($this->state['steps'] as $table => &$step) {
            if ($step['complete'] || in_array($table, ['files', 'finish'], true)) {
                continue;
            }
            $this->state['current'] = $table;
            while (!$step['complete']) {
                $page = self::call($endpoint, $this->key, ['op' => 'rows', 'table' => $table, 'cursor' => $step['cursor'], 'limit' => 300]);
                $this->db->tx(function () use ($table, $page, &$step) {
                    $this->insertRows($table, $page['rows']);
                    $step['done'] += count($page['rows']);
                    $step['cursor'] = (int)$page['cursor'];
                    $step['complete'] = (bool)$page['done'];
                    self::save($this->state);
                });
                if ($this->outOfTime()) {
                    return $this->state;
                }
            }
        }
        unset($step);
        $f = &$this->state['steps']['files'];
        $this->state['current'] = 'files';
        while (!$f['complete']) {
            $ids = self::call($endpoint, $this->key, ['op' => 'attachment_ids', 'after' => $f['cursor']])['ids'];
            if (!$ids) {
                $f['complete'] = true;
                break;
            }
            foreach ($ids as $id) {
                do {
                    $chunk = self::call($endpoint, $this->key, ['op' => 'file', 'id' => $id, 'offset' => $f['offset']]);
                    $this->writeChunk($chunk);
                    $f['offset'] = $chunk['eof'] ? 0 : $f['offset'] + strlen((string)base64_decode($chunk['data']));
                    if ($chunk['eof']) {
                        $f['cursor'] = (int)$id;
                        $f['done']++;
                    }
                    if ($this->outOfTime()) {
                        self::save($this->state);
                        return $this->state;
                    }
                } while (!$chunk['eof']);
            }
            self::save($this->state);
        }
        unset($f);
        try {
            self::call($endpoint, $this->key, ['op' => 'finished']);
        } catch (\Throwable) {
        }
        return $this->finish();
    }

    private function runFile(): array
    {
        $fh = fopen((string)$this->state['path'], 'rb');
        if (!$fh) {
            throw new \RuntimeException(__('The export file can\'t be read any more.'));
        }
        if ((int)$this->state['file_offset'] === 0) {
            fgets($fh);
            fgets($fh);
            $this->state['file_offset'] = ftell($fh);
        }
        fseek($fh, (int)$this->state['file_offset']);
        while (($line = fgets($fh)) !== false) {
            $rec = Migration::open($this->key, $line);
            $this->db->tx(function () use ($rec, $fh) {
                if (isset($rec['t'])) {
                    $t = (string)$rec['t'];
                    $this->state['current'] = $t;
                    if (isset($this->state['steps'][$t])) {
                        $this->insertRows($t, $rec['rows']);
                        $this->state['steps'][$t]['done'] += count($rec['rows']);
                    }
                } elseif (isset($rec['f'])) {
                    $this->completeTablesBefore('files');
                    $this->state['current'] = 'files';
                    $this->writeChunk($rec['f']);
                    if ($rec['f']['eof']) {
                        $this->state['steps']['files']['done']++;
                    }
                } elseif (isset($rec['end'])) {
                    $this->completeTablesBefore('finish');
                    $this->state['steps']['files']['complete'] = true;
                }
                $this->state['file_offset'] = ftell($fh);
                self::save($this->state);
            });
            if (isset($rec['end'])) {
                fclose($fh);
                return $this->finish();
            }
            if ($this->outOfTime()) {
                fclose($fh);
                return $this->state;
            }
        }
        fclose($fh);
        throw new \RuntimeException(__('The export file ends early. It may not have finished uploading; upload it again and retry.'));
    }

    private function completeTablesBefore(string $step): void
    {
        foreach ($this->state['steps'] as $k => &$s) {
            if ($k === $step) {
                break;
            }
            $s['complete'] = true;
        }
    }

    private function insertRows(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $cols = $this->columns[$table] ??= array_keys(Schema::definition()[$table]['columns'] ?? []);
        $secret = Migration::SECRET_COLUMNS[$table] ?? [];
        $from = [(string)($this->state['manifest']['url_prefix'] ?? '') . '/', (string)($this->state['manifest']['base_url'] ?? '') . '/'];
        $to = [rtrim(App::url('/'), '/') . '/', App::baseUrl() . '/'];
        $rewrite = $from[1] !== '/' && $from[1] !== $to[1];
        foreach ($rows as $r) {
            $row = array_intersect_key($r, array_flip($cols));
            foreach ($row as $c => $v) {
                if ($rewrite && is_string($v) && str_contains($v, $from[1])) {
                    $row[$c] = str_replace($from, $to, $v);
                }
            }
            foreach ($secret as $c) {
                if (!empty($row[$c])) {
                    $row[$c] = Crypto::encrypt((string)$row[$c]);
                }
            }
            if ($table === 'settings') {
                if (in_array($row['name'], Migration::SKIP_SETTINGS, true) || $row['name'] === self::STATE_KEY) {
                    continue;
                }
                if (in_array($row['name'], Settings::SECRET, true) && !empty($row['value'])) {
                    $row['value'] = Crypto::encrypt((string)$row['value']);
                }
                $this->db->delete('settings', 'name = ?', [$row['name']]);
            }
            if ($table === 'webhook_deliveries' && empty($row['success'])) {
                $row['next_attempt_at'] = null; // don't re-send old webhook retries from the new server
            }
            $this->db->insert($table, $row);
        }
    }

    private function writeChunk(array $chunk): void
    {
        if (!empty($chunk['missing'])) {
            $this->state['missing_files']++;
            return;
        }
        $att = $this->db->find('attachments', (int)$chunk['id']);
        if (!$att || !preg_match('~^[A-Za-z0-9/_\-]+$~', (string)$att['path']) || str_contains((string)$att['path'], '..')) {
            return;
        }
        $path = Attachments::fullPath($att);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException(__('Can\'t write to {folder}. Make storage/attachments writable.', ['folder' => dirname($path)]));
        }
        $fh = fopen($path, (int)$chunk['offset'] === 0 ? 'wb' : 'cb');
        fseek($fh, (int)$chunk['offset']);
        fwrite($fh, (string)base64_decode((string)$chunk['data']));
        fclose($fh);
    }

    private function finish(): array
    {
        $this->state['current'] = 'finish';
        Installer::upgrade();
        if ($this->state['pause']) {
            $this->db->update('email_accounts', ['active' => 0], '1 = 1');
            $this->db->update('webhooks', ['active' => 0], '1 = 1');
        }
        $this->state['steps']['finish']['complete'] = true;
        foreach ($this->state['steps'] as &$s) {
            $s['complete'] = true;
        }
        unset($s);
        $this->state['status'] = 'done';
        $this->state['finished_at'] = DB::now();
        if ($this->state['missing_files']) {
            $this->state['warnings'][] = $this->state['missing_files'] . ' attachment file(s) were missing on the other help desk and couldn\'t be copied.';
        }
        $summary = $this->state;
        unset($summary['key']);
        $this->db->delete('settings', 'name = ?', [self::STATE_KEY]);
        Settings::set('clone_summary', json_encode($summary));
        if (!empty($this->state['manifest']['instance_id']) && $this->state['mode'] === 'url') {
            BhcSync::remember((string)$this->state['manifest']['instance_id'], [
                'base_url' => (string)($this->state['manifest']['base_url'] ?? ''), 'helpdesk' => (string)($this->state['manifest']['helpdesk'] ?? ''),
                'last_sync' => (string)($this->state['manifest']['created_at'] ?? ''), 'how' => 'copied during setup',
            ]);
        } elseif (!empty($this->state['manifest']['instance_id'])) {
            BhcSync::remember((string)$this->state['manifest']['instance_id'], [
                'base_url' => (string)($this->state['manifest']['base_url'] ?? ''), 'helpdesk' => (string)($this->state['manifest']['helpdesk'] ?? ''),
                'last_sync' => (string)($this->state['manifest']['created_at'] ?? ''), 'how' => 'copied from an export file during setup',
            ]);
        }
        if ((int)($this->state['manifest']['schema'] ?? 0) < 4) {
            Installer::grantCollaboratorPermission();
        }
        Installer::finishClone();
        \Bakkbone\HelpCentre\Core\Audit::log('migration.copied', 'system', null, ['from' => $this->state['manifest']['base_url'] ?? '']);
        return $this->state;
    }

    public static function call(string $endpoint, string $key, array $req): array
    {
        $headers = ['Content-Type: text/plain', 'X-Migration-Key: ' . $key, 'Accept: text/plain'];
        for ($attempt = 1; ; $attempt++) {
            try {
                [$status, $body] = Http::request('POST', $endpoint, Migration::seal($key, $req), $headers, 60);
                break;
            } catch (\RuntimeException $e) {
                if ($attempt >= 3) {
                    throw new \RuntimeException(__('Couldn\'t reach the other help desk: {error}', ['error' => preg_replace('/^HTTP request failed: /', '', $e->getMessage())]));
                }
                sleep(2);
            }
        }
        if ($status !== 200) {
            $msg = json_decode($body, true)['error'] ?? null;
            throw new \RuntimeException(($msg ?: __('The other help desk answered with an error')) . " ($status)");
        }
        $data = Migration::open($key, $body);
        if (!is_array($data)) {
            throw new \RuntimeException(__('Unexpected answer from the other help desk.'));
        }
        return $data;
    }

    private static function label(string $table): string
    {
        return [
            'settings' => __('Settings'), 'departments' => __('Departments'), 'teams' => __('Teams'), 'team_members' => __('Team members'), 'roles' => __('Roles'),
            'staff' => __('Agents'), 'staff_dept_access' => __('Agent access'), 'organizations' => __('Organisations'), 'users' => __('Users'), 'sla' => __('SLA plans'),
            'priorities' => __('Priorities'), 'ticket_statuses' => __('Statuses'), 'help_topics' => __('Help topics'), 'custom_fields' => __('Custom fields'),
            'tickets' => __('Tickets'), 'thread_entries' => __('Messages & notes'), 'attachments' => __('Attachment records'), 'ticket_events' => __('Ticket history'),
            'ticket_collaborators' => __('Collaborators'), 'ticket_field_values' => __('Custom field values'), 'canned_responses' => __('Canned replies'),
            'filters' => __('Filters'), 'email_accounts' => __('Mailboxes'), 'mail_seen' => __('Fetched-mail log'), 'email_templates' => __('Email templates'),
            'kb_categories' => __('KB categories'), 'kb_articles' => __('KB articles'), 'tasks' => __('Tasks'), 'webhooks' => __('Webhooks'),
            'webhook_deliveries' => __('Webhook history'), 'api_keys' => __('API keys'), 'banlist' => __('Ban list'), 'saved_searches' => __('Saved searches'),
            'system_log' => __('System log'), 'import_map' => __('Import history'), 'audit_log' => __('Audit log'),
        ][$table] ?? ucfirst(str_replace('_', ' ', $table));
    }
}
