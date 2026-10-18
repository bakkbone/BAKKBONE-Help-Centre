<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;

final class Templates
{
    /** Trusted HTML: never escaped. */
    public const RAW_KEYS = ['message', 'response', 'note', 'signature', 'comments', 'thread'];

    /** @return array<string,array{name:string,group:string,subject:string,body:string}> */
    public static function defaults(): array
    {
        $btn = fn(string $href, string $label) => '<p><a href="' . $href . '" style="display:inline-block;background:%{helpdesk.brand_color};color:#ffffff;text-decoration:none;padding:10px 24px;border-radius:999px;font-weight:500;font-size:14px;line-height:20px;letter-spacing:.1px">' . $label . '</a></p>';
        $side = \Bakkbone\HelpCentre\Core\I18n::dir() === 'rtl' ? 'border-right' : 'border-left';
        $quote = fn(string $tag, string $colour = '#c3c6cf') => '<blockquote style="' . $side . ':3px solid ' . $colour . ';margin:0;padding:4px 12px">' . $tag . '</blockquote>';
        $hi = '<p>' . t_html('Hi %{recipient.first_name},') . '</p>';
        $code = '<p style="font-size:30px;font-weight:700;letter-spacing:6px;margin:18px 0">%{otp.code}</p>';
        $view = fn() => $btn('%{recipient.ticket_link}', t_html('View your ticket'));
        $open = fn() => $btn('%{ticket.staff_url}', t_html('Open ticket'));
        $user = __('End user');
        $auth = __('Authentication');
        $agent = __('Agent');
        return [
            'ticket.autoresponse' => [
                'name' => __('New ticket auto-response'), 'group' => $user,
                'subject' => '[#%{ticket.number}] %{ticket.subject}',
                'body' => $hi . '<p>' . t_html('Thanks for contacting us. A support request has been created and a member of our team will be in touch soon.') . '</p>'
                    . '<p>' . t_html('<strong>Ticket:</strong> #%{ticket.number}') . '<br>' . t_html('<strong>Subject:</strong> %{ticket.subject}') . '<br>' . t_html('<strong>Department:</strong> %{ticket.dept}') . '</p>'
                    . $view() . '<p>' . t_html('You can reply to this email to add more information.') . '</p><p>%{company.name}</p>',
            ],
            'message.autoresponse' => [
                'name' => __('New message auto-response'), 'group' => $user,
                'subject' => __('Re: [#%{ticket.number}] %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('We\'ve received your message on ticket #%{ticket.number} and will get back to you soon.') . '</p>' . $view(),
            ],
            'ticket.notice' => [
                'name' => __('Ticket opened by agent (notice to user)'), 'group' => $user,
                'subject' => '[#%{ticket.number}] %{ticket.subject}',
                'body' => $hi . '<p>' . t_html('We\'ve opened support ticket #%{ticket.number} on your behalf.') . '</p>%{message}<br>%{signature}' . $view(),
            ],
            'ticket.reply' => [
                'name' => __('Agent reply'), 'group' => $user,
                'subject' => __('Re: [#%{ticket.number}] %{ticket.subject}'),
                'body' => '%{response}<br>%{signature}<hr style="border:none;border-top:1px solid #c3c6cf;margin:20px 0">'
                    . '<p style="font-size:13px;color:#43474e">' . t_html('Ticket #%{ticket.number}') . ' &middot; ' . t_html('Status: %{ticket.status}') . ' &middot; <a href="%{recipient.ticket_link}">' . t_html('View online') . '</a></p>',
            ],
            'ticket.closed' => [
                'name' => __('Ticket resolved/closed notice'), 'group' => $user,
                'subject' => __('Resolved: [#%{ticket.number}] %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('Your ticket #%{ticket.number} has been marked as <strong>%{ticket.status}</strong>. If you still need help, simply reply to this email and we\'ll reopen it.') . '</p>' . $view(),
            ],
            'user.otp' => [
                'name' => __('Sign-in code (end user)'), 'group' => $auth,
                'subject' => __('Your %{helpdesk.name} sign-in code: %{otp.code}'),
                'body' => $hi . '<p>' . t_html('Use this code to sign in:') . '</p>' . $code . '<p>' . t_html('It expires in %{otp.minutes} minutes. If you didn\'t request it, you can ignore this email.') . '</p>',
            ],
            'user.verify' => [
                'name' => __('Account verification code (end user)'), 'group' => $auth,
                'subject' => __('Confirm your %{helpdesk.name} account: %{otp.code}'),
                'body' => $hi . '<p>' . t_html('Enter this code to confirm your email address and finish creating your account:') . '</p>' . $code . '<p>' . t_html('It expires in %{otp.minutes} minutes.') . '</p>',
            ],
            'password.reset' => [
                'name' => __('Password reset code'), 'group' => $auth,
                'subject' => __('Reset your %{helpdesk.name} password'),
                'body' => $hi . '<p>' . t_html('Use this code to reset your password:') . '</p>' . $code . '<p>' . t_html('It expires in %{otp.minutes} minutes. If you didn\'t request a reset, you can ignore this email; your password won\'t change.') . '</p>',
            ],
            'staff.otp' => [
                'name' => __('Sign-in code (agent)'), 'group' => $auth,
                'subject' => __('Agent sign-in code: %{otp.code}'),
                'body' => $hi . '<p>' . t_html('Your agent panel sign-in code is:') . '</p>' . $code . '<p>' . t_html('It expires in %{otp.minutes} minutes. Request IP: %{otp.ip}') . '</p>',
            ],
            'ticket.alert' => [
                'name' => __('New ticket alert'), 'group' => $agent,
                'subject' => __('New ticket #%{ticket.number}: %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('A new ticket has been opened.') . '</p><p>' . t_html('<strong>From:</strong> %{user.name} <%{user.email}>') . '<br>' . t_html('<strong>Department:</strong> %{ticket.dept}') . '<br>' . t_html('<strong>Help topic:</strong> %{ticket.topic}') . '<br>' . t_html('<strong>Priority:</strong> %{ticket.priority}') . '</p>'
                    . $quote('%{message}') . $open(),
            ],
            'message.alert' => [
                'name' => __('New message alert'), 'group' => $agent,
                'subject' => __('New message on #%{ticket.number}: %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('%{poster.name} replied to ticket #%{ticket.number}.') . '</p>' . $quote('%{message}') . $open(),
            ],
            'note.alert' => [
                'name' => __('Internal note alert'), 'group' => $agent,
                'subject' => __('Internal note on #%{ticket.number}: %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('%{poster.name} added an internal note to ticket #%{ticket.number}.') . '</p>' . $quote('%{note}', '#f59e0b') . $open(),
            ],
            'assign.alert' => [
                'name' => __('Ticket assigned alert'), 'group' => $agent,
                'subject' => __('Ticket #%{ticket.number} assigned to %{ticket.assigned}'),
                'body' => $hi . '<p>' . t_html('Ticket #%{ticket.number} (%{ticket.subject}) has been assigned to <strong>%{ticket.assigned}</strong> by %{staff.name}.') . '</p>%{comments}' . $open(),
            ],
            'transfer.alert' => [
                'name' => __('Ticket transferred alert'), 'group' => $agent,
                'subject' => __('Ticket #%{ticket.number} transferred to %{ticket.dept}'),
                'body' => $hi . '<p>' . t_html('Ticket #%{ticket.number} (%{ticket.subject}) was transferred to <strong>%{ticket.dept}</strong> by %{staff.name}.') . '</p>%{comments}' . $open(),
            ],
            'overdue.alert' => [
                'name' => __('Overdue ticket alert'), 'group' => $agent,
                'subject' => __('Overdue: #%{ticket.number} %{ticket.subject}'),
                'body' => $hi . '<p>' . t_html('Ticket #%{ticket.number} is now <strong>overdue</strong> (due %{ticket.due}).') . '</p><p>' . t_html('<strong>Department:</strong> %{ticket.dept}') . '<br>' . t_html('<strong>Assigned:</strong> %{ticket.assigned}') . '</p>' . $open(),
            ],
            'task.alert' => [
                'name' => __('Task assigned alert'), 'group' => $agent,
                'subject' => __('Task assigned: %{task.title}'),
                'body' => $hi . '<p>' . t_html('%{staff.name} assigned you a task: <strong>%{task.title}</strong>.') . '</p><p>' . t_html('Due: %{task.due}') . '</p>' . $btn('%{task.url}', t_html('Open task')),
            ],
        ];
    }

    public static function seed(): void
    {
        $db = App::db();
        foreach (self::defaults() as $code => $t) {
            if (!$db->val('SELECT COUNT(*) FROM email_templates WHERE code = ?', [$code])) {
                $db->insert('email_templates', ['code' => $code, 'subject' => $t['subject'], 'body' => $t['body'], 'updated_at' => DB::now()]);
            }
        }
    }

    /** @return array{subject:string,body:string} */
    public static function get(string $code): array
    {
        $row = App::db()->row('SELECT subject, body FROM email_templates WHERE code = ?', [$code]);
        if ($row) {
            return $row;
        }
        $d = self::defaults()[$code] ?? ['subject' => '', 'body' => ''];
        return ['subject' => $d['subject'], 'body' => $d['body']];
    }

    /** @return array{subject:string,html:string} */
    public static function render(string $code, array $context): array
    {
        $t = self::get($code);
        return [
            'subject' => html_entity_decode(strip_tags(self::merge($t['subject'], $context, 'text')), ENT_QUOTES, 'UTF-8'),
            'html' => self::merge($t['body'], $context, 'html'),
        ];
    }

    /** $mode: html escapes (except RAW_KEYS), text doesn't, json escapes for a JSON string, url urlencodes. */
    public static function merge(string $template, array $context, string $mode = 'html'): string
    {
        $template = \Bakkbone\HelpCentre\Core\Html::restoreMergeTags($template);
        return (string)preg_replace_callback('/%\{\s*([a-zA-Z0-9_.\-]+)\s*\}/', function ($m) use ($context, $mode) {
            $path = $m[1];
            $value = self::lookup($context, $path);
            if (is_array($value)) {
                $value = $mode === 'json' ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
                return $mode === 'json' ? substr(json_encode((string)$value), 1, -1) : $value;
            }
            $value = $value === null ? '' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value);
            $root = explode('.', $path)[0];
            return match ($mode) {
                'html' => in_array($root, self::RAW_KEYS, true) ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                'json' => substr((string)json_encode(in_array($root, self::RAW_KEYS, true) ? $value : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1),
                'url' => rawurlencode($value),
                default => in_array($root, self::RAW_KEYS, true) ? \Bakkbone\HelpCentre\Core\Html::toText($value) : $value,
            };
        }, $template);
    }

    public static function lookup(array $context, string $path): mixed
    {
        $node = $context;
        foreach (explode('.', $path) as $part) {
            if (is_array($node) && array_key_exists($part, $node)) {
                $node = $node[$part];
            } else {
                return null;
            }
        }
        return $node;
    }

    public static function variables(): array
    {
        return [
            'Ticket' => [
                'ticket.id', 'ticket.number', 'ticket.subject', 'ticket.status', 'ticket.priority', 'ticket.dept', 'ticket.topic',
                'ticket.source', 'ticket.created', 'ticket.due', 'ticket.closed', 'ticket.assigned', 'ticket.team', 'ticket.sla',
                'ticket.staff_url', 'ticket.client_url', 'ticket.field.<name>',
            ],
            'User (ticket owner)' => ['user.id', 'user.name', 'user.first_name', 'user.email', 'user.phone', 'user.organization'],
            'Recipient' => ['recipient.name', 'recipient.first_name', 'recipient.email', 'recipient.ticket_link'],
            'Agent (actor)' => ['staff.name', 'staff.first_name', 'staff.email', 'signature'],
            'Content' => ['message', 'response', 'note', 'comments', 'poster.name'],
            'Help desk' => ['helpdesk.name', 'helpdesk.url', 'company.name'],
            'Auth emails' => ['otp.code', 'otp.minutes', 'otp.ip'],
        ];
    }
}
