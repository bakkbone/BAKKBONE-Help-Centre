<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\Attachments;
use Bakkbone\HelpCentre\Services\EmailAccounts;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    private const BACKOFF_MINUTES = [5, 15, 60, 240, 720];

    /** @param array{ to:list<array{email:string,name?:string}>,cc?:list<array{email:string,name?:string}>,bcc?:list<array{email:string,name?:string}>,subject:string,html:string,attachments?:list<int>,headers?:array<string,string>,message_id?:string,reply_to?:array{email:string,name?:string},auto?:bool,separator?:bool } $msg */
    public static function send(array $msg, ?int $accountId = null): string
    {
        $account = $accountId ? EmailAccounts::find($accountId) : null;
        $account ??= EmailAccounts::default();
        $msg['message_id'] = $msg['message_id'] ?? self::newMessageId();
        $filtered = \Bakkbone\HelpCentre\Core\Hooks::apply('mail.outgoing', $msg, $account);
        if (!is_array($filtered)) {
            return $msg['message_id'];
        }
        $msg = $filtered + ['message_id' => $msg['message_id']];
        $msg['to'] = self::uniqueRecipients($msg['to'] ?? []);
        if (!$msg['to']) {
            return $msg['message_id'];
        }
        $id = App::db()->insert('mail_queue', [
            'account_id' => $account['id'] ?? null,
            'payload' => json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'attempts' => 0,
            'next_attempt_at' => DB::now(),
            'created_at' => DB::now(),
        ]);
        self::deliverQueued($id);
        return $msg['message_id'];
    }

    public static function processQueue(int $limit = 50): int
    {
        $ids = App::db()->col(
            'SELECT id FROM mail_queue WHERE sent_at IS NULL AND failed = 0 AND next_attempt_at <= ? ORDER BY id LIMIT ' . (int)$limit,
            [DB::now()]
        );
        $sent = 0;
        foreach ($ids as $id) {
            if (self::deliverQueued((int)$id)) {
                $sent++;
            }
        }
        App::db()->delete('mail_queue', 'sent_at IS NOT NULL AND sent_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        return $sent;
    }

    public static function deliverQueued(int $id): bool
    {
        $db = App::db();
        $row = $db->find('mail_queue', $id);
        if (!$row || $row['sent_at']) {
            return true;
        }
        $msg = json_decode((string)$row['payload'], true) ?: [];
        $account = EmailAccounts::find($row['account_id'] ? (int)$row['account_id'] : null);
        try {
            self::deliver($msg, $account);
            $db->update('mail_queue', ['sent_at' => DB::now(), 'attempts' => (int)$row['attempts'] + 1, 'last_error' => null], 'id = ?', [$id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$row['attempts'] + 1;
            $failed = $attempts > count(self::BACKOFF_MINUTES);
            $next = $failed ? null : gmdate('Y-m-d H:i:s', time() + 60 * self::BACKOFF_MINUTES[$attempts - 1]);
            $db->update('mail_queue', ['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000), 'next_attempt_at' => $next, 'failed' => $failed ? 1 : 0], 'id = ?', [$id]);
            Log::error(
                $failed ? 'Email delivery failed permanently' : 'Email delivery failed (will retry)',
                'To: ' . implode(', ', array_column($msg['to'] ?? [], 'email')) . "\nSubject: " . ($msg['subject'] ?? '') . "\nMailbox: " . ($account['email'] ?? 'PHP mail()') . "\nError: " . $e->getMessage()
            );
            return false;
        }
    }

    public static function deliver(array $msg, ?array $account): void
    {
        $m = self::build($msg, $account);
        $protocol = $account['outbound_protocol'] ?? 'mail';

        if ($protocol === 'graph') {
            if (!$m->preSend()) {
                throw new MailException('Could not build message: ' . $m->ErrorInfo);
            }
            $graph = new GraphClient(
                (string)$account['ms_tenant'],
                (string)$account['ms_client_id'],
                EmailAccounts::secret($account, 'ms_client_secret'),
                (string)$account['email']
            );
            $graph->sendMime($m->getSentMIMEMessage());
            return;
        }
        try {
            $sent = $m->send();
        } catch (\Throwable $e) {
            $sent = false;
            $m->ErrorInfo = $e->getMessage();
        }
        if (!$sent) {
            if (($account['auth_type'] ?? '') === 'oauth_ms') {
                // Next attempt gets a fresh token, in case permissions changed since this one was issued.
                MicrosoftOAuth::forget((string)$account['ms_tenant'], (string)$account['ms_client_id'], EmailAccounts::secret($account, 'ms_client_secret'), MicrosoftOAuth::SCOPE_OUTLOOK);
                if (stripos($m->ErrorInfo, 'authent') !== false) {
                    $m->ErrorInfo .= ' Check the app has the SMTP.SendAsApp application permission with admin consent, the service principal has FullAccess to the mailbox, and SMTP AUTH is enabled for it (Set-CASMailbox -SmtpClientAuthenticationDisabled $false).';
                }
            }
            throw new MailException($m->ErrorInfo);
        }
    }

    public static function build(array $msg, ?array $account): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->XMailer = 'BAKKBONE Help Centre';
        $m->Timeout = 30;

        $fromEmail = $account['email'] ?? (Settings::get('admin_email') ?: 'noreply@' . self::host());
        $fromName = ($account['from_name'] ?? '') ?: (string)Settings::get('helpdesk_name');
        $m->setFrom($fromEmail, $fromName, false);
        if (!empty($msg['reply_to']['email'])) {
            $m->addReplyTo($msg['reply_to']['email'], $msg['reply_to']['name'] ?? '');
        }
        foreach ($msg['to'] ?? [] as $r) {
            $m->addAddress($r['email'], $r['name'] ?? '');
        }
        foreach ($msg['cc'] ?? [] as $r) {
            $m->addCC($r['email'], $r['name'] ?? '');
        }
        foreach ($msg['bcc'] ?? [] as $r) {
            $m->addBCC($r['email'], $r['name'] ?? '');
        }
        $m->Subject = (string)($msg['subject'] ?? '');
        $m->MessageID = '<' . $msg['message_id'] . '>';
        foreach ($msg['headers'] ?? [] as $k => $v) {
            if ($v !== '' && $v !== null) {
                $m->addCustomHeader($k, (string)$v);
            }
        }
        // Prevent auto-reply loops with Exchange / other help desks
        $m->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        if (!empty($msg['auto'])) {
            $m->addCustomHeader('Auto-Submitted', 'auto-replied');
        }

        $html = self::layout((string)($msg['html'] ?? ''), (bool)($msg['separator'] ?? false));
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = Html::toText($html);

        foreach ($msg['attachments'] ?? [] as $attId) {
            $att = Attachments::find((int)$attId);
            if ($att && is_file(Attachments::fullPath($att))) {
                $m->addAttachment(Attachments::fullPath($att), $att['filename'], PHPMailer::ENCODING_BASE64, $att['mime']);
            }
        }

        $protocol = $account['outbound_protocol'] ?? 'mail';
        if ($protocol === 'smtp') {
            $m->isSMTP();
            $m->Host = (string)$account['smtp_host'];
            $m->Port = (int)($account['smtp_port'] ?: 587);
            $enc = (string)$account['smtp_encryption'];
            if ($enc === 'ssl') {
                $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $m->SMTPSecure = '';
                $m->SMTPAutoTLS = false;
            }
            if (($account['auth_type'] ?? 'password') === 'oauth_ms') {
                $m->SMTPAuth = true;
                $m->AuthType = 'XOAUTH2';
                $token = MicrosoftOAuth::token((string)$account['ms_tenant'], (string)$account['ms_client_id'], EmailAccounts::secret($account, 'ms_client_secret'), MicrosoftOAuth::SCOPE_OUTLOOK);
                $m->setOAuth(new XOAuth2Token((string)($account['smtp_username'] ?: $account['email']), $token));
            } elseif ($account['smtp_auth']) {
                $m->SMTPAuth = true;
                $m->Username = (string)($account['smtp_username'] ?: $account['email']);
                $m->Password = EmailAccounts::secret($account, 'smtp_password');
            }
        } elseif ($protocol === 'mail') {
            $m->isMail();
        }
        return $m;
    }

    public static function layout(string $body, bool $separator = false): string
    {
        $brand = valid_hex_color((string)Settings::get('brand_color'));
        $name = e(Settings::get('helpdesk_name'));
        $sep = $separator
            ? '<div style="color:#73777f;font-size:12px;margin:0 0 16px">' . e(Settings::get('reply_separator')) . '</div>'
            : '';
        $footer = trim((string)Settings::get('footer_text'));
        return '<!doctype html><html' . lang_attr() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f3f6">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f3f6"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:16px;border:1px solid #c3c6cf;border-top:4px solid ' . $brand . '">'
            . '<tr><td dir="' . \Bakkbone\HelpCentre\Core\I18n::dir() . '" style="padding:24px 28px;font-family:Roboto,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1a1c1e">'
            . $sep . $body
            . '</td></tr></table>'
            . '<div style="font-family:Roboto,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;color:#43474e;padding:16px">'
            . $name . ($footer !== '' ? ' &middot; ' . e($footer) : '') . '</div>'
            . '</td></tr></table></body></html>';
    }

    public static function newMessageId(?int $ticketId = null): string
    {
        $rand = bin2hex(random_bytes(8));
        if ($ticketId) {
            // Signed so replies can be threaded to the ticket even if headers are rewritten.
            $sig = substr(hash_hmac('sha256', 'ticket:' . $ticketId, \Bakkbone\HelpCentre\Core\Crypto::key()), 0, 10);
            return "bkhc.$ticketId.$sig.$rand@" . self::host();
        }
        return "bkhc.$rand@" . self::host();
    }

    public static function ticketIdFromMessageId(string $mid): ?int
    {
        if (!preg_match('/^bkhc\.(\d+)\.([0-9a-f]{10})\.[0-9a-f]+@/i', $mid, $m)) {
            return null;
        }
        $sig = substr(hash_hmac('sha256', 'ticket:' . $m[1], \Bakkbone\HelpCentre\Core\Crypto::key()), 0, 10);
        return hash_equals($sig, strtolower($m[2])) ? (int)$m[1] : null;
    }

    public static function host(): string
    {
        $h = parse_url(App::baseUrl(), PHP_URL_HOST);
        return is_string($h) && $h !== '' ? $h : 'helpcentre.local';
    }

    /** @param list<array{email:string,name?:string}> $list */
    private static function uniqueRecipients(array $list): array
    {
        $seen = [];
        $out = [];
        $own = EmailAccounts::addresses();
        foreach ($list as $r) {
            $email = strtolower(trim((string)($r['email'] ?? '')));
            if ($email === '' || isset($seen[$email]) || in_array($email, $own, true) || !filter_var(MimeParser::asciiEmail($email), FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$email] = true;
            $out[] = ['email' => $email, 'name' => (string)($r['name'] ?? '')];
        }
        return $out;
    }
}
