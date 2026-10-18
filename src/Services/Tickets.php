<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Hooks;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Mail\Mailer;

final class Tickets
{
    public const SOURCES = ['web' => 'Web', 'email' => 'Email', 'phone' => 'Phone', 'api' => 'API', 'other' => 'Other'];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static array $lookupCache = [];

    public static function find(int $id): ?array
    {
        return App::db()->find('tickets', $id);
    }

    public static function byNumber(string $number): ?array
    {
        return App::db()->row('SELECT * FROM tickets WHERE number = ?', [trim($number, " #\t")]);
    }

    public static function lookup(string $table, mixed $id): ?array
    {
        if (!$id) {
            return null;
        }
        if (!isset(self::$lookupCache[$table])) {
            self::$lookupCache[$table] = [];
            foreach (App::db()->all('SELECT * FROM ' . App::db()->q($table)) as $r) {
                self::$lookupCache[$table][(int)$r['id']] = $r;
            }
        }
        return self::$lookupCache[$table][(int)$id] ?? null;
    }

    public static function lookupName(string $table, mixed $id, string $default = ''): string
    {
        $r = self::lookup($table, $id);
        return $r ? (string)$r['name'] : $default;
    }

    public static function clearLookupCache(): void
    {
        self::$lookupCache = [];
    }

    public static function isOpen(array $t): bool
    {
        return (self::lookup('ticket_statuses', $t['status_id'])['state'] ?? 'open') === 'open';
    }

    public static function statusesByState(string $state): array
    {
        return array_map('intval', App::db()->col('SELECT id FROM ticket_statuses WHERE state = ?', [$state]));
    }

    public static function generateNumber(): string
    {
        $format = (string)Settings::get('ticket_number_format') ?: '######';
        if (!str_contains($format, '#')) {
            $format .= '######';
        }
        $db = App::db();
        for ($i = 0; $i < 50; $i++) {
            $num = preg_replace_callback('/#/', fn() => (string)random_int(0, 9), $format) ?? $format;
            // Avoid a leading zero when the format starts with digits
            if (str_starts_with($format, '#') && $num[0] === '0') {
                $num[0] = (string)random_int(1, 9);
            }
            if (!$db->val('SELECT COUNT(*) FROM tickets WHERE number = ?', [$num])) {
                return $num;
            }
            if ($i > 20) {
                $format .= '#';
            }
        }
        return $format . '-' . time();
    }

    public static function context(array $t, array $extra = []): array
    {
        $user = Users::find((int)$t['user_id']);
        $org = $user ? Users::org($user['org_id'] ? (int)$user['org_id'] : null) : null;
        $staff = Staff::find($t['staff_id'] ? (int)$t['staff_id'] : null);
        $team = self::lookupName('teams', $t['team_id']);
        $assigned = $staff ? Staff::name($staff) : $team;
        $fields = [];
        foreach (self::fieldValues((int)$t['id']) as $f) {
            $fields[$f['name']] = $f['display'];
        }
        $ctx = [
            'helpdesk' => [
                'name' => (string)Settings::get('helpdesk_name'),
                'url' => App::url('/'),
                'brand_color' => valid_hex_color((string)Settings::get('brand_color')),
            ],
            'company' => ['name' => (string)Settings::get('company_name')],
            'ticket' => [
                'id' => (int)$t['id'],
                'number' => (string)$t['number'],
                'subject' => (string)$t['subject'],
                'status' => self::lookupName('ticket_statuses', $t['status_id'], __('Open')),
                'state' => self::lookup('ticket_statuses', $t['status_id'])['state'] ?? 'open',
                'priority' => self::lookupName('priorities', $t['priority_id']),
                'priority_id' => $t['priority_id'] ? (int)$t['priority_id'] : null,
                'dept' => self::lookupName('departments', $t['dept_id']),
                'dept_id' => $t['dept_id'] ? (int)$t['dept_id'] : null,
                'topic' => self::lookupName('help_topics', $t['topic_id']),
                'topic_id' => $t['topic_id'] ? (int)$t['topic_id'] : null,
                'sla' => self::lookupName('sla', $t['sla_id']),
                'source' => (string)$t['source'],
                'created' => fmt_dt($t['created_at']),
                'created_iso' => $t['created_at'] ? gmdate('c', strtotime($t['created_at'] . ' UTC')) : null,
                'due' => fmt_dt($t['due_at']),
                'due_iso' => $t['due_at'] ? gmdate('c', strtotime($t['due_at'] . ' UTC')) : null,
                'closed' => fmt_dt($t['closed_at']),
                'assigned' => $assigned !== '' ? $assigned : __('Unassigned'),
                'staff_id' => $t['staff_id'] ? (int)$t['staff_id'] : null,
                'team' => $team,
                'team_id' => $t['team_id'] ? (int)$t['team_id'] : null,
                'overdue' => (bool)$t['is_overdue'],
                'answered' => (bool)$t['is_answered'],
                'ip' => (string)($t['ip'] ?? ''),
                'staff_url' => App::url('/panel/tickets/' . $t['id']),
                'client_url' => App::url('/tickets/' . $t['number']),
                'field' => $fields,
            ],
            'user' => [
                'id' => $user ? (int)$user['id'] : null,
                'name' => $user['name'] ?? '',
                'first_name' => Users::firstName((string)($user['name'] ?? '')),
                'email' => $user['email'] ?? '',
                'phone' => $user['phone'] ?? '',
                'organization' => $org['name'] ?? '',
            ],
        ];
        return Hooks::apply('ticket.context', array_replace_recursive($ctx, $extra), $t);
    }

    public static function recipientContext(array $t, string $email, string $name, ?int $userId = null): array
    {
        $first = Users::firstName($name);
        return ['recipient' => [
            'email' => $email,
            'name' => $name,
            'first_name' => $first !== '' ? $first : 'there',
            'ticket_link' => $userId ? self::guestLink($t, $userId) : App::url('/tickets/' . $t['number']),
        ]];
    }

    public static function staffRecipient(array $s): array
    {
        $first = trim((string)$s['first_name']);
        return ['recipient' => [
            'email' => $s['email'], 'name' => Staff::name($s), 'first_name' => $first !== '' ? $first : Staff::name($s), 'ticket_link' => '',
        ]];
    }

    public static function actorContext(?array $staff): array
    {
        if (!$staff) {
            return ['staff' => ['name' => (string)Settings::get('helpdesk_name'), 'first_name' => '', 'email' => '']];
        }
        return ['staff' => ['id' => (int)$staff['id'], 'name' => Staff::name($staff), 'first_name' => (string)$staff['first_name'], 'email' => (string)$staff['email']]];
    }

    public static function guestLink(array $t, int $userId): string
    {
        $sig = substr(Crypto::hmac('view|' . $t['id'] . '|' . $userId), 0, 24);
        return App::url('/view/' . $t['number'] . '/' . $userId . '-' . $sig);
    }

    public static function verifyGuestToken(array $t, string $token): ?int
    {
        if (!preg_match('/^(\d+)-([0-9a-f]{24})$/', $token, $m)) {
            return null;
        }
        $expected = substr(Crypto::hmac('view|' . $t['id'] . '|' . $m[1]), 0, 24);
        return hash_equals($expected, $m[2]) ? (int)$m[1] : null;
    }

    /** @return array{ok:bool,ticket?:array,entry_id?:int,error?:string,rejected?:bool} */
    public static function create(array $d): array
    {
        $d = Hooks::apply('ticket.before_create', $d);
        if (!is_array($d) || !empty($d['reject'])) {
            $reason = is_array($d) && is_string($d['reject']) ? $d['reject'] : __('This request was refused.');
            Log::info('Ticket rejected by an add-on', $reason);
            return ['ok' => false, 'error' => $reason];
        }
        $db = App::db();
        $source = (string)($d['source'] ?? 'web');
        $creator = $d['creator'] ?? null;

        $user = !empty($d['user_id']) ? Users::find((int)$d['user_id']) : null;
        $email = strtolower(trim((string)($user['email'] ?? $d['email'] ?? '')));
        if (!filter_var(\Bakkbone\HelpCentre\Mail\MimeParser::asciiEmail($email), FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('A valid email address is required.')];
        }
        if (!$creator && Users::isBanned($email)) {
            Log::info('Ticket rejected (banned sender)', $email);
            return ['ok' => false, 'rejected' => true, 'error' => __('Your request could not be accepted.')];
        }
        if ($user && $user['status'] === 'locked' && !$creator) {
            return ['ok' => false, 'rejected' => true, 'error' => __('Your account is locked.')];
        }

        $subject = trim((string)($d['subject'] ?? ''));
        $subject = $subject !== '' ? mb_substr($subject, 0, 250) : __('(no subject)');
        $format = (string)($d['message_format'] ?? 'html');
        $rawMessage = (string)($d['message'] ?? '');
        $message = $format === 'text' ? Html::fromText($rawMessage) : $rawMessage;
        $message = self::prepareInlineImages($message);
        $message = Html::clean($message);

        // Routing precedence: defaults < mailbox < help topic < filters < explicit.
        $r = [
            'dept_id' => (int)Settings::get('default_dept_id') ?: null,
            'priority_id' => (int)Settings::get('default_priority_id') ?: null,
            'sla_id' => null,
            'topic_id' => null,
            'staff_id' => null,
            'team_id' => null,
            'status_id' => (int)Settings::get('default_status_id') ?: null,
        ];
        $disableAutoresponse = false;
        $account = !empty($d['email_account_id']) ? EmailAccounts::find((int)$d['email_account_id']) : null;
        if ($account) {
            foreach (['dept_id', 'priority_id', 'topic_id'] as $k) {
                if (!empty($account[$k])) {
                    $r[$k] = (int)$account[$k];
                }
            }
        }
        $topicId = (int)($d['topic_id'] ?? 0) ?: $r['topic_id'];
        $applyTopic = function (?int $topicId) use (&$r, &$disableAutoresponse) {
            $topic = $topicId ? self::lookup('help_topics', $topicId) : null;
            if (!$topic) {
                return;
            }
            $r['topic_id'] = (int)$topic['id'];
            foreach (['dept_id', 'priority_id', 'sla_id', 'staff_id', 'team_id', 'status_id'] as $k) {
                if (!empty($topic[$k])) {
                    $r[$k] = (int)$topic[$k];
                }
            }
            if ($topic['disable_autoresponse']) {
                $disableAutoresponse = true;
            }
        };
        $applyTopic($topicId);

        $filter = FilterEngine::apply([
            'email' => $email,
            'name' => (string)($d['name'] ?? $user['name'] ?? ''),
            'domain' => substr(strrchr($email, '@') ?: '', 1),
            'subject' => $subject,
            'body' => Html::toText($message),
            'to' => strtolower((string)($d['to_email'] ?? ($account['email'] ?? ''))),
            'cc' => implode(', ', array_column($d['cc'] ?? [], 'email')),
            'topic' => self::lookupName('help_topics', $r['topic_id']),
        ], $source);
        if ($filter['reject'] && !$creator) {
            Log::info('Ticket rejected by filter', "From: $email\nSubject: $subject\nFilter: " . implode(', ', $filter['matched']));
            return ['ok' => false, 'rejected' => true, 'error' => __('Your request could not be accepted.')];
        }
        if (!empty($filter['actions']['topic_id'])) {
            $applyTopic((int)$filter['actions']['topic_id']);
        }
        foreach (['dept_id', 'priority_id', 'sla_id', 'staff_id', 'team_id', 'status_id'] as $k) {
            if (!empty($filter['actions'][$k])) {
                $r[$k] = (int)$filter['actions'][$k];
            }
        }
        if (!empty($filter['actions']['disable_autoresponse'])) {
            $disableAutoresponse = true;
        }
        foreach (['dept_id', 'priority_id', 'sla_id', 'staff_id', 'team_id', 'status_id'] as $k) {
            if (!empty($d[$k])) {
                $r[$k] = (int)$d[$k];
            }
        }
        if (!$r['dept_id'] || !self::lookup('departments', $r['dept_id'])) {
            $r['dept_id'] = (int)$db->val('SELECT id FROM departments ORDER BY id LIMIT 1') ?: null;
        }
        if (!$r['sla_id']) {
            $r['sla_id'] = (int)(self::lookup('departments', $r['dept_id'])['sla_id'] ?? 0) ?: ((int)Settings::get('default_sla_id') ?: null);
        }
        if (!$r['status_id'] || (self::lookup('ticket_statuses', $r['status_id'])['state'] ?? '') !== 'open') {
            $r['status_id'] = (int)Settings::get('default_status_id') ?: (int)$db->val("SELECT id FROM ticket_statuses WHERE state = 'open' ORDER BY sort, id LIMIT 1");
        }

        $user ??= Users::findOrCreate($email, (string)($d['name'] ?? ''), $d['phone'] ?? null);

        $maxOpen = Settings::int('max_open_tickets');
        if ($maxOpen > 0 && !$creator) {
            $openIds = self::statusesByState('open');
            $count = (int)$db->val('SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status_id IN ' . DB::in($openIds), array_merge([(int)$user['id']], $openIds));
            if ($count >= $maxOpen) {
                Log::info('Ticket rejected (open ticket limit)', $email);
                return ['ok' => false, 'error' => "You already have $count open tickets. Please reply to an existing ticket instead."];
            }
        }

        $now = DB::now();
        $due = !empty($d['due_at']) ? (string)$d['due_at'] : Sla::dueAt($r['sla_id']);

        [$ticket, $entryId] = $db->tx(function (DB $db) use ($d, $r, $user, $subject, $message, $source, $now, $due, $account, $creator, $email) {
            $ticketId = $db->insert('tickets', [
                'number' => self::generateNumber(),
                'user_id' => (int)$user['id'],
                'dept_id' => $r['dept_id'],
                'topic_id' => $r['topic_id'],
                'priority_id' => $r['priority_id'],
                'status_id' => $r['status_id'],
                'sla_id' => $r['sla_id'],
                'staff_id' => $r['staff_id'],
                'team_id' => $r['team_id'],
                'subject' => $subject,
                'source' => $source,
                'ip' => $d['ip'] ?? null,
                'email_account_id' => $account['id'] ?? null,
                'is_overdue' => 0,
                'is_answered' => 0,
                'due_at' => $due,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $entryId = self::addEntry($ticketId, 'M', [
                'user_id' => (int)$user['id'],
                'poster' => (string)$user['name'],
                'body' => $message,
                'source' => $source,
                'message_id' => $d['message_id'] ?? null,
                'recipients' => !empty($d['cc']) ? json_encode(['cc' => $d['cc']]) : null,
                'ip' => $d['ip'] ?? null,
                'created_at' => $now,
            ], $d['attachments'] ?? []);

            if (!empty($d['fields']) && is_array($d['fields'])) {
                self::saveFields($ticketId, $d['fields']);
            }
            if (!empty($d['cc']) && Settings::bool('add_cc_collaborators')) {
                foreach ($d['cc'] as $cc) {
                    $ccEmail = strtolower((string)$cc['email']);
                    if ($ccEmail === $email || in_array($ccEmail, EmailAccounts::addresses(), true) || Users::isBanned($ccEmail) || Staff::byEmail($ccEmail)) {
                        continue;
                    }
                    $cu = Users::findOrCreate($ccEmail, (string)($cc['name'] ?? ''));
                    self::addCollaborator($ticketId, (int)$cu['id']);
                }
            }
            self::event($ticketId, 'created', ['source' => $source], $creator ? (int)$creator['id'] : null, $creator ? null : (int)$user['id']);
            if ($r['staff_id'] || $r['team_id']) {
                self::event($ticketId, 'assigned', ['staff_id' => $r['staff_id'], 'team_id' => $r['team_id'], 'auto' => true], $creator ? (int)$creator['id'] : null);
            }
            return [self::find($ticketId), $entryId];
        });

        // Notifications and integrations run outside the transaction.
        try {
            $autorespond = ($d['autorespond'] ?? true) && !$disableAutoresponse && empty($d['auto_generated']);
            if ($creator) {
                if (!empty($d['response'])) {
                    self::postResponse($ticket, $creator, (string)$d['response'], $d['response_attachments'] ?? [], ['notify' => (bool)($d['notify_user'] ?? true), 'signature' => $d['signature'] ?? 'mine', 'is_creation' => true]);
                } elseif (!empty($d['notify_user'])) {
                    Notifier::ticketNotice($ticket, $entryId, $creator);
                }
            } elseif ($autorespond) {
                Notifier::newTicketAutoresponse($ticket, $entryId);
            }
            if ($d['alert'] ?? true) {
                Notifier::newTicketAlert($ticket, $entryId, $creator);
            }
            if ($r['staff_id'] || $r['team_id']) {
                Notifier::assigned($ticket, $creator, '');
            }
        } catch (\Throwable $e) {
            Log::error('Ticket notification failed', $e->getMessage());
        }
        Webhooks::fire('ticket.created', (int)$ticket['id'], ['message' => $message, 'poster' => ['name' => $user['name']]] + self::actorContext($creator));
        if ($creator) {
            Audit::log('ticket.create', 'ticket', (int)$ticket['id'], ['number' => $ticket['number']]);
        }
        return ['ok' => true, 'ticket' => self::find((int)$ticket['id']), 'entry_id' => $entryId];
    }

    /** @param list<array<string,mixed>> $attachments */
    public static function addEntry(int $ticketId, string $type, array $data, array $attachments = []): int
    {
        $db = App::db();
        $entryId = $db->insert('thread_entries', array_merge([
            'ticket_id' => $ticketId,
            'type' => $type,
            'format' => 'html',
            'created_at' => DB::now(),
        ], $data));
        $body = (string)($data['body'] ?? '');
        $changed = false;
        foreach ($attachments as $a) {
            $attId = Attachments::store($a, $ticketId, $entryId);
            if ($attId && !empty($a['content_id'])) {
                $placeholder = self::cidPlaceholder((string)$a['content_id']);
                if (str_contains($body, $placeholder)) {
                    $body = str_replace($placeholder, App::url('/file/' . $attId, ['inline' => 1]), $body);
                    $db->update('attachments', ['inline' => 1], 'id = ?', [$attId]);
                    $changed = true;
                }
            }
        }
        if (str_contains($body, '/__cid__/')) {
            $body = (string)preg_replace('~<img[^>]+/__cid__/[^>]*>~i', '', $body);
            $changed = true;
        }
        if ($changed) {
            $db->update('thread_entries', ['body' => $body], 'id = ?', [$entryId]);
        }
        return $entryId;
    }

    private static function cidPlaceholder(string $cid): string
    {
        return App::url('/__cid__/' . rawurlencode(trim($cid, '<> ')));
    }

    /** Rewrite cid: sources to placeholder URLs so they survive sanitising. */
    public static function prepareInlineImages(string $html): string
    {
        return (string)preg_replace_callback('~(src\s*=\s*["\']?)cid:([^"\'\s>]+)~i', fn($m) => $m[1] . self::cidPlaceholder(rawurldecode($m[2])), $html);
    }

    public static function postMessage(array $t, array $user, string $html, array $attachments = [], array $opts = []): int
    {
        $db = App::db();
        $html = Html::clean(self::prepareInlineImages(($opts['format'] ?? 'html') === 'text' ? Html::fromText($html) : $html));
        $now = DB::now();
        $wasClosed = !self::isOpen($t);

        $entryId = self::addEntry((int)$t['id'], 'M', [
            'user_id' => (int)$user['id'],
            'poster' => (string)$user['name'],
            'body' => $html,
            'source' => $opts['source'] ?? 'web',
            'message_id' => $opts['message_id'] ?? null,
            'recipients' => !empty($opts['cc']) ? json_encode(['cc' => $opts['cc']]) : null,
            'ip' => $opts['ip'] ?? null,
            'created_at' => $now,
        ], $attachments);

        $update = ['last_message_at' => $now, 'is_answered' => 0, 'updated_at' => $now];
        if ($t['is_answered'] || !$t['due_at']) {
            $update['due_at'] = Sla::dueAt($t['sla_id'] ? (int)$t['sla_id'] : null);
            $update['is_overdue'] = 0;
        }
        if ($wasClosed && Settings::bool('reopen_closed')) {
            $update['status_id'] = (int)Settings::get('default_status_id') ?: (int)$db->val("SELECT id FROM ticket_statuses WHERE state='open' ORDER BY sort, id LIMIT 1");
            $update['closed_at'] = null;
            $update['due_at'] = Sla::dueAt($t['sla_id'] ? (int)$t['sla_id'] : null);
            self::event((int)$t['id'], 'reopened', [], null, (int)$user['id']);
        }
        $db->update('tickets', $update, 'id = ?', [$t['id']]);
        $t = self::find((int)$t['id']);

        try {
            if (($opts['alert'] ?? true)) {
                Notifier::newMessageAlert($t, $entryId, $user);
            }
            if (($opts['autorespond'] ?? true) && Settings::bool('autoresp_new_message') && empty($opts['auto_generated'])) {
                Notifier::newMessageAutoresponse($t, $user);
            }
        } catch (\Throwable $e) {
            Log::error('Message notification failed', $e->getMessage());
        }
        Webhooks::fire('ticket.message', (int)$t['id'], ['message' => $html, 'poster' => ['name' => $user['name']]]);
        return $entryId;
    }

    public static function postResponse(array $t, array $staff, string $html, array $attachments = [], array $opts = []): int
    {
        $db = App::db();
        $html = Html::clean(self::prepareInlineImages($html));
        $sig = self::signature($staff, $t, (string)($opts['signature'] ?? 'none'));
        $body = $html . ($sig !== '' ? '<div class="signature">' . $sig . '</div>' : '');
        $now = DB::now();
        $messageId = $opts['message_id'] ?? Mailer::newMessageId((int)$t['id']);

        $entryId = self::addEntry((int)$t['id'], 'R', [
            'staff_id' => (int)$staff['id'],
            'poster' => Staff::name($staff),
            'body' => $body,
            'source' => $opts['source'] ?? 'web',
            'message_id' => $messageId,
            'ip' => $opts['ip'] ?? null,
            'created_at' => $now,
        ], $attachments);

        $update = ['is_answered' => 1, 'is_overdue' => 0, 'last_response_at' => $now, 'updated_at' => $now];
        if (!$t['staff_id'] && !$t['team_id'] && Settings::bool('claim_on_reply')) {
            $update['staff_id'] = (int)$staff['id'];
            self::event((int)$t['id'], 'assigned', ['staff_id' => (int)$staff['id'], 'claim' => true], (int)$staff['id']);
        }
        $db->update('tickets', $update, 'id = ?', [$t['id']]);
        $t = self::find((int)$t['id']);

        if (!empty($opts['status_id']) && (int)$opts['status_id'] !== (int)$t['status_id']) {
            self::setStatus($t, (int)$opts['status_id'], $staff, false);
            $t = self::find((int)$t['id']);
        }

        if ($opts['notify'] ?? true) {
            try {
                Notifier::response($t, $entryId, $staff, $body, (bool)($opts['collaborators'] ?? true), $messageId);
            } catch (\Throwable $e) {
                Log::error('Reply email failed', $e->getMessage());
            }
        }
        Webhooks::fire('ticket.reply', (int)$t['id'], ['message' => $body, 'poster' => ['name' => Staff::name($staff)]] + self::actorContext($staff));
        Audit::log('ticket.reply', 'ticket', (int)$t['id']);
        return $entryId;
    }

    public static function postNote(array $t, array $staff, string $html, array $attachments = [], array $opts = []): int
    {
        $html = Html::clean(self::prepareInlineImages($html));
        $entryId = self::addEntry((int)$t['id'], 'N', [
            'staff_id' => (int)$staff['id'],
            'poster' => Staff::name($staff),
            'body' => $html,
            'source' => $opts['source'] ?? 'web',
            'message_id' => $opts['message_id'] ?? null,
            'created_at' => DB::now(),
        ], $attachments);
        App::db()->update('tickets', ['updated_at' => DB::now()], 'id = ?', [$t['id']]);
        if (!empty($opts['status_id']) && (int)$opts['status_id'] !== (int)$t['status_id']) {
            self::setStatus($t, (int)$opts['status_id'], $staff, false);
        }
        if ($opts['alert'] ?? true) {
            try {
                Notifier::note(self::find((int)$t['id']), $html, $staff);
            } catch (\Throwable $e) {
                Log::error('Note alert failed', $e->getMessage());
            }
        }
        Webhooks::fire('ticket.note', (int)$t['id'], ['message' => $html, 'poster' => ['name' => Staff::name($staff)]] + self::actorContext($staff));
        return $entryId;
    }

    /** $t is null on the new-ticket form, where the department isn't known yet. */
    public static function defaultSignature(array $staff, ?array $t = null): string
    {
        $valid = ['mine', 'dept', 'none'];
        $pref = (string)($staff['default_signature'] ?? '');
        if (!in_array($pref, $valid, true)) {
            $pref = (string)Settings::get('default_signature');
        }
        if (!in_array($pref, $valid, true)) {
            $pref = 'mine';
        }
        if ($pref === 'none') {
            return 'none';
        }
        $filled = fn(?string $h) => trim(html_entity_decode(strip_tags((string)$h, '<img>'), ENT_QUOTES, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") !== '';
        $mine = $filled($staff['signature'] ?? '');
        $dept = $t ? $filled(self::lookup('departments', $t['dept_id'])['signature'] ?? '') : null;
        if ($pref === 'mine') {
            return $mine ? 'mine' : ($dept === false ? 'none' : 'dept');
        }
        if ($dept === false) {
            return $mine ? 'mine' : 'none';
        }
        return 'dept';
    }

    public static function signature(array $staff, array $t, string $which): string
    {
        if ($which === 'mine') {
            return Html::clean((string)$staff['signature'], 'signature');
        }
        if ($which === 'dept') {
            return Html::clean((string)(self::lookup('departments', $t['dept_id'])['signature'] ?? ''), 'signature');
        }
        return '';
    }

    public static function event(int $ticketId, string $event, array $data = [], ?int $staffId = null, ?int $userId = null): void
    {
        App::db()->insert('ticket_events', [
            'ticket_id' => $ticketId,
            'staff_id' => $staffId,
            'user_id' => $userId,
            'event' => $event,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => DB::now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function entries(int $ticketId, bool $includeNotes = true): array
    {
        $rows = App::db()->all(
            'SELECT * FROM thread_entries WHERE ticket_id = ?' . ($includeNotes ? '' : " AND type <> 'N'") . ' ORDER BY created_at, id',
            [$ticketId]
        );
        $atts = Attachments::forEntries(array_map(fn($r) => (int)$r['id'], $rows));
        foreach ($rows as &$r) {
            $r['attachments'] = array_values(array_filter($atts[(int)$r['id']] ?? [], fn($a) => !$a['inline']));
        }
        return $rows;
    }

    public static function assign(array $t, ?int $staffId, ?int $teamId, ?array $actor, string $comment = ''): void
    {
        App::db()->update('tickets', ['staff_id' => $staffId ?: null, 'team_id' => $teamId ?: null, 'updated_at' => DB::now()], 'id = ?', [$t['id']]);
        self::event((int)$t['id'], $staffId || $teamId ? 'assigned' : 'unassigned', ['staff_id' => $staffId, 'team_id' => $teamId, 'comment' => $comment], $actor ? (int)$actor['id'] : null);
        if ($comment !== '' && $actor) {
            self::postNote($t, $actor, Html::fromText($comment), [], ['alert' => false]);
        }
        $t = self::find((int)$t['id']);
        if (($staffId && (!$actor || $staffId !== (int)$actor['id'])) || $teamId) {
            Notifier::assigned($t, $actor, $comment);
        }
        Webhooks::fire('ticket.assigned', (int)$t['id'], self::actorContext($actor));
        Audit::log('ticket.assign', 'ticket', (int)$t['id'], ['staff_id' => $staffId, 'team_id' => $teamId]);
    }

    public static function transfer(array $t, int $deptId, ?array $actor, string $comment = ''): void
    {
        if ($deptId === (int)$t['dept_id']) {
            return;
        }
        App::db()->update('tickets', ['dept_id' => $deptId, 'updated_at' => DB::now()], 'id = ?', [$t['id']]);
        self::event((int)$t['id'], 'transferred', ['from' => (int)$t['dept_id'], 'to' => $deptId, 'comment' => $comment], $actor ? (int)$actor['id'] : null);
        if ($comment !== '' && $actor) {
            self::postNote($t, $actor, Html::fromText($comment), [], ['alert' => false]);
        }
        Notifier::transferred(self::find((int)$t['id']), $actor, $comment);
        Audit::log('ticket.transfer', 'ticket', (int)$t['id'], ['dept_id' => $deptId]);
        Webhooks::fire('ticket.transferred', (int)$t['id'], ['from_dept' => self::lookupName('departments', $t['dept_id']), 'from_dept_id' => (int)$t['dept_id'], 'comments' => $comment] + self::actorContext($actor));
    }

    public static function setStatus(array $t, int $statusId, ?array $actor, bool $notifyUser = false): void
    {
        $status = self::lookup('ticket_statuses', $statusId);
        if (!$status || $statusId === (int)$t['status_id']) {
            return;
        }
        $now = DB::now();
        $update = ['status_id' => $statusId, 'updated_at' => $now];
        if ($status['state'] === 'closed') {
            $update['closed_at'] = $now;
            $update['is_overdue'] = 0;
        } else {
            $update['closed_at'] = null;
            if (!self::isOpen($t)) {
                $update['due_at'] = Sla::dueAt($t['sla_id'] ? (int)$t['sla_id'] : null);
            }
        }
        App::db()->update('tickets', $update, 'id = ?', [$t['id']]);
        self::event((int)$t['id'], $status['state'] === 'closed' ? 'closed' : 'status', ['status_id' => $statusId, 'status' => $status['name']], $actor ? (int)$actor['id'] : null);
        App::db()->delete('ticket_locks', 'ticket_id = ?', [$t['id']]);
        $t = self::find((int)$t['id']);
        if ($notifyUser && $status['state'] === 'closed') {
            Notifier::closed($t);
        }
        Webhooks::fire('ticket.status', (int)$t['id'], self::actorContext($actor));
        if ($status['state'] === 'closed') {
            Webhooks::fire('ticket.closed', (int)$t['id'], self::actorContext($actor));
        }
        Audit::log('ticket.status', 'ticket', (int)$t['id'], ['status' => $status['name']]);
    }

    public static function updateDetails(array $t, array $data, array $actor): void
    {
        $allowed = ['subject', 'priority_id', 'topic_id', 'sla_id', 'due_at', 'source', 'user_id'];
        $update = [];
        $changes = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $v = $data[$k];
            if ((string)$v !== (string)$t[$k]) {
                $update[$k] = $v === '' ? null : $v;
                $changes[$k] = ['from' => $t[$k], 'to' => $v];
            }
        }
        if (isset($update['sla_id']) && !array_key_exists('due_at', $data)) {
            $update['due_at'] = Sla::dueAt((int)$update['sla_id'], strtotime($t['created_at'] . ' UTC'));
        }
        if ($update) {
            $update['updated_at'] = DB::now();
            if (isset($update['due_at'])) {
                $update['is_overdue'] = 0;
            }
            App::db()->update('tickets', $update, 'id = ?', [$t['id']]);
            self::event((int)$t['id'], 'edited', $changes, (int)$actor['id']);
            Audit::log('ticket.edit', 'ticket', (int)$t['id'], $changes);
            if (isset($changes['priority_id'])) {
                Webhooks::fire('ticket.priority', (int)$t['id'], ['from_priority' => self::lookupName('priorities', $t['priority_id'])] + self::actorContext($actor));
            }
            Hooks::fire('ticket.updated', self::find((int)$t['id']), $changes, $actor);
        }
    }

    public static function merge(array $parent, array $childIds, array $actor): int
    {
        $db = App::db();
        $closed = (int)Settings::get('closed_status_id') ?: (int)$db->val("SELECT id FROM ticket_statuses WHERE state='closed' ORDER BY sort DESC LIMIT 1");
        $n = 0;
        foreach ($childIds as $cid) {
            $cid = (int)$cid;
            if ($cid === (int)$parent['id']) {
                continue;
            }
            $child = self::find($cid);
            if (!$child || $child['merged_into']) {
                continue;
            }
            $db->tx(function (DB $db) use ($child, $parent, $closed, $actor) {
                $db->update('thread_entries', ['ticket_id' => $parent['id']], 'ticket_id = ?', [$child['id']]);
                $db->update('attachments', ['ticket_id' => $parent['id']], 'ticket_id = ?', [$child['id']]);
                $db->update('tasks', ['ticket_id' => $parent['id']], 'ticket_id = ?', [$child['id']]);
                if ((int)$child['user_id'] !== (int)$parent['user_id']) {
                    self::addCollaborator((int)$parent['id'], (int)$child['user_id']);
                }
                foreach ($db->col('SELECT user_id FROM ticket_collaborators WHERE ticket_id = ?', [$child['id']]) as $uid) {
                    if ((int)$uid !== (int)$parent['user_id']) {
                        self::addCollaborator((int)$parent['id'], (int)$uid);
                    }
                }
                $db->update('tickets', ['merged_into' => $parent['id'], 'status_id' => $closed, 'closed_at' => DB::now(), 'updated_at' => DB::now()], 'id = ?', [$child['id']]);
                self::event((int)$child['id'], 'merged', ['into' => $parent['number']], (int)$actor['id']);
                self::event((int)$parent['id'], 'merged', ['from' => $child['number']], (int)$actor['id']);
            });
            $n++;
        }
        $db->update('tickets', ['updated_at' => DB::now()], 'id = ?', [$parent['id']]);
        Audit::log('ticket.merge', 'ticket', (int)$parent['id'], ['children' => $childIds]);
        if ($n) {
            Webhooks::fire('ticket.merged', (int)$parent['id'], ['merged_ids' => array_values(array_map('intval', $childIds))] + self::actorContext($actor));
        }
        return $n;
    }

    public static function delete(array $t): void
    {
        Webhooks::fire('ticket.deleted', (int)$t['id']); // Before deleting, so listeners and webhooks still see the ticket.
        $db = App::db();
        $db->tx(function (DB $db) use ($t) {
            Attachments::deleteForTicket((int)$t['id']);
            foreach (['thread_entries', 'ticket_events', 'ticket_collaborators', 'ticket_field_values', 'ticket_locks'] as $tbl) {
                $db->delete($tbl, 'ticket_id = ?', [$t['id']]);
            }
            $db->update('tasks', ['ticket_id' => null], 'ticket_id = ?', [$t['id']]);
            $db->delete('tickets', 'id = ?', [$t['id']]);
        });
        Audit::log('ticket.delete', 'ticket', (int)$t['id'], ['number' => $t['number'], 'subject' => $t['subject']]);
    }

    public static function collaborators(int $ticketId, bool $activeOnly = true): array
    {
        return App::db()->all(
            'SELECT u.*, c.active AS collab_active, c.id AS collab_id FROM ticket_collaborators c JOIN users u ON u.id = c.user_id WHERE c.ticket_id = ?' . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY u.name',
            [$ticketId]
        );
    }

    public static function addCollaborator(int $ticketId, int $userId): void
    {
        $db = App::db();
        $owner = (int)$db->val('SELECT user_id FROM tickets WHERE id = ?', [$ticketId]);
        if ($owner === $userId) {
            return;
        }
        $existing = $db->row('SELECT id, active FROM ticket_collaborators WHERE ticket_id = ? AND user_id = ?', [$ticketId, $userId]);
        if ($existing) {
            if (!$existing['active']) {
                $db->update('ticket_collaborators', ['active' => 1], 'id = ?', [$existing['id']]);
            }
            return;
        }
        $db->insert('ticket_collaborators', ['ticket_id' => $ticketId, 'user_id' => $userId, 'active' => 1, 'created_at' => DB::now()]);
    }

    public static function isParticipant(array $t, int $userId): bool
    {
        if ((int)$t['user_id'] === $userId) {
            return true;
        }
        return (bool)App::db()->val('SELECT COUNT(*) FROM ticket_collaborators WHERE ticket_id = ? AND user_id = ? AND active = 1', [$t['id'], $userId]);
    }

    public static function clientCanView(array $user, array $t): bool
    {
        if ($t['merged_into']) {
            return false;
        }
        if ((int)$t['user_id'] === (int)$user['id']) {
            return true;
        }
        if (Settings::bool('collaborator_ticket_visibility') && self::isParticipant($t, (int)$user['id'])) {
            return true;
        }
        if ($user['org_id']) {
            $org = Users::org((int)$user['org_id']);
            if ($org && $org['sharing']) {
                $ownerOrg = (int)App::db()->val('SELECT org_id FROM users WHERE id = ?', [$t['user_id']]);
                return $ownerOrg === (int)$user['org_id'];
            }
        }
        return false;
    }

    public static function customFields(?int $topicId = null, bool $clientOnly = false): array
    {
        $rows = App::db()->all('SELECT * FROM custom_fields WHERE active = 1' . ($clientOnly ? ' AND client_visible = 1' : '') . ' ORDER BY sort, id');
        return array_values(array_filter($rows, function ($f) use ($topicId) {
            $topics = array_filter(array_map('intval', explode(',', (string)$f['topic_ids'])));
            return !$topics || ($topicId && in_array($topicId, $topics, true));
        }));
    }

    /** @return array{0:array<int,string>,1:list<string>} */
    public static function validateFields(array $fields, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $f) {
            $id = (int)$f['id'];
            $raw = $input[$id] ?? '';
            if (is_array($raw)) {
                $raw = implode(', ', array_map('strval', $raw));
            }
            $v = trim((string)$raw);
            if ($f['type'] === 'checkbox') {
                $v = $v !== '' && $v !== '0' ? '1' : '0';
            }
            if ($f['required'] && ($v === '' || ($f['type'] === 'checkbox' && $v === '0'))) {
                $errors[] = __('{field} is required.', ['field' => $f['label']]);
                continue;
            }
            if ($v !== '') {
                if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = __('{field} must be a valid email address.', ['field' => $f['label']]);
                    continue;
                }
                if ($f['type'] === 'number' && !is_numeric($v)) {
                    $errors[] = __('{field} must be a number.', ['field' => $f['label']]);
                    continue;
                }
                if ($f['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $errors[] = __('{field} must be a date.', ['field' => $f['label']]);
                    continue;
                }
                if (in_array($f['type'], ['select', 'radio'], true) && !in_array($v, self::fieldOptions($f), true)) {
                    $errors[] = __('{field} has an invalid choice.', ['field' => $f['label']]);
                    continue;
                }
            }
            $values[$id] = mb_substr($v, 0, 5000);
        }
        return [$values, $errors];
    }

    public static function fieldOptions(array $f): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', (string)$f['options']) ?: [])));
    }

    /** @param array<int,string> $values */
    public static function saveFields(int $ticketId, array $values): void
    {
        $db = App::db();
        foreach ($values as $fieldId => $v) {
            $db->delete('ticket_field_values', 'ticket_id = ? AND field_id = ?', [$ticketId, (int)$fieldId]);
            if ($v !== '' && $v !== null) {
                $db->insert('ticket_field_values', ['ticket_id' => $ticketId, 'field_id' => (int)$fieldId, 'value' => (string)$v]);
            }
        }
    }

    /** @return list<array{id:int,name:string,label:string,type:string,value:string,display:string,client_visible:int}> */
    public static function fieldValues(int $ticketId): array
    {
        $rows = App::db()->all(
            'SELECT f.id, f.name, f.label, f.type, f.client_visible, v.value FROM ticket_field_values v JOIN custom_fields f ON f.id = v.field_id WHERE v.ticket_id = ? ORDER BY f.sort, f.id',
            [$ticketId]
        );
        foreach ($rows as &$r) {
            $r['display'] = $r['type'] === 'checkbox' ? ($r['value'] === '1' ? __('Yes') : __('No')) : (string)$r['value'];
        }
        return $rows;
    }

    public static function lockedBy(int $ticketId, int $staffId): ?array
    {
        $lock = App::db()->row('SELECT * FROM ticket_locks WHERE ticket_id = ? AND expires_at > ?', [$ticketId, DB::now()]);
        if ($lock && (int)$lock['staff_id'] !== $staffId) {
            return Staff::find((int)$lock['staff_id']);
        }
        return null;
    }

    public static function lock(int $ticketId, int $staffId): bool
    {
        $minutes = Settings::int('lock_minutes');
        if ($minutes <= 0) {
            return true;
        }
        if (self::lockedBy($ticketId, $staffId)) {
            return false;
        }
        $db = App::db();
        $db->delete('ticket_locks', 'ticket_id = ?', [$ticketId]);
        $db->insert('ticket_locks', ['ticket_id' => $ticketId, 'staff_id' => $staffId, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60)]);
        return true;
    }

    public static function unlock(int $ticketId, int $staffId): void
    {
        App::db()->delete('ticket_locks', 'ticket_id = ? AND staff_id = ?', [$ticketId, $staffId]);
    }

    public static function markOverdue(): int
    {
        $db = App::db();
        $open = self::statusesByState('open');
        if (!$open) {
            return 0;
        }
        $rows = $db->all(
            'SELECT * FROM tickets WHERE is_overdue = 0 AND is_answered = 0 AND due_at IS NOT NULL AND due_at < ? AND merged_into IS NULL AND status_id IN ' . DB::in($open) . ' LIMIT 200',
            array_merge([DB::now()], $open)
        );
        foreach ($rows as $t) {
            $db->update('tickets', ['is_overdue' => 1], 'id = ?', [$t['id']]);
            self::event((int)$t['id'], 'overdue');
            $sla = self::lookup('sla', $t['sla_id']);
            if (!$sla || !$sla['disable_overdue_alerts']) {
                try {
                    Notifier::overdue(self::find((int)$t['id']));
                } catch (\Throwable $e) {
                    Log::error('Overdue alert failed', $e->getMessage());
                }
            }
            Webhooks::fire('ticket.overdue', (int)$t['id']);
        }
        return count($rows);
    }

    public static function autoClose(): int
    {
        $days = Settings::int('auto_close_days');
        $status = (int)Settings::get('resolved_status_id');
        if ($days <= 0 || !$status) {
            return 0;
        }
        $open = self::statusesByState('open');
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = App::db()->all(
            'SELECT * FROM tickets WHERE is_answered = 1 AND last_response_at < ? AND (last_message_at IS NULL OR last_message_at < last_response_at) AND status_id IN ' . DB::in($open) . ' LIMIT 200',
            array_merge([$cutoff], $open)
        );
        foreach ($rows as $t) {
            self::setStatus($t, $status, null, true);
            self::event((int)$t['id'], 'auto_closed', ['days' => $days]);
        }
        return count($rows);
    }
}
