<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Settings
{
    public const SECRET = ['captcha_secret'];

    public const DEFAULTS = [
        'helpdesk_name' => 'BAKKBONE Help Centre',
        'locale' => 'en',
        'company_name' => 'BAKKBONE Australia',
        'timezone' => 'Australia/Sydney',
        'date_format' => 'j M Y',
        'time_format' => 'g:i a',
        'admin_email' => '',
        'brand_color' => '#0e7490',
        'logo_url' => '',
        'logo_dark_url' => '',
        'default_signature' => 'mine',
        'footer_text' => '',
        'landing_html' => '<h2>How can we help?</h2><p>Search our knowledge base, open a new support ticket, or check the status of an existing request.</p>',
        'thank_you_html' => '<p>Thanks for getting in touch. We\'ve received your request and emailed you a copy. We\'ll respond as soon as possible.</p>',
        'kb_enabled' => '1',

        'ticket_number_format' => '######',
        'default_dept_id' => '1',
        'default_priority_id' => '2',
        'default_sla_id' => '1',
        'default_status_id' => '1',
        'resolved_status_id' => '3',
        'closed_status_id' => '4',
        'reopen_closed' => '1',
        'auto_close_days' => '0',
        'allow_client_close' => '1',
        'max_open_tickets' => '0',
        'claim_on_reply' => '1',
        'lock_minutes' => '5',
        'staff_email_reply' => '1',
        'add_cc_collaborators' => '1',
        'collaborator_ticket_visibility' => '1',

        'default_email_account_id' => '',
        'alert_email_account_id' => '',
        'accept_unknown_senders' => '1',
        'reply_separator' => '##- Please type your reply above this line -##',
        'autoresp_new_ticket' => '1',
        'autoresp_new_message' => '0',
        'notify_user_staff_ticket' => '1',
        'alert_new_ticket' => 'dept_members,dept_manager,admin',
        'alert_new_message' => 'assigned,dept_manager',
        'alert_note' => 'assigned',
        'alert_assign' => '1',
        'alert_transfer' => 'assigned,dept_manager',
        'alert_overdue' => 'assigned,dept_manager',
        'mail_fetch_max' => '50',

        'attachments_enabled' => '1',
        'client_attachments' => '1',
        'max_file_mb' => '10',
        'max_files' => '5',
        'allowed_ext' => 'jpg,jpeg,png,gif,webp,heic,bmp,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,odt,ods,txt,rtf,zip,7z,rar,eml,msg,mp4,mov,mp3,wav,json,xml,log',

        'client_registration' => 'public',
        'client_require_login' => '0',
        'client_password_login' => '1',
        'client_passwordless' => '1',
        'staff_password_login' => '1',
        'staff_passwordless' => '1',
        'staff_require_2fa' => '0',
        'otp_ttl_minutes' => '10',
        'staff_session_minutes' => '120',
        'client_session_minutes' => '240',
        'login_max_attempts' => '5',
        'login_lockout_minutes' => '15',
        'captcha_provider' => 'none',
        'captcha_site_key' => '',
        'captcha_secret' => '',
        'captcha_on_login' => '0',

        'cron_last_run' => '',
        'schema_version' => '0',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function load(): void
    {
        self::$cache = [];
        try {
            foreach (App::db()->all('SELECT name, value FROM settings') as $r) {
                self::$cache[$r['name']] = (string)$r['value'];
            }
        } catch (\Throwable) {
            self::$cache = [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::load();
        }
        if (array_key_exists($key, self::$cache)) {
            $v = self::$cache[$key];
            return in_array($key, self::SECRET, true) ? Crypto::decrypt($v) : $v;
        }
        return $default ?? match ($key) {
            'landing_html' => '<h2>' . t_html('How can we help?') . '</h2><p>' . t_html('Search our knowledge base, open a new support ticket, or check the status of an existing request.') . '</p>',
            'reply_separator' => __('##- Please type your reply above this line -##'),
            'thank_you_html' => '<p>' . t_html('Thanks for getting in touch. We\'ve received your request and emailed you a copy. We\'ll respond as soon as possible.') . '</p>',
            default => self::DEFAULTS[$key] ?? null,
        };
    }

    public static function bool(string $key): bool
    {
        return (string)self::get($key) === '1';
    }

    public static function int(string $key): int
    {
        return (int)self::get($key);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)self::get($key)))));
    }

    public static function set(string $key, mixed $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        $stored = in_array($key, self::SECRET, true) ? (string)Crypto::encrypt($value) : $value;
        $db = App::db();
        if ($db->val('SELECT COUNT(*) FROM settings WHERE name = ?', [$key])) {
            $db->update('settings', ['value' => $stored], 'name = ?', [$key]);
        } else {
            $db->insert('settings', ['name' => $key, 'value' => $stored]);
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $stored;
        }
    }

    public static function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string)self::get('timezone') ?: 'UTC');
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
