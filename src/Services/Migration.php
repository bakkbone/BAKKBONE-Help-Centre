<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Schema;
use Bakkbone\HelpCentre\Core\Settings;

final class Migration
{
    public const FORMAT = 'bkhc-migrate';
    public const FILE_EXT = 'bhcx';
    private const KEY_TTL = 86400;

    /** Never copied: short-lived security state, and outgoing mail that mustn't be sent twice. */
    public const SKIP_TABLES = ['otp_codes', 'login_attempts', 'ticket_locks', 'mail_queue'];

    public const SKIP_SETTINGS = ['schema_version', 'cron_last_run', 'osticket_import', 'migration_key_hash', 'migration_key_expires', 'clone_state',
        'clone_summary', 'instance_id', 'sync_sources', 'bhc_sync_state', 'setup_import'];

    /** Lets a copy recognise where its data came from. */
    public static function instanceId(): string
    {
        $id = (string)Settings::get('instance_id', '');
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            $id = bin2hex(random_bytes(8));
            Settings::set('instance_id', $id);
        }
        return $id;
    }

    public const SECRET_COLUMNS = [
        'email_accounts' => ['ms_client_secret', 'in_password', 'smtp_password'],
        'staff' => ['totp_secret'],
        'webhooks' => ['secret'],
    ];

    public static function createKey(): string
    {
        $key = 'bkhc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Settings::set('migration_key_hash', hash('sha256', $key));
        Settings::set('migration_key_expires', (string)(time() + self::KEY_TTL));
        return $key;
    }

    public static function revokeKey(): void
    {
        Settings::set('migration_key_hash', '');
        Settings::set('migration_key_expires', '');
    }

    public static function keyExpires(): ?int
    {
        $exp = (int)Settings::get('migration_key_expires', 0);
        return $exp > time() && (string)Settings::get('migration_key_hash') !== '' ? $exp : null;
    }

    public static function keyValid(string $key): bool
    {
        $hash = (string)Settings::get('migration_key_hash', '');
        return $hash !== '' && self::keyExpires() !== null && hash_equals($hash, hash('sha256', trim($key)));
    }

    private static function cipherKey(string $migrationKey): string
    {
        return hash_hkdf('sha256', trim($migrationKey), 32, 'bkhc-migrate-v1');
    }

    public static function seal(string $migrationKey, mixed $data): string
    {
        $plain = (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $plain = function_exists('gzdeflate') ? 'z' . gzdeflate($plain, 6) : 'j' . $plain;
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::cipherKey($migrationKey), OPENSSL_RAW_DATA, $nonce, $tag);
        return base64_encode($nonce . $tag . $ct);
    }

    public static function open(string $migrationKey, string $line): mixed
    {
        $raw = base64_decode(trim($line), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException(__('The data is damaged or not from BAKKBONE Help Centre.'));
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::cipherKey($migrationKey), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException(__('Wrong migration key, or the key has been replaced since this data was created.'));
        }
        $json = $plain[0] === 'z' ? gzinflate(substr($plain, 1)) : substr($plain, 1);
        return json_decode((string)$json, true);
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_values(array_filter(array_keys(Schema::definition()), fn($t) => !in_array($t, self::SKIP_TABLES, true)));
    }

    /** @return array<string,mixed> */
    public static function manifest(): array
    {
        $db = App::db();
        $tables = [];
        foreach (self::tables() as $t) {
            $tables[$t] = $t === 'settings'
                ? (int)$db->val('SELECT COUNT(*) FROM settings WHERE name NOT IN ' . DB::in(self::SKIP_SETTINGS), self::SKIP_SETTINGS)
                : (int)$db->val('SELECT COUNT(*) FROM ' . $db->q($t));
        }
        return [
            'format' => self::FORMAT, 'version' => HC_VERSION, 'schema' => HC_SCHEMA_VERSION, 'instance_id' => self::instanceId(),
            'helpdesk' => (string)Settings::get('helpdesk_name'), 'base_url' => App::baseUrl(), 'url_prefix' => rtrim(App::url('/'), '/'),
            'tables' => $tables,
            'attachments' => (int)$db->val('SELECT COUNT(*) FROM attachments'),
            'attachment_bytes' => (int)$db->val('SELECT COALESCE(SUM(size), 0) FROM attachments'),
            'created_at' => DB::now(),
        ];
    }

    // Secrets are decrypted here because they're re-encrypted in transit and at the destination.
    /** @return array{rows:list<array<string,mixed>>,cursor:int,done:bool} */
    public static function rows(string $table, int $cursor, int $limit = 300, ?string $since = null): array
    {
        if (!in_array($table, self::tables(), true)) {
            throw new \InvalidArgumentException(__('Unknown table'));
        }
        $db = App::db();
        $def = Schema::definition()[$table];
        $hasId = isset($def['columns']['id']);
        // Catch-up runs only need rows created or changed since the last run.
        $where = '';
        $params = [];
        if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            if (isset($def['columns']['updated_at'], $def['columns']['created_at'])) {
                $where = ' AND (updated_at >= ? OR created_at >= ?)';
                $params = [$since, $since];
            } elseif (isset($def['columns']['created_at'])) {
                $where = ' AND created_at >= ?';
                $params = [$since];
            }
        }
        if ($hasId) {
            $rows = $db->all('SELECT * FROM ' . $db->q($table) . ' WHERE id > ?' . $where . ' ORDER BY id LIMIT ' . $limit, array_merge([$cursor], $params));
            $next = $rows ? (int)end($rows)['id'] : $cursor;
        } else {
            $order = implode(', ', array_map([$db, 'q'], $def['primary'] ?? array_keys($def['columns'])));
            $rows = $db->all('SELECT * FROM ' . $db->q($table) . " ORDER BY $order LIMIT $limit OFFSET $cursor");
            $next = $cursor + count($rows);
        }
        $done = count($rows) < $limit;
        $rows = array_map(fn($r) => self::decryptRow($table, $r), $rows);
        if ($table === 'settings') {
            $rows = array_values(array_filter($rows, fn($r) => !in_array($r['name'], self::SKIP_SETTINGS, true)));
        }
        return ['rows' => $rows, 'cursor' => $next, 'done' => $done];
    }

    /** Lets catch-up imports fetch a record that a changed row points at. */
    public static function row(string $table, int $id): ?array
    {
        if (!in_array($table, self::tables(), true) || $table === 'settings' || !isset(Schema::definition()[$table]['columns']['id'])) {
            throw new \InvalidArgumentException(__('Unknown table'));
        }
        $r = App::db()->find($table, $id);
        return $r ? self::decryptRow($table, $r) : null;
    }

    private static function decryptRow(string $table, array $r): array
    {
        foreach (self::SECRET_COLUMNS[$table] ?? [] as $col) {
            if (!empty($r[$col])) {
                $r[$col] = Crypto::decrypt((string)$r[$col]);
            }
        }
        if ($table === 'settings' && in_array($r['name'], Settings::SECRET, true) && !empty($r['value'])) {
            $r['value'] = Crypto::decrypt((string)$r['value']);
        }
        return $r;
    }

    /** @return array{id:int,offset:int,data:string,eof:bool,size:int} */
    public static function fileChunk(int $attachmentId, int $offset, int $length = 1048576): array
    {
        $att = Attachments::find($attachmentId);
        $path = $att ? Attachments::fullPath($att) : '';
        if (!$att || !is_file($path)) {
            return ['id' => $attachmentId, 'offset' => $offset, 'data' => '', 'eof' => true, 'size' => 0, 'missing' => true];
        }
        $size = (int)filesize($path);
        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $data = (string)fread($fh, $length);
        fclose($fh);
        return ['id' => $attachmentId, 'offset' => $offset, 'data' => base64_encode($data), 'eof' => $offset + strlen($data) >= $size, 'size' => $size];
    }

    public static function writeExport($out, string $key, ?callable $progress = null): void
    {
        @set_time_limit(0);
        $manifest = self::manifest();
        fwrite($out, json_encode(['format' => self::FORMAT, 'type' => 'export', 'version' => HC_VERSION, 'schema' => HC_SCHEMA_VERSION]) . "\n");
        fwrite($out, self::seal($key, ['m' => $manifest]) . "\n");
        foreach (self::tables() as $t) {
            $cursor = 0;
            do {
                $page = self::rows($t, $cursor, 500);
                if ($page['rows']) {
                    fwrite($out, self::seal($key, ['t' => $t, 'rows' => $page['rows']]) . "\n");
                }
                $cursor = $page['cursor'];
                $progress && $progress($t, $cursor);
            } while (!$page['done']);
        }
        $db = App::db();
        $last = 0;
        while ($ids = $db->col('SELECT id FROM attachments WHERE id > ? ORDER BY id LIMIT 200', [$last])) {
            foreach ($ids as $id) {
                $offset = 0;
                do {
                    $chunk = self::fileChunk((int)$id, $offset);
                    fwrite($out, self::seal($key, ['f' => $chunk]) . "\n");
                    $offset += strlen((string)base64_decode($chunk['data']));
                } while (!$chunk['eof']);
                $last = (int)$id;
            }
            $progress && $progress('attachments', $last);
        }
        fwrite($out, self::seal($key, ['end' => true]) . "\n");
    }
}
