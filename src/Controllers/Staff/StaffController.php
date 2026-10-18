<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;

abstract class StaffController extends Controller
{
    protected string $layout = 'layouts/staff';
    protected array $staff;

    public function __construct()
    {
        $this->staff = Auth::requireStaff();
    }

    protected function can(string $perm): bool
    {
        return Staff::can($this->staff, $perm);
    }

    protected function requirePerm(string $perm): void
    {
        if (!$this->can($perm)) {
            Router::forbidden();
        }
    }
}
