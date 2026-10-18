<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Core\Settings;

final class Installer
{
    /** @return array{config:string,written:bool} */
    public static function install(array $db, array $admin, array $options): array
    {
        $config = [
            'installed' => true,
            'db' => $db,
            'app_key' => Crypto::generateKey(),
            'cron_key' => bin2hex(random_bytes(16)),
            'base_url' => rtrim((string)($options['base_url'] ?? ''), '/'),
            'pretty_urls' => (bool)($options['pretty_urls'] ?? true),
            'debug' => false,
            'trusted_proxies' => [],
        ];
        App::$config = $config;
        $conn = new DB($db);
        App::setDb($conn);

        Schema::sync($conn);
        self::seed($options);
        self::createAdmin($admin);

        $php = self::configFile($config);
        $path = App::configPath();
        $written = (is_writable(dirname($path)) || (is_file($path) && is_writable($path))) && file_put_contents($path, $php, LOCK_EX) !== false;
        if ($written) {
            @chmod($path, 0640);
        }
        App::$installed = true;
        return ['config' => $php, 'written' => $written];
    }

    /** The config is written as not yet installed so the copy can run over several requests. */
    public static function prepareClone(array $db, array $options): void
    {
        $config = [
            'installed' => false,
            'cloning' => true,
            'db' => $db,
            'app_key' => Crypto::generateKey(),
            'cron_key' => bin2hex(random_bytes(16)),
            'base_url' => rtrim((string)($options['base_url'] ?? ''), '/'),
            'pretty_urls' => (bool)($options['pretty_urls'] ?? true),
            'debug' => false,
            'trusted_proxies' => [],
        ];
        App::$config = $config;
        $conn = new DB($db);
        App::setDb($conn);
        Schema::sync($conn);
        if (!self::writeConfig($config)) {
            throw new \RuntimeException(__('Copying needs to save config/config.php as it goes, but the config folder isn\'t writable. Make it writable, or run the copy from the command line (php bin/install.php --from=...).'));
        }
    }

    /** The config stays marked as not yet installed while the import runs over several requests. */
    public static function prepareOsTicketInstall(array $db, array $admin, array $options): int
    {
        $config = [
            'installed' => false,
            'importing' => 'osticket',
            'db' => $db,
            'app_key' => Crypto::generateKey(),
            'cron_key' => bin2hex(random_bytes(16)),
            'base_url' => rtrim((string)($options['base_url'] ?? ''), '/'),
            'pretty_urls' => (bool)($options['pretty_urls'] ?? true),
            'debug' => false,
            'trusted_proxies' => [],
        ];
        App::$config = $config;
        $conn = new DB($db);
        App::setDb($conn);
        Schema::sync($conn);
        self::seed(['lookups' => false, 'admin_email' => $admin['email'], 'locale' => \Bakkbone\HelpCentre\Core\I18n::locale()]);
        $adminId = self::createAdmin($admin);
        if (!self::writeConfig($config)) {
            throw new \RuntimeException(__('Importing needs to save config/config.php as it goes, but the config folder isn\'t writable. Make it writable, or install first and then use Admin → Import.'));
        }
        return $adminId;
    }

    public static function finishClone(): void
    {
        $config = App::$config;
        unset($config['cloning'], $config['importing']);
        $config['installed'] = true;
        App::$config = $config;
        App::$installed = true;
        self::writeConfig($config);
    }

    public static function abandonClone(): void
    {
        $db = App::db();
        foreach (array_keys(Schema::definition()) as $table) {
            $db->pdo->exec('DROP TABLE IF EXISTS ' . $db->q($table));
        }
        @unlink(App::configPath());
    }

    public static function writeConfig(array $config): bool
    {
        $path = App::configPath();
        $written = (is_writable(dirname($path)) || (is_file($path) && is_writable($path))) && file_put_contents($path, self::configFile($config), LOCK_EX) !== false;
        if ($written) {
            @chmod($path, 0640);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        }
        return $written;
    }

    public static function configFile(array $c): string
    {
        $export = self::export($c);
        return "<?php\n/*\n * BAKKBONE Help Centre configuration.\n * Generated " . gmdate('Y-m-d H:i') . " UTC. Keep this file private - it contains secrets.\n"
            . " * IMPORTANT: back up app_key. Without it, stored mailbox passwords cannot be decrypted.\n */\nreturn " . $export . ";\n";
    }

    private static function export(mixed $v, int $level = 0): string
    {
        if (!is_array($v)) {
            return var_export($v, true);
        }
        if ($v === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $level + 1);
        $out = "[\n";
        $list = array_is_list($v);
        foreach ($v as $k => $item) {
            $out .= $pad . ($list ? '' : var_export($k, true) . ' => ') . self::export($item, $level + 1) . ",\n";
        }
        return $out . str_repeat('    ', $level) . ']';
    }

    public static function seed(array $options = []): void
    {
        $db = App::db();
        $now = DB::now();
        // An osTicket import's own departments, topics, statuses etc. replace the starter ones.
        $lookups = $options['lookups'] ?? true;
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM priorities')) {
            foreach ([[__('Low'), '#16a34a', 1], [__('Normal'), '#2563eb', 2], [__('High'), '#d97706', 3], [__('Emergency'), '#dc2626', 4]] as [$n, $c, $u]) {
                $db->insert('priorities', ['name' => $n, 'color' => $c, 'urgency' => $u, 'public' => 1]);
            }
        }
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM ticket_statuses')) {
            foreach ([[__('Open'), 'open', __('Open'), 1], [__('Awaiting customer'), 'open', __('Awaiting your reply'), 2], [__('Resolved'), 'closed', __('Resolved'), 3], [__('Closed'), 'closed', __('Closed'), 4]] as [$n, $s, $cn, $o]) {
                $db->insert('ticket_statuses', ['name' => $n, 'state' => $s, 'client_name' => $cn, 'sort' => $o]);
            }
        }
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM sla')) {
            $db->insert('sla', ['name' => __('Default (24 hours)'), 'grace_hours' => 24, 'active' => 1, 'created_at' => $now]);
            $db->insert('sla', ['name' => __('Priority (4 hours)'), 'grace_hours' => 4, 'active' => 1, 'created_at' => $now]);
        }
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM departments')) {
            $db->insert('departments', ['name' => __('Support'), 'sla_id' => 1, 'public' => 1, 'autoresp_enabled' => 1, 'alert_members' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $db->insert('departments', ['name' => __('Accounts & Billing'), 'sla_id' => 1, 'public' => 1, 'autoresp_enabled' => 1, 'alert_members' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM help_topics')) {
            $topics = [
                [__('General enquiry'), 1, 2, 1], [__('Technical support'), 1, 2, 2], [__('Website change request'), 1, 2, 3], [__('Billing & invoices'), 2, 2, 4],
            ];
            foreach ($topics as [$n, $d, $p, $s]) {
                $db->insert('help_topics', ['name' => $n, 'dept_id' => $d, 'priority_id' => $p, 'public' => 1, 'active' => 1, 'sort' => $s, 'created_at' => $now]);
            }
        }
        if (!(int)$db->val('SELECT COUNT(*) FROM roles')) {
            $all = array_keys(Staff::PERMISSIONS);
            $db->insert('roles', ['name' => __('All access'), 'permissions' => json_encode($all), 'created_at' => $now]);
            $db->insert('roles', ['name' => __('Expanded access'), 'permissions' => json_encode(array_values(array_diff($all, ['ticket.delete', 'user.delete', 'ban.manage']))), 'created_at' => $now]);
            $db->insert('roles', ['name' => __('Limited access'), 'permissions' => json_encode(['ticket.create', 'ticket.reply', 'ticket.assign', 'ticket.transfer', 'ticket.close', 'task.manage']), 'created_at' => $now]);
            $db->insert('roles', ['name' => __('View only'), 'permissions' => json_encode([]), 'created_at' => $now]);
        }
        if ($lookups && !(int)$db->val('SELECT COUNT(*) FROM kb_categories')) {
            $cat = $db->insert('kb_categories', ['name' => __('Getting started'), 'description' => __('Using the help centre'), 'public' => 1, 'sort' => 1, 'created_at' => $now]);
            $db->insert('kb_articles', [
                'category_id' => $cat, 'question' => __('How do I check on my support request?'), 'public' => 1, 'keywords' => __('status, ticket, track'),
                'answer' => '<p>' . t_html('Sign in with your email address (we can email you a one-time code, no password needed) and choose <strong>My tickets</strong>. You can also simply reply to any email we send you about your request.') . '</p>',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Templates::seed();
        foreach (['helpdesk_name', 'company_name', 'timezone', 'locale'] as $k) {
            if (!empty($options[$k])) {
                Settings::set($k, (string)$options[$k]);
            }
        }
        if (!empty($options['admin_email'])) {
            Settings::set('admin_email', (string)$options['admin_email']);
        }
        Settings::set('schema_version', (string)HC_SCHEMA_VERSION);
    }

    public static function createAdmin(array $a): int
    {
        $db = App::db();
        $existing = $db->row('SELECT id FROM staff WHERE LOWER(username) = ? OR LOWER(email) = ?', [strtolower($a['username']), strtolower($a['email'])]);
        if ($existing) {
            return (int)$existing['id'];
        }
        $id = $db->insert('staff', [
            'username' => $a['username'],
            'email' => strtolower($a['email']),
            'first_name' => $a['first_name'],
            'last_name' => $a['last_name'],
            'password_hash' => $a['password'] !== '' ? Auth::hash($a['password']) : null,
            'dept_id' => (int)$db->val('SELECT id FROM departments ORDER BY id LIMIT 1') ?: null,
            'role_id' => (int)$db->val('SELECT id FROM roles ORDER BY id LIMIT 1') ?: null,
            'is_admin' => 1,
            'active' => 1,
            'signature' => '<p>' . t_html('Kind regards,') . '<br><strong>' . e($a['first_name'] . ' ' . $a['last_name']) . '</strong><br>' . e(Settings::get('company_name')) . '</p>',
            'created_at' => DB::now(),
            'updated_at' => DB::now(),
        ]);
        $db->update('departments', ['manager_id' => $id], 'id = 1 AND manager_id IS NULL');
        return $id;
    }

    /** Safe to run repeatedly. */
    public static function upgrade(): array
    {
        $log = Schema::sync(App::db());
        Templates::seed();
        Settings::set('schema_version', (string)HC_SCHEMA_VERSION);
        Settings::set('schema_upgrade_error', '');
        return $log;
    }
}
