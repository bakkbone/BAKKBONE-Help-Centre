<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Services\Auth;

abstract class AdminController extends Controller
{
    protected string $layout = 'layouts/staff';
    protected array $staff;

    public function __construct()
    {
        $this->staff = Auth::requireAdmin();
    }

    protected function view(string $template, array $data = []): void
    {
        $data['adminSection'] = $data['adminSection'] ?? '';
        parent::view($template, $data);
    }
}
