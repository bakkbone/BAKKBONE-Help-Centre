<?php
// Example add-on: see ADDONS.md for what each call does. Copy this folder rather than editing it, as updates replace it.
declare(strict_types=1);

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;

/** @var \Bakkbone\HelpCentre\Core\Addon $addon */

$addon->permission('view_stats', __('View the Hello World stats page'));

$addon->slot('panel.head', fn() => '<link rel="stylesheet" href="' . e($addon->asset('hello.css')) . '">');

$addon->on('ticket.created', function (array $ticket, array $extra) use ($addon) {
    $addon->log('New ticket #' . $ticket['number'], (string)$ticket['subject']);
});

$addon->filter('ticket.context', function (array $ctx, array $ticket) use ($addon) {
    $ctx['hello'] = ['greeting' => (string)$addon->setting('greeting')];
    return $ctx;
});

$addon->filter('mail.outgoing', function (array $msg) use ($addon) {
    $footer = trim((string)$addon->setting('email_footer'));
    if ($footer !== '') {
        $msg['html'] .= '<p style="font-size:12px;color:#5f6368">' . nl2br(e($footer)) . '</p>';
    }
    return $msg; // return false instead to stop the email being sent
});

$addon->slot('panel.ticket.sidebar', function (array $ctx) use ($addon) {
    $notes = $addon->db()->all('SELECT * FROM ' . $addon->table('notes') . ' WHERE ticket_id = ? ORDER BY id DESC LIMIT 20', [$ctx['ticket']['id']]);
    return $addon->render('sidebar', ['ticket' => $ctx['ticket'], 'notes' => $notes, 'colour' => (string)$addon->setting('colour')]);
});

$addon->panelRoute('POST', '/notes/{id:\d+}', function (string $id) use ($addon) {
    $ticket = Tickets::find((int)$id);
    $staff = Auth::staff();
    if (!$ticket || !Staff::canAccessTicket($staff, $ticket)) {
        \Bakkbone\HelpCentre\Core\Router::notFound();
    }
    $body = trim(Request::str('body'));
    if ($body !== '') {
        $addon->db()->insert($addon->table('notes'), [
            'ticket_id' => (int)$ticket['id'], 'staff_id' => (int)$staff['id'], 'body' => mb_substr($body, 0, 2000), 'created_at' => DB::now(),
        ]);
        Session::flash('success', __('Note added.'));
    }
    \Bakkbone\HelpCentre\Core\View::redirect('/panel/tickets/' . (int)$ticket['id']);
});

$addon->navItem(__('Hello stats'), '', 'extension', 'view_stats');
$addon->panelRoute('GET', '', function () use ($addon) {
    $db = $addon->db();
    $addon->view('stats', [
        'title' => __('Hello stats'),
        'notes' => (int)$db->val('SELECT COUNT(*) FROM ' . $addon->table('notes')),
        'open' => (int)$db->val("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE s.state = 'open' AND t.merged_into IS NULL"),
        'today' => (int)$db->val('SELECT COUNT(*) FROM tickets WHERE created_at >= ?', [gmdate('Y-m-d 00:00:00')]),
        'recent' => $db->all('SELECT n.*, t.number, t.subject FROM ' . $addon->table('notes') . ' n JOIN tickets t ON t.id = n.ticket_id ORDER BY n.id DESC LIMIT 10'),
    ]);
}, 'view_stats');

$addon->slot('panel.dashboard', function (array $ctx) use ($addon) {
    if (!$addon->setting('show_dashboard')) {
        return '';
    }
    $name = $ctx['staff']['first_name'] ?? __('there');
    return '<div class="hello-banner">' . icon('extension') . '<span>' . t('{greeting}, {name}!', ['greeting' => $addon->setting('greeting'), 'name' => $name]) . '</span></div>';
});

$addon->portalRoute('GET', '', function () use ($addon) {
    $addon->view('portal', ['title' => __('Hello'), 'greeting' => (string)$addon->setting('greeting')], 'portal');
});

$addon->apiRoute('GET', '/stats', function (?array $apiKey) use ($addon) {
    \Bakkbone\HelpCentre\Core\View::json([
        'notes' => (int)$addon->db()->val('SELECT COUNT(*) FROM ' . $addon->table('notes')),
        'key' => $apiKey['name'] ?? null,
    ]);
});

$addon->cron('tidy', function () use ($addon) {
    $n = $addon->db()->delete($addon->table('notes'), 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 730 * 86400)]);
    return "removed $n old notes";
});

$addon->onEnable(fn() => $addon->log('Switched on'));
$addon->onDisable(fn() => $addon->log('Switched off'));
