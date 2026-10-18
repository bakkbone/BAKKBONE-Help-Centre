<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\Attachments;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Captcha;
use Bakkbone\HelpCentre\Services\Tickets;
use Bakkbone\HelpCentre\Services\Users;

final class TicketController extends Controller
{
    public function create(): void
    {
        $user = Auth::user();
        if (!$user && Settings::bool('client_require_login')) {
            Session::set('user_return', App::url('/open'));
            $this->flash('info', __('Please sign in to open a ticket.'));
            $this->redirect('/login');
        }
        $topics = App::db()->all('SELECT * FROM help_topics WHERE active = 1 AND public = 1 ORDER BY sort, name');
        $this->view('client/open', [
            'title' => __('New support ticket'),
            'user' => $user,
            'topics' => $topics,
            'fields' => App::db()->all('SELECT * FROM custom_fields WHERE active = 1 AND client_visible = 1 ORDER BY sort, id'),
        ]);
    }

    public function store(): void
    {
        $user = Auth::user();
        if (!$user && Settings::bool('client_require_login')) {
            $this->redirect('/login');
        }
        $errors = [];
        if (!$user && !Captcha::verify()) {
            $errors[] = __('Please complete the spam check and try again.');
        }
        $email = $user ? (string)$user['email'] : strtolower(Request::str('email'));
        $name = $user ? (string)$user['name'] : Request::str('name');
        $subject = Request::str('subject');
        $message = (string)Request::post('message', '');
        $topicId = Request::int('topic_id') ?: null;

        if (!$user) {
            if ($name === '') {
                $errors[] = __('Please enter your name.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Please enter a valid email address.');
            }
        }
        if ($subject === '') {
            $errors[] = __('Please enter a subject.');
        }
        if (trim(strip_tags($message, '<img>')) === '') {
            $errors[] = __('Please describe your request.');
        }
        if ($topicId) {
            $topic = Tickets::lookup('help_topics', $topicId);
            if (!$topic || !$topic['public'] || !$topic['active']) {
                $errors[] = __('Please choose a valid help topic.');
                $topicId = null;
            }
        } elseif ((int)App::db()->val('SELECT COUNT(*) FROM help_topics WHERE active = 1 AND public = 1') > 0) {
            $errors[] = __('Please choose a help topic.');
        }
        [$fieldValues, $fieldErrors] = Tickets::validateFields(Tickets::customFields($topicId, true), (array)Request::post('field', []));
        $errors = array_merge($errors, $fieldErrors);

        $files = [];
        if (Settings::bool('attachments_enabled') && Settings::bool('client_attachments')) {
            [$files, $fileErrors] = Attachments::validateUploads(Request::files('attachments'));
            $errors = array_merge($errors, $fileErrors);
        }
        if ($errors) {
            $this->failWith($errors, '/open');
        }

        $result = Tickets::create([
            'email' => $email,
            'name' => $name,
            'phone' => $user ? null : (Request::str('phone') ?: null),
            'user_id' => $user['id'] ?? null,
            'subject' => $subject,
            'message' => $message,
            'source' => 'web',
            'topic_id' => $topicId,
            'priority_id' => null,
            'fields' => $fieldValues,
            'attachments' => $files,
            'ip' => Request::ip(),
        ]);
        if (!$result['ok']) {
            $this->failWith($result['error'] ?? __('Sorry, we could not create your ticket.'), '/open');
        }
        $t = $result['ticket'];
        if ($user) {
            $this->flash('success', __('Ticket #{number} has been created.', ['number' => $t['number']]));
            $this->redirect('/tickets/' . $t['number']);
        }
        Session::set('submitted_ticket', $t['number']);
        $this->redirect('/submitted');
    }

    public function submitted(): void
    {
        $number = Session::get('submitted_ticket');
        if (!$number) {
            $this->redirect('/');
        }
        $this->view('client/submitted', ['title' => __('Ticket submitted'), 'number' => $number]);
    }

    public function index(): void
    {
        $user = Auth::requireUser();
        $db = App::db();
        $state = Request::str('state', 'open');
        $q = Request::str('q');
        [$page, $per, $offset] = $this->paging(20);

        $where = ['t.merged_into IS NULL'];
        $params = [];
        $scope = ['t.user_id = ?'];
        $params[] = (int)$user['id'];
        if (Settings::bool('collaborator_ticket_visibility')) {
            $scope[] = 't.id IN (SELECT ticket_id FROM ticket_collaborators WHERE user_id = ? AND active = 1)';
            $params[] = (int)$user['id'];
        }
        $org = Users::org($user['org_id'] ? (int)$user['org_id'] : null);
        if ($org && $org['sharing']) {
            $scope[] = 't.user_id IN (SELECT id FROM users WHERE org_id = ?)';
            $params[] = (int)$org['id'];
        }
        $where[] = '(' . implode(' OR ', $scope) . ')';
        if (in_array($state, ['open', 'closed'], true)) {
            $ids = Tickets::statusesByState($state) ?: [0];
            $where[] = 't.status_id IN ' . DB::in($ids);
            array_push($params, ...$ids);
        }
        if ($q !== '') {
            $where[] = '(t.number LIKE ? OR t.subject LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int)$db->val("SELECT COUNT(*) FROM tickets t WHERE $sqlWhere", $params);
        $rows = $db->all(
            "SELECT t.*, u.name AS owner_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE $sqlWhere ORDER BY t.updated_at DESC LIMIT $per OFFSET $offset",
            $params
        );
        $this->view('client/tickets', [
            'title' => __('My tickets'), 'tickets' => $rows, 'state' => $state, 'q' => $q, 'page' => $page,
            'pages' => (int)ceil($total / $per), 'total' => $total, 'user' => $user,
        ]);
    }

    private function access(string $number): array
    {
        $t = Tickets::byNumber($number);
        if (!$t) {
            $this->notFound();
        }
        if ($t['merged_into']) {
            $parent = Tickets::find((int)$t['merged_into']);
            if ($parent) {
                $this->redirect('/tickets/' . $parent['number']);
            }
        }
        $user = Auth::user();
        if ($user && Tickets::clientCanView($user, $t)) {
            return [$t, $user];
        }
        $guest = Auth::guest();
        if ($guest && (int)$guest['ticket_id'] === (int)$t['id']) {
            $gu = Users::find((int)$guest['user_id']);
            if ($gu && $gu['status'] === 'active') {
                return [$t, $gu];
            }
        }
        if (!$user) {
            Session::set('user_return', App::url('/tickets/' . $number));
            $this->flash('info', __('Please sign in to view that ticket.'));
            $this->redirect('/login');
        }
        \Bakkbone\HelpCentre\Core\Router::forbidden(__('You do not have access to this ticket.'));
    }

    public function show(string $number): void
    {
        [$t, $viewer] = $this->access($number);
        $entries = Tickets::entries((int)$t['id'], false);
        $status = Tickets::lookup('ticket_statuses', $t['status_id']);
        $this->view('client/ticket', [
            'title' => '#' . $t['number'] . ' ' . $t['subject'],
            't' => $t,
            'viewer' => $viewer,
            'isGuest' => !Auth::user(),
            'entries' => $entries,
            'status' => $status,
            'isOpen' => Tickets::isOpen($t),
            'fields' => array_values(array_filter(Tickets::fieldValues((int)$t['id']), fn($f) => $f['client_visible'])),
            'owner' => Users::find((int)$t['user_id']),
        ]);
    }

    public function reply(string $number): void
    {
        [$t, $viewer] = $this->access($number);
        $message = (string)Request::post('message', '');
        $errors = [];
        if (trim(strip_tags($message, '<img>')) === '') {
            $errors[] = __('Please enter a message.');
        }
        $files = [];
        if (Settings::bool('attachments_enabled') && Settings::bool('client_attachments')) {
            [$files, $fileErrors] = Attachments::validateUploads(Request::files('attachments'));
            $errors = array_merge($errors, $fileErrors);
        }
        if (!Tickets::isOpen($t) && !Settings::bool('reopen_closed')) {
            $errors[] = __('This ticket is closed. Please open a new ticket.');
        }
        if ($errors) {
            $this->failWith($errors, '/tickets/' . $number);
        }
        if (!Tickets::isParticipant($t, (int)$viewer['id'])) {
            Tickets::addCollaborator((int)$t['id'], (int)$viewer['id']);
        }
        Tickets::postMessage($t, $viewer, $message, $files, ['source' => 'web', 'ip' => Request::ip()]);
        $this->flash('success', __('Your reply has been posted.'));
        $this->redirect('/tickets/' . $number);
    }

    public function close(string $number): void
    {
        [$t, $viewer] = $this->access($number);
        if (!Settings::bool('allow_client_close') || (int)$t['user_id'] !== (int)$viewer['id']) {
            \Bakkbone\HelpCentre\Core\Router::forbidden();
        }
        $status = (int)Settings::get('resolved_status_id');
        if ($status && Tickets::isOpen($t)) {
            Tickets::setStatus($t, $status, null, false);
            Tickets::event((int)$t['id'], 'closed_by_user', [], null, (int)$viewer['id']);
            $this->flash('success', __('Ticket marked as resolved. Reply any time to reopen it.'));
        }
        $this->redirect('/tickets/' . $number);
    }

    public function guestLink(string $number, string $token): void
    {
        $t = Tickets::byNumber($number);
        $uid = $t ? Tickets::verifyGuestToken($t, $token) : null;
        if (!$t || !$uid) {
            $this->flash('error', __('That link is invalid or has expired. Please sign in to view your ticket.'));
            $this->redirect('/login');
        }
        $user = Auth::user();
        if (!$user || (int)$user['id'] !== $uid) {
            $u = Users::find($uid);
            if (!$u || $u['status'] !== 'active') {
                $this->redirect('/login');
            }
            Auth::setGuest((int)$t['id'], $uid);
        }
        $this->redirect('/tickets/' . $t['number']);
    }
}
