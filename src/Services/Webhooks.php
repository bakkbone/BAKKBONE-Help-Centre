<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Http;
use Bakkbone\HelpCentre\Core\Hooks;
use Bakkbone\HelpCentre\Core\Log;

final class Webhooks
{
    public const EVENTS = [
        'ticket.created' => 'New ticket created',
        'ticket.message' => 'New message from user',
        'ticket.reply' => 'Agent replied',
        'ticket.note' => 'Internal note added',
        'ticket.assigned' => 'Ticket assigned',
        'ticket.status' => 'Status changed',
        'ticket.closed' => 'Ticket closed/resolved',
        'ticket.overdue' => 'Ticket became overdue',
        'ticket.transferred' => 'Ticket transferred to another department',
        'ticket.priority' => 'Priority changed',
        'ticket.merged' => 'Tickets merged into this one',
        'ticket.deleted' => 'Ticket deleted',
    ];

    private const BACKOFF_MINUTES = [1, 5, 30, 120, 360];

    /** @var list<int> */
    private static array $pending = [];
    private static bool $shutdownRegistered = false;

    public static function fire(string $event, int $ticketId, array $extra = []): void
    {
        if (Hooks::has($event) || Hooks::has('ticket.any')) {
            $t = Tickets::find($ticketId);
            if ($t) {
                Hooks::fire($event, $t, $extra);
                Hooks::fire('ticket.any', $event, $t, $extra);
            }
        }
        try {
            $hooks = App::db()->all('SELECT * FROM webhooks WHERE active = 1');
            if (!$hooks) {
                return;
            }
            $ticket = Tickets::find($ticketId);
            if (!$ticket) {
                return;
            }
            $context = null;
            foreach ($hooks as $hook) {
                $events = array_filter(array_map('trim', explode(',', (string)$hook['events'])));
                if (!in_array($event, $events, true)) {
                    continue;
                }
                $depts = array_filter(array_map('intval', explode(',', (string)$hook['dept_ids'])));
                if ($depts && !in_array((int)$ticket['dept_id'], $depts, true)) {
                    continue;
                }
                $context ??= array_merge(Tickets::context($ticket), $extra, ['event' => $event]);
                self::queue($hook, $event, $ticketId, $context);
            }
        } catch (\Throwable $e) {
            Log::error('Webhook dispatch failed', $e->getMessage());
        }
    }

    private static function queue(array $hook, string $event, ?int $ticketId, array $context): int
    {
        [$body, $headers] = self::buildRequest($hook, $event, $context);
        $id = App::db()->insert('webhook_deliveries', [
            'webhook_id' => $hook['id'],
            'event' => $event,
            'ticket_id' => $ticketId,
            'request_body' => $body,
            'request_headers' => json_encode($headers, JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'success' => 0,
            'next_attempt_at' => DB::now(),
            'created_at' => DB::now(),
            'updated_at' => DB::now(),
        ]);
        self::$pending[] = $id;
        if (!self::$shutdownRegistered && PHP_SAPI !== 'cli') {
            self::$shutdownRegistered = true;
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::flushPending();
            });
        } elseif (PHP_SAPI === 'cli') {
            self::flushPending();
        }
        return $id;
    }

    public static function flushPending(): void
    {
        $ids = self::$pending;
        self::$pending = [];
        foreach ($ids as $id) {
            self::attempt($id);
        }
    }

    /** @return array{0:string,1:array<string,string>} */
    public static function buildRequest(array $hook, string $event, array $context): array
    {
        $type = (string)$hook['content_type'];
        $template = trim((string)$hook['body_template']);
        $payload = self::defaultPayload($event, $context);

        if ($type === 'form') {
            $contentType = 'application/x-www-form-urlencoded';
            $body = $template !== ''
                ? Templates::merge($template, $context, 'url')
                : http_build_query(self::flatten($payload));
        } elseif ($type === 'text') {
            $contentType = 'text/plain; charset=utf-8';
            $body = Templates::merge($template, $context, 'text');
        } else {
            $contentType = 'application/json';
            $body = $template !== ''
                ? Templates::merge($template, $context, 'json')
                : (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $deliveryId = bin2hex(random_bytes(8));
        $headers = [
            'Content-Type' => $contentType,
            'User-Agent' => 'BAKKBONE-Help-Centre-Webhook/' . HC_VERSION,
            'X-HelpCentre-Event' => $event,
            'X-HelpCentre-Delivery' => $deliveryId,
        ];
        foreach (preg_split('/\r?\n/', (string)$hook['headers']) ?: [] as $line) {
            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }
            $name = trim(substr($line, 0, $p));
            if ($name !== '' && preg_match('/^[A-Za-z0-9\-]+$/', $name)) {
                $headers[$name] = str_replace(["\r", "\n"], '', Templates::merge(trim(substr($line, $p + 1)), $context, 'text'));
            }
        }
        $secret = (string)Crypto::decrypt($hook['secret'] ?? '');
        if ($secret !== '') {
            $ts = (string)time();
            $headers['X-HelpCentre-Timestamp'] = $ts;
            $headers['X-HelpCentre-Signature'] = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
        }
        return [$body, $headers];
    }

    public static function defaultPayload(string $event, array $c): array
    {
        return [
            'event' => $event,
            'timestamp' => gmdate('c'),
            'helpdesk' => ['name' => $c['helpdesk']['name'] ?? '', 'url' => $c['helpdesk']['url'] ?? ''],
            'ticket' => $c['ticket'] ?? [],
            'user' => $c['user'] ?? [],
            'message' => [
                'html' => $c['message'] ?? '',
                'text' => \Bakkbone\HelpCentre\Core\Html::toText((string)($c['message'] ?? '')),
                'poster' => $c['poster']['name'] ?? '',
            ],
            'staff' => $c['staff'] ?? null,
        ];
    }

    /** @return array<string,string> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
            }
        }
        return $out;
    }

    public static function attempt(int $deliveryId): bool
    {
        $db = App::db();
        $d = $db->find('webhook_deliveries', $deliveryId);
        if (!$d || $d['success']) {
            return true;
        }
        $hook = $db->find('webhooks', (int)$d['webhook_id']);
        if (!$hook) {
            $db->update('webhook_deliveries', ['next_attempt_at' => null], 'id = ?', [$deliveryId]);
            return false;
        }
        $headers = [];
        foreach (json_decode((string)$d['request_headers'], true) ?: [] as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $attempts = (int)$d['attempts'] + 1;
        $status = 0;
        $resp = '';
        try {
            $method = strtoupper((string)$hook['method']) ?: 'POST';
            $url = (string)$hook['url'];
            if (!self::urlAllowed($url)) {
                throw new \RuntimeException(__('Webhook URL must be http(s) and must not target a private network.'));
            }
            [$status, $resp] = Http::request($method, $url, $method === 'GET' ? null : (string)$d['request_body'], $headers, 10);
        } catch (\Throwable $e) {
            $resp = 'Error: ' . $e->getMessage();
        }
        $ok = $status >= 200 && $status < 300;
        $next = (!$ok && $attempts <= count(self::BACKOFF_MINUTES)) ? gmdate('Y-m-d H:i:s', time() + 60 * self::BACKOFF_MINUTES[$attempts - 1]) : null;
        $db->update('webhook_deliveries', [
            'status_code' => $status ?: null,
            'response_body' => mb_substr($resp, 0, 4000),
            'attempts' => $attempts,
            'success' => $ok ? 1 : 0,
            'next_attempt_at' => $next,
            'updated_at' => DB::now(),
        ], 'id = ?', [$deliveryId]);
        if (!$ok && $next === null) {
            Log::warning('Webhook delivery failed permanently', "Webhook: {$hook['name']}\nURL: {$hook['url']}\nStatus: $status\n" . mb_substr($resp, 0, 1000));
        }
        return $ok;
    }

    public static function retryDue(int $limit = 50): int
    {
        $ids = App::db()->col('SELECT id FROM webhook_deliveries WHERE success = 0 AND next_attempt_at IS NOT NULL AND next_attempt_at <= ? ORDER BY id LIMIT ' . (int)$limit, [DB::now()]);
        $n = 0;
        foreach ($ids as $id) {
            if (self::attempt((int)$id)) {
                $n++;
            }
        }
        App::db()->delete('webhook_deliveries', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
        return $n;
    }

    public static function test(int $hookId): array
    {
        $hook = App::db()->find('webhooks', $hookId);
        if (!$hook) {
            return ['ok' => false, 'message' => 'Webhook not found'];
        }
        $ticket = App::db()->row('SELECT * FROM tickets ORDER BY id DESC LIMIT 1');
        $event = explode(',', (string)$hook['events'])[0] ?: 'ticket.created';
        $context = $ticket ? Tickets::context($ticket) : self::sampleContext();
        $context['event'] = $event;
        $id = self::queue($hook, $event, $ticket ? (int)$ticket['id'] : null, $context);
        self::$pending = array_values(array_diff(self::$pending, [$id]));
        $ok = self::attempt($id);
        $d = App::db()->find('webhook_deliveries', $id);
        return ['ok' => $ok, 'message' => 'HTTP ' . ($d['status_code'] ?? 'n/a') . ' - ' . mb_substr((string)$d['response_body'], 0, 300)];
    }

    public static function sampleContext(): array
    {
        return [
            'helpdesk' => ['name' => setting('helpdesk_name'), 'url' => App::url('/')],
            'ticket' => ['id' => 0, 'number' => '000000', 'subject' => 'Sample ticket', 'status' => 'Open', 'priority' => 'Normal', 'dept' => 'Support'],
            'user' => ['name' => 'Sample User', 'email' => 'user@example.com'],
            'message' => '<p>This is a test webhook from BAKKBONE Help Centre.</p>',
            'poster' => ['name' => 'Sample User'],
        ];
    }

    /** SSRF guard: http(s) only, and no private or loopback targets unless config allows them. */
    public static function urlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        if (App::config('webhooks_allow_private', false)) {
            return true;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}
