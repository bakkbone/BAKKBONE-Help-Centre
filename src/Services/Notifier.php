<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Mail\Mailer;

final class Notifier
{
    public static function newTicketAutoresponse(array $t, int $entryId): void
    {
        if (!Settings::bool('autoresp_new_ticket')) {
            return;
        }
        $dept = Tickets::lookup('departments', $t['dept_id']);
        if ($dept && !$dept['autoresp_enabled']) {
            return;
        }
        $user = Users::find((int)$t['user_id']);
        if (!$user) {
            return;
        }
        $entry = App::db()->find('thread_entries', $entryId);
        self::toUser($t, $user, 'ticket.autoresponse', ['message' => (string)($entry['body'] ?? '')], [], true, $entry['message_id'] ?? null);
    }

    public static function newMessageAutoresponse(array $t, array $user): void
    {
        self::toUser($t, $user, 'message.autoresponse', [], [], true);
    }

    public static function ticketNotice(array $t, int $entryId, array $staff): void
    {
        $user = Users::find((int)$t['user_id']);
        $entry = App::db()->find('thread_entries', $entryId);
        if (!$user || !$entry) {
            return;
        }
        $atts = App::db()->col('SELECT id FROM attachments WHERE entry_id = ? AND inline = 0', [$entryId]);
        $ctx = ['message' => (string)$entry['body'], 'signature' => Tickets::signature($staff, $t, 'mine')] + Tickets::actorContext($staff);
        self::toUser($t, $user, 'ticket.notice', $ctx, array_map('intval', $atts), false);
    }

    public static function response(array $t, int $entryId, array $staff, string $bodyWithSignature, bool $collaborators, string $messageId): void
    {
        $user = Users::find((int)$t['user_id']);
        $atts = array_map('intval', App::db()->col('SELECT id FROM attachments WHERE entry_id = ? AND inline = 0', [$entryId]));
        // Body already contains the signature; keep %{signature} empty to avoid duplicates.
        $ctx = ['response' => $bodyWithSignature, 'signature' => ''] + Tickets::actorContext($staff);
        $recipients = $user ? [$user] : [];
        if ($collaborators) {
            foreach (Tickets::collaborators((int)$t['id']) as $c) {
                $recipients[] = $c;
            }
        }
        $first = true;
        foreach ($recipients as $r) {
            self::toUser($t, $r, 'ticket.reply', $ctx, $atts, false, null, $first ? $messageId : null);
            $first = false;
        }
    }

    public static function closed(array $t): void
    {
        $user = Users::find((int)$t['user_id']);
        if ($user) {
            self::toUser($t, $user, 'ticket.closed', [], [], true);
        }
    }

    public static function toUser(array $t, array $user, string $template, array $extra = [], array $attachments = [], bool $auto = false, ?string $inReplyTo = null, ?string $messageId = null): void
    {
        if (($user['status'] ?? 'active') === 'locked' || Users::isBanned((string)$user['email'])) {
            return;
        }
        $ctx = Tickets::context($t, $extra + Tickets::recipientContext($t, (string)$user['email'], (string)$user['name'], (int)$user['id']));
        $mail = Templates::render($template, $ctx);
        [$irt, $refs] = self::threadHeaders((int)$t['id'], $inReplyTo);
        $account = $t['email_account_id'] ? EmailAccounts::find((int)$t['email_account_id']) : null;
        if (!$account || !$account['active']) {
            $account = EmailAccounts::forDept($t['dept_id'] ? (int)$t['dept_id'] : null);
        }
        Mailer::send([
            'to' => [['email' => (string)$user['email'], 'name' => (string)$user['name']]],
            'subject' => $mail['subject'],
            'html' => $mail['html'],
            'attachments' => $attachments,
            'message_id' => $messageId ?? Mailer::newMessageId((int)$t['id']),
            'headers' => array_filter(['In-Reply-To' => $irt, 'References' => $refs, 'X-HelpCentre-Ticket' => (string)$t['number']]),
            'auto' => $auto,
            'separator' => true,
        ], $account['id'] ?? null);
    }

    /** @return array{0:?string,1:?string} */
    private static function threadHeaders(int $ticketId, ?string $inReplyTo = null): array
    {
        $ids = App::db()->col("SELECT message_id FROM thread_entries WHERE ticket_id = ? AND message_id IS NOT NULL AND message_id <> '' ORDER BY id", [$ticketId]);
        if (!$ids) {
            return [$inReplyTo ? "<$inReplyTo>" : null, $inReplyTo ? "<$inReplyTo>" : null];
        }
        $last = $inReplyTo ?? end($ids);
        $refs = array_unique(array_merge([reset($ids)], array_slice($ids, -8), $inReplyTo ? [$inReplyTo] : []));
        return ["<$last>", implode(' ', array_map(fn($i) => "<$i>", $refs))];
    }

    public static function newTicketAlert(array $t, int $entryId, ?array $creator): void
    {
        $entry = App::db()->find('thread_entries', $entryId);
        $user = Users::find((int)$t['user_id']);
        self::alert($t, Settings::list('alert_new_ticket'), 'ticket.alert', [
            'message' => (string)($entry['body'] ?? ''),
            'poster' => ['name' => $user['name'] ?? ''],
        ] + Tickets::actorContext($creator), $creator ? (int)$creator['id'] : null);
    }

    public static function newMessageAlert(array $t, int $entryId, array $user): void
    {
        $entry = App::db()->find('thread_entries', $entryId);
        self::alert($t, Settings::list('alert_new_message'), 'message.alert', [
            'message' => (string)($entry['body'] ?? ''),
            'poster' => ['name' => (string)$user['name']],
        ], null, true);
    }

    public static function note(array $t, string $html, array $staff): void
    {
        self::alert($t, Settings::list('alert_note'), 'note.alert', ['note' => $html, 'poster' => ['name' => Staff::name($staff)]] + Tickets::actorContext($staff), (int)$staff['id']);
    }

    public static function assigned(array $t, ?array $actor, string $comment): void
    {
        if (!Settings::bool('alert_assign')) {
            return;
        }
        $targets = $t['staff_id'] ? ['assigned'] : ($t['team_id'] ? ['team'] : []);
        self::alert($t, $targets, 'assign.alert', ['comments' => $comment !== '' ? '<p><em>' . e($comment) . '</em></p>' : ''] + Tickets::actorContext($actor), $actor ? (int)$actor['id'] : null);
    }

    public static function transferred(array $t, ?array $actor, string $comment): void
    {
        self::alert($t, Settings::list('alert_transfer'), 'transfer.alert', ['comments' => $comment !== '' ? '<p><em>' . e($comment) . '</em></p>' : ''] + Tickets::actorContext($actor), $actor ? (int)$actor['id'] : null, true);
    }

    public static function overdue(array $t): void
    {
        self::alert($t, Settings::list('alert_overdue'), 'overdue.alert', [], null, true);
    }

    public static function alert(array $t, array $targets, string $template, array $extra, ?int $excludeStaffId = null, bool $fallbackToDept = false): void
    {
        if (!$targets) {
            return;
        }
        $recipients = [];
        $add = function (?array $s) use (&$recipients, $excludeStaffId) {
            if ($s && $s['active'] && !$s['onvacation'] && (int)$s['id'] !== (int)$excludeStaffId && $s['email']) {
                $recipients[strtolower($s['email'])] = $s;
            }
        };
        $dept = Tickets::lookup('departments', $t['dept_id']);
        $hasAssignee = false;
        foreach ($targets as $target) {
            switch ($target) {
                case 'assigned':
                    if ($t['staff_id']) {
                        $add(Staff::find((int)$t['staff_id']));
                        $hasAssignee = true;
                    } elseif ($t['team_id']) {
                        foreach (Staff::teamMembers((int)$t['team_id']) as $s) {
                            $add($s);
                        }
                        $hasAssignee = true;
                    }
                    break;
                case 'team':
                    if ($t['team_id']) {
                        foreach (Staff::teamMembers((int)$t['team_id']) as $s) {
                            $add($s);
                        }
                    }
                    break;
                case 'dept_members':
                    if ($t['dept_id'] && (!$dept || $dept['alert_members'])) {
                        foreach (Staff::deptMembers((int)$t['dept_id']) as $s) {
                            $add($s);
                        }
                    }
                    break;
                case 'dept_manager':
                    if ($dept && $dept['manager_id']) {
                        $add(Staff::find((int)$dept['manager_id']));
                    }
                    break;
            }
        }
        if ($fallbackToDept && in_array('assigned', $targets, true) && !$hasAssignee && $t['dept_id']) {
            foreach (Staff::deptMembers((int)$t['dept_id']) as $s) {
                $add($s);
            }
        }
        $account = EmailAccounts::alerts();
        foreach ($recipients as $s) {
            $ctx = Tickets::context($t, $extra + Tickets::staffRecipient($s));
            $mail = Templates::render($template, $ctx);
            Mailer::send(['to' => [['email' => $s['email'], 'name' => Staff::name($s)]], 'subject' => $mail['subject'], 'html' => $mail['html'], 'auto' => true, 'message_id' => Mailer::newMessageId((int)$t['id']), 'headers' => ['X-HelpCentre-Ticket' => (string)$t['number']]], $account['id'] ?? null);
        }
        if (in_array('admin', $targets, true)) {
            $admin = trim((string)Settings::get('admin_email'));
            if ($admin !== '' && !isset($recipients[strtolower($admin)])) {
                $ctx = Tickets::context($t, $extra + ['recipient' => ['email' => $admin, 'name' => __('Administrator'), 'first_name' => __('Administrator'), 'ticket_link' => '']]);
                $mail = Templates::render($template, $ctx);
                Mailer::send(['to' => [['email' => $admin, 'name' => __('Administrator')]], 'subject' => $mail['subject'], 'html' => $mail['html'], 'auto' => true], $account['id'] ?? null);
            }
        }
    }

    public static function simple(string $template, string $email, string $name, array $extra = [], ?int $accountId = null): void
    {
        $first = Users::firstName($name);
        $ctx = array_replace_recursive([
            'helpdesk' => ['name' => (string)Settings::get('helpdesk_name'), 'url' => App::url('/'), 'brand_color' => valid_hex_color((string)Settings::get('brand_color'))],
            'company' => ['name' => (string)Settings::get('company_name')],
            'recipient' => ['email' => $email, 'name' => $name, 'first_name' => $first !== '' ? $first : 'there'],
        ], $extra);
        $mail = Templates::render($template, $ctx);
        Mailer::send(['to' => [['email' => $email, 'name' => $name]], 'subject' => $mail['subject'], 'html' => $mail['html'], 'auto' => true], $accountId ?? (EmailAccounts::default()['id'] ?? null));
    }
}
