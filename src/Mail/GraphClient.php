<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

use Bakkbone\HelpCentre\Core\Http;

/** Raw MIME both ways, so Graph mail uses the same parser and builder as IMAP and SMTP. */
final class GraphClient
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private string $tenant,
        private string $clientId,
        private string $clientSecret,
        private string $mailbox,
    ) {
    }

    private function token(): string
    {
        return MicrosoftOAuth::token($this->tenant, $this->clientId, $this->clientSecret, MicrosoftOAuth::SCOPE_GRAPH);
    }

    /** @return array{0:int,1:mixed,2:string} */
    private function call(string $method, string $path, ?string $body = null, string $contentType = 'application/json'): array
    {
        for ($attempt = 1; ; $attempt++) {
            $token = $this->token();
            $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
            if ($body !== null) {
                $headers[] = 'Content-Type: ' . $contentType;
            }
            [$status, $resp] = Http::request($method, self::BASE . $path, $body, $headers, 60);
            if (($status === 401 || $status === 403) && $attempt === 1) {
                // The cached token may predate a permission change in Entra: get a fresh one and try once more.
                MicrosoftOAuth::forget($this->tenant, $this->clientId, $this->clientSecret, MicrosoftOAuth::SCOPE_GRAPH);
                continue;
            }
            break;
        }
        $json = json_decode($resp, true);
        if ($status >= 400) {
            $msg = is_array($json) ? ($json['error']['code'] ?? '') . ': ' . ($json['error']['message'] ?? '') : $resp;
            $hint = '';
            if ($status === 403 || $status === 401) {
                $needed = str_contains($path, '/sendMail') ? ['Mail.Send'] : ['Mail.ReadWrite', 'Mail.Read'];
                $hint = MicrosoftOAuth::explainDenied($token, $needed, $this->mailbox, 'Microsoft Graph', $this->clientId);
            } elseif ($status === 404 && str_contains((string)$msg, 'ErrorInvalidUser')) {
                $hint = ' There\'s no Exchange Online mailbox called ' . $this->mailbox . ' in this tenant. Check the address in "Username / mailbox", and that the tenant ID is the right organisation.';
            }
            throw new MailException("Microsoft Graph $method $path failed (HTTP $status): " . mb_substr((string)$msg, 0, 500) . $hint);
        }
        return [$status, $json, $resp];
    }

    private function userPath(): string
    {
        return '/users/' . rawurlencode($this->mailbox);
    }

    public function folderId(string $name): string
    {
        $wellKnown = ['inbox', 'archive', 'deleteditems', 'junkemail', 'sentitems', 'drafts'];
        $norm = strtolower(str_replace(' ', '', $name));
        if ($name === '' || $norm === 'inbox') {
            return 'inbox';
        }
        if (in_array($norm, $wellKnown, true)) {
            return $norm;
        }
        $filter = rawurlencode("displayName eq '" . str_replace("'", "''", $name) . "'");
        foreach (['/mailFolders?$filter=' . $filter, '/mailFolders/inbox/childFolders?$filter=' . $filter] as $p) {
            [, $json] = $this->call('GET', $this->userPath() . $p);
            if (!empty($json['value'][0]['id'])) {
                return (string)$json['value'][0]['id'];
            }
        }
        throw new MailException("Mail folder '$name' was not found in {$this->mailbox}");
    }

    /** @return list<string> */
    public function unreadMessageIds(string $folder = 'inbox', int $max = 50): array
    {
        $fid = $this->folderId($folder);
        $q = '?$filter=' . rawurlencode('isRead eq false') . '&$select=id,receivedDateTime&$top=' . max(1, min(100, $max));
        [, $json] = $this->call('GET', $this->userPath() . '/mailFolders/' . rawurlencode($fid) . '/messages' . $q);
        $rows = $json['value'] ?? [];
        usort($rows, fn($a, $b) => strcmp((string)($a['receivedDateTime'] ?? ''), (string)($b['receivedDateTime'] ?? '')));
        return array_map(fn($r) => (string)$r['id'], $rows);
    }

    public function mime(string $id): string
    {
        [, , $raw] = $this->call('GET', $this->userPath() . '/messages/' . rawurlencode($id) . '/$value');
        return $raw;
    }

    public function markRead(string $id): void
    {
        $this->call('PATCH', $this->userPath() . '/messages/' . rawurlencode($id), json_encode(['isRead' => true]));
    }

    public function move(string $id, string $folder): void
    {
        $this->call('POST', $this->userPath() . '/messages/' . rawurlencode($id) . '/move', json_encode(['destinationId' => $this->folderId($folder)]));
    }

    public function delete(string $id): void
    {
        $this->call('DELETE', $this->userPath() . '/messages/' . rawurlencode($id));
    }

    /** Graph accepts base64 MIME on /sendMail. */
    public function sendMime(string $mime): void
    {
        $this->call('POST', $this->userPath() . '/sendMail', base64_encode($mime), 'text/plain');
    }

    public function test(): string
    {
        [, $json] = $this->call('GET', $this->userPath() . '/mailFolders/inbox?$select=displayName,totalItemCount,unreadItemCount');
        return _n('Connected to {mailbox} - Inbox has {n} message ({unread} unread).', 'Connected to {mailbox} - Inbox has {n} messages ({unread} unread).', (int)($json['totalItemCount'] ?? 0), ['mailbox' => $this->mailbox, 'unread' => fmt_num((int)($json['unreadItemCount'] ?? 0))]);
    }
}
