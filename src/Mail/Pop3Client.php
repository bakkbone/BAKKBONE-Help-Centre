<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

final class Pop3Client
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(private int $timeout = 30)
    {
    }

    public function connect(string $host, int $port, string $encryption = 'ssl', bool $verifyPeer = true): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verifyPeer, 'verify_peer_name' => $verifyPeer, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new MailException("POP3 connection to $host:$port failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expectOk($this->readLine());
        if ($encryption === 'tls') {
            $this->cmd('STLS');
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailException('POP3 STLS negotiation failed');
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->cmd('USER ' . $user);
        $this->cmd('PASS ' . $pass);
    }

    public function authenticateXoauth2(string $user, string $token): void
    {
        $this->cmd('AUTH XOAUTH2 ' . base64_encode("user=$user\x01auth=Bearer $token\x01\x01"));
    }

    /** @return array<int,string> */
    public function uidl(): array
    {
        $this->cmd('UIDL');
        $out = [];
        foreach ($this->readMultiline() as $line) {
            [$n, $uid] = array_pad(explode(' ', trim($line), 2), 2, '');
            $out[(int)$n] = $uid;
        }
        return $out;
    }

    public function retrieve(int $n): string
    {
        $this->cmd('RETR ' . $n);
        return implode("\r\n", $this->readMultiline()) . "\r\n";
    }

    public function delete(int $n): void
    {
        $this->cmd('DELE ' . $n);
    }

    public function quit(): void
    {
        if ($this->sock) {
            try {
                $this->cmd('QUIT');
            } catch (\Throwable) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function cmd(string $c): string
    {
        if (@fwrite($this->sock, $c . "\r\n") === false) {
            throw new MailException('POP3 write failed');
        }
        $line = $this->readLine();
        $this->expectOk($line, strtok($c, ' ') ?: $c);
        return $line;
    }

    private function expectOk(string $line, string $cmd = 'greeting'): void
    {
        if (!str_starts_with($line, '+OK')) {
            throw new MailException("POP3 $cmd failed: $line");
        }
    }

    /** @return list<string> */
    private function readMultiline(): array
    {
        $lines = [];
        while (true) {
            $l = $this->readLine();
            if ($l === '.') {
                break;
            }
            if (str_starts_with($l, '..')) {
                $l = substr($l, 1);
            }
            $lines[] = $l;
        }
        return $lines;
    }

    private function readLine(): string
    {
        $l = @fgets($this->sock);
        if ($l === false) {
            throw new MailException('POP3 connection closed or timed out');
        }
        return rtrim($l, "\r\n");
    }
}
