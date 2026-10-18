<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Api;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Mail\InboundProcessor;
use Bakkbone\HelpCentre\Services\Attachments;
use Bakkbone\HelpCentre\Services\Cron;
use Bakkbone\HelpCentre\Services\Tickets;

final class ApiController extends Controller
{
    /** $capability '' accepts any active key. */
    public static function requireKey(string $capability = ''): array
    {
        return (new self())->authenticate($capability);
    }

    private function authenticate(string $capability): array
    {
        $key = (string)(Request::header('X-API-Key') ?? '');
        if ($key === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)Request::header('Authorization'), $m)) {
            $key = $m[1];
        }
        if ($key === '') {
            $this->json(['error' => 'Missing API key (send it in the X-API-Key header).'], 401);
        }
        $row = App::db()->row('SELECT * FROM api_keys WHERE key_hash = ? AND active = 1', [hash('sha256', $key)]);
        if (!$row || ($capability !== '' && empty($row[$capability]))) {
            Log::warning('API request rejected', 'Invalid key or missing permission (' . $capability . ') from ' . Request::ip());
            $this->json(['error' => 'Invalid API key or permission denied.'], 401);
        }
        $allow = array_filter(preg_split('/\s+/', (string)$row['ip_allow']) ?: []);
        if ($allow && !$this->ipAllowed(Request::ip(), $allow)) {
            $this->json(['error' => 'This API key may not be used from ' . Request::ip()], 403);
        }
        App::db()->update('api_keys', ['last_used_at' => DB::now()], 'id = ?', [$row['id']]);
        return $row;
    }

    private function ipAllowed(string $ip, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if (!str_contains($r, '/')) {
                if ($ip === $r) {
                    return true;
                }
                continue;
            }
            [$subnet, $bits] = explode('/', $r, 2);
            $ipBin = @inet_pton($ip);
            $subBin = @inet_pton($subnet);
            if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
                continue;
            }
            $bits = (int)$bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = chr((0xff << (8 - $rem)) & 0xff);
            if ((($ipBin[$bytes] ?? "\0") & $mask) === (($subBin[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }
        return false;
    }

    public function createTicket(): void
    {
        $this->authenticate('can_create_tickets');
        $ctype = strtolower((string)Request::header('Content-Type'));
        if (str_contains($ctype, 'application/json')) {
            $data = json_decode(Request::body(), true);
            if (!is_array($data)) {
                $this->json(['error' => 'Invalid JSON body.'], 400);
            }
        } else {
            $data = $_POST;
        }

        [$message, $format] = $this->decodeMessage((string)($data['message'] ?? ''));
        $errors = [];
        if (!filter_var((string)($data['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email is required and must be valid';
        }
        if (trim((string)($data['subject'] ?? '')) === '') {
            $errors[] = 'subject is required';
        }
        if (trim(strip_tags($message)) === '') {
            $errors[] = 'message is required';
        }

        $attachments = [];
        foreach ((array)($data['attachments'] ?? []) as $att) {
            if (!is_array($att)) {
                continue;
            }
            // Formats: {"name.ext": "data:mime;base64,..."} or {"name":..,"type":..,"data":..,"encoding":"base64"}
            if (isset($att['name'], $att['data'])) {
                $content = ($att['encoding'] ?? '') === 'base64' ? base64_decode((string)$att['data'], true) : (string)$att['data'];
                $file = ['filename' => (string)$att['name'], 'mime' => (string)($att['type'] ?? 'application/octet-stream'), 'content' => (string)$content];
            } else {
                $name = (string)array_key_first($att);
                $file = $this->decodeDataUri($name, (string)$att[$name]);
            }
            if (!$file || $file['content'] === '') {
                continue;
            }
            if (strlen($file['content']) > Attachments::maxBytes()) {
                $errors[] = 'attachment ' . $file['filename'] . ' is too large';
                continue;
            }
            if (!Attachments::extensionAllowed($file['filename'])) {
                $errors[] = 'attachment type not allowed: ' . $file['filename'];
                continue;
            }
            $attachments[] = $file;
        }

        $topicId = (int)($data['topicId'] ?? $data['topic_id'] ?? 0) ?: null;
        $fieldInput = [];
        foreach (Tickets::customFields($topicId) as $f) {
            if (array_key_exists($f['name'], $data)) {
                $fieldInput[(int)$f['id']] = is_scalar($data[$f['name']]) ? (string)$data[$f['name']] : '';
            }
        }
        // Like osTicket, required custom fields aren't enforced, so existing website forms keep working when fields are added.
        [$fieldValues, $fieldErrors] = Tickets::validateFields(array_map(fn($f) => ['required' => 0] + $f, array_values(array_filter(Tickets::customFields($topicId), fn($f) => array_key_exists((int)$f['id'], $fieldInput)))), $fieldInput);
        $errors = array_merge($errors, $fieldErrors);
        if ($errors) {
            $this->json(['error' => 'Validation failed', 'details' => $errors], 400);
        }

        $priority = $data['priority'] ?? $data['priorityId'] ?? null;
        if ($priority !== null && !ctype_digit((string)$priority)) {
            $priority = App::db()->val('SELECT id FROM priorities WHERE LOWER(name) = ?', [strtolower((string)$priority)]);
        }
        $source = strtolower((string)($data['source'] ?? 'api'));
        $result = Tickets::create([
            'email' => (string)$data['email'],
            'name' => (string)($data['name'] ?? ''),
            'phone' => isset($data['phone']) ? (string)$data['phone'] : null,
            'subject' => (string)$data['subject'],
            'message' => $message,
            'message_format' => $format,
            'source' => array_key_exists($source, Tickets::SOURCES) ? $source : 'api',
            'topic_id' => $topicId,
            'dept_id' => (int)($data['deptId'] ?? $data['dept_id'] ?? 0) ?: null,
            'priority_id' => $priority ? (int)$priority : null,
            'fields' => $fieldValues,
            'attachments' => $attachments,
            'ip' => (string)($data['ip'] ?? Request::ip()),
            'autorespond' => !isset($data['autorespond']) || filter_var($data['autorespond'], FILTER_VALIDATE_BOOLEAN),
            'alert' => !isset($data['alert']) || filter_var($data['alert'], FILTER_VALIDATE_BOOLEAN),
        ]);
        if (!$result['ok']) {
            $this->json(['error' => $result['error'] ?? 'Ticket rejected'], 422);
        }
        $t = $result['ticket'];
        if (str_contains((string)Request::header('Accept'), 'text/plain') || Request::get('format') === 'text') {
            http_response_code(201);
            header('Content-Type: text/plain');
            echo $t['number'];
            exit;
        }
        $this->json(['id' => (int)$t['id'], 'number' => $t['number'], 'url' => App::url('/tickets/' . $t['number'])], 201);
    }

    /** @return array{0:string,1:string} */
    private function decodeMessage(string $m): array
    {
        if (preg_match('/^data:(text\/html|text\/plain)([^,]*),(.*)$/s', $m, $mm)) {
            $body = str_contains($mm[2], 'base64') ? (string)base64_decode($mm[3]) : rawurldecode($mm[3]);
            return [$body, $mm[1] === 'text/html' ? 'html' : 'text'];
        }
        return [$m, $m !== strip_tags($m) ? 'html' : 'text'];
    }

    private function decodeDataUri(string $name, string $uri): ?array
    {
        if (!preg_match('/^data:([^;,]*)((?:;[^,]*)*),(.*)$/s', $uri, $m)) {
            return null;
        }
        $content = str_contains($m[2], 'base64') ? base64_decode($m[3], true) : rawurldecode($m[3]);
        return ['filename' => $name, 'mime' => $m[1] ?: 'application/octet-stream', 'content' => (string)$content];
    }

    public function pipe(): void
    {
        $this->authenticate('can_pipe');
        $raw = Request::body();
        if (trim($raw) === '') {
            $this->json(['error' => 'Empty message body'], 400);
        }
        try {
            $r = InboundProcessor::process($raw, null, 'api-pipe');
        } catch (\Throwable $e) {
            Log::error('API pipe failed', $e->getMessage());
            $this->json(['error' => 'Processing failed - the message will need to be resent.'], 500);
        }
        $this->json($r, in_array($r['status'], ['created', 'appended'], true) ? 201 : 200);
    }

    public function cron(): void
    {
        $key = (string)Request::input('key', '');
        $expected = (string)App::config('cron_key', '');
        if ($expected === '' || !hash_equals($expected, $key)) {
            $this->json(['error' => 'Invalid cron key'], 403);
        }
        @set_time_limit(300);
        $this->json(['ok' => true, 'result' => Cron::run()]);
    }
}
