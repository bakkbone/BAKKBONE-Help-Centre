<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Http;

final class MicrosoftOAuth
{
    public const SCOPE_GRAPH = 'https://graph.microsoft.com/.default';
    public const SCOPE_OUTLOOK = 'https://outlook.office365.com/.default';

    public static function token(string $tenant, string $clientId, string $clientSecret, string $scope = self::SCOPE_GRAPH): string
    {
        if ($tenant === '' || $clientId === '' || $clientSecret === '') {
            throw new MailException('Microsoft 365 tenant ID, client ID and client secret are all required.');
        }
        $cacheFile = self::cacheFile($tenant, $clientId, $clientSecret, $scope);
        if (is_file($cacheFile)) {
            $c = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['expires'] ?? 0) > time() + 120 && !empty($c['token'])) {
                return (string)$c['token'];
            }
        }
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
        [$status, $body] = Http::request('POST', $url, http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope,
            'grant_type' => 'client_credentials',
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $json = json_decode($body, true);
        if ($status !== 200 || empty($json['access_token'])) {
            $err = is_array($json) ? (($json['error'] ?? '') . ': ' . ($json['error_description'] ?? '')) : $body;
            throw new MailException('Microsoft OAuth token request failed (HTTP ' . $status . '): ' . mb_substr((string)$err, 0, 500));
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents($cacheFile, json_encode(['token' => $json['access_token'], 'expires' => time() + (int)($json['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($cacheFile, 0600);
        return (string)$json['access_token'];
    }

    private static function cacheFile(string $tenant, string $clientId, string $clientSecret, string $scope): string
    {
        return App::storage('cache/mstoken_' . hash('sha256', "$tenant|$clientId|$scope|" . substr(hash('sha256', $clientSecret), 0, 16)) . '.json');
    }

    /** Tokens carry the permissions granted when issued, so after a consent change the cached one keeps failing. */
    public static function forget(string $tenant, string $clientId, string $clientSecret, string $scope = self::SCOPE_GRAPH): void
    {
        @unlink(self::cacheFile($tenant, $clientId, $clientSecret, $scope));
    }

    public static function roles(string $token): array
    {
        $parts = explode('.', $token);
        $payload = isset($parts[1]) ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true) : null;
        return is_array($payload['roles'] ?? null) ? array_values(array_map('strval', $payload['roles'])) : [];
    }

    /** @param list<string> $needed */
    public static function explainDenied(string $token, array $needed, string $mailbox, string $api, string $clientId = ''): string
    {
        $roles = self::roles($token);
        $has = array_intersect($needed, $roles);
        if (!$has) {
            return ' The app\'s token has ' . ($roles ? 'only these application permissions: ' . implode(', ', $roles) : 'no application permissions at all')
                . '. In the Microsoft Entra admin centre, open the app registration → API permissions, add ' . implode(' or ', $needed)
                . " ($api, type \"Application\", not \"Delegated\"), then click \"Grant admin consent\" and test again.";
        }
        $app = $clientId !== '' ? $clientId : '<application-client-id>';
        return ' The app does have ' . implode(', ', $has) . ', so Exchange is refusing it for this mailbox specifically. Usually an application access policy'
            . ' (or RBAC for Applications scope) is limiting the app to other mailboxes, or a recent change hasn\'t applied yet (allow up to an hour).'
            . ' Check in Exchange Online PowerShell: Test-ApplicationAccessPolicy -Identity ' . $mailbox . ' -AppId ' . $app
            . ' (it should say AccessCheckResult: Granted). Also check ' . $mailbox . ' is an Exchange Online mailbox (user or shared), not an alias or a group.';
    }
}
