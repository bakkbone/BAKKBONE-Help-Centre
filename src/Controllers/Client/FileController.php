<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Services\Attachments;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
use Bakkbone\HelpCentre\Services\Users;

final class FileController extends Controller
{
    public function show(int $id): void
    {
        $att = Attachments::find($id);
        if (!$att) {
            $this->notFound();
        }
        $inline = Request::bool('inline');
        $ticket = $att['ticket_id'] ? Tickets::find((int)$att['ticket_id']) : null;

        if ($staff = Auth::staff()) {
            if ($ticket && Staff::canAccessTicket($staff, $ticket)) {
                Attachments::output($att, $inline);
            }
            if (!$ticket && $att['task_id']) {
                Attachments::output($att, $inline);
            }
        }

        if ($ticket && $att['entry_id']) {
            $entryType = App::db()->val('SELECT type FROM thread_entries WHERE id = ?', [$att['entry_id']]);
            if ($entryType !== 'N') {
                $user = Auth::user();
                if ($user && Tickets::clientCanView($user, $ticket)) {
                    Attachments::output($att, $inline);
                }
                $guest = Auth::guest();
                if ($guest && (int)$guest['ticket_id'] === (int)$ticket['id']) {
                    Attachments::output($att, $inline);
                }
            }
        }
        if (!Auth::staff() && !Auth::user() && !Auth::guest()) {
            $this->redirect('/login');
        }
        Router::forbidden(__('You do not have access to this file.'));
    }
}
