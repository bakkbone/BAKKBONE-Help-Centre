<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;

final class KbController extends Controller
{
    public function __construct()
    {
        if (!Settings::bool('kb_enabled')) {
            $this->notFound();
        }
    }

    public function index(): void
    {
        $db = App::db();
        $q = Request::str('q');
        $results = null;
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $results = $db->all(
                "SELECT a.id, a.question, a.answer, c.name AS category FROM kb_articles a JOIN kb_categories c ON c.id = a.category_id
                 WHERE a.public = 1 AND c.public = 1 AND (a.question LIKE ? OR a.answer LIKE ? OR a.keywords LIKE ?) ORDER BY a.views DESC LIMIT 50",
                [$like, $like, $like]
            );
        }
        $cats = $db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM kb_articles a WHERE a.category_id = c.id AND a.public = 1) AS n
             FROM kb_categories c WHERE c.public = 1 ORDER BY c.sort, c.name'
        );
        $this->view('client/kb_index', ['title' => __('Knowledge base'), 'cats' => $cats, 'q' => $q, 'results' => $results]);
    }

    public function category(int $id): void
    {
        $db = App::db();
        $cat = $db->row('SELECT * FROM kb_categories WHERE id = ? AND public = 1', [$id]);
        if (!$cat) {
            $this->notFound();
        }
        $articles = $db->all('SELECT id, question, answer FROM kb_articles WHERE category_id = ? AND public = 1 ORDER BY question', [$id]);
        $this->view('client/kb_category', ['title' => $cat['name'], 'cat' => $cat, 'articles' => $articles]);
    }

    public function article(int $id): void
    {
        $db = App::db();
        $a = $db->row('SELECT a.*, c.name AS category, c.public AS cat_public FROM kb_articles a JOIN kb_categories c ON c.id = a.category_id WHERE a.id = ?', [$id]);
        if (!$a || !$a['public'] || !$a['cat_public']) {
            $this->notFound();
        }
        $db->run('UPDATE kb_articles SET views = views + 1 WHERE id = ?', [$id]);
        $related = $db->all('SELECT id, question FROM kb_articles WHERE category_id = ? AND id <> ? AND public = 1 ORDER BY views DESC LIMIT 5', [$a['category_id'], $id]);
        $this->view('client/kb_article', ['title' => $a['question'], 'a' => $a, 'related' => $related]);
    }
}
