<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Router;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\View;

abstract class Controller
{
    protected string $layout = 'layouts/client';

    protected function view(string $template, array $data = []): void
    {
        View::render($template, $data, $this->layout);
    }

    protected function redirect(string $path, array $query = []): never
    {
        View::redirect($query ? App::url($path, $query) : $path);
    }

    protected function back(string $fallback = '/'): never
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = parse_url(App::baseUrl(), PHP_URL_HOST);
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
            View::redirect($ref);
        }
        View::redirect($fallback);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    protected function failWith(array|string $errors, string $fallback = '/'): never
    {
        foreach ((array)$errors as $e) {
            Session::flash('error', $e);
        }
        Session::flashInput($_POST);
        $this->back($fallback);
    }

    protected function notFound(): never
    {
        Router::notFound();
    }

    protected function json(mixed $data, int $status = 200): never
    {
        View::json($data, $status);
    }

    /** @return array{0:int,1:int,2:int} page, per page, offset */
    protected function paging(int $perPage = 25): array
    {
        $page = max(1, Request::int('page', 1));
        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
