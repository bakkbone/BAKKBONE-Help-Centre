# BAKKBONE Help Centre

A self-hosted help desk and ticketing system written in PHP, built as a modern alternative to osTicket.

📖 **Full documentation is in the [Wiki](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki).**

## Features

- **End-user portal**: open tickets, track and reply to them, browse the knowledge base. Mobile-first.
- **Agent panel** (`/panel`): queues, threaded tickets, assignment, SLA, tasks, users, organisations, canned responses and a knowledge base. Fully responsive, with automatic dark mode.
- **Email in and out**: IMAP, POP3, Microsoft 365 / Exchange Online (Graph API or OAuth 2.0), MTA piping and remote piping over HTTPS. Attachments and inline images work both ways, and failed sends are retried automatically. The PHP `imap` extension isn't needed.
- **Sign-in options**: password, **passwordless (emailed 6-digit code)** for agents and end users, and **TOTP two-factor authentication** for agents.
- **Tickets your way**: departments, help topics, custom fields, filters, SLA plans, custom statuses, collaborators, merging, collision locks, saved searches and bulk actions.
- **Webhooks**: send ticket events using a default JSON payload or **your own template filled with merge data from the ticket**, signed with HMAC.
- **REST API** compatible with osTicket's, so existing website forms only need a new URL.
- **Add-ons, custom styles and language packs** (including right-to-left) without touching core files.
- **One-click migration** from osTicket, and built-in copying between servers (staging → production).

See the [osTicket feature comparison](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/osTicket-Feature-Comparison) for what's included and what isn't yet.

## Requirements

- **PHP 8.1+** with `pdo`, `pdo_mysql` (or `pdo_sqlite`), `openssl`, `curl`, `mbstring` and `json`
- **MySQL 5.7+ / MariaDB 10.3+**, or SQLite for small installs
- Apache, nginx, IIS or any server that runs PHP
- Cron, Windows Task Scheduler or a web-cron service

**No Composer or shell access needed.** The only two libraries, [PHPMailer](https://github.com/PHPMailer/PHPMailer) and [HTML Purifier](http://htmlpurifier.org/), are downloaded and checksum-verified automatically on first load.

Details: [Requirements](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Requirements).

## Quick start

1. Create an empty MySQL/MariaDB database (or use SQLite).
2. Upload this folder and point the document root at `public/` (or, on Apache, upload its contents into the web root).
3. Make `storage/` and `config/` writable by PHP.
4. Open the site in your browser and follow the installer.
5. Sign in at `/panel`, add a mailbox under **Admin → Mailboxes**, and set up cron:

   ```cron
   */2 * * * * php /path/to/BAKKBONE-Help-Centre/bin/cron.php >/dev/null 2>&1
   ```

**Back up `config/config.php`**: it holds the `app_key` that encrypts stored secrets.

To try it locally:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Full instructions, including Composer, the command-line installer and server configuration: [Installation](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Installation).

## Documentation

| Topic | Wiki page |
|---|---|
| Installing and configuring | [Installation](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Installation) · [Web Server Configuration](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Web-Server-Configuration) · [Configuration File](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Configuration-File) · [Cron](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Cron-and-Scheduled-Tasks) |
| Email | [Email Setup](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Email-Setup) · [Microsoft 365](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Microsoft-365) · [Piping](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Email-Piping) · [Templates and Merge Tags](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Email-Templates-and-Merge-Tags) |
| Using the help desk | [Tickets](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Tickets) · [Filters](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Ticket-Filters) · [Agents and Roles](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Agents,-Roles-and-Permissions) · [Portals and Sign-In](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Portals-and-Sign-In) · [Settings](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Admin-Settings-Reference) |
| Integrating | [Webhooks](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Webhooks) · [REST API](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/REST-API) |
| Extending | [Add-ons](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Add-on-Development) · [Customising](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Customising) · [Translations](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Translations) |
| Running it | [Security](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Security) · [Upgrading and Backups](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Upgrading-and-Backups) · [Copying a Help Desk](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Copying-a-Help-Desk) · [Command-Line Tools](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Command-Line-Tools) · [Troubleshooting](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Troubleshooting) |
| Moving from osTicket | [Migrating from osTicket](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Migrating-from-osTicket) |

Guides that ship with the code:

- [ADDONS.md](ADDONS.md): writing add-ons
- [lang/README.md](lang/README.md): translating
- [custom/README.md](custom/README.md): your own styles, scripts and template overrides

## Upgrading

Upload the new release over the old files, **keeping `config/` and `storage/`**. Libraries and database changes update automatically on the next page load. See [Upgrading and Backups](https://github.com/bakkbone/BAKKBONE-Help-Centre/wiki/Upgrading-and-Backups).

---

osTicket is a registered trademark of Enhancesoft LLC. BAKKBONE Help Centre is not affiliated with or endorsed by osTicket or Enhancesoft, and contains no osTicket code.

## License

Copyright © BAKKBONE Australia.

BAKKBONE Help Centre is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public License](LICENSE) for more details.

Bundled third-party assets keep their own licences: the QR code generator (`public/assets/js/qrcode.js`, MIT), the icons (`public/assets/icons-LICENSE.txt`, Apache 2.0) and the Roboto fonts (`public/assets/fonts/`, SIL Open Font License). PHPMailer and HTML Purifier, downloaded on first load, are LGPL-2.1.
