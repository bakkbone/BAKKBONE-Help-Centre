# Writing add-ons for BAKKBONE Help Centre

Add-ons let you add features without editing the help desk's own files, so
updating BAKKBONE Help Centre never overwrites your work. An add-on can:

- react when something happens (a ticket is created, closed, transferred…)
- change things as they pass through (outgoing emails, merge tags, new tickets)
- add HTML to pages (the ticket sidebar, the dashboard, every page's `<head>`)
- add its own pages to the agent panel and the end-user portal, with menu links
- add API endpoints, scheduled jobs, permissions, settings and database tables

For small visual changes you don't need an add-on — see `custom/README.md`
(your own `custom.css`, `custom.js` and template overrides).

The quickest start is the example in `addons/hello-world/`, which uses nearly
everything below. Copy the folder, rename it and change `name` in `addon.json`.
Don't edit the example in place: updates ship a fresh copy of it.

Add-ons must be licensed under the GPL, version 3 or later — see
"Licensing" at the end.

---

## 1. Layout

```
addons/
  my-addon/              ← the folder name is the add-on's "slug"
    addon.json           ← required: name, version, settings…
    addon.php            ← required: runs on every request while switched on
    schema.php           ← optional: your database tables
    templates/           ← optional: your page templates (*.php)
    assets/              ← optional: CSS, JS, images, served at /addons/my-addon/assets/…
    lang/                ← optional: translations of your text (see "Translations")
    src/                 ← optional: your classes (see "autoload")
```

The slug must be lower-case letters, numbers and dashes, at most 40 characters.
Folders starting with `_` or `.` are ignored (handy for drafts).

Switch add-ons on and off under **Admin → Add-ons**. Switching off keeps the
add-on's settings and tables, so switching back on picks up where it left off.

## 2. addon.json

```json
{
    "name": "My Add-on",
    "version": "1.0.0",
    "description": "One line shown on the Add-ons page.",
    "author": "You",
    "url": "https://example.com",
    "requires": { "helpcentre": ">=1.0.0", "php": ">=8.1", "extensions": ["curl"] },
    "autoload": { "Acme\\MyAddon\\": "src/" },
    "settings": {
        "api_token": { "label": "API token", "type": "password", "required": true },
        "mode": { "label": "Mode", "type": "select", "options": { "live": "Live", "test": "Test" }, "default": "test" }
    }
}
```

| Key        | Notes |
|------------|-------|
| `version`  | Bump it when you change `schema.php`; new tables/columns are added on the next page load. |
| `requires` | If the server doesn't meet these, the add-on shows "Can't load" with the reason. |
| `autoload` | PSR-4: `Acme\MyAddon\Foo` loads `src/Foo.php`. (The `Bakkbone\` namespace is reserved.) |
| `main`     | Optional: a different file name instead of `addon.php`. |
| `settings` | Shown as a form under **Admin → Add-ons → Settings**. |

**Setting types:** `text`, `textarea`, `number`, `email`, `url`, `color`,
`checkbox`, `select` (with `options`, either `{"value": "Label"}` or a plain
list) and `password` (stored encrypted, never shown again). Each can have
`label`, `hint`, `default` and `required`.

## 3. addon.php

The file runs with `$addon` in scope. Register what you need, then return —
don't do slow work here, because it runs on every request.

```php
<?php
/** @var \Bakkbone\HelpCentre\Core\Addon $addon */

$addon->on('ticket.closed', function (array $ticket, array $extra) use ($addon) {
    $addon->log('Closed #' . $ticket['number']);
});
```

### The `$addon` API

| Method | What it does |
|--------|--------------|
| `on($event, $fn, $priority = 10)` | Run `$fn` when an event happens (see §4). Lower priority runs first. |
| `filter($name, $fn, $priority = 10)` | Change a value; `$fn` gets the value (plus context) and **must return it** (see §5). |
| `slot($name, $fn, $priority = 10)` | Add HTML to a page; `$fn(array $context)` returns or echoes HTML (see §6). |
| `panelRoute($method, $path, $fn, $permission = null)` | Agent page at `/panel/addons/{slug}{path}`. Requires a signed-in agent. |
| `portalRoute($method, $path, $fn)` | Public page at `/addons/{slug}{path}`. |
| `apiRoute($method, $path, $fn, $requireKey = true)` | API endpoint at `/api/addons/{slug}{path}`. Needs a valid API key unless `$requireKey` is false; the key's row is passed as the last argument. |
| `navItem($label, $path = '', $icon = 'extension', $permission = null)` | Link in the agent menu. `$icon` is an icon name from `public/assets/icons.svg`, or an image URL such as `$addon->asset('icon.svg')`. |
| `portalNavItem($label, $path = '', $signedInOnly = false)` | Link in the portal's top menu. |
| `view($template, $data = [], $area = 'panel')` | Render `templates/{template}.php` inside the panel (or `'portal'`) layout. Set `title` in `$data`. |
| `render($template, $data = [])` | Render a template to a string (for slots and emails). |
| `url($path)`, `portalUrl($path)`, `asset($file)` | URLs for your panel pages, portal pages and `assets/` files. |
| `setting($key, $default = null)` / `setSetting($key, $value)` | Read and write settings, including your own state. |
| `permission($key, $label)` / `can($key)` | Register a permission admins can give to roles, and check it. |
| `db()` / `table($name)` | The database, and the full name of one of your tables (`notes` → `addon_my_addon_notes`). |
| `cron($name, $fn)` | Run `$fn` on every cron run. Return a short status string. |
| `onEnable($fn)` / `onDisable($fn)` | Run once when an admin switches the add-on on or off. |
| `log($message, $details = '', $level = 'info')` | Write to **Admin → System log**. |

Routes use the same patterns as the core: `'/item/{id:\d+}'` passes `id` to your
function as a string. `$method` is `GET`, `POST` or `ANY`. **POST requests from
the panel and portal are CSRF-checked automatically**, so include
`<?= csrf_field() ?>` in your forms. API routes aren't (they use API keys).

Inside templates you have the core helpers — `e()` (escape — always use it for
output), `url()`, `icon()`, `csrf_field()`, `fmt_dt()`, `time_ago()`,
`setting()` — plus `$addon` and anything you pass in `$data`.

Useful core classes: `Services\Tickets` (`find`, `context`, `postNote`,
`setStatus`, `assign`, `transfer`…), `Services\Users`, `Services\Staff`
(`can`, `canAccessTicket`), `Services\Auth` (`staff()` = signed-in agent,
`user()` = signed-in end user), `Core\Request`, `Core\Session` (`flash`),
`Core\View` (`json`, `redirect`), `Mail\Mailer::send`. All are under the
`Bakkbone\HelpCentre\` namespace.

## 4. Events

Every listener gets the **ticket row** (as an array) and an **`$extra`** array.
`$extra` holds the acting agent (`staff.name`, `staff.email`) where there is one,
plus the details listed.

| Event | When | `$extra` also has |
|-------|------|-------------------|
| `ticket.created` | A ticket is opened (any source) | `message`, `poster.name` |
| `ticket.message` | The end user adds a message | `message`, `poster.name` |
| `ticket.reply` | An agent replies | `message`, `poster.name` |
| `ticket.note` | An internal note is added | `message`, `poster.name` |
| `ticket.assigned` | Assigned or unassigned | |
| `ticket.status` | Status changes | |
| `ticket.closed` | Status changes to a closed state | |
| `ticket.transferred` | Moved to another department | `from_dept`, `from_dept_id`, `comments` |
| `ticket.priority` | Priority changes | `from_priority` |
| `ticket.merged` | Other tickets were merged into this one | `merged_ids` |
| `ticket.deleted` | About to be deleted (the ticket still exists) | |
| `ticket.overdue` | Became overdue (from cron) | |
| `ticket.any` | Any of the above. Listener gets `($event, $ticket, $extra)` | |

Other events:

| Event | Listener gets |
|-------|---------------|
| `ticket.updated` | `($ticket, $changes, $agent)` after subject/priority/topic/SLA/due/user edits. `$changes` is `['field' => ['from' => …, 'to' => …]]`. |
| `user.created` | `($user)` when a new end user is added (by email, portal, API or agent). |
| `cron` | `()` once per cron run (or use `$addon->cron()` for a named job). |

The same ticket events can be sent to other systems without code under
**Admin → Webhooks**.

## 5. Filters

A filter gets a value and returns it, changed or not. **Always return the value.**

| Filter | Value | Also gets | Notes |
|--------|-------|-----------|-------|
| `ticket.context` | Merge-tag data (array) | `$ticket` | Add keys to create merge tags: `$ctx['crm']['tier'] = 'Gold'` gives `%{crm.tier}` in email templates and webhooks. |
| `ticket.before_create` | New-ticket data (array) | | Change fields (`subject`, `message`, `email`, `name`, `source`, `topic_id`, `dept_id`, `priority_id`, `sla_id`, `staff_id`, `team_id`, `status_id`), or return `['reject' => 'Reason']` to refuse the ticket. Routing fields you set here override help topics and filters. |
| `mail.outgoing` | Email (array: `to`, `cc`, `subject`, `html`, `headers`…) | the mailbox row | Change any part, or return `false` to stop the email. |

## 6. Page slots

Slots let you add HTML to pages. Your function gets a `$context` array and returns
(or echoes) HTML. **Escape anything that comes from users with `e()`.**

| Slot | Where | `$context` |
|------|-------|------------|
| `panel.head` / `portal.head` | Inside `<head>`: stylesheets, meta tags | |
| `panel.body_end` / `portal.body_end` | Before `</body>`: `<script src="…">` tags | |
| `panel.dashboard` | Top of the agent dashboard | `staff` |
| `panel.ticket.sidebar` | End of the ticket sidebar | `ticket`, `user`, `staff` |
| `panel.ticket.main` | Below the reply box | `ticket`, `user`, `staff` |
| `panel.user.sidebar` | End of a user's sidebar | `user` |
| `portal.ticket.sidebar` | End of the ticket sidebar on the portal | `ticket` |

Use the help desk's CSS classes (`card`, `card-head`, `card-body`, `btn`,
`btn-sm`, `field`, `badge`, `alert alert-warning`…) so your HTML matches, and
its CSS variables (`var(--md-primary)`, `var(--md-surface-container)`…) so it
works in light and dark mode.

**Scripts:** inline `<script>` blocks are blocked by the security policy. Put
JavaScript in a file in `assets/` and add it with
`slot('panel.body_end', fn() => '<script src="' . e($addon->asset('app.js')) . '" defer></script>')`.
To call your own routes from JavaScript, send the CSRF token from the page's
`<meta name="csrf-token">` as an `X-CSRF-Token` header on POSTs.

## 7. Database tables

Put your tables in `schema.php`, in the same format as `database/schema.php`:

```php
<?php
return [
    'notes' => [
        'columns' => ['id' => 'pk', 'ticket_id' => 'int|0', 'body' => 'text', 'created_at' => 'datetime'],
        'index' => [['ticket_id']],
    ],
];
```

Names get your prefix automatically (`addon_my_addon_notes`), so use
`$addon->table('notes')` in queries. Column types: `pk`, `int`, `bigint`,
`bool`, `string:<length>`, `text`, `longtext`, `datetime`; add `|default`
(e.g. `'bool|0'`). Tables and columns are added when the add-on is switched on
and whenever its `version` changes. Nothing is ever dropped. Store times with
`\Bakkbone\HelpCentre\Core\DB::now()` (UTC).

`$addon->db()` returns the help desk's database wrapper: `all($sql, $params)`,
`row()`, `val()`, `col()`, `insert($table, $data)` (returns the id),
`update($table, $data, $where, $params)`, `delete($table, $where, $params)` and
`tx(fn)`. Always pass values as parameters (`?`), never in the SQL string. It
works on both MySQL and SQLite as long as you stick to standard SQL.

Add-on tables aren't copied when cloning a help desk or running a catch-up
import; copy them yourself if you need to.

## 8. Translations

The help desk runs in one language, chosen by the admin (see
[lang/README.md](lang/README.md)). Write your text in English and pass it
through the same helpers the core uses, so it follows that choice:

```php
$addon->navItem(__('Hello stats'), '', 'extension', 'view_stats');
Session::flash('success', __('Note added.'));
```

```php
<h1><?= t('{name} settings', ['name' => $title]) ?></h1>
<p><?= tn('{n} note', '{n} notes', $count) ?></p>
```

`__()` and `_n()` return plain text; `t()` and `tn()` return HTML-safe text.
Write whole sentences with `{placeholders}` rather than joining pieces, and
keep the text as a literal in the call so it can be extracted. The `name`,
`description`, setting labels, hints and option labels in `addon.json` are
translated too.

Translators put `<code>.json` files in your `lang/` folder. Build the list of
your strings with:

```
php bin/i18n.php extract --addon=my-addon    # writes addons/my-addon/lang/template.json
```

It only lists text the core doesn't already translate. An add-on's pack can't
change the language's plural rule or direction; those come from the core pack.

Use CSS logical properties (`margin-inline-start`, `padding-inline-end`,
`inset-inline-start`…) instead of left and right in your styles, so pages
mirror correctly in right-to-left languages.

## 9. When something goes wrong

- If a listener, filter or slot **throws an exception**, it's skipped and the
  rest of the page carries on. The error is written to **Admin → System log**
  and shown on the Add-ons page.
- If an add-on causes a **fatal PHP error** (a syntax error, calling a missing
  function…), it's **switched off automatically**. The reason is shown on the
  Add-ons page. Fix it and switch it back on.
- If the site still won't load, add `'safe_mode' => true,` to
  `config/config.php`. No add-ons load until you remove it. Renaming or deleting
  the add-on's folder also works.
- Set `'debug' => true` in `config/config.php` while developing to see errors
  on screen (never on a live site).

## 10. Installing and updating

- **By FTP / file manager:** copy the add-on's folder into `addons/`, then
  switch it on under **Admin → Add-ons**. To update, replace the folder; any
  `schema.php` changes apply on the next page load if `version` was bumped.
- **By upload:** add `'addon_uploads' => true,` to `config/config.php` to get
  an upload box on the Add-ons page. It takes a `.zip` with `addon.json` at the
  top (or inside one folder). It's off by default because an add-on can run any
  code on the server, so anyone with an admin account could too.

Add-ons have full access to the help desk and its database. Only install ones
you wrote or trust.

When you update BAKKBONE Help Centre, upload everything **except** `config/`
and `storage/`. Uploading `addons/` and `custom/` is safe: the release only
contains the example add-on and README files there, so your own files aren't
touched.

## 11. Licensing

Add-ons must be licensed under the GNU General Public License, version 3 or
later (`GPL-3.0-or-later`), the same licence as BAKKBONE Help Centre. Add-ons
run inside the help desk and use its classes, hooks and templates, so they're
treated as part of it.

When you distribute an add-on:

- include the GPL licence text (a `LICENSE` file in the add-on's folder);
- make the source available to everyone you give the add-on to — for a PHP
  add-on, shipping the folder as-is does this;
- check that any libraries you bundle are GPL-compatible.

See [LICENSE](LICENSE) and the README's License section.
