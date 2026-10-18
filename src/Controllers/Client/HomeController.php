<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Settings;

final class HomeController extends Controller
{
    public function index(): void
    {
        $featured = [];
        if (Settings::bool('kb_enabled')) {
            $featured = App::db()->all(
                'SELECT a.id, a.question FROM kb_articles a JOIN kb_categories c ON c.id = a.category_id
                 WHERE a.public = 1 AND c.public = 1 ORDER BY a.views DESC, a.id DESC LIMIT 6'
            );
        }
        $this->view('client/home', ['title' => __('Home'), 'featured' => $featured]);
    }
}
