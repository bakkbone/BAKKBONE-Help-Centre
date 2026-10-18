<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;

final class SettingsController extends AdminController
{
    private function tabs(): array
    {
        $db = App::db();
        $opts = fn(string $table, string $order = 'name') => ['' => __('— none —')] + array_column($db->all("SELECT id, name FROM $table ORDER BY $order"), 'name', 'id');
        $statuses = fn(string $state) => array_column($db->all('SELECT id, name FROM ticket_statuses WHERE state = ? ORDER BY sort', [$state]), 'name', 'id');
        $accounts = ['' => __('— none (PHP mail) —')] + array_column($db->all('SELECT id, email FROM email_accounts ORDER BY email'), 'email', 'id');
        $tz = array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers());
        $alertTargets = ['assigned' => __('Assigned agent / team'), 'dept_members' => __('Department members'), 'dept_manager' => __('Department manager'), 'admin' => __('Admin email address')];

        return [
            'general' => ['label' => __('General'), 'fields' => [
                'locale' => [__('Language'), 'select', array_map(fn($l) => $l['native'] !== $l['name'] ? $l['native'] . ' (' . $l['name'] . ')' : $l['name'], \Bakkbone\HelpCentre\Core\I18n::available()), __('The language of the whole help desk: both portals and emails. Add more by putting a language pack in custom/lang/ (see lang/README.md).')],
                'helpdesk_name' => [__('Help desk name'), 'text'],
                'company_name' => [__('Company name'), 'text'],
                'admin_email' => [__('Administrator email'), 'text', __('Receives system alerts when chosen as an alert recipient.')],
                'timezone' => [__('Time zone'), 'select', $tz],
                'date_format' => [__('Date format'), 'text', __('PHP date() format, e.g. j M Y or d/m/Y')],
                'time_format' => [__('Time format'), 'text', __('e.g. g:i a or H:i')],
                'brand_color' => [__('Brand colour'), 'color'],
                'logo_url' => [__('Logo URL'), 'text', __('Optional. An https:// image URL shown in the portal header and agent panel.')],
                'logo_dark_url' => [__('Dark mode logo URL'), 'text', __('Optional. Shown instead of the logo above when dark mode is on, e.g. a white or light version. Leave blank to use the same logo in both modes.')],
                'footer_text' => [__('Footer text'), 'text'],
            ]],
            'tickets' => ['label' => __('Tickets'), 'fields' => [
                'ticket_number_format' => [__('Ticket number format'), 'text', __('# is replaced with a random digit, e.g. ###### or BB-#######')],
                'default_dept_id' => [__('Default department'), 'select', $opts('departments')],
                'default_priority_id' => [__('Default priority'), 'select', $opts('priorities', 'urgency')],
                'default_sla_id' => [__('Default SLA plan'), 'select', $opts('sla')],
                'default_status_id' => [__('Status for new / reopened tickets'), 'select', $statuses('open')],
                'default_signature' => [__('Default signature when replying'), 'select', ['mine' => __('Agent\'s own signature'), 'dept' => __('Department signature'), 'none' => __('No signature')], __('Agents can override this on their profile. If the chosen signature is blank, the other one is used instead.')],
                'resolved_status_id' => [__('"Resolved" status (user close / auto-close)'), 'select', $statuses('closed')],
                'closed_status_id' => [__('"Close" button and merge/ban status'), 'select', $statuses('closed'), __('Used by the Close button on the ticket list, and given to merged tickets and to tickets whose sender is banned.')],
                'reopen_closed' => [__('Reopen closed tickets when the user replies'), 'bool'],
                'auto_close_days' => [__('Auto-resolve answered tickets after N days without a user reply (0 = off)'), 'number'],
                'allow_client_close' => [__('Allow users to resolve their own tickets'), 'bool'],
                'max_open_tickets' => [__('Maximum open tickets per user (0 = unlimited)'), 'number'],
                'claim_on_reply' => [__('Assign unassigned tickets to the agent who replies'), 'bool'],
                'lock_minutes' => [__('Ticket lock duration in minutes (0 = no collision locking)'), 'number'],
                'collaborator_ticket_visibility' => [__('Collaborators (CCs) can see the ticket in the portal'), 'bool'],
                'block_remote_images' => [__('Hide images from other websites in tickets until an agent chooses to show them'), 'bool', __('Images loaded from another website let the sender see when, and from which IP address, an agent opened the ticket.')],
            ]],
            'email' => ['label' => __('Email'), 'fields' => [
                'default_email_account_id' => [__('Default outgoing mailbox'), 'select', $accounts],
                'alert_email_account_id' => [__('Mailbox for agent alerts'), 'select', $accounts],
                'accept_unknown_senders' => [__('Accept new tickets by email from unregistered addresses'), 'bool'],
                'staff_email_reply' => [__('Agents can reply to tickets by email'), 'bool'],
                'add_cc_collaborators' => [__('Add CC\'d addresses as collaborators'), 'bool'],
                'reply_separator' => [__('Reply separator line'), 'text', __('Text above this line in an email reply is kept; quoted history below it is removed.')],
                'autoresp_new_ticket' => [__('Auto-respond to new tickets'), 'bool'],
                'autoresp_new_message' => [__('Auto-respond to new messages'), 'bool'],
                'notify_user_staff_ticket' => [__('Default: notify user when an agent opens a ticket for them'), 'bool'],
                'alert_new_ticket' => [__('New ticket alert recipients'), 'multi', $alertTargets],
                'alert_new_message' => [__('New message alert recipients'), 'multi', $alertTargets],
                'alert_note' => [__('Internal note alert recipients'), 'multi', $alertTargets],
                'alert_assign' => [__('Alert agents when tickets are assigned to them'), 'bool'],
                'alert_transfer' => [__('Transfer alert recipients'), 'multi', $alertTargets],
                'alert_overdue' => [__('Overdue ticket alert recipients'), 'multi', $alertTargets],
                'mail_fetch_max' => [__('Max emails fetched per mailbox per cron run'), 'number'],
            ]],
            'auth' => ['label' => __('Portals & sign-in'), 'fields' => [
                'client_registration' => [__('End-user registration'), 'select', ['public' => __('Anyone can register'), 'disabled' => __('Disabled (users are created by email/agents)')]],
                'client_require_login' => [__('Require sign-in to open tickets in the portal'), 'bool'],
                'client_password_login' => [__('End users: allow password sign-in'), 'bool'],
                'client_passwordless' => [__('End users: allow passwordless sign-in (emailed code)'), 'bool'],
                'staff_password_login' => [__('Agents: allow password sign-in'), 'bool'],
                'staff_passwordless' => [__('Agents: allow passwordless sign-in (emailed code)'), 'bool'],
                'staff_require_2fa' => [__('Agents: require two-factor authentication (TOTP app)'), 'bool'],
                'otp_ttl_minutes' => [__('Emailed code lifetime (minutes)'), 'number'],
                'staff_session_minutes' => [__('Agent idle session timeout (minutes)'), 'number'],
                'client_session_minutes' => [__('End-user idle session timeout (minutes)'), 'number'],
                'login_max_attempts' => [__('Failed attempts before lockout'), 'number'],
                'login_lockout_minutes' => [__('Lockout duration (minutes)'), 'number'],
                'captcha_provider' => [__('CAPTCHA on public forms'), 'select', ['none' => __('None (honeypot only)'), 'turnstile' => __('Cloudflare Turnstile'), 'hcaptcha' => 'hCaptcha', 'recaptcha' => __('Google reCAPTCHA v2')]],
                'captcha_site_key' => [__('CAPTCHA site key'), 'text'],
                'captcha_secret' => [__('CAPTCHA secret key'), 'secret'],
                'captcha_on_login' => [__('Also require CAPTCHA on end-user sign-in'), 'bool'],
            ]],
            'attachments' => ['label' => __('Attachments'), 'fields' => [
                'attachments_enabled' => [__('Enable attachments'), 'bool'],
                'client_attachments' => [__('End users can upload attachments in the portal'), 'bool'],
                'max_file_mb' => [__('Maximum file size (MB)'), 'number', __('Also check PHP upload_max_filesize / post_max_size.')],
                'max_files' => [__('Maximum files per post'), 'number'],
                'allowed_ext' => [__('Allowed file extensions'), 'textarea', __('Comma separated. Use * to allow any (PHP files are always blocked).')],
            ]],
            'pages' => ['label' => __('Portal pages'), 'fields' => [
                'landing_html' => [__('Home page introduction'), 'html'],
                'thank_you_html' => [__('"Ticket submitted" message'), 'html'],
                'kb_enabled' => [__('Show the knowledge base in the portal'), 'bool'],
            ]],
        ];
    }

    public function home(): void
    {
        $this->redirect('/panel/admin/settings/general');
    }

    public function edit(string $tab): void
    {
        $tabs = $this->tabs();
        if (!isset($tabs[$tab])) {
            $this->notFound();
        }
        $this->view('admin/settings', ['title' => __('Settings: {section}', ['section' => $tabs[$tab]['label']]), 'tabs' => $tabs, 'tab' => $tab, 'adminSection' => 'settings']);
    }

    public function save(string $tab): void
    {
        $tabs = $this->tabs();
        if (!isset($tabs[$tab])) {
            $this->notFound();
        }
        foreach ($tabs[$tab]['fields'] as $key => $def) {
            $type = $def[1];
            switch ($type) {
                case 'bool':
                    Settings::set($key, Request::bool($key) ? '1' : '0');
                    break;
                case 'number':
                    Settings::set($key, (string)max(0, Request::int($key)));
                    break;
                case 'multi':
                    $vals = array_intersect((array)Request::post($key, []), array_keys($def[2]));
                    Settings::set($key, implode(',', $vals));
                    break;
                case 'secret':
                    $v = (string)Request::post($key, '');
                    if ($v !== '') {
                        Settings::set($key, $v);
                    }
                    break;
                case 'html':
                    Settings::set($key, Html::clean((string)Request::post($key, ''), 'signature'));
                    break;
                case 'color':
                    Settings::set($key, valid_hex_color(Request::str($key)));
                    break;
                case 'select':
                    $v = Request::str($key);
                    if (array_key_exists($v, $def[2])) {
                        Settings::set($key, $v);
                    }
                    break;
                default:
                    Settings::set($key, Request::str($key));
            }
        }
        if ($tab === 'general') {
            foreach (['logo_url', 'logo_dark_url'] as $k) {
                if (Settings::get($k) && !preg_match('#^https?://#i', (string)Settings::get($k))) {
                    Settings::set($k, '');
                }
            }
        }
        if ($tab === 'auth' && !Settings::bool('staff_password_login') && !Settings::bool('staff_passwordless')) {
            Settings::set('staff_passwordless', '1');
            $this->flash('warning', __('At least one agent sign-in method must stay enabled; passwordless sign-in was kept on.'));
        }
        if ($tab === 'auth' && !Settings::bool('client_password_login') && !Settings::bool('client_passwordless')) {
            Settings::set('client_passwordless', '1');
            $this->flash('warning', __('At least one end-user sign-in method must stay enabled; passwordless sign-in was kept on.'));
        }
        Audit::log('settings.update', 'settings', null, ['tab' => $tab]);
        $this->flash('success', __('Settings saved.'));
        $this->redirect('/panel/admin/settings/' . $tab);
    }
}
