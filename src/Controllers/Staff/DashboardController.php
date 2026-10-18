<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;

final class DashboardController extends StaffController
{
    public function index(): void
    {
        $db = App::db();
        [$scope, $params] = Staff::ticketScope($this->staff);
        $open = Tickets::statusesByState('open') ?: [0];
        $closed = Tickets::statusesByState('closed') ?: [0];
        $openIn = 't.status_id IN ' . DB::in($open);
        $base = "FROM tickets t WHERE $scope AND t.merged_into IS NULL";
        $count = fn(string $extra, array $p = []) => (int)$db->val("SELECT COUNT(*) $base AND $extra", array_merge($params, $p));

        $tz = Settings::timezone();
        $todayStart = (new \DateTimeImmutable('today', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $stats = [
            'open' => $count($openIn, $open),
            'mine' => $count("$openIn AND t.staff_id = ?", array_merge($open, [(int)$this->staff['id']])),
            'unassigned' => $count("$openIn AND t.staff_id IS NULL AND t.team_id IS NULL", $open),
            'overdue' => $count("$openIn AND t.is_overdue = 1", $open),
            'new_today' => $count('t.created_at >= ?', [$todayStart]),
            'closed_today' => $count('t.status_id IN ' . DB::in($closed) . ' AND t.closed_at >= ?', array_merge($closed, [$todayStart])),
        ];

        $days = [];
        $from = (new \DateTimeImmutable('today', $tz))->modify('-13 days');
        $fromUtc = $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        for ($i = 0; $i < 14; $i++) {
            $days[$from->modify("+$i days")->format('Y-m-d')] = ['created' => 0, 'closed' => 0];
        }
        foreach ($db->col("SELECT t.created_at $base AND t.created_at >= ?", array_merge($params, [$fromUtc])) as $c) {
            $d = local_dt((string)$c)?->format('Y-m-d');
            if ($d && isset($days[$d])) {
                $days[$d]['created']++;
            }
        }
        foreach ($db->col("SELECT t.closed_at $base AND t.closed_at >= ?", array_merge($params, [$fromUtc])) as $c) {
            $d = local_dt((string)$c)?->format('Y-m-d');
            if ($d && isset($days[$d])) {
                $days[$d]['closed']++;
            }
        }

        $byDept = $db->all(
            "SELECT d.name, COUNT(t.id) AS n FROM tickets t JOIN departments d ON d.id = t.dept_id WHERE $scope AND t.merged_into IS NULL AND $openIn GROUP BY d.name ORDER BY n DESC",
            array_merge($params, $open)
        );
        $myQueue = $db->all(
            "SELECT t.*, u.name AS user_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.staff_id = ? AND $openIn AND t.merged_into IS NULL ORDER BY t.is_overdue DESC, t.due_at IS NULL, t.due_at, t.updated_at DESC LIMIT 10",
            array_merge([(int)$this->staff['id']], $open)
        );
        $myTasks = $db->all("SELECT * FROM tasks WHERE staff_id = ? AND status = 'open' ORDER BY due_at IS NULL, due_at LIMIT 8", [(int)$this->staff['id']]);
        $cronLast = (string)Settings::get('cron_last_run');

        $this->view('staff/dashboard', [
            'title' => __('Dashboard'), 'stats' => $stats, 'days' => $days, 'byDept' => $byDept,
            'myQueue' => $myQueue, 'myTasks' => $myTasks, 'cronLast' => $cronLast,
        ]);
    }
}
