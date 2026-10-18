<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Settings;

/** Time-boxed, resumable steps so it fits within shared-hosting time limits. */
final class OsTicketImporter
{
    private const STATE_KEY = 'osticket_import';
    private const SRC = 'osticket';

    /** Run in this order: key => [label, source table for the total]. */
    public const STEPS = [
        'priorities' => ['Priorities', 'ticket_priority'],
        'statuses' => ['Ticket statuses', 'ticket_status'],
        'sla' => ['SLA plans', 'sla'],
        'departments' => ['Departments', 'department'],
        'staff' => ['Agents', 'staff'],
        'teams' => ['Teams', 'team'],
        'topics' => ['Help topics', 'help_topic'],
        'fields' => ['Custom ticket fields', 'form_field'],
        'mailboxes' => ['Mailboxes (switched off, no passwords)', 'email'],
        'orgs' => ['Organisations', 'organization'],
        'users' => ['Users', 'user'],
        'tickets' => ['Tickets, messages & attachments', 'ticket'],
        'events' => ['Ticket activity history', 'thread_event'],
        'tasks' => ['Tasks', 'task'],
        'canned' => ['Canned replies', 'canned_response'],
        'kb_categories' => ['Knowledge base categories', 'faq_category'],
        'kb_articles' => ['Knowledge base articles', 'faq'],
        'filters' => ['Filters & ban list', 'filter'],
        'apikeys' => ['API keys', 'api_key'],
        'templates' => ['Email templates', 'email_template'],
        'finish' => ['Finishing up', ''],
    ];

    private const TEMPLATE_MAP = [
        'ticket.autoresp' => 'ticket.autoresponse', 'message.autoresp' => 'message.autoresponse', 'ticket.notice' => 'ticket.notice',
        'ticket.reply' => 'ticket.reply', 'ticket.alert' => 'ticket.alert', 'message.alert' => 'message.alert', 'note.alert' => 'note.alert',
        'assigned.alert' => 'assign.alert', 'transfer.alert' => 'transfer.alert', 'ticket.overdue' => 'overdue.alert', 'task.alert' => 'task.alert',
    ];

    private const VARIABLE_MAP = [
        'ticket.dept.name' => 'ticket.dept', 'ticket.topic.name' => 'ticket.topic', 'ticket.priority.desc' => 'ticket.priority',
        'ticket.priority.name' => 'ticket.priority', 'ticket.status.name' => 'ticket.status', 'ticket.sla.name' => 'ticket.sla',
        'ticket.staff.name' => 'ticket.assigned', 'ticket.staff.name.full' => 'ticket.assigned', 'ticket.staff.name.first' => 'ticket.assigned',
        'ticket.assignee' => 'ticket.assigned', 'ticket.assignee.name' => 'ticket.assigned', 'ticket.team.name' => 'ticket.team',
        'ticket.create_date' => 'ticket.created', 'ticket.due_date' => 'ticket.due', 'ticket.close_date' => 'ticket.closed',
        'ticket.client_link' => 'recipient.ticket_link', 'ticket.staff_link' => 'ticket.staff_url',
        'ticket.name' => 'user.name', 'ticket.name.full' => 'user.name', 'ticket.name.first' => 'user.first_name', 'ticket.name.last' => 'user.name',
        'ticket.email' => 'user.email', 'ticket.phone' => 'user.phone', 'ticket.user.name' => 'user.name', 'ticket.user.name.first' => 'user.first_name',
        'ticket.user.email' => 'user.email', 'recipient.name.first' => 'recipient.first_name', 'recipient.name.full' => 'recipient.name',
        'recipient.name.last' => 'recipient.name', 'recipient.name.short' => 'recipient.first_name', 'note.message' => 'note',
        'poster.name.first' => 'poster.name', 'poster.name.full' => 'poster.name', 'assignee.name' => 'ticket.assigned',
        'assignee.name.first' => 'ticket.assigned', 'assignee.name.full' => 'ticket.assigned', 'assigner.name' => 'staff.name',
        'assigner.name.first' => 'staff.name', 'assigner.name.full' => 'staff.name', 'staff.name.first' => 'staff.name', 'staff.name.full' => 'staff.name',
        'assigner.name.short' => 'staff.name', 'staff.name.short' => 'staff.name', 'poster.name.short' => 'poster.name',
        'note.poster' => 'poster.name', 'note.poster.name' => 'poster.name', 'ticket.name.short' => 'user.first_name',
        'ticket.dept' => 'ticket.dept', 'ticket.duedate' => 'ticket.due', 'ticket.source' => 'ticket.source',
        'url' => 'helpdesk.url', 'company.name' => 'company.name',
    ];

    private DB $src;
    private DB $db;
    private string $p;
    /** @var array<string,mixed> */
    private array $state;
    private \DateTimeZone $srcTz;
    private \DateTimeZone $utc;
    /** @var array<string,array<int,?int>> */
    private array $mapCache = [];
    private float $deadline = 0.0;
    /** @var array<int,string> */
    private array $fieldTypes = [];

    /** @return array<string,mixed>|null */
    public static function state(): ?array
    {
        $raw = (string)Settings::get(self::STATE_KEY, '');
        $s = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($s) ? $s : null;
    }

    private static function saveState(array $s): void
    {
        Settings::set(self::STATE_KEY, json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    public static function reset(): void
    {
        Settings::set(self::STATE_KEY, '');
    }

    /** @return array{host:string,port:int,name:string,user:string,pass:string,prefix:string} */
    public static function readConfig(string $path): array
    {
        $path = rtrim(trim($path), '/\\');
        $candidates = str_ends_with($path, '.php') ? [$path] : [$path . '/include/ost-config.php', $path . '/ost-config.php'];
        $file = null;
        foreach ($candidates as $c) {
            if (basename($c) === 'ost-config.php' && is_file($c) && is_readable($c)) {
                $file = $c;
                break;
            }
        }
        if ($file === null) {
            throw new \RuntimeException(__('Couldn\'t find a readable include/ost-config.php in "{path}". Check the path, or enter the database details instead.', ['path' => $path]));
        }
        // Parse define('NAME','value') without executing the file.
        $src = (string)file_get_contents($file);
        $get = function (string $name) use ($src): ?string {
            if (preg_match('/define\(\s*[\'"]' . $name . '[\'"]\s*,\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\)/s', $src, $m)) {
                return stripcslashes($m[2]);
            }
            return null;
        };
        $host = (string)($get('DBHOST') ?? 'localhost');
        $port = 3306;
        $socket = null;
        if (preg_match('/^(.*):(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], (int)$m[2]];
        } elseif (preg_match('/^(.*):(\/.+)$/', $host, $m)) {
            [$host, $socket] = [$m[1], $m[2]];
        }
        if ($get('DBNAME') === null) {
            throw new \RuntimeException(__('That ost-config.php doesn\'t contain database settings (DBNAME). Is osTicket fully installed?'));
        }
        return array_filter([
            'host' => $host, 'port' => $port, 'socket' => $socket, 'name' => (string)$get('DBNAME'),
            'user' => (string)$get('DBUSER'), 'pass' => (string)$get('DBPASS'), 'prefix' => (string)($get('TABLE_PREFIX') ?? 'ost_'),
        ], fn($v) => $v !== null);
    }

    /** @param array<string,mixed> $conn @return array{helpdesk:string,counts:array<string,int>,files_path:?string,storage:array<string,int>,db_timezone:string} */
    public static function inspect(array $conn): array
    {
        $src = self::connect($conn);
        $p = self::prefix($conn);
        foreach (['ticket', 'thread_entry', 'config', 'user', 'staff'] as $t) {
            if (!self::tableExists($src, $p . $t)) {
                throw new \RuntimeException(__('This doesn\'t look like an osTicket 1.10+ database: table {table} is missing. Check the database name and table prefix.', ['table' => $p . $t]));
            }
        }
        $counts = [];
        foreach (self::STEPS as $key => [, $table]) {
            if ($table !== '') {
                $counts[$key] = self::tableExists($src, $p . $table) ? (int)$src->val("SELECT COUNT(*) FROM `{$p}{$table}`") : 0;
            }
        }
        if (self::tableExists($src, $p . 'email_template')) {
            $tpl = (int)($src->val("SELECT value FROM `{$p}config` WHERE `key` = 'default_template_id' LIMIT 1") ?: 1);
            $counts['templates'] = (int)$src->val("SELECT COUNT(*) FROM `{$p}email_template` WHERE tpl_id = ? AND code_name IN ('" . implode("','", array_keys(self::TEMPLATE_MAP)) . "')", [$tpl]);
        }
        if (self::tableExists($src, $p . 'form_field')) {
            $counts['fields'] = (int)$src->val("SELECT COUNT(*) FROM `{$p}form_field` ff JOIN `{$p}form` f ON f.id = ff.form_id WHERE f.type IN ('T','G')");
        }
        $storage = [];
        if (self::tableExists($src, $p . 'file')) {
            foreach ($src->all("SELECT bk, COUNT(*) n FROM `{$p}file` f JOIN `{$p}attachment` a ON a.file_id = f.id GROUP BY bk") as $r) {
                $storage[(string)$r['bk']] = (int)$r['n'];
            }
        }
        $cfg = fn(string $key) => $src->val("SELECT value FROM `{$p}config` WHERE `key` = ? ORDER BY namespace = 'core' DESC, id LIMIT 1", [$key]);
        return [
            'helpdesk' => (string)($cfg('helpdesk_title') ?? ''),
            'counts' => $counts,
            'files_path' => ($fp = $cfg('uploadpath')) ? (string)$fp : null,
            'storage' => $storage,
            'db_timezone' => (string)($cfg('db_timezone') ?: self::serverOffset($src)),
        ];
    }

    /** @param array<string,mixed> $conn */
    public static function start(array $conn, ?string $filesPath, int $staffId, array $options = []): array
    {
        if ((int)Settings::get('schema_version') < HC_SCHEMA_VERSION) {
            Installer::upgrade();
        }
        $info = self::inspect($conn);
        $filesPath = trim((string)$filesPath) !== '' ? rtrim(trim((string)$filesPath), '/\\') : $info['files_path'];
        try {
            new \DateTimeZone($info['db_timezone']);
        } catch (\Throwable) {
            $info['db_timezone'] = 'UTC';
        }
        $steps = [];
        foreach (self::STEPS as $key => [$label]) {
            $steps[$key] = ['label' => $label, 'total' => $info['counts'][$key] ?? 0, 'done' => 0, 'cursor' => 0, 'complete' => false];
        }
        $conn['pass'] = Crypto::encrypt((string)($conn['pass'] ?? ''));
        $state = [
            'conn' => $conn, 'files_path' => $filesPath, 'db_timezone' => $info['db_timezone'], 'helpdesk' => $info['helpdesk'],
            'storage' => $info['storage'], 'steps' => $steps, 'status' => 'running', 'warnings' => [], 'skipped' => [],
            'started_at' => DB::now(), 'finished_at' => null, 'started_by' => $staffId, 'error' => null,
            'options' => ['templates' => !empty($options['templates']), 'install' => !empty($options['install'])],
        ];
        if (empty($options['templates'])) {
            $state['steps']['templates'] = ['label' => __('Email templates (not selected)'), 'total' => 0, 'done' => 0, 'cursor' => 0, 'complete' => true];
        }
        if (($info['storage']['F'] ?? 0) > 0 && (!$filesPath || !is_dir($filesPath))) {
            $state['warnings'][] = "osTicket keeps {$info['storage']['F']} attachments on disk" . ($filesPath ? " in \"$filesPath\", but that folder isn't readable from here" : '') . '. Those files will be skipped unless you set the attachments folder.';
        }
        foreach ($info['storage'] as $bk => $n) {
            if (!in_array($bk, ['D', 'F'], true)) {
                $state['warnings'][] = "$n attachments are stored in an external storage plugin (\"$bk\", e.g. S3) and can't be copied automatically.";
            }
        }
        self::saveState($state);
        Audit::log('osticket.import.start', 'system', null, ['database' => $conn['name'] ?? '']);
        return $state;
    }

    /** @return array<string,mixed> */
    public static function step(float $seconds = 8.0, bool $resume = false): array
    {
        $state = self::state();
        if ($resume && $state && $state['status'] === 'failed') {
            $state['status'] = 'running';
            $state['error'] = null;
            self::saveState($state);
        }
        if (!$state || $state['status'] !== 'running') {
            return $state ?? [];
        }
        $lock = fopen(App::storage('cache/osticket-import.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $state; // another request is already working
        }
        @set_time_limit(max(60, (int)$seconds * 4));
        @ini_set('memory_limit', '512M');
        try {
            $imp = new self($state, $seconds);
            $state = $imp->run();
        } catch (\Throwable $e) {
            $state = self::state() ?? $state;
            $state['status'] = 'failed';
            $state['error'] = $e->getMessage();
            Log::error('osTicket import failed', $e->getMessage() . "\n" . $e->getTraceAsString());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::saveState($state);
        return $state;
    }

    /** Weighted towards tickets, where nearly all the work is. */
    public static function percent(array $state): int
    {
        $total = 0;
        $done = 0;
        foreach ($state['steps'] ?? [] as $key => $s) {
            $w = $key === 'tickets' ? 20 : 1;
            $t = max(1, (int)$s['total']);
            $total += $t * $w;
            $done += ($s['complete'] ? $t : min($t, (int)$s['done'])) * $w;
        }
        return $total ? (int)floor($done / $total * 100) : 0;
    }

    private function __construct(array $state, float $seconds)
    {
        $this->state = $state;
        $conn = $state['conn'];
        $conn['pass'] = (string)Crypto::decrypt($conn['pass'] ?? '');
        $this->src = self::connect($conn);
        $this->p = self::prefix($conn);
        $this->db = App::db();
        $this->utc = new \DateTimeZone('UTC');
        $this->srcTz = new \DateTimeZone((string)($state['db_timezone'] ?? 'UTC'));
        $this->deadline = microtime(true) + $seconds;
    }

    private function run(): array
    {
        foreach (array_keys(self::STEPS) as $key) {
            if ($this->state['steps'][$key]['complete']) {
                continue;
            }
            $this->state['current'] = $key;
            $method = 'step' . str_replace('_', '', ucwords($key, '_'));
            $finished = $this->$method($this->state['steps'][$key]);
            if ($finished) {
                $this->state['steps'][$key]['complete'] = true;
                $this->state['steps'][$key]['done'] = max($this->state['steps'][$key]['done'], $this->state['steps'][$key]['total']);
            }
            self::saveState($this->state);
            if (!$finished || $this->outOfTime()) {
                return $this->state;
            }
        }
        $this->state['status'] = 'done';
        $this->state['current'] = null;
        $this->state['finished_at'] = DB::now();
        Audit::log('osticket.import.done', 'system', null, ['tickets' => $this->state['steps']['tickets']['done']]);
        return $this->state;
    }

    private function outOfTime(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function batch(array &$step, string $table, string $idCol, callable $fn, int $size = 200, string $where = ''): bool
    {
        if (!self::tableExists($this->src, $this->p . $table)) {
            return true;
        }
        while (true) {
            $rows = $this->src->all(
                "SELECT * FROM `{$this->p}{$table}` WHERE `$idCol` > ?" . ($where !== '' ? " AND ($where)" : '') . " ORDER BY `$idCol` LIMIT $size",
                [(int)$step['cursor']]
            );
            if (!$rows) {
                return true;
            }
            foreach ($rows as $r) {
                $fn($r);
                $step['cursor'] = (int)$r[$idCol];
                $step['done']++;
                if ($this->outOfTime()) {
                    return false;
                }
            }
        }
    }

    private function stepPriorities(array &$step): bool
    {
        return $this->batch($step, 'ticket_priority', 'priority_id', function (array $r) {
            $name = trim((string)($r['priority_desc'] ?: $r['priority']));
            $id = $this->findByName('priorities', $name) ?? $this->db->insert('priorities', [
                'name' => mb_substr($name, 0, 60), 'color' => self::color((string)($r['priority_color'] ?? '')),
                'urgency' => (int)($r['priority_urgency'] ?? 0), 'public' => (int)($r['ispublic'] ?? 1) ? 1 : 0,
            ]);
            $this->setMap('priority', (int)$r['priority_id'], $id);
        });
    }

    private function stepStatuses(array &$step): bool
    {
        return $this->batch($step, 'ticket_status', 'id', function (array $r) {
            $name = trim((string)$r['name']);
            $state = ($r['state'] ?? 'open') === 'open' ? 'open' : 'closed';
            $id = $this->findByName('ticket_statuses', $name);
            if (!$id) {
                $props = json_decode((string)($r['properties'] ?? ''), true) ?: [];
                $id = $this->db->insert('ticket_statuses', [
                    'name' => mb_substr($name, 0, 60), 'state' => $state,
                    'client_name' => mb_substr((string)($props['description'] ?? '') ?: $name, 0, 60), 'sort' => (int)($r['sort'] ?? 0),
                ]);
            }
            $this->setMap('status', (int)$r['id'], $id);
        });
    }

    private function stepSla(array &$step): bool
    {
        return $this->batch($step, 'sla', 'id', function (array $r) {
            $name = trim((string)$r['name']);
            $flags = (int)($r['flags'] ?? 0);
            $active = isset($r['isactive']) ? (int)$r['isactive'] : (isset($r['flags']) ? ($flags & 1 ? 1 : 0) : 1);
            $noAlerts = isset($r['disable_overdue_alerts']) ? (int)$r['disable_overdue_alerts'] : ($flags & 4 ? 1 : 0);
            $id = $this->findByName('sla', $name) ?? $this->db->insert('sla', [
                'name' => mb_substr($name, 0, 120), 'grace_hours' => max(1, (int)$r['grace_period']), 'active' => $active,
                'disable_overdue_alerts' => $noAlerts, 'notes' => self::text($r['notes'] ?? null), 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
            ]);
            $this->setMap('sla', (int)$r['id'], $id);
        });
    }

    private function stepDepartments(array &$step): bool
    {
        return $this->batch($step, 'department', 'id', function (array $r) {
            $name = trim((string)($r['name'] ?? $r['dept_name'] ?? ''));
            $id = $this->findByName('departments', $name);
            if (!$id) {
                $id = $this->db->insert('departments', [
                    'name' => mb_substr($name, 0, 120), 'sla_id' => $this->map('sla', $r['sla_id'] ?? null),
                    'signature' => self::html($r['signature'] ?? $r['dept_signature'] ?? ''), 'public' => (int)($r['ispublic'] ?? 1) ? 1 : 0,
                    'autoresp_enabled' => (int)($r['ticket_auto_response'] ?? 1) ? 1 : 0, 'alert_members' => 1,
                    'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => DB::now(),
                ]);
            }
            $this->setMap('dept', (int)$r['id'], $id);
            if (!empty($r['manager_id'])) {
                $this->state['pending_managers'][$id] = (int)$r['manager_id'];
            }
            if (!empty($r['email_id'])) {
                $this->state['pending_dept_email'][$id] = (int)$r['email_id'];
            }
        }, 200);
    }

    private function stepStaff(array &$step): bool
    {
        $roles = [];
        foreach ($this->db->all('SELECT id, name FROM roles') as $role) {
            $roles[strtolower((string)$role['name'])] = (int)$role['id'];
        }
        $srcRoles = [];
        if (self::tableExists($this->src, $this->p . 'role')) {
            foreach ($this->src->all("SELECT id, name FROM `{$this->p}role`") as $role) {
                $srcRoles[(int)$role['id']] = strtolower(trim((string)$role['name']));
            }
        }
        $fallbackRole = $roles['limited access'] ?? ($roles ? min($roles) : null);
        return $this->batch($step, 'staff', 'staff_id', function (array $r) use ($roles, $srcRoles, $fallbackRole) {
            $email = strtolower(trim((string)$r['email']));
            $existing = $email !== '' ? $this->db->row('SELECT id FROM staff WHERE LOWER(email) = ?', [$email]) : null;
            if ($existing) {
                $this->setMap('staff', (int)$r['staff_id'], (int)$existing['id']);
                return;
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->skip('Agent "' . $r['username'] . '" has no valid email address');
                return;
            }
            $username = $this->uniqueValue('staff', 'username', substr(strtolower(preg_replace('/[^a-z0-9._\-]/i', '', (string)$r['username']) ?: (string)strtok($email, '@')), 0, 60));
            $hash = (string)($r['passwd'] ?? '');
            $id = $this->db->insert('staff', [
                'username' => $username, 'email' => $email,
                'first_name' => mb_substr(trim((string)$r['firstname']), 0, 80), 'last_name' => mb_substr(trim((string)$r['lastname']), 0, 80),
                'phone' => mb_substr(trim((string)($r['phone'] ?? '') ?: (string)($r['mobile'] ?? '')), 0, 40) ?: null,
                'password_hash' => str_starts_with($hash, '$2') ? $hash : null, // osTicket bcrypt hashes work as-is
                'dept_id' => $this->map('dept', $r['dept_id'] ?? null),
                'role_id' => $roles[$srcRoles[(int)($r['role_id'] ?? 0)] ?? ''] ?? $fallbackRole,
                'is_admin' => (int)($r['isadmin'] ?? 0) ? 1 : 0, 'active' => (int)($r['isactive'] ?? 1) ? 1 : 0,
                'onvacation' => (int)($r['onvacation'] ?? 0) ? 1 : 0, 'signature' => self::html($r['signature'] ?? ''),
                'last_login_at' => $this->dt($r['lastlogin'] ?? null), 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => DB::now(),
            ]);
            $this->setMap('staff', (int)$r['staff_id'], $id);
            if (self::tableExists($this->src, $this->p . 'staff_dept_access')) {
                foreach ($this->src->col("SELECT dept_id FROM `{$this->p}staff_dept_access` WHERE staff_id = ?", [(int)$r['staff_id']]) as $d) {
                    if ($nd = $this->map('dept', $d)) {
                        $this->insertIgnore('staff_dept_access', ['staff_id' => $id, 'dept_id' => $nd]);
                    }
                }
            }
        }, 100);
    }

    private function stepTeams(array &$step): bool
    {
        return $this->batch($step, 'team', 'team_id', function (array $r) {
            $name = trim((string)$r['name']);
            $flags = (int)($r['flags'] ?? 1);
            $id = $this->findByName('teams', $name) ?? $this->db->insert('teams', [
                'name' => mb_substr($name, 0, 120), 'lead_id' => $this->map('staff', $r['lead_id'] ?? null),
                'active' => isset($r['isenabled']) ? (int)$r['isenabled'] : ($flags & 1 ? 1 : 0),
                'alert_members' => isset($r['noalerts']) ? ((int)$r['noalerts'] ? 0 : 1) : ($flags & 2 ? 0 : 1),
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
            ]);
            $this->setMap('team', (int)$r['team_id'], $id);
            if (self::tableExists($this->src, $this->p . 'team_member')) {
                foreach ($this->src->col("SELECT staff_id FROM `{$this->p}team_member` WHERE team_id = ?", [(int)$r['team_id']]) as $s) {
                    if ($ns = $this->map('staff', $s)) {
                        $this->insertIgnore('team_members', ['team_id' => $id, 'staff_id' => $ns]);
                    }
                }
            }
        });
    }

    private function stepTopics(array &$step): bool
    {
        // Parents first so child topics can point at them.
        return $this->batch($step, 'help_topic', 'topic_id', function (array $r) {
            $name = trim((string)$r['topic']);
            $parent = $this->map('topic', $r['topic_pid'] ?? null);
            $id = (int)$this->db->val('SELECT id FROM help_topics WHERE LOWER(name) = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL') . ' LIMIT 1', array_merge([mb_strtolower($name)], $parent ? [$parent] : []));
            if (!$id) {
                $flags = (int)($r['flags'] ?? 2);
                $id = $this->db->insert('help_topics', [
                    'name' => mb_substr($name, 0, 120), 'parent_id' => $parent, 'dept_id' => $this->map('dept', $r['dept_id'] ?? null),
                    'priority_id' => $this->map('priority', $r['priority_id'] ?? null), 'sla_id' => $this->map('sla', $r['sla_id'] ?? null),
                    'staff_id' => $this->map('staff', $r['staff_id'] ?? null), 'team_id' => $this->map('team', $r['team_id'] ?? null),
                    'status_id' => $this->map('status', $r['status_id'] ?? null), 'public' => (int)($r['ispublic'] ?? 1) ? 1 : 0,
                    'active' => isset($r['isactive']) ? (int)$r['isactive'] : ($flags & 2 ? 1 : 0),
                    'disable_autoresponse' => (int)($r['noautoresp'] ?? 0) ? 1 : 0, 'sort' => (int)($r['sort'] ?? 0),
                    'notes' => self::text($r['notes'] ?? null), 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
                ]);
            }
            $this->setMap('topic', (int)$r['topic_id'], $id);
            if (!$parent && !empty($r['topic_pid'])) {
                $this->state['pending_topic_parents'][$id] = (int)$r['topic_pid'];
            }
        });
    }

    private function stepOrgs(array &$step): bool
    {
        return $this->batch($step, 'organization', 'id', function (array $r) {
            $name = trim((string)$r['name']);
            $id = $this->findByName('organizations', $name, 190) ?? $this->db->insert('organizations', [
                'name' => mb_substr($name, 0, 190), 'domains' => mb_substr(trim((string)($r['domain'] ?? '')), 0, 255) ?: null,
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => $this->dt($r['updated'] ?? null) ?? DB::now(),
            ]);
            $this->setMap('org', (int)$r['id'], $id);
            $this->fillDetails('organizations', $id, 'O', (int)$r['id'], ['name']);
        }, 300);
    }

    private function stepUsers(array &$step): bool
    {
        $hasAccount = self::tableExists($this->src, $this->p . 'user_account');
        return $this->batch($step, 'user', 'id', function (array $r) use ($hasAccount) {
            $email = strtolower(trim((string)$this->src->val(
                "SELECT address FROM `{$this->p}user_email` WHERE " . (!empty($r['default_email_id']) ? 'id = ?' : 'user_id = ? ORDER BY id') . ' LIMIT 1',
                [!empty($r['default_email_id']) ? (int)$r['default_email_id'] : (int)$r['id']]
            )));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->skip('User "' . $r['name'] . '" has no valid email address');
                return;
            }
            $existing = $this->db->val('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                $this->setMap('user', (int)$r['id'], (int)$existing);
                $this->fillDetails('users', (int)$existing, 'U', (int)$r['id'], ['name', 'email']);
                return;
            }
            $acct = $hasAccount ? $this->src->row("SELECT status, passwd FROM `{$this->p}user_account` WHERE user_id = ? LIMIT 1", [(int)$r['id']]) : null;
            $hash = (string)($acct['passwd'] ?? '');
            $id = $this->db->insert('users', [
                'name' => mb_substr(trim((string)$r['name']) ?: $email, 0, 190), 'email' => $email,
                'org_id' => $this->map('org', $r['org_id'] ?? null),
                'password_hash' => str_starts_with($hash, '$2') ? $hash : null,
                'status' => $acct && ((int)$acct['status'] & 2) ? 'locked' : 'active',
                'verified' => $acct && ((int)$acct['status'] & 1) ? 1 : 0,
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => $this->dt($r['updated'] ?? null) ?? DB::now(),
            ]);
            $this->setMap('user', (int)$r['id'], $id);
            $this->fillDetails('users', $id, 'U', (int)$r['id'], ['name', 'email']);
        }, 300);
    }

    private function stepTickets(array &$step): bool
    {
        $hasCdata = self::tableExists($this->src, $this->p . 'ticket__cdata');
        $defaultDept = (int)($this->db->val('SELECT id FROM departments ORDER BY id LIMIT 1') ?: 0) ?: null;
        $openStatus = (int)($this->db->val("SELECT id FROM ticket_statuses WHERE state = 'open' ORDER BY sort, id LIMIT 1") ?: 0) ?: null;
        return $this->batch($step, 'ticket', 'ticket_id', function (array $r) use ($hasCdata, $defaultDept, $openStatus) {
            if ($tid = $this->map('ticket', (int)$r['ticket_id'])) {
                // Imported on an earlier run: only add custom field values it may be missing.
                if (!$this->db->val('SELECT 1 FROM ticket_field_values WHERE ticket_id = ? LIMIT 1', [$tid])) {
                    $this->importFieldValues((int)$r['ticket_id'], $tid);
                }
                return;
            }
            $this->db->tx(function () use ($r, $hasCdata, $defaultDept, $openStatus) {
                $this->importTicket($r, $hasCdata, $defaultDept, $openStatus);
            });
        }, 25);
    }

    private function importTicket(array $r, bool $hasCdata, ?int $defaultDept, ?int $openStatus): void
    {
        $oldId = (int)$r['ticket_id'];
        $cdata = $hasCdata ? ($this->src->row("SELECT * FROM `{$this->p}ticket__cdata` WHERE ticket_id = ?", [$oldId]) ?? []) : [];
        $subject = trim((string)($cdata['subject'] ?? ''));
        if ($subject === '') {
            $subject = trim((string)$this->formValue('T', $oldId, 'subject')) ?: '(no subject)';
        }
        $prio = $cdata['priority'] ?? $this->formValue('T', $oldId, 'priority');
        $priorityId = is_numeric($prio) ? $this->map('priority', (int)$prio) : null;
        $topicId = $this->map('topic', $r['topic_id'] ?? null);
        if (!$priorityId && $topicId) {
            $priorityId = (int)$this->db->val('SELECT priority_id FROM help_topics WHERE id = ?', [$topicId]) ?: null;
        }
        $userId = $this->map('user', $r['user_id'] ?? null);
        if (!$userId) {
            $this->skip("Ticket #{$r['number']}: its user couldn't be imported");
            return;
        }
        $number = trim((string)$r['number']) !== '' ? (string)$r['number'] : (string)$oldId;
        if ($this->db->val('SELECT id FROM tickets WHERE number = ?', [$number])) {
            $number = $this->uniqueValue('tickets', 'number', 'OT-' . $number);
        }
        $thread = self::tableExists($this->src, $this->p . 'thread')
            ? $this->src->row("SELECT * FROM `{$this->p}thread` WHERE object_type = 'T' AND object_id = ? LIMIT 1", [$oldId])
            : null;
        $statusId = $this->map('status', $r['status_id'] ?? null) ?? $openStatus;
        $source = strtolower(trim((string)($r['source'] ?? 'other')));
        $now = DB::now();
        $ticketId = $this->db->insert('tickets', [
            'number' => mb_substr($number, 0, 32), 'user_id' => $userId,
            'dept_id' => $this->map('dept', $r['dept_id'] ?? null) ?? $defaultDept, 'topic_id' => $topicId,
            'priority_id' => $priorityId, 'status_id' => $statusId, 'sla_id' => $this->map('sla', $r['sla_id'] ?? null),
            'staff_id' => $this->map('staff', $r['staff_id'] ?? null), 'team_id' => $this->map('team', $r['team_id'] ?? null),
            'subject' => mb_substr($subject, 0, 255), 'source' => array_key_exists($source, Tickets::SOURCES) ? $source : 'other',
            'ip' => mb_substr((string)($r['ip_address'] ?? ''), 0, 45) ?: null,
            'is_overdue' => (int)($r['isoverdue'] ?? 0) ? 1 : 0, 'is_answered' => (int)($r['isanswered'] ?? 0) ? 1 : 0,
            'due_at' => $this->dt($r['est_duedate'] ?? null) ?? $this->dt($r['duedate'] ?? null),
            'closed_at' => $this->dt($r['closed'] ?? null),
            'last_message_at' => $this->dt($thread['lastmessage'] ?? null), 'last_response_at' => $this->dt($thread['lastresponse'] ?? null),
            'created_at' => $this->dt($r['created'] ?? null) ?? $now,
            'updated_at' => $this->dt($r['lastupdate'] ?? null) ?? $this->dt($r['updated'] ?? null) ?? $now,
        ]);
        $this->setMap('ticket', $oldId, $ticketId);
        $this->importFieldValues($oldId, $ticketId);

        if ($thread) {
            $this->importThread((int)$thread['id'], $ticketId, null);
            if (self::tableExists($this->src, $this->p . 'thread_collaborator')) {
                foreach ($this->src->all("SELECT * FROM `{$this->p}thread_collaborator` WHERE thread_id = ?", [(int)$thread['id']]) as $c) {
                    if ($cu = $this->map('user', $c['user_id'])) {
                        $active = isset($c['isactive']) ? (int)$c['isactive'] : ((int)($c['flags'] ?? 1) & 1);
                        $this->insertIgnore('ticket_collaborators', ['ticket_id' => $ticketId, 'user_id' => $cu, 'active' => $active ? 1 : 0, 'created_at' => $this->dt($c['created'] ?? null) ?? $now]);
                    }
                }
            }
        }
        $this->db->insert('ticket_events', ['ticket_id' => $ticketId, 'event' => 'imported', 'data' => json_encode(['from' => 'osTicket', 'number' => $r['number']]), 'created_at' => $now]);
    }

    private function importThread(int $threadId, ?int $ticketId, ?int $taskId): string
    {
        $first = '';
        $hasEmail = self::tableExists($this->src, $this->p . 'thread_entry_email');
        $entries = $this->src->all("SELECT * FROM `{$this->p}thread_entry` WHERE thread_id = ? ORDER BY id", [$threadId]);
        foreach ($entries as $e) {
            $body = (string)($e['body'] ?? '');
            $body = ($e['format'] ?? 'html') === 'text' ? Html::fromText($body) : $body;
            $body = Html::clean(Tickets::prepareInlineImages($body));
            if ($first === '') {
                $first = $body;
            }
            if ($taskId) {
                continue;
            }
            $files = $this->attachmentsFor((int)$e['id']);
            $type = in_array($e['type'], ['M', 'R', 'N'], true) ? $e['type'] : 'N';
            $mid = $hasEmail ? $this->src->val("SELECT mid FROM `{$this->p}thread_entry_email` WHERE thread_entry_id = ? LIMIT 1", [(int)$e['id']]) : null;
            $poster = trim((string)($e['poster'] ?? ''));
            $staffId = $this->map('staff', $e['staff_id'] ?? null);
            if ($poster === '' && $staffId) {
                $sr = $this->db->row('SELECT first_name, last_name FROM staff WHERE id = ?', [$staffId]);
                $poster = trim(($sr['first_name'] ?? '') . ' ' . ($sr['last_name'] ?? ''));
            }
            $title = trim((string)($e['title'] ?? ''));
            if ($title !== '' && $type === 'N') {
                $body = '<p><strong>' . e($title) . '</strong></p>' . $body;
            }
            Tickets::addEntry((int)$ticketId, $type, [
                'user_id' => $this->map('user', $e['user_id'] ?? null), 'staff_id' => $staffId,
                'poster' => mb_substr($poster, 0, 190) ?: null, 'body' => $body,
                'source' => mb_substr(strtolower((string)($e['source'] ?? '')), 0, 20) ?: null,
                'message_id' => $mid ? mb_substr(trim((string)$mid, '<> '), 0, 255) : null,
                'ip' => mb_substr((string)($e['ip_address'] ?? ''), 0, 45) ?: null,
                'created_at' => $this->dt($e['created'] ?? null) ?? DB::now(),
            ], $files);
        }
        return $first;
    }

    /** @return list<array<string,mixed>> */
    private function attachmentsFor(int $entryId): array
    {
        if (!self::tableExists($this->src, $this->p . 'attachment')) {
            return [];
        }
        $rows = $this->src->all(
            "SELECT a.name AS att_name, a.inline, f.* FROM `{$this->p}attachment` a JOIN `{$this->p}file` f ON f.id = a.file_id WHERE a.type = 'H' AND a.object_id = ? ORDER BY a.id",
            [$entryId]
        );
        $out = [];
        foreach ($rows as $f) {
            $name = (string)($f['att_name'] ?: $f['name']);
            $content = $this->fileContent($f);
            if ($content === null) {
                $this->skip("Attachment \"$name\" (" . ($f['bk'] === 'F' ? 'file not found on disk' : 'stored in "' . $f['bk'] . '"') . ')');
                continue;
            }
            $out[] = [
                'filename' => $name, 'mime' => (string)($f['type'] ?: 'application/octet-stream'), 'content' => $content,
                'content_id' => (int)$f['inline'] ? (string)$f['key'] : null, 'inline' => (int)$f['inline'] === 1,
            ];
        }
        return $out;
    }

    private function fileContent(array $f): ?string
    {
        if ($f['bk'] === 'D') {
            $chunks = $this->src->col("SELECT filedata FROM `{$this->p}file_chunk` WHERE file_id = ? ORDER BY chunk_id", [(int)$f['id']]);
            return $chunks ? implode('', $chunks) : ((int)$f['size'] === 0 ? '' : null);
        }
        if ($f['bk'] === 'F') {
            $base = (string)($this->state['files_path'] ?? '');
            $key = (string)$f['key'];
            if ($base === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
                return null;
            }
            // osTicket's filesystem plugin uses <base>/<first char of key>/<key>, or <base>/<key> for older files.
            foreach ([$base . '/' . $key[0] . '/' . $key, $base . '/' . $key] as $path) {
                if (is_file($path) && is_readable($path)) {
                    return (string)file_get_contents($path);
                }
            }
        }
        return null;
    }

    private function stepTasks(array &$step): bool
    {
        $hasCdata = self::tableExists($this->src, $this->p . 'task__cdata');
        return $this->batch($step, 'task', 'id', function (array $r) use ($hasCdata) {
            if ($this->map('task', (int)$r['id'])) {
                return;
            }
            $title = $hasCdata ? (string)$this->src->val("SELECT title FROM `{$this->p}task__cdata` WHERE task_id = ?", [(int)$r['id']]) : '';
            $title = trim($title) ?: trim((string)$this->formValue('A', (int)$r['id'], 'title')) ?: 'Task ' . ($r['number'] ?? $r['id']);
            $thread = $this->src->val("SELECT id FROM `{$this->p}thread` WHERE object_type = 'A' AND object_id = ? LIMIT 1", [(int)$r['id']]);
            $desc = $thread ? $this->importThread((int)$thread, null, (int)$r['id']) : '';
            $id = $this->db->insert('tasks', [
                'ticket_id' => ($r['object_type'] ?? '') === 'T' ? $this->map('ticket', $r['object_id'] ?? null) : null,
                'title' => mb_substr($title, 0, 255), 'description' => $desc, 'dept_id' => $this->map('dept', $r['dept_id'] ?? null),
                'staff_id' => $this->map('staff', $r['staff_id'] ?? null), 'status' => ((int)($r['flags'] ?? 0) & 1) ? 'open' : 'closed',
                'due_at' => $this->dt($r['duedate'] ?? null), 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
                'closed_at' => $this->dt($r['closed'] ?? null),
            ]);
            $this->setMap('task', (int)$r['id'], $id);
        }, 50);
    }

    private function stepCanned(array &$step): bool
    {
        return $this->batch($step, 'canned_response', 'canned_id', function (array $r) {
            $title = trim((string)$r['title']);
            if ($this->findByName('canned_responses', $title, 190, 'title')) {
                return;
            }
            $this->db->insert('canned_responses', [
                'title' => mb_substr($title, 0, 190), 'body' => self::html($r['response'] ?? ''), 'dept_id' => $this->map('dept', $r['dept_id'] ?? null),
                'active' => (int)($r['isenabled'] ?? 1) ? 1 : 0, 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => DB::now(),
            ]);
        });
    }

    private function stepKbCategories(array &$step): bool
    {
        return $this->batch($step, 'faq_category', 'category_id', function (array $r) {
            $name = trim((string)$r['name']);
            $id = $this->findByName('kb_categories', $name, 190) ?? $this->db->insert('kb_categories', [
                'name' => mb_substr($name, 0, 190), 'description' => self::html($r['description'] ?? ''),
                'public' => (int)($r['ispublic'] ?? 1) > 0 ? 1 : 0, 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
            ]);
            $this->setMap('kbcat', (int)$r['category_id'], $id);
        });
    }

    private function stepKbArticles(array &$step): bool
    {
        return $this->batch($step, 'faq', 'faq_id', function (array $r) {
            $cat = $this->map('kbcat', $r['category_id'] ?? null);
            $q = trim((string)$r['question']);
            if (!$cat || $this->db->val('SELECT id FROM kb_articles WHERE category_id = ? AND question = ?', [$cat, mb_substr($q, 0, 255)])) {
                return;
            }
            $this->db->insert('kb_articles', [
                'category_id' => $cat, 'question' => mb_substr($q, 0, 255), 'answer' => self::html($r['answer'] ?? ''),
                'keywords' => mb_substr(trim((string)($r['keywords'] ?? '')), 0, 255) ?: null, 'public' => (int)($r['ispublished'] ?? 1) ? 1 : 0,
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => $this->dt($r['updated'] ?? null) ?? DB::now(),
            ]);
        }, 100);
    }

    private function stepFields(array &$step): bool
    {
        $topicForms = [];
        if (self::tableExists($this->src, $this->p . 'help_topic_form')) {
            foreach ($this->src->all("SELECT topic_id, form_id FROM `{$this->p}help_topic_form`") as $tf) {
                $topicForms[(int)$tf['form_id']][] = (int)$tf['topic_id'];
            }
        }
        $forms = [];
        foreach ($this->src->all("SELECT id, type, title FROM `{$this->p}form` WHERE type IN ('T','G')") as $f) {
            $forms[(int)$f['id']] = $f;
        }
        if (!$forms) {
            return true;
        }
        return $this->batch($step, 'form_field', 'id', function (array $r) use ($forms, $topicForms) {
            $form = $forms[(int)$r['form_id']] ?? null;
            $name = trim((string)$r['name']);
            $type = (string)$r['type'];
            if (!$form || in_array($name, ['subject', 'message', 'priority'], true) || in_array($type, ['thread', 'priority', 'info', 'break'], true)) {
                return; // built-in parts of the ticket, or layout-only fields
            }
            $label = trim((string)$r['label']) ?: $name;
            $conf = json_decode((string)($r['configuration'] ?? ''), true) ?: [];
            [$ourType, $options] = $this->fieldType($type, $conf);
            if ($ourType === null) {
                $this->skip("Custom field \"$label\" (osTicket type \"$type\" has no equivalent here)");
                return;
            }
            $flags = (int)($r['flags'] ?? 1);
            $topics = [];
            if ($form['type'] === 'G') {
                foreach ($topicForms[(int)$form['id']] ?? [] as $old) {
                    if ($t = $this->map('topic', $old)) {
                        $topics[] = $t;
                    }
                }
            }
            $existing = $this->db->val('SELECT id FROM custom_fields WHERE LOWER(label) = ? AND type = ?', [mb_strtolower(mb_substr($label, 0, 190)), $ourType]);
            if ($existing) {
                $this->setMap('field', (int)$r['id'], (int)$existing);
                return;
            }
            $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($name !== '' ? $name : $label)), '_') ?: 'field';
            $id = $this->db->insert('custom_fields', [
                'name' => $this->uniqueValue('custom_fields', 'name', substr($slug, 0, 58)), 'label' => mb_substr($label, 0, 190), 'type' => $ourType,
                'options' => $options, 'hint' => mb_substr(trim(strip_tags((string)($r['hint'] ?? ''))), 0, 255) ?: null,
                'required' => ($flags & 0x00400) && $ourType !== 'checkbox' ? 1 : 0, // a required checkbox would force every new ticket to tick it
                'client_visible' => ($flags & 0x00100) ? 1 : 0,
                'topic_ids' => $topics ? implode(',', $topics) : null,
                // A topic-only form that no topic uses anymore: keep its values but don't show the field on new tickets.
                'active' => ($flags & 0x00001) && ($form['type'] === 'T' || $topics) ? 1 : 0,
                'sort' => (int)($r['sort'] ?? 0) + ($form['type'] === 'T' ? 0 : 100),
            ]);
            $this->setMap('field', (int)$r['id'], $id);
        }, 200, "form_id IN (" . implode(',', array_keys($forms)) . ")");
    }

    /** @return array{0:?string,1:?string} */
    private function fieldType(string $type, array $conf): array
    {
        if (preg_match('/^list-(\d+)$/', $type, $m)) {
            $items = self::tableExists($this->src, $this->p . 'list_items')
                ? $this->src->col("SELECT value FROM `{$this->p}list_items` WHERE list_id = ? AND (status & 1) ORDER BY sort, value", [(int)$m[1]]) : [];
            return [!empty($conf['multiselect']) ? 'textarea' : 'select', $items ? implode("\n", array_map(fn($v) => str_replace(["\n", ','], ' ', $v), $items)) : null];
        }
        return match ($type) {
            'text' => [match ($conf['validator'] ?? '') { 'email' => 'email', 'phone' => 'phone', 'number' => 'number', default => 'text' }, null],
            'memo' => ['textarea', null],
            'phone' => ['phone', null],
            'bool' => ['checkbox', null],
            'datetime' => ['date', null],
            'choices' => !empty($conf['multiselect']) ? ['textarea', null] : ['select', implode("\n", array_map(
                fn($line) => str_replace(',', ' ', trim(explode(':', $line, 2)[1] ?? $line)),
                array_filter(array_map('trim', explode("\n", (string)($conf['choices'] ?? ''))))
            ))],
            default => [null, null],
        };
    }

    private function importFieldValues(int $oldTicketId, int $ticketId): void
    {
        foreach ($this->formValues('T', $oldTicketId) as $v) {
            $fid = $this->map('field', $v['field_id']);
            if (!$fid) {
                continue;
            }
            $type = $this->fieldTypes[$fid] ??= (string)$this->db->val('SELECT type FROM custom_fields WHERE id = ?', [$fid]);
            $value = $this->convertValue($type, $v['value']);
            if ($value !== '' && !$this->db->val('SELECT 1 FROM ticket_field_values WHERE ticket_id = ? AND field_id = ?', [$ticketId, $fid])) {
                $this->db->insert('ticket_field_values', ['ticket_id' => $ticketId, 'field_id' => $fid, 'value' => mb_substr($value, 0, 5000)]);
            }
        }
    }

    private function convertValue(string $type, ?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $j = json_decode($raw, true); // choices and lists store {"key":"Label"}
            if (is_array($j)) {
                $raw = implode(', ', array_map('strval', array_values($j)));
            }
        }
        return match ($type) {
            'checkbox' => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'date' => ctype_digit($raw) ? gmdate('Y-m-d', (int)$raw) : (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) ? substr($raw, 0, 10) : ''),
            'phone' => str_replace('X', ' ext. ', $raw),
            default => $raw,
        };
    }

    private function fillDetails(string $table, int $id, string $objectType, int $oldId, array $ignore): void
    {
        $row = $this->db->find($table, $id);
        if (!$row) {
            return;
        }
        $updates = [];
        $extra = [];
        foreach ($this->formValues($objectType, $oldId) as $v) {
            $val = $this->convertValue('text', $v['value']);
            if ($val === '' || in_array($v['name'], $ignore, true)) {
                continue;
            }
            $col = match ($v['name']) { 'phone' => 'phone', 'website' => $table === 'organizations' ? 'website' : null, 'notes' => 'notes', default => null };
            if ($col && array_key_exists($col, $row)) {
                if (trim((string)$row[$col]) === '' && !isset($updates[$col])) {
                    $updates[$col] = $col === 'phone' ? mb_substr(str_replace('X', ' ext. ', $val), 0, 40) : ($col === 'website' ? mb_substr($val, 0, 255) : $val);
                }
                continue;
            }
            $extra[] = $v['label'] . ': ' . $val;
        }
        if ($extra && !str_contains((string)$row['notes'], $extra[0])) {
            $updates['notes'] = trim(trim((string)($updates['notes'] ?? $row['notes'] ?? '')) . "\n" . implode("\n", $extra));
        }
        if ($updates) {
            $this->db->update($table, $updates, 'id = ?', [$id]);
        }
    }

    /** @return list<array{field_id:int,name:string,label:string,value:?string}> */
    private function formValues(string $objectType, int $objectId): array
    {
        if (!self::tableExists($this->src, $this->p . 'form_entry_values')) {
            return [];
        }
        return array_map(fn($r) => ['field_id' => (int)$r['field_id'], 'name' => (string)$r['name'], 'label' => (string)$r['label'], 'value' => $r['value']], $this->src->all(
            "SELECT v.field_id, f.name, f.label, v.value FROM `{$this->p}form_entry` e
             JOIN `{$this->p}form_entry_values` v ON v.entry_id = e.id JOIN `{$this->p}form_field` f ON f.id = v.field_id
             WHERE e.object_type = ? AND e.object_id = ? ORDER BY f.sort, f.id",
            [$objectType, $objectId]
        ));
    }

    /** Imported switched off and without passwords so both systems don't fetch the same mail. */
    private function stepMailboxes(array &$step): bool
    {
        $accounts = self::tableExists($this->src, $this->p . 'email_account');
        return $this->batch($step, 'email', 'email_id', function (array $r) use ($accounts) {
            $email = strtolower(trim((string)$r['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            if ($existing = $this->db->val('SELECT id FROM email_accounts WHERE LOWER(email) = ?', [$email])) {
                $this->setMap('email', (int)$r['email_id'], (int)$existing);
                return;
            }
            if ($accounts) {
                $in = $this->src->row("SELECT * FROM `{$this->p}email_account` WHERE email_id = ? AND type = 'mailbox' LIMIT 1", [(int)$r['email_id']]) ?? [];
                $out = $this->src->row("SELECT * FROM `{$this->p}email_account` WHERE email_id = ? AND type = 'smtp' LIMIT 1", [(int)$r['email_id']]) ?? [];
            } else { // osTicket 1.10 - 1.16 keep the settings on the email row itself
                $in = ['active' => $r['mail_active'] ?? 0, 'host' => $r['mail_host'] ?? '', 'port' => $r['mail_port'] ?? null, 'protocol' => $r['mail_protocol'] ?? '',
                    'encryption' => $r['mail_encryption'] ?? '', 'folder' => $r['mail_folder'] ?? null, 'archivefolder' => $r['mail_archivefolder'] ?? null,
                    'postfetch' => !empty($r['mail_delete']) ? 'delete' : (!empty($r['mail_archivefolder']) ? 'archive' : 'nothing'), 'auth_bk' => ''];
                $out = ['active' => $r['smtp_active'] ?? 0, 'host' => $r['smtp_host'] ?? '', 'port' => $r['smtp_port'] ?? null, 'auth_bk' => ''];
            }
            $hasIn = !empty($in['active']) && trim((string)($in['host'] ?? '')) !== '';
            $hasOut = !empty($out['active']) && trim((string)($out['host'] ?? '')) !== '';
            $isMs = (bool)preg_match('/office365|outlook\.com/i', ($in['host'] ?? '') . ' ' . ($out['host'] ?? ''))
                || str_starts_with((string)($in['auth_bk'] ?? ''), 'oauth2') || str_starts_with((string)($out['auth_bk'] ?? ''), 'oauth2');
            $inPort = (int)($in['port'] ?? 0) ?: null;
            $outPort = (int)($out['port'] ?? 0) ?: null;
            $id = $this->db->insert('email_accounts', [
                'name' => mb_substr(trim((string)$r['name']) ?: $email, 0, 120), 'email' => $email, 'from_name' => mb_substr(trim((string)$r['name']), 0, 190) ?: null,
                'auth_type' => $isMs ? 'oauth_ms' : 'password',
                'inbound_protocol' => $hasIn ? (strtoupper((string)$in['protocol']) === 'POP' ? 'pop3' : 'imap') : 'none',
                'in_host' => $hasIn ? mb_substr((string)$in['host'], 0, 190) : null, 'in_port' => $hasIn ? $inPort : null,
                'in_encryption' => match (strtoupper((string)($in['encryption'] ?? ''))) { 'NONE' => 'none', 'SSL' => 'ssl', default => in_array($inPort, [143, 110], true) ? 'tls' : 'ssl' },
                'in_folder' => mb_substr((string)($in['folder'] ?? '') ?: 'INBOX', 0, 120),
                'in_post_action' => match ((string)($in['postfetch'] ?? 'nothing')) { 'archive' => 'move', 'delete' => 'delete', default => 'seen' },
                'in_archive_folder' => mb_substr((string)($in['archivefolder'] ?? ''), 0, 120) ?: null,
                'outbound_protocol' => $hasOut ? 'smtp' : 'mail',
                'smtp_host' => $hasOut ? mb_substr((string)$out['host'], 0, 190) : null, 'smtp_port' => $hasOut ? $outPort : null,
                'smtp_encryption' => $outPort === 465 ? 'ssl' : 'tls', 'smtp_auth' => 1,
                'dept_id' => $this->map('dept', $r['dept_id'] ?? null), 'priority_id' => $this->map('priority', $r['priority_id'] ?? null),
                'topic_id' => $this->map('topic', $r['topic_id'] ?? null),
                'active' => 0, // never start fetching while osTicket may still be fetching the same mailbox
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(), 'updated_at' => DB::now(),
            ]);
            $this->setMap('email', (int)$r['email_id'], $id);
            $this->state['mailboxes_imported'] = (int)($this->state['mailboxes_imported'] ?? 0) + 1;
        });
    }

    private function stepEvents(array &$step): bool
    {
        if (!self::tableExists($this->src, $this->p . 'thread_event')) {
            return true;
        }
        $names = self::tableExists($this->src, $this->p . 'event')
            ? array_column($this->src->all("SELECT id, name FROM `{$this->p}event`"), 'name', 'id') : [];
        $fieldLabels = null;
        while (true) {
            $rows = $this->src->all(
                "SELECT e.*, t.object_id FROM `{$this->p}thread_event` e JOIN `{$this->p}thread` t ON t.id = e.thread_id
                 WHERE e.id > ? AND t.object_type = 'T' ORDER BY e.id LIMIT 300",
                [(int)$step['cursor']]
            );
            if (!$rows) {
                return true;
            }
            foreach ($rows as $e) {
                $step['cursor'] = (int)$e['id'];
                $step['done']++;
                $ticketId = $this->map('ticket', $e['object_id']);
                if ($ticketId && !(int)($e['annulled'] ?? 0) && !$this->map('event', (int)$e['id'])) {
                    $name = isset($e['event_id']) ? (string)($names[(int)$e['event_id']] ?? '') : (string)($e['state'] ?? '');
                    $data = json_decode((string)($e['data'] ?? ''), true) ?: [];
                    $fieldLabels ??= self::tableExists($this->src, $this->p . 'form_field')
                        ? array_column($this->src->all("SELECT id, label FROM `{$this->p}form_field`"), 'label', 'id') : [];
                    [$event, $ours] = $this->mapEvent($name, $data, $e, $fieldLabels);
                    if ($event !== null) {
                        $staffActor = ($e['uid_type'] ?? 'S') === 'S' ? $this->map('staff', $e['uid'] ?? ($e['staff_id'] ?? null)) : null;
                        $userActor = ($e['uid_type'] ?? '') === 'U' ? $this->map('user', $e['uid'] ?? null) : null;
                        $id = $this->db->insert('ticket_events', [
                            'ticket_id' => $ticketId, 'staff_id' => $staffActor, 'user_id' => $userActor, 'event' => $event,
                            'data' => $ours ? json_encode($ours, JSON_UNESCAPED_UNICODE) : null,
                            'created_at' => $this->dt($e['timestamp'] ?? null) ?? DB::now(),
                        ]);
                        $this->setMap('event', (int)$e['id'], $id);
                    }
                }
                if ($this->outOfTime()) {
                    return false;
                }
            }
        }
    }

    /** @return array{0:?string,1:array<string,mixed>} */
    private function mapEvent(string $name, array $data, array $e, array $fieldLabels): array
    {
        switch ($name) {
            case 'closed':
                return ['closed', ['status' => is_array($data['status'] ?? null) ? (string)($data['status'][1] ?? 'Closed') : 'Closed']];
            case 'reopened':
                return ['reopened', []];
            case 'assigned':
                if (!empty($data['claim'])) {
                    return ['assigned', ['claim' => true, 'staff_id' => $this->map('staff', $e['staff_id'] ?? null)]];
                }
                return ['assigned', array_filter(['staff_id' => $this->map('staff', $data['staff'] ?? ($e['staff_id'] ?? null)), 'team_id' => $this->map('team', $data['team'] ?? null)])];
            case 'released':
                return ['unassigned', []];
            case 'transferred':
                $prev = $this->src->val("SELECT dept_id FROM `{$this->p}thread_event` WHERE thread_id = ? AND id < ? AND dept_id > 0 ORDER BY id DESC LIMIT 1", [(int)$e['thread_id'], (int)$e['id']]);
                return ['transferred', ['from' => $this->map('dept', $prev), 'to' => $this->map('dept', $e['dept_id'] ?? null)]];
            case 'referred':
                return ['referred', []];
            case 'overdue':
                return ['overdue', []];
            case 'edited':
                if (is_array($data['status'] ?? null)) {
                    return ['status', ['status' => (string)($data['status'][1] ?? '')]];
                }
                $what = [];
                foreach ($data['fields'] ?? [] as $fid => $_) {
                    $what[strtolower((string)($fieldLabels[(int)$fid] ?? 'field'))] = 1;
                }
                foreach (array_keys($data) as $k) {
                    if ($k !== 'fields') {
                        $what[str_replace(['_id', 'duedate'], ['', 'due date'], (string)$k)] = 1;
                    }
                }
                return ['edited', $what ?: ['details' => 1]];
            case 'collab':
                $who = fn(array $list) => implode(', ', array_map(fn($c) => is_array($c) ? (string)($c['name'] ?? '') : (string)$c, $list));
                if (!empty($data['add'])) {
                    return ['collaborator_added', ['email' => $who((array)$data['add'])]];
                }
                return !empty($data['del']) ? ['collaborator_removed', []] : [null, []];
            case 'merged':
                return ['merged_tickets', []];
            case 'linked':
            case 'unlinked':
                return [$name, []];
            default:
                return [null, []]; // created, viewed, message, note, login... are already covered or just noise
        }
    }

    private function stepFilters(array &$step): bool
    {
        $fieldNames = self::tableExists($this->src, $this->p . 'form_field')
            ? array_column($this->src->all("SELECT id, name FROM `{$this->p}form_field`"), 'name', 'id') : [];
        return $this->batch($step, 'filter', 'id', function (array $r) use ($fieldNames) {
            $rules = $this->src->all("SELECT * FROM `{$this->p}filter_rule` WHERE filter_id = ? AND isactive = 1 ORDER BY id", [(int)$r['id']]);
            if (strcasecmp(trim((string)$r['name']), 'SYSTEM BAN LIST') === 0) {
                foreach ($rules as $rule) {
                    $pattern = strtolower(trim((string)$rule['val']));
                    if ($rule['what'] === 'email' && $pattern !== '' && !$this->db->val('SELECT 1 FROM banlist WHERE pattern = ?', [$pattern])) {
                        $this->db->insert('banlist', ['pattern' => mb_substr($pattern, 0, 190), 'notes' => 'Imported from osTicket', 'created_at' => DB::now()]);
                        $this->state['banned_imported'] = (int)($this->state['banned_imported'] ?? 0) + 1;
                    }
                }
                return;
            }
            $name = mb_substr(trim((string)$r['name']) ?: 'osTicket filter ' . $r['id'], 0, 120);
            if ($this->findByName('filters', $name)) {
                return;
            }
            $notes = [];
            $ours = [];
            foreach ($rules as $rule) {
                $what = (string)$rule['what'];
                if (preg_match('/^field\.(\d+)$/', $what, $m)) {
                    $what = $fieldNames[(int)$m[1]] ?? $what;
                }
                $field = match ($what) {
                    'email', 'reply-to' => 'email', 'name', 'reply-to-name' => 'name', 'subject' => 'subject', 'message', 'body' => 'body', 'addressee' => 'to',
                    default => null,
                };
                $op = match ((string)$rule['how']) {
                    'equal' => 'equals', 'not_equal' => 'not_equals', 'contains' => 'contains', 'dn_contain' => 'not_contains',
                    'starts' => 'starts', 'ends' => 'ends', 'match' => 'regex', 'not_match' => 'not_regex', default => null,
                };
                if ($field === null || $op === null) {
                    $notes[] = "Rule not imported: \"{$rule['what']}\" {$rule['how']} \"{$rule['val']}\"";
                    continue;
                }
                if ($what === 'addressee') {
                    $notes[] = 'The "To and Cc" rule now only checks the To address.';
                }
                $ours[] = ['field' => $field, 'op' => $op, 'value' => (string)$rule['val']];
            }
            $actions = [];
            foreach ($this->src->all("SELECT * FROM `{$this->p}filter_action` WHERE filter_id = ? ORDER BY sort, id", [(int)$r['id']]) as $a) {
                $c = json_decode((string)($a['configuration'] ?? ''), true) ?: [];
                $set = match ((string)$a['type']) {
                    'reject' => ['reject', true],
                    'noresp' => ['disable_autoresponse', true],
                    'dept' => ['dept_id', $this->map('dept', $c['dept_id'] ?? null)],
                    'pri' => ['priority_id', $this->map('priority', $c['priority'] ?? ($c['priority_id'] ?? null))],
                    'sla' => ['sla_id', $this->map('sla', $c['sla_id'] ?? null)],
                    'team' => ['team_id', $this->map('team', $c['team_id'] ?? null)],
                    'agent' => ['staff_id', $this->map('staff', $c['staff_id'] ?? null)],
                    'topic' => ['topic_id', $this->map('topic', $c['topic_id'] ?? null)],
                    'status' => ['status_id', $this->map('status', $c['status_id'] ?? null)],
                    default => null,
                };
                if ($set === null || $set[1] === null) {
                    $notes[] = 'Action not imported: ' . $a['type'] . ($c ? ' ' . json_encode($c) : '');
                    continue;
                }
                $actions[$set[0]] = $set[1];
            }
            if (!empty($r['email_id'])) {
                $notes[] = 'In osTicket this filter only applied to one mailbox; here it applies to all of them.';
            }
            $this->db->insert('filters', [
                'name' => $name, 'active' => 0, 'sort' => (int)($r['execorder'] ?? 99), 'match_all' => (int)($r['match_all_rules'] ?? 0) ? 1 : 0,
                'stop_on_match' => (int)($r['stop_onmatch'] ?? 0) ? 1 : 0,
                'sources' => match ((string)($r['target'] ?? 'Any')) { 'Web' => 'web', 'Email' => 'email', 'API' => 'api', default => null },
                'rules' => json_encode($ours), 'actions' => json_encode($actions, JSON_FORCE_OBJECT),
                'notes' => trim("Imported from osTicket and switched off. Check it, then turn it on.\n" . implode("\n", $notes) . "\n" . trim((string)($r['notes'] ?? ''))),
                'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
            ]);
            $this->state['filters_imported'] = (int)($this->state['filters_imported'] ?? 0) + 1;
        });
    }

    /** osTicket stores API keys unencrypted, so existing website forms can keep their key. */
    private function stepApikeys(array &$step): bool
    {
        return $this->batch($step, 'api_key', 'id', function (array $r) {
            $key = trim((string)$r['apikey']);
            if ($key === '' || $this->db->val('SELECT 1 FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)])) {
                return;
            }
            $ip = trim((string)($r['ipaddr'] ?? ''));
            $this->db->insert('api_keys', [
                'name' => mb_substr('osTicket key' . (trim((string)($r['notes'] ?? '')) !== '' ? ': ' . trim(strip_tags((string)$r['notes'])) : ($ip !== '' ? " ($ip)" : '')), 0, 120),
                'key_hash' => hash('sha256', $key), 'key_prefix' => substr($key, 0, 12),
                'ip_allow' => filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '0.0.0.0' ? $ip : null,
                'can_create_tickets' => (int)($r['can_create_tickets'] ?? 1) ? 1 : 0, 'can_pipe' => 0,
                'active' => (int)($r['isactive'] ?? 1) ? 1 : 0, 'created_at' => $this->dt($r['created'] ?? null) ?? DB::now(),
            ]);
        });
    }

    private function stepTemplates(array &$step): bool
    {
        if (empty($this->state['options']['templates']) || !self::tableExists($this->src, $this->p . 'email_template')) {
            return true;
        }
        $tpl = (int)($this->src->val("SELECT value FROM `{$this->p}config` WHERE `key` = 'default_template_id' LIMIT 1") ?: 1);
        $known = $this->knownVariables();
        foreach ($this->src->all("SELECT * FROM `{$this->p}email_template` WHERE tpl_id = ? ORDER BY id", [$tpl]) as $t) {
            $code = self::TEMPLATE_MAP[(string)$t['code_name']] ?? null;
            if (!$code) {
                continue;
            }
            $unknown = [];
            $translate = function (string $text) use ($known, &$unknown): string {
                return (string)preg_replace_callback('/%\{\s*([a-zA-Z0-9_.:\-]+)\s*\}/', function ($m) use ($known, &$unknown) {
                    $var = self::VARIABLE_MAP[$m[1]] ?? $m[1];
                    if (!in_array($var, $known, true) && !in_array(explode('.', $var)[0], ['field'], true)) {
                        $unknown[$m[1]] = true;
                    }
                    return '%{' . $var . '}';
                }, $text);
            };
            $subject = $translate((string)$t['subject']);
            $body = $translate((string)$t['body']);
            $data = ['subject' => mb_substr($subject, 0, 255), 'body' => $body, 'updated_at' => DB::now()];
            if ($this->db->val('SELECT 1 FROM email_templates WHERE code = ?', [$code])) {
                $this->db->update('email_templates', $data, 'code = ?', [$code]);
            } else {
                $this->db->insert('email_templates', $data + ['code' => $code]);
            }
            $step['done']++;
            if ($unknown) {
                $list = array_map(fn($v) => '%{' . $v . '}', array_keys($unknown));
                $this->state['warnings'][] = "Email template \"$code\" uses " . implode(', ', $list)
                    . (count($list) === 1 ? ', which has' : ', which have') . ' no equivalent here and will show as blank. Edit it under Admin → Email templates.';
            }
        }
        return true;
    }

    /** @return list<string> */
    private function knownVariables(): array
    {
        $vars = ['message', 'response', 'note', 'signature', 'comments', 'poster.name', 'staff.name', 'otp.code', 'otp.minutes', 'otp.ip', 'task.title', 'task.url', 'task.due'];
        $walk = function (array $node, string $prefix) use (&$walk, &$vars): void {
            foreach ($node as $k => $v) {
                $path = $prefix === '' ? (string)$k : "$prefix.$k";
                is_array($v) ? $walk($v, $path) : $vars[] = $path;
            }
        };
        $sample = $this->db->row('SELECT * FROM tickets ORDER BY id DESC LIMIT 1');
        if ($sample) {
            try {
                $walk(Tickets::context($sample), '');
            } catch (\Throwable) {
            }
        }
        $vars[] = 'recipient.name';
        $vars[] = 'recipient.first_name';
        $vars[] = 'recipient.email';
        $vars[] = 'recipient.ticket_link';
        return array_values(array_unique($vars));
    }

    private function stepFinish(array &$step): bool
    {
        foreach ($this->state['pending_managers'] ?? [] as $deptId => $oldStaff) {
            if ($s = $this->map('staff', $oldStaff)) {
                $this->db->update('departments', ['manager_id' => $s], 'id = ? AND manager_id IS NULL', [(int)$deptId]);
            }
        }
        foreach ($this->state['pending_topic_parents'] ?? [] as $topicId => $oldParent) {
            if ($pid = $this->map('topic', $oldParent)) {
                $this->db->update('help_topics', ['parent_id' => $pid], 'id = ?', [(int)$topicId]);
            }
        }
        foreach ($this->state['pending_dept_email'] ?? [] as $deptId => $oldEmail) {
            if ($m = $this->map('email', $oldEmail)) {
                $this->db->update('departments', ['email_account_id' => $m], 'id = ? AND email_account_id IS NULL', [(int)$deptId]);
            }
        }
        $cfg = fn(string $key) => $this->src->val("SELECT value FROM `{$this->p}config` WHERE `key` = ? ORDER BY namespace = 'core' DESC, id LIMIT 1", [$key]);
        // Ticket number format uses the same "#" = random digit pattern.
        $format = trim((string)$cfg('ticket_number_format'));
        if ($format !== '' && str_contains($format, '#') && mb_strlen($format) <= 32) {
            Settings::set('ticket_number_format', $format);
        }
        if ((int)$cfg('ticket_sequence_id') > 0) {
            $this->state['warnings'][] = 'osTicket numbered tickets in sequence. New tickets here use random numbers in the same format (' . ($format ?: '######') . ').';
        }
        if (!Settings::int('default_email_account_id') && ($m = $this->map('email', $cfg('default_email_id')))) {
            Settings::set('default_email_account_id', (string)$m);
        }
        if (!empty($this->state['mailboxes_imported'])) {
            $n = (int)$this->state['mailboxes_imported'];
            $this->state['warnings'][] = ($n === 1 ? '1 mailbox was' : "$n mailboxes were") . ' copied switched off and without passwords. Under Admin → Mailboxes, add each password (or the Microsoft 365 app details), test it, then switch it on once osTicket has stopped fetching that mailbox.';
        }
        if (!empty($this->state['filters_imported'])) {
            $n = (int)$this->state['filters_imported'];
            $this->state['warnings'][] = ($n === 1 ? '1 filter was' : "$n filters were") . ' copied switched off. Review them under Admin → Filters, then turn them on.';
        }
        if (!empty($this->state['options']['install'])) {
            if ($title = trim((string)$cfg('helpdesk_title'))) {
                Settings::set('helpdesk_name', $title);
            }
            $tz = trim((string)$cfg('default_timezone'));
            if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                Settings::set('timezone', $tz);
            }
            if (self::tableExists($this->src, $this->p . 'form')) {
                $company = $this->src->val("SELECT v.value FROM `{$this->p}form` f JOIN `{$this->p}form_entry` e ON e.form_id = f.id
                    JOIN `{$this->p}form_entry_values` v ON v.entry_id = e.id JOIN `{$this->p}form_field` ff ON ff.id = v.field_id
                    WHERE f.type = 'C' AND ff.name = 'name' LIMIT 1");
                if (trim((string)$company) !== '') {
                    Settings::set('company_name', trim((string)$company));
                }
            }
            $firstDept = (int)$this->db->val('SELECT id FROM departments ORDER BY id LIMIT 1') ?: null;
            $this->db->update('staff', ['dept_id' => $firstDept], 'dept_id IS NULL');
            $role = (int)$this->db->val("SELECT id FROM roles WHERE LOWER(name) = 'all access'") ?: null;
            $this->db->update('staff', ['role_id' => $role], 'role_id IS NULL AND is_admin = 1');
            Installer::seed(); // only fills tables that are still empty
        }
        unset($this->state['pending_managers'], $this->state['pending_topic_parents'], $this->state['pending_dept_email']);
        $step['done'] = 1;
        return true;
    }

    private static function connect(array $c): DB
    {
        try {
            return new DB(['driver' => 'mysql', 'host' => $c['host'] ?? 'localhost', 'port' => (int)($c['port'] ?? 3306), 'socket' => $c['socket'] ?? null,
                'name' => $c['name'] ?? '', 'user' => $c['user'] ?? '', 'pass' => $c['pass'] ?? '']);
        } catch (\PDOException $e) {
            throw new \RuntimeException(__('Couldn\'t connect to the osTicket database: {error}', ['error' => $e->getMessage()]));
        }
    }

    private static function prefix(array $c): string
    {
        $p = (string)($c['prefix'] ?? 'ost_');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $p)) {
            throw new \RuntimeException(__('The table prefix may only contain letters, numbers and underscores.'));
        }
        return $p;
    }

    private static function tableExists(DB $db, string $table): bool
    {
        static $cache = [];
        $k = spl_object_id($db) . ':' . $table;
        return $cache[$k] ??= (bool)$db->val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    }

    /** osTicket stores dates in the MySQL server's own zone; if it hasn't recorded that zone, use the server's current UTC offset. */
    private static function serverOffset(DB $src): string
    {
        try {
            // osTicket's own connections use time_zone = SYSTEM, i.e. the database server's OS time zone.
            $min = $src->val("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', 'SYSTEM'))");
        } catch (\Throwable) {
            $min = null;
        }
        if ($min === null) {
            return 'UTC';
        }
        $min = (int)$min;
        return $min === 0 ? 'UTC' : sprintf('%s%02d:%02d', $min < 0 ? '-' : '+', intdiv(abs($min), 60), abs($min) % 60);
    }

    private function dt(mixed $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v, $this->srcTz))->setTimezone($this->utc)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function map(string $type, mixed $oldId): ?int
    {
        $oldId = (int)$oldId;
        if ($oldId <= 0) {
            return null;
        }
        if (!array_key_exists($oldId, $this->mapCache[$type] ?? [])) {
            $v = $this->db->val('SELECT new_id FROM import_map WHERE source = ? AND type = ? AND old_id = ?', [self::SRC, $type, $oldId]);
            $this->mapCache[$type][$oldId] = $v ? (int)$v : null;
        }
        return $this->mapCache[$type][$oldId];
    }

    private function setMap(string $type, int $oldId, int $newId): void
    {
        if ($this->map($type, $oldId) === null) {
            $this->db->insert('import_map', ['source' => self::SRC, 'type' => $type, 'old_id' => $oldId, 'new_id' => $newId]);
        }
        $this->mapCache[$type][$oldId] = $newId;
    }

    private function findByName(string $table, string $name, int $len = 120, string $col = 'name'): ?int
    {
        $id = $this->db->val("SELECT id FROM $table WHERE LOWER($col) = ? LIMIT 1", [mb_strtolower(mb_substr($name, 0, $len))]);
        return $id ? (int)$id : null;
    }

    private function uniqueValue(string $table, string $col, string $base): string
    {
        $v = $base;
        for ($i = 2; $this->db->val("SELECT 1 FROM $table WHERE $col = ?", [$v]); $i++) {
            $v = $base . '-' . $i;
        }
        return $v;
    }

    private function insertIgnore(string $table, array $data): void
    {
        $where = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys(array_intersect_key($data, array_flip(['ticket_id', 'user_id', 'staff_id', 'dept_id', 'team_id'])))));
        $params = array_values(array_intersect_key($data, array_flip(['ticket_id', 'user_id', 'staff_id', 'dept_id', 'team_id'])));
        if (!$this->db->val("SELECT 1 FROM $table WHERE $where", $params)) {
            $this->db->insert($table, $data);
        }
    }

    private function formValue(string $objectType, int $objectId, string $field): ?string
    {
        if (!self::tableExists($this->src, $this->p . 'form_entry_values')) {
            return null;
        }
        $v = $this->src->val(
            "SELECT v.value FROM `{$this->p}form_entry` e JOIN `{$this->p}form_entry_values` v ON v.entry_id = e.id JOIN `{$this->p}form_field` f ON f.id = v.field_id
             WHERE e.object_type = ? AND e.object_id = ? AND f.name = ? LIMIT 1",
            [$objectType, $objectId, $field]
        );
        if (is_string($v) && str_starts_with($v, '{')) {
            $j = json_decode($v, true); // choice fields store {"id":"label"}
            if (is_array($j)) {
                return (string)array_key_first($j);
            }
        }
        return $v === null ? null : (string)$v;
    }

    private function skip(string $what): void
    {
        $this->state['skipped_count'] = (int)($this->state['skipped_count'] ?? 0) + 1;
        if (count($this->state['skipped']) < 200) {
            $this->state['skipped'][] = $what;
        }
    }

    private static function html(mixed $v): string
    {
        $v = trim((string)$v);
        return $v === '' ? '' : Html::clean($v);
    }

    private static function text(mixed $v): ?string
    {
        $v = trim(strip_tags((string)$v));
        return $v === '' ? null : $v;
    }

    private static function color(string $c): string
    {
        return preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $c) ? $c : '#64748b';
    }
}
