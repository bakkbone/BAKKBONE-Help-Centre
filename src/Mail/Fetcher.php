<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Log;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\EmailAccounts;

final class Fetcher
{
    /** @return array<string,string> */
    public static function run(): array
    {
        $summary = [];
        foreach (EmailAccounts::all(true) as $acc) {
            if (($acc['inbound_protocol'] ?? 'none') === 'none') {
                continue;
            }
            $summary[$acc['email']] = self::fetchAccount($acc);
        }
        return $summary;
    }

    public static function fetchAccount(array $acc): string
    {
        $max = max(1, Settings::int('mail_fetch_max'));
        $stats = ['created' => 0, 'appended' => 0, 'ignored' => 0, 'rejected' => 0, 'errors' => 0];
        $error = null;
        try {
            match ($acc['inbound_protocol']) {
                'imap' => self::fetchImap($acc, $max, $stats),
                'pop3' => self::fetchPop3($acc, $max, $stats),
                'graph' => self::fetchGraph($acc, $max, $stats),
                default => null,
            };
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Log::error('Mail fetch failed: ' . $acc['email'], $error);
        }
        App::db()->update('email_accounts', ['last_fetch_at' => DB::now(), 'last_error' => $error], 'id = ?', [$acc['id']]);
        $s = __('{new} new, {replies} replies, {ignored} ignored, {rejected} rejected', ['new' => $stats['created'], 'replies' => $stats['appended'], 'ignored' => $stats['ignored'], 'rejected' => $stats['rejected']]);
        if ($stats['errors']) {
            $s .= ', ' . _n('{n} error', '{n} errors', $stats['errors']);
        }
        return $error ? 'ERROR: ' . $error : $s;
    }

    /** Returns false to leave the message for a retry. */
    private static function handle(string $raw, array $acc, array &$stats): bool
    {
        try {
            $r = InboundProcessor::process($raw, $acc, $acc['inbound_protocol']);
            $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
            return true;
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::error('Failed to process email from ' . $acc['email'], $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
            return false;
        }
    }

    public static function imapConnect(array $acc): ImapClient
    {
        $c = new ImapClient();
        $enc = (string)($acc['in_encryption'] ?: 'ssl');
        $port = (int)($acc['in_port'] ?: ($enc === 'ssl' ? 993 : 143));
        $c->connect((string)$acc['in_host'], $port, $enc, !App::config('mail_insecure_tls', false));
        $user = (string)($acc['in_username'] ?: $acc['email']);
        if ($acc['auth_type'] === 'oauth_ms') {
            self::xoauth($c, $user, $acc, ['IMAP.AccessAsApp']);
        } else {
            $c->login($user, EmailAccounts::secret($acc, 'in_password'));
        }
        return $c;
    }

    private static function fetchImap(array $acc, int $max, array &$stats): void
    {
        $c = self::imapConnect($acc);
        try {
            $c->select((string)($acc['in_folder'] ?: 'INBOX'));
            $uids = array_slice($c->searchUnseen(), 0, $max);
            $archiveReady = false;
            foreach ($uids as $uid) {
                $raw = $c->fetchRaw($uid);
                if ($raw === '' || !self::handle($raw, $acc, $stats)) {
                    continue;
                }
                $action = (string)$acc['in_post_action'];
                if ($action === 'delete') {
                    $c->delete($uid);
                } elseif ($action === 'move' && $acc['in_archive_folder']) {
                    if (!$archiveReady) {
                        $c->createFolder((string)$acc['in_archive_folder']);
                        $archiveReady = true;
                    }
                    $c->markSeen($uid);
                    $c->move($uid, (string)$acc['in_archive_folder']);
                } else {
                    $c->markSeen($uid);
                }
            }
        } finally {
            $c->logout();
        }
    }

    private static function fetchPop3(array $acc, int $max, array &$stats): void
    {
        $c = new Pop3Client();
        $enc = (string)($acc['in_encryption'] ?: 'ssl');
        $port = (int)($acc['in_port'] ?: ($enc === 'ssl' ? 995 : 110));
        $c->connect((string)$acc['in_host'], $port, $enc, !App::config('mail_insecure_tls', false));
        $user = (string)($acc['in_username'] ?: $acc['email']);
        if ($acc['auth_type'] === 'oauth_ms') {
            self::xoauth($c, $user, $acc, ['POP.AccessAsApp']);
        } else {
            $c->login($user, EmailAccounts::secret($acc, 'in_password'));
        }
        $db = App::db();
        try {
            $n = 0;
            foreach ($c->uidl() as $num => $uid) {
                if ($n >= $max) {
                    break;
                }
                if ($db->val('SELECT COUNT(*) FROM mail_seen WHERE account_id = ? AND uid = ?', [$acc['id'], $uid])) {
                    continue;
                }
                $n++;
                if (!self::handle($c->retrieve($num), $acc, $stats)) {
                    continue;
                }
                if ($acc['in_post_action'] === 'delete') {
                    $c->delete($num);
                } else {
                    $db->insert('mail_seen', ['account_id' => $acc['id'], 'uid' => mb_substr($uid, 0, 190), 'created_at' => DB::now()]);
                }
            }
        } finally {
            $c->quit();
        }
    }

    public static function graphClient(array $acc): GraphClient
    {
        return new GraphClient((string)$acc['ms_tenant'], (string)$acc['ms_client_id'], EmailAccounts::secret($acc, 'ms_client_secret'), (string)($acc['in_username'] ?: $acc['email']));
    }

    private static function fetchGraph(array $acc, int $max, array &$stats): void
    {
        $g = self::graphClient($acc);
        $folder = (string)($acc['in_folder'] ?: 'inbox');
        foreach ($g->unreadMessageIds($folder, $max) as $id) {
            if (!self::handle($g->mime($id), $acc, $stats)) {
                continue;
            }
            $action = (string)$acc['in_post_action'];
            if ($action === 'delete') {
                $g->delete($id);
            } elseif ($action === 'move' && $acc['in_archive_folder']) {
                $g->markRead($id);
                $g->move($id, (string)$acc['in_archive_folder']);
            } else {
                $g->markRead($id);
            }
        }
    }

    public static function testInbound(array $acc): string
    {
        switch ($acc['inbound_protocol']) {
            case 'imap':
                $c = self::imapConnect($acc);
                $count = $c->select((string)($acc['in_folder'] ?: 'INBOX'));
                $unseen = count($c->searchUnseen());
                $c->logout();
                return _n('IMAP OK - folder has {n} message ({unread} unread).', 'IMAP OK - folder has {n} messages ({unread} unread).', $count, ['unread' => fmt_num($unseen)]);
            case 'pop3':
                $c = new Pop3Client();
                $enc = (string)($acc['in_encryption'] ?: 'ssl');
                $c->connect((string)$acc['in_host'], (int)($acc['in_port'] ?: ($enc === 'ssl' ? 995 : 110)), $enc);
                $user = (string)($acc['in_username'] ?: $acc['email']);
                if ($acc['auth_type'] === 'oauth_ms') {
                    self::xoauth($c, $user, $acc, ['POP.AccessAsApp']);
                } else {
                    $c->login($user, EmailAccounts::secret($acc, 'in_password'));
                }
                $n = count($c->uidl());
                $c->quit();
                return _n('POP3 OK - mailbox has {n} message.', 'POP3 OK - mailbox has {n} messages.', $n);
            case 'graph':
                return self::graphClient($acc)->test();
            default:
                return __('Inbound fetching is disabled for this mailbox.');
        }
    }

    /** A refused sign-in is retried once with a fresh token, in case the cached one predates a permission change. */
    private static function xoauth(object $c, string $user, array $acc, array $needed): void
    {
        [$tenant, $client, $secret] = [(string)$acc['ms_tenant'], (string)$acc['ms_client_id'], EmailAccounts::secret($acc, 'ms_client_secret')];
        try {
            $c->authenticateXoauth2($user, MicrosoftOAuth::token($tenant, $client, $secret, MicrosoftOAuth::SCOPE_OUTLOOK));
            return;
        } catch (MailException $first) {
            MicrosoftOAuth::forget($tenant, $client, $secret, MicrosoftOAuth::SCOPE_OUTLOOK);
        }
        $token = MicrosoftOAuth::token($tenant, $client, $secret, MicrosoftOAuth::SCOPE_OUTLOOK);
        try {
            $c->authenticateXoauth2($user, $token);
        } catch (MailException $e) {
            $roles = MicrosoftOAuth::roles($token);
            $hint = array_intersect($needed, $roles)
                ? ' The app has ' . implode(', ', array_intersect($needed, $roles)) . ', so check the mailbox permission in Exchange Online PowerShell: the service principal must be registered with New-ServicePrincipal using the Object ID from Enterprise applications, and given Add-MailboxPermission -Identity ' . $user . ' -AccessRights FullAccess. Changes can take up to an hour.'
                : MicrosoftOAuth::explainDenied($token, $needed, $user, 'Office 365 Exchange Online', $client);
            throw new MailException($e->getMessage() . $hint);
        }
    }
}
