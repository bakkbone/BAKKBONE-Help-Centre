<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Templates;

final class TemplateController extends AdminController
{
    public function index(): void
    {
        $this->view('admin/templates', ['title' => __('Email templates'), 'adminSection' => 'templates', 'defaults' => Templates::defaults()]);
    }

    public function form(string $code): void
    {
        $defaults = Templates::defaults();
        if (!isset($defaults[$code])) {
            $this->notFound();
        }
        $this->view('admin/template_form', [
            'title' => $defaults[$code]['name'], 'adminSection' => 'templates', 'code' => $code,
            'meta' => $defaults[$code], 'tpl' => Templates::get($code), 'vars' => Templates::variables(),
        ]);
    }

    public function save(string $code): void
    {
        if (!isset(Templates::defaults()[$code])) {
            $this->notFound();
        }
        $subject = Request::str('subject');
        // Admin-only and may hold merge tags in attributes/CSS, so stored as written rather than sanitised.
        $body = (string)Request::post('body', '');
        $body = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $body) ?? $body;
        $db = App::db();
        if ($db->val('SELECT COUNT(*) FROM email_templates WHERE code = ?', [$code])) {
            $db->update('email_templates', ['subject' => $subject, 'body' => $body, 'updated_at' => DB::now()], 'code = ?', [$code]);
        } else {
            $db->insert('email_templates', ['code' => $code, 'subject' => $subject, 'body' => $body, 'updated_at' => DB::now()]);
        }
        $this->flash('success', __('Template saved.'));
        $this->redirect('/panel/admin/templates/' . $code);
    }

    public function reset(string $code): void
    {
        App::db()->delete('email_templates', 'code = ?', [$code]);
        Templates::seed();
        $this->flash('success', __('Template restored to the default.'));
        $this->redirect('/panel/admin/templates/' . $code);
    }
}
