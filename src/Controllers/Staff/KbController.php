<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;

final class KbController extends StaffController
{
    public function index(): void
    {
        $db = App::db();
        $cats = $db->all('SELECT * FROM kb_categories ORDER BY sort, name');
        $articles = $db->all('SELECT id, category_id, question, public, views, updated_at FROM kb_articles ORDER BY question');
        $byCat = [];
        foreach ($articles as $a) {
            $byCat[(int)$a['category_id']][] = $a;
        }
        $this->view('staff/kb', ['title' => __('Knowledge base'), 'cats' => $cats, 'byCat' => $byCat, 'canManage' => $this->can('kb.manage')]);
    }

    public function search(): void
    {
        $q = '%' . Request::str('q') . '%';
        $rows = App::db()->all('SELECT id, question FROM kb_articles WHERE public = 1 AND (question LIKE ? OR keywords LIKE ?) ORDER BY views DESC LIMIT 10', [$q, $q]);
        $this->json(array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['question'], 'url' => App::url('/kb/' . $r['id'])], $rows));
    }

    public function categoryForm(?int $id = null): void
    {
        $this->requirePerm('kb.manage');
        $cat = $id ? App::db()->find('kb_categories', $id) : null;
        if ($id && !$cat) {
            $this->notFound();
        }
        $this->view('staff/kb_category_form', ['title' => $cat ? __('Edit category') : __('New category'), 'cat' => $cat]);
    }

    public function categorySave(?int $id = null): void
    {
        $this->requirePerm('kb.manage');
        $data = [
            'name' => Request::str('name'),
            'description' => Request::str('description') ?: null,
            'public' => Request::bool('public') ? 1 : 0,
            'sort' => Request::int('sort'),
        ];
        if ($data['name'] === '') {
            $this->failWith(__('Name is required.'), '/panel/kb');
        }
        if ($id) {
            App::db()->update('kb_categories', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            App::db()->insert('kb_categories', $data);
        }
        $this->flash('success', __('Category saved.'));
        $this->redirect('/panel/kb');
    }

    public function categoryDelete(int $id): void
    {
        $this->requirePerm('kb.manage');
        if ((int)App::db()->val('SELECT COUNT(*) FROM kb_articles WHERE category_id = ?', [$id]) > 0) {
            $this->failWith(__('Move or delete the articles in this category first.'), '/panel/kb');
        }
        App::db()->delete('kb_categories', 'id = ?', [$id]);
        $this->flash('success', __('Category deleted.'));
        $this->redirect('/panel/kb');
    }

    public function articleForm(?int $id = null): void
    {
        $this->requirePerm('kb.manage');
        $a = $id ? App::db()->find('kb_articles', $id) : null;
        if ($id && !$a) {
            $this->notFound();
        }
        $this->view('staff/kb_article_form', [
            'title' => $a ? __('Edit article') : __('New article'), 'a' => $a,
            'cats' => App::db()->all('SELECT id, name FROM kb_categories ORDER BY sort, name'),
            'catId' => Request::int('category_id'),
        ]);
    }

    public function articleSave(?int $id = null): void
    {
        $this->requirePerm('kb.manage');
        $data = [
            'category_id' => Request::int('category_id'),
            'question' => Request::str('question'),
            'answer' => Html::clean((string)Request::post('answer', ''), 'signature'),
            'keywords' => Request::str('keywords') ?: null,
            'public' => Request::bool('public') ? 1 : 0,
            'updated_at' => DB::now(),
        ];
        if ($data['question'] === '' || !$data['category_id']) {
            $this->failWith(__('Title and category are required.'), $id ? "/panel/kb/articles/$id" : '/panel/kb/articles/new');
        }
        if ($id) {
            App::db()->update('kb_articles', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = DB::now();
            $id = App::db()->insert('kb_articles', $data);
        }
        $this->flash('success', __('Article saved.'));
        $this->redirect('/panel/kb/articles/' . $id);
    }

    public function articleDelete(int $id): void
    {
        $this->requirePerm('kb.manage');
        App::db()->delete('kb_articles', 'id = ?', [$id]);
        $this->flash('success', __('Article deleted.'));
        $this->redirect('/panel/kb');
    }
}
