<?php
// Only for writing config/config.php by hand; the installer normally creates it (see README → Installation).
return [
    'installed' => true,

    'db' => [
        'driver' => 'mysql', // 'mysql' (MySQL 5.7+ / MariaDB 10.3+) or 'sqlite'
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'helpcentre',
        'user' => 'helpcentre',
        'pass' => 'change-me',
        // 'socket' => '/var/run/mysqld/mysqld.sock',
        // SQLite instead: 'driver' => 'sqlite', 'path' => __DIR__ . '/../storage/helpcentre.sqlite',
    ],

    // Back this up: changing it makes every stored secret (mailbox passwords, 2FA, OAuth) unreadable.
    'app_key' => 'base64:REPLACE_WITH_RANDOM_KEY',

    // Secret for the web cron URL /api/cron?key=...
    'cron_key' => 'REPLACE_WITH_RANDOM_STRING',

    'base_url' => 'https://help.example.com', // no trailing slash

    // false gives /index.php/panel/... URLs, for servers without mod_rewrite or the nginx rule.
    'pretty_urls' => true,

    'debug' => false,

    // Proxies whose X-Forwarded-For/-Proto are trusted; ['*'] behind Cloudflare only if the origin isn't directly reachable.
    'trusted_proxies' => [],

    // 'storage_path' => '/var/lib/helpcentre', // attachments, cache, sessions
    // 'webhooks_allow_private' => false, // allow webhooks to private/LAN addresses
    // 'mail_insecure_tls' => false, // skip TLS certificate checks for IMAP/POP (not recommended)
    // 'safe_mode' => true, // load no add-ons, if one stops the site loading
    // 'addon_uploads' => true, // let admins upload add-on .zips (an add-on can run any code)
];
