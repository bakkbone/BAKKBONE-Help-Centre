<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

/** Doesn't need PHP's deprecated imap extension. */
final class ImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    /** @var list<string> */
    private array $capabilities = [];
    public array $log = [];

    public function __construct(private int $timeout = 30)
    {
    }

    public function connect(string $host, int $port, string $encryption = 'ssl', bool $verifyPeer = true): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'allow_self_signed' => !$verifyPeer,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new MailException("IMAP connection to $host:$port failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new MailException('Unexpected IMAP greeting: ' . $greeting);
        }
        if ($encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailException('IMAP STARTTLS negotiation failed');
            }
        }
        $this->capability();
    }

    public function capability(): array
    {
        $resp = $this->command('CAPABILITY');
        foreach ($resp['lines'] as $l) {
            if (str_starts_with($l, '* CAPABILITY')) {
                $this->capabilities = array_map('strtoupper', explode(' ', trim(substr($l, 13))));
            }
        }
        return $this->capabilities;
    }

    public function hasCapability(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->capabilities, true);
    }

    public function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass), true);
        $this->capability();
    }

    public function authenticateXoauth2(string $user, string $accessToken): void
    {
        $sasl = base64_encode("user=$user\x01auth=Bearer $accessToken\x01\x01");
        $tag = $this->nextTag();
        $this->write("$tag AUTHENTICATE XOAUTH2 $sasl\r\n", "$tag AUTHENTICATE XOAUTH2 ****");
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) {
                // Server sent an error challenge; respond with empty line to get the final status
                $this->write("\r\n");
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new MailException('IMAP XOAUTH2 authentication failed: ' . $line);
                }
                break;
            }
        }
        $this->capability();
    }

    public function select(string $folder): int
    {
        $resp = $this->command('SELECT ' . $this->quote($this->encodeFolder($folder)));
        foreach ($resp['lines'] as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /** @return list<int> */
    public function searchUnseen(): array
    {
        $resp = $this->command('UID SEARCH UNSEEN');
        $uids = [];
        foreach ($resp['lines'] as $l) {
            if (str_starts_with(strtoupper($l), '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) ?: [] as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int)$u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** BODY.PEEK so the message isn't marked as read. */
    public function fetchRaw(int $uid): string
    {
        $resp = $this->command("UID FETCH $uid (BODY.PEEK[])");
        return $resp['literals'][0] ?? '';
    }

    public function markSeen(int $uid): void
    {
        $this->command("UID STORE $uid +FLAGS.SILENT (\\Seen)");
    }

    public function delete(int $uid): void
    {
        $this->command("UID STORE $uid +FLAGS.SILENT (\\Deleted)");
        if ($this->hasCapability('UIDPLUS')) {
            $this->command("UID EXPUNGE $uid");
        } else {
            $this->command('EXPUNGE');
        }
    }

    public function move(int $uid, string $folder): void
    {
        $f = $this->quote($this->encodeFolder($folder));
        if ($this->hasCapability('MOVE')) {
            $this->command("UID MOVE $uid $f");
            return;
        }
        $this->command("UID COPY $uid $f");
        $this->delete($uid);
    }

    public function createFolder(string $folder): void
    {
        try {
            $this->command('CREATE ' . $this->quote($this->encodeFolder($folder)));
        } catch (MailException) {
            // already exists
        }
    }

    /** @return list<string> */
    public function listFolders(): array
    {
        $resp = $this->command('LIST "" "*"');
        $out = [];
        foreach ($resp['lines'] as $l) {
            if (preg_match('/^\* LIST \([^)]*\) (?:"[^"]*"|NIL) (.+)$/i', $l, $m)) {
                $out[] = $this->decodeFolder(trim($m[1], '"'));
            }
        }
        return $out;
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
    }

    /** @return array{lines:list<string>,literals:list<string>} */
    private function command(string $cmd, bool $sensitive = false): array
    {
        $tag = $this->nextTag();
        $this->write("$tag $cmd\r\n", $sensitive ? "$tag " . strtok($cmd, ' ') . ' ****' : null);
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            while (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->readBytes((int)$m[1]);
                $line .= ' <literal>' . $this->readLine();
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 3));
                if ($status !== 'OK ') {
                    throw new MailException('IMAP command failed (' . strtok($cmd, ' ') . '): ' . $line);
                }
                $lines[] = $line;
                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    private function nextTag(): string
    {
        return 'A' . str_pad((string)++$this->tag, 4, '0', STR_PAD_LEFT);
    }

    private function write(string $data, ?string $logAs = null): void
    {
        if (!$this->sock) {
            throw new MailException('IMAP not connected');
        }
        $this->log[] = 'C: ' . trim($logAs ?? $data);
        if (@fwrite($this->sock, $data) === false) {
            throw new MailException('IMAP write failed');
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new MailException($meta['timed_out'] ? 'IMAP read timed out' : 'IMAP connection closed by server');
        }
        $line = rtrim($line, "\r\n");
        if (count($this->log) < 200) {
            $this->log[] = 'S: ' . mb_substr($line, 0, 300);
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->sock, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] || feof($this->sock)) {
                    throw new MailException('IMAP literal read failed');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private function encodeFolder(string $name): string
    {
        if (!preg_match('/[^\x20-\x7e]|&/', $name)) {
            return $name;
        }
        return (string)preg_replace_callback('/&|[^\x20-\x7e]+/u', function ($m) {
            if ($m[0] === '&') {
                return '&-';
            }
            $b64 = base64_encode((string)mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8'));
            return '&' . str_replace('/', ',', rtrim($b64, '=')) . '-';
        }, $name);
    }

    private function decodeFolder(string $name): string
    {
        return (string)preg_replace_callback('/&([^-]*)-/', function ($m) {
            if ($m[1] === '') {
                return '&';
            }
            return (string)mb_convert_encoding((string)base64_decode(str_replace(',', '/', $m[1])), 'UTF-8', 'UTF-16BE');
        }, $name);
    }
}
