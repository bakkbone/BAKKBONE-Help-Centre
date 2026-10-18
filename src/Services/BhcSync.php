<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Core\Settings;

/** IDs can differ from the source here, so rows are matched by natural key and foreign keys are remapped. */
final class BhcSync
{
    private const STATE_KEY = 'bhc_sync_state';
    private const SOURCES_KEY = 'sync_sources';

    /** Order matters: referenced tables come first. */
    private const TABLES = [
        'roles' => ['Roles', []],
        'sla' => ['SLA plans', []],
        'priorities' => ['Priorities', []],
        'ticket_statuses' => ['Statuses', []],
        'email_accounts' => ['Mailboxes', ['dept_id' => 'departments', 'priority_id' => 'priorities', 'topic_id' => 'help_topics']],
        'departments' => ['Departments', ['sla_id' => 'sla', 'email_account_id' => 'email_accounts', 'manager_id' => 'staff']],
        'staff' => ['Agents', ['dept_id' => 'departments', 'role_id' => 'roles']],
        'teams' => ['Teams', ['lead_id' => 'staff']],
        'help_topics' => ['Help topics', ['parent_id' => 'help_topics', 'dept_id' => 'departments', 'priority_id' => 'priorities', 'sla_id' => 'sla',
            'staff_id' => 'staff', 'team_id' => 'teams', 'status_id' => 'ticket_statuses']],
        'custom_fields' => ['Custom fields', []],
        'organizations' => ['Organisations', []],
        'users' => ['Users', ['org_id' => 'organizations']],
        'tickets' => ['Tickets', ['user_id' => 'users', 'dept_id' => 'departments', 'topic_id' => 'help_topics', 'priority_id' => 'priorities',
            'status_id' => 'ticket_statuses', 'sla_id' => 'sla', 'staff_id' => 'staff', 'team_id' => 'teams', 'email_account_id' => 'email_accounts',
            'merged_into' => 'tickets']],
        'thread_entries' => ['Messages & notes', ['ticket_id' => 'tickets', 'user_id' => 'users', 'staff_id' => 'staff']],
        'tasks' => ['Tasks', ['ticket_id' => 'tickets', 'dept_id' => 'departments', 'staff_id' => 'staff', 'created_by' => 'staff']],
        'attachments' => ['Attachments', ['ticket_id' => 'tickets', 'entry_id' => 'thread_entries', 'task_id' => 'tasks']],
        'ticket_events' => ['Ticket history', ['ticket_id' => 'tickets', 'staff_id' => 'staff', 'user_id' => 'users']],
        'ticket_collaborators' => ['Collaborators', ['ticket_id' => 'tickets', 'user_id' => 'users']],
        'ticket_field_values' => ['Custom field values', ['ticket_id' => 'tickets', 'field_id' => 'custom_fields']],
        'kb_categories' => ['KB categories', []],
        'kb_articles' => ['KB articles', ['category_id' => 'kb_categories']],
        'canned_responses' => ['Canned replies', ['dept_id' => 'departments']],
    ];

    private const UPDATE_IF_NEWER = ['tickets', 'users', 'organizations', 'kb_articles', 'tasks'];

    /** @var array<string,mixed> */
    private array $state;
    private DB $db;
    private string $key;
    private string $src;
    private float $deadline;
    /** @var array<string,array<int,?int>> */
    private array $mapCache = [];
    /** @var array<string,int> */
    private array $pulling = [];

    /** @return array<string,array<string,string>> */
    public static function sources(): array
    {
        $s = json_decode((string)Settings::get(self::SOURCES_KEY, ''), true);
        return is_array($s) ? $s : [];
    }

    public static function remember(string $instanceId, array $info): void
    {
        $all = self::sources();
        $all[$instanceId] = array_merge($all[$instanceId] ?? [], $info);
        Settings::set(self::SOURCES_KEY, json_encode($all, JSON_UNESCAPED_SLASHES));
    }

    public static function state(): ?array
    {
        $s = json_decode((string)Settings::get(self::STATE_KEY, ''), true);
        return is_array($s) ? $s : null;
    }

    private static function save(array $s): void
    {
        Settings::set(self::STATE_KEY, json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    public static function reset(): void
    {
        Settings::set(self::STATE_KEY, '');
    }

    public static function percent(array $state): int
    {
        $total = 0;
        $done = 0;
        foreach ($state['steps'] ?? [] as $s) {
            $total++;
            $done += $s['complete'] ? 1 : 0;
        }
        return $total ? (int)floor($done / $total * 100) : 0;
    }

    public static function start(string $url, string $key, int $staffId, bool $full = false): array
    {
        $probe = CloneImporter::probe(['mode' => 'url', 'url' => $url, 'key' => $key]);
        $m = $probe['manifest'];
        $instance = (string)($m['instance_id'] ?? '');
        if ($instance === '') {
            throw new \RuntimeException(__('The other help desk needs updating to this version before catch-up imports work.'));
        }
        if ($instance === Migration::instanceId()) {
            throw new \RuntimeException(__('That address is this help desk.'));
        }
        $known = self::sources()[$instance] ?? null;
        $since = !$full && $known && !empty($known['last_sync']) ? (string)$known['last_sync'] : null;
        $steps = [];
        foreach (self::TABLES as $t => [$label]) {
            $steps[$t] = ['label' => $label, 'total' => $since ? 0 : (int)($m['tables'][$t] ?? 0), 'done' => 0, 'cursor' => 0, 'complete' => false];
        }
        $steps['files'] = ['label' => __('Attachment files'), 'total' => 0, 'done' => 0, 'cursor' => 0, 'complete' => false];
        $state = [
            'status' => 'running', 'endpoint' => $probe['endpoint'], 'key' => Crypto::encrypt(trim($key)), 'instance' => $instance,
            'manifest' => $m, 'since' => $since, 'steps' => $steps, 'current' => null, 'error' => null,
            'added' => [], 'updated' => [], 'files' => [], 'missing_files' => 0, 'started_at' => DB::now(), 'started_by' => $staffId,
        ];
        self::save($state);
        Audit::log('sync.start', 'system', null, ['from' => $m['base_url'] ?? '', 'since' => $since]);
        return $state;
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
        $lock = fopen(App::storage('cache/bhc-sync.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $state;
        }
        @set_time_limit(max(60, (int)$seconds * 4));
        try {
            $run = new self($state, $seconds);
            $state = $run->run();
        } catch (\Throwable $e) {
            $state = self::state() ?? $state;
            $state['status'] = 'failed';
            $state['error'] = $e->getMessage();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::save($state);
        return $state;
    }

    private function __construct(array $state, float $seconds)
    {
        $this->state = $state;
        $this->db = App::db();
        $this->key = (string)Crypto::decrypt($state['key']);
        $this->src = 'bhc:' . substr((string)$state['instance'], 0, 16);
        $this->deadline = microtime(true) + $seconds;
    }

    private function run(): array
    {
        $endpoint = (string)$this->state['endpoint'];
        foreach (array_keys(self::TABLES) as $table) {
            $step = &$this->state['steps'][$table];
            if ($step['complete']) {
                continue;
            }
            $this->state['current'] = $table;
            while (!$step['complete']) {
                $req = ['op' => 'rows', 'table' => $table, 'cursor' => $step['cursor'], 'limit' => 200];
                if ($this->state['since'] !== null) {
                    $req['since'] = $this->state['since'];
                }
                $page = CloneImporter::call($endpoint, $this->key, $req);
                $this->db->tx(function () use ($table, $page, &$step) {
                    foreach ($page['rows'] as $row) {
                        $this->mergeRow($table, $row);
                        $step['done']++;
                    }
                    $step['cursor'] = (int)$page['cursor'];
                    $step['complete'] = (bool)$page['done'];
                    self::save($this->state);
                });
                if ($this->outOfTime()) {
                    return $this->state;
                }
            }
            unset($step);
        }
        $f = &$this->state['steps']['files'];
        $f['total'] = count($this->state['files']);
        $this->state['current'] = 'files';
        while ($f['cursor'] < count($this->state['files'])) {
            [$srcId, $localId] = $this->state['files'][$f['cursor']];
            $offset = (int)($f['offset'] ?? 0);
            do {
                $chunk = CloneImporter::call($endpoint, $this->key, ['op' => 'file', 'id' => $srcId, 'offset' => $offset]);
                if (!empty($chunk['missing'])) {
                    $this->state['missing_files']++;
                    break;
                }
                $this->writeChunk($localId, $offset, (string)base64_decode((string)$chunk['data']));
                $offset += strlen((string)base64_decode((string)$chunk['data']));
                $f['offset'] = $chunk['eof'] ? 0 : $offset;
                if (!$chunk['eof'] && $this->outOfTime()) {
                    return $this->state;
                }
            } while (!$chunk['eof']);
            $f['offset'] = 0;
            $f['cursor']++;
            $f['done']++;
            if ($this->outOfTime()) {
                return $this->state;
            }
        }
        $f['complete'] = true;
        unset($f);
        return $this->finish();
    }

    private function outOfTime(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function finish(): array
    {
        $this->state['status'] = 'done';
        $this->state['current'] = null;
        $this->state['finished_at'] = DB::now();
        $m = $this->state['manifest'];
        self::remember((string)$this->state['instance'], [
            'base_url' => (string)($m['base_url'] ?? ''), 'helpdesk' => (string)($m['helpdesk'] ?? ''),
            // The other side's clock when this run started: the next run fetches changes from there on.
            'last_sync' => (string)($m['created_at'] ?? ''), 'how' => 'catch-up import',
            'last_run' => DB::now(),
        ]);
        unset($this->state['key']);
        Audit::log('sync.done', 'system', null, ['added' => $this->state['added'], 'updated' => $this->state['updated']]);
        return $this->state;
    }

    private function mergeRow(string $table, array $r): void
    {
        $oldId = isset($r['id']) ? (int)$r['id'] : 0;
        $row = $this->prepare($table, $r);
        if ($row === null) {
            return;
        }
        $localId = $oldId ? $this->map($table, $oldId) : null;
        $localId ??= $this->findExisting($table, $row);
        if ($localId) {
            if ($oldId) {
                $this->setMap($table, $oldId, $localId);
            }
            $this->maybeUpdate($table, $localId, $row);
            return;
        }
        if (!isset(Schema::definition()[$table]['columns']['id'])) {
            $this->insertComposite($table, $row);
            return;
        }
        unset($row['id']);
        $row = $this->beforeInsert($table, $row, $r);
        $newId = $this->db->insert($table, $row);
        if ($oldId) {
            $this->setMap($table, $oldId, $newId);
        }
        $this->state['added'][$table] = (int)($this->state['added'][$table] ?? 0) + 1;
        if ($table === 'attachments') {
            $this->state['files'][] = [$oldId, $newId];
            $this->relinkInlineImage($newId, $oldId, (int)($row['entry_id'] ?? 0));
        }
    }

    /** Null means skip the row. */
    private function prepare(string $table, array $r): ?array
    {
        $cols = array_keys(Schema::definition()[$table]['columns'] ?? []);
        $row = array_intersect_key($r, array_flip($cols));
        foreach (self::TABLES[$table][1] as $col => $ref) {
            if (!empty($row[$col])) {
                $mapped = $this->map($ref, (int)$row[$col]) ?? $this->pull($ref, (int)$row[$col], $table, (int)($r['id'] ?? 0));
                if ($mapped === null && in_array($col, ['ticket_id', 'field_id'], true) && in_array($table, ['thread_entries', 'ticket_events', 'ticket_collaborators', 'ticket_field_values'], true)) {
                    return null; // its ticket wasn't imported
                }
                $row[$col] = $mapped;
            }
        }
        if ($table === 'tickets' && empty($row['user_id'])) {
            return null;
        }
        foreach (Migration::SECRET_COLUMNS[$table] ?? [] as $c) {
            if (!empty($row[$c])) {
                $row[$c] = Crypto::encrypt((string)$row[$c]);
            }
        }
        $from = [(string)($this->state['manifest']['url_prefix'] ?? '') . '/', (string)($this->state['manifest']['base_url'] ?? '') . '/'];
        $to = [rtrim(App::url('/'), '/') . '/', App::baseUrl() . '/'];
        if ($from[1] !== '/' && $from[1] !== $to[1]) {
            foreach ($row as $c => $v) {
                if (is_string($v) && str_contains($v, $from[1])) {
                    $row[$c] = str_replace($from, $to, $v);
                }
            }
        }
        return $row;
    }

    /** A changed row can point at a record this run didn't fetch (e.g. a new reply on an old ticket), so fetch and merge that first. */
    private function pull(string $table, int $oldId, string $fromTable, int $fromId): ?int
    {
        if ($table === $fromTable && $oldId === $fromId) {
            return null; // points at itself
        }
        $this->pulling[$table . ':' . $oldId] = ($this->pulling[$table . ':' . $oldId] ?? 0) + 1;
        if ($this->pulling[$table . ':' . $oldId] > 1) {
            return null; // already being fetched further up (circular reference)
        }
        $res = CloneImporter::call((string)$this->state['endpoint'], $this->key, ['op' => 'row', 'table' => $table, 'id' => $oldId]);
        if (!empty($res['row'])) {
            $this->mergeRow($table, $res['row']);
        }
        unset($this->pulling[$table . ':' . $oldId]);
        return $this->map($table, $oldId);
    }

    /** Natural keys stop rows that already exist here (e.g. from an earlier copy) being duplicated. */
    private function findExisting(string $table, array $row): ?int
    {
        $lower = fn(string $col, string $tbl) => $this->db->val("SELECT id FROM $tbl WHERE LOWER($col) = ? LIMIT 1", [mb_strtolower((string)($row[$col] ?? ''))]);
        $id = match ($table) {
            'roles', 'sla', 'priorities', 'ticket_statuses', 'departments', 'teams', 'kb_categories', 'organizations' => $lower('name', $table),
            'email_accounts', 'users' => $lower('email', $table),
            'staff' => $this->db->val('SELECT id FROM staff WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1', [mb_strtolower((string)$row['email']), mb_strtolower((string)$row['username'])]),
            'help_topics' => $this->db->val('SELECT id FROM help_topics WHERE LOWER(name) = ? AND ' . (!empty($row['parent_id']) ? 'parent_id = ?' : 'parent_id IS NULL') . ' LIMIT 1',
                array_merge([mb_strtolower((string)$row['name'])], !empty($row['parent_id']) ? [$row['parent_id']] : [])),
            'custom_fields' => $this->db->val('SELECT id FROM custom_fields WHERE name = ?', [$row['name']]),
            'tickets' => $this->db->val('SELECT id FROM tickets WHERE number = ? AND user_id = ?', [$row['number'], $row['user_id']]),
            'thread_entries' => !empty($row['message_id'])
                ? $this->db->val('SELECT id FROM thread_entries WHERE ticket_id = ? AND message_id = ?', [$row['ticket_id'], $row['message_id']])
                : $this->db->val('SELECT id FROM thread_entries WHERE ticket_id = ? AND type = ? AND created_at = ?', [$row['ticket_id'], $row['type'], $row['created_at']]),
            'attachments' => $this->db->val('SELECT id FROM attachments WHERE ' . (!empty($row['entry_id']) ? 'entry_id = ?' : 'entry_id IS NULL AND ticket_id = ?')
                . ' AND filename = ? AND size = ?', [$row['entry_id'] ?: $row['ticket_id'], $row['filename'], $row['size']]),
            'ticket_events' => $this->db->val('SELECT id FROM ticket_events WHERE ticket_id = ? AND event = ? AND created_at = ?', [$row['ticket_id'], $row['event'], $row['created_at']]),
            'ticket_collaborators' => $this->db->val('SELECT id FROM ticket_collaborators WHERE ticket_id = ? AND user_id = ?', [$row['ticket_id'], $row['user_id']]),
            'tasks' => $this->db->val('SELECT id FROM tasks WHERE title = ? AND created_at = ?', [$row['title'], $row['created_at']]),
            'kb_articles' => $this->db->val('SELECT id FROM kb_articles WHERE category_id = ? AND question = ?', [$row['category_id'], $row['question']]),
            'canned_responses' => $lower('title', 'canned_responses'),
            default => null,
        };
        return $id ? (int)$id : null;
    }

    private function maybeUpdate(string $table, int $localId, array $row): void
    {
        if (!in_array($table, self::UPDATE_IF_NEWER, true)) {
            return;
        }
        $local = $this->db->find($table, $localId);
        if (!$local) {
            return;
        }
        if ($table === 'tasks') {
            if ($local['status'] !== $row['status']) {
                $this->db->update('tasks', ['status' => $row['status'], 'closed_at' => $row['closed_at'] ?? null], 'id = ?', [$localId]);
                $this->state['updated'][$table] = (int)($this->state['updated'][$table] ?? 0) + 1;
            }
            return;
        }
        if (empty($row['updated_at']) || (string)$row['updated_at'] <= (string)($local['updated_at'] ?? '')) {
            return; // this side has the newer (or the same) version
        }
        unset($row['id']);
        if ($table === 'tickets') {
            unset($row['number']); // keep this side's number
        }
        if ($table === 'users') {
            unset($row['email'], $row['password_hash']);
        }
        $this->db->update($table, $row, 'id = ?', [$localId]);
        $this->state['updated'][$table] = (int)($this->state['updated'][$table] ?? 0) + 1;
    }

    private function beforeInsert(string $table, array $row, array $original): array
    {
        if ($table === 'tickets' && $this->db->val('SELECT 1 FROM tickets WHERE number = ?', [$row['number']])) {
            // Same number already used here for a different customer's ticket.
            $base = $row['number'] . '-B';
            $n = $base;
            for ($i = 2; $this->db->val('SELECT 1 FROM tickets WHERE number = ?', [$n]); $i++) {
                $n = $base . $i;
            }
            $row['number'] = mb_substr($n, 0, 32);
        }
        if ($table === 'staff' || $table === 'email_accounts') {
            $row['active'] = $table === 'staff' ? $row['active'] : 0; // new mailboxes arrive switched off
        }
        if ($table === 'attachments') {
            $path = (string)$row['path'];
            if (!preg_match('~^[A-Za-z0-9/_\-]+$~', $path) || str_contains($path, '..') || is_file(App::storage('attachments/' . $path))) {
                $row['path'] = gmdate('Y/m') . '/' . bin2hex(random_bytes(16));
            }
        }
        return $row;
    }

    private function insertComposite(string $table, array $row): void
    {
        if ($table === 'ticket_field_values') {
            if (empty($row['ticket_id']) || empty($row['field_id'])) {
                return;
            }
            $this->db->delete('ticket_field_values', 'ticket_id = ? AND field_id = ?', [$row['ticket_id'], $row['field_id']]);
            $this->db->insert('ticket_field_values', $row);
        }
    }

    /** Attachment ids differ here, so inline image links (/file/<id>) need rewriting. */
    private function relinkInlineImage(int $newId, int $oldId, int $entryId): void
    {
        if (!$entryId || $newId === $oldId) {
            return;
        }
        $body = (string)$this->db->val('SELECT body FROM thread_entries WHERE id = ?', [$entryId]);
        $fixed = (string)preg_replace('~(/file/)' . $oldId . '(?=[?"\'&\s]|$)~', '${1}' . $newId, $body);
        if ($fixed !== $body) {
            $this->db->update('thread_entries', ['body' => $fixed], 'id = ?', [$entryId]);
        }
    }

    private function writeChunk(int $attachmentId, int $offset, string $data): void
    {
        $att = $this->db->find('attachments', $attachmentId);
        if (!$att) {
            return;
        }
        $path = Attachments::fullPath($att);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0770, true);
        }
        $fh = fopen($path, $offset === 0 ? 'wb' : 'cb');
        fseek($fh, $offset);
        fwrite($fh, $data);
        fclose($fh);
    }

    private function map(string $type, int $oldId): ?int
    {
        if ($oldId <= 0) {
            return null;
        }
        if (!array_key_exists($oldId, $this->mapCache[$type] ?? [])) {
            $v = $this->db->val('SELECT new_id FROM import_map WHERE source = ? AND type = ? AND old_id = ?', [$this->src, substr($type, 0, 20), $oldId]);
            $this->mapCache[$type][$oldId] = $v ? (int)$v : null;
        }
        return $this->mapCache[$type][$oldId];
    }

    private function setMap(string $type, int $oldId, int $newId): void
    {
        if ($this->map($type, $oldId) === $newId) {
            return;
        }
        $this->db->delete('import_map', 'source = ? AND type = ? AND old_id = ?', [$this->src, substr($type, 0, 20), $oldId]);
        $this->db->insert('import_map', ['source' => $this->src, 'type' => substr($type, 0, 20), 'old_id' => $oldId, 'new_id' => $newId]);
        $this->mapCache[$type][$oldId] = $newId;
    }
}
