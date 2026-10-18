<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

use Bakkbone\HelpCentre\Controllers\Api\ApiController;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;

/** See ADDONS.md. */
final class Addon
{
    /** @var list<callable> */
    public array $enableCallbacks = [];
    /** @var list<callable> */
    public array $disableCallbacks = [];

    public function __construct(
        public readonly string $slug,
        public readonly array $manifest,
        public readonly string $path,
    ) {
    }

    public function name(): string
    {
        return (string)($this->manifest['name'] ?? $this->slug);
    }

    public function version(): string
    {
        return (string)($this->manifest['version'] ?? '0.0.0');
    }

    public function on(string $event, callable $callback, int $priority = 10): self
    {
        Hooks::on($event, $callback, $priority, $this->slug);
        return $this;
    }

    public function filter(string $name, callable $callback, int $priority = 10): self
    {
        Hooks::on($name, $callback, $priority, $this->slug);
        return $this;
    }

    public function slot(string $name, callable $callback, int $priority = 10): self
    {
        Hooks::on($name, $callback, $priority, $this->slug);
        return $this;
    }

    public function cron(string $name, callable $callback): self
    {
        Addons::$cron[$this->slug . ':' . $name] = ['owner' => $this->slug, 'cb' => $callback];
        return $this;
    }

    public function onEnable(callable $callback): self
    {
        $this->enableCallbacks[] = $callback;
        return $this;
    }

    public function onDisable(callable $callback): self
    {
        $this->disableCallbacks[] = $callback;
        return $this;
    }

    public function panelRoute(string $method, string $path, callable $handler, ?string $permission = null): self
    {
        $perm = $permission !== null ? $this->permKey($permission) : null;
        Addons::$routes[] = [$this->methods($method), '/panel/addons/' . $this->slug . $this->cleanPath($path), function (...$args) use ($handler, $perm) {
            $staff = Auth::requireStaff();
            if ($perm !== null && !Staff::can($staff, $perm)) {
                Router::forbidden();
            }
            $handler(...$args);
        }, []];
        return $this;
    }

    public function portalRoute(string $method, string $path, callable $handler): self
    {
        Addons::$routes[] = [$this->methods($method), '/addons/' . $this->slug . $this->cleanPath($path), function (...$args) use ($handler) {
            $handler(...$args);
        }, []];
        return $this;
    }

    /** The API key row is passed to the handler as its last argument. */
    public function apiRoute(string $method, string $path, callable $handler, bool $requireKey = true): self
    {
        Addons::$routes[] = [$this->methods($method), '/api/addons/' . $this->slug . $this->cleanPath($path), function (...$args) use ($handler, $requireKey) {
            $key = $requireKey ? ApiController::requireKey() : null;
            $handler(...array_merge($args, [$key]));
        }, ['nocsrf' => true]];
        return $this;
    }

    public function navItem(string $label, string $path = '', string $icon = 'extension', ?string $permission = null): self
    {
        Addons::$nav[] = [
            'label' => $label, 'href' => '/panel/addons/' . $this->slug . $this->cleanPath($path), 'icon' => $icon,
            'perm' => $permission !== null ? $this->permKey($permission) : null, 'owner' => $this->slug,
        ];
        return $this;
    }

    public function portalNavItem(string $label, string $path = '', bool $signedInOnly = false): self
    {
        Addons::$portalNav[] = ['label' => $label, 'href' => '/addons/' . $this->slug . $this->cleanPath($path), 'signed_in' => $signedInOnly, 'owner' => $this->slug];
        return $this;
    }

    public function url(string $path = '', array $query = []): string
    {
        return App::url('/panel/addons/' . $this->slug . $this->cleanPath($path), $query);
    }

    public function portalUrl(string $path = '', array $query = []): string
    {
        return App::url('/addons/' . $this->slug . $this->cleanPath($path), $query);
    }

    public function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $full = $this->path . '/assets/' . $file;
        $v = is_file($full) ? (string)filemtime($full) : $this->version();
        return App::url('/addons/' . $this->slug . '/assets/' . $file) . '?v=' . $v;
    }

    public function view(string $template, array $data = [], string $area = 'panel'): void
    {
        $layout = $area === 'portal' ? 'layouts/client' : 'layouts/staff';
        echo View::fetchFile($this->templateFile($template), $data + ['addon' => $this], $layout);
    }

    public function render(string $template, array $data = []): string
    {
        return View::fetchFile($this->templateFile($template), $data + ['addon' => $this]);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $def = Addons::settingDefinition($this, $key);
        $raw = Settings::get($this->settingKey($key));
        if ($raw === null) {
            return $default ?? ($def['default'] ?? null);
        }
        if (($def['type'] ?? '') === 'password') {
            return Crypto::decrypt((string)$raw);
        }
        if (($def['type'] ?? '') === 'checkbox') {
            return $raw === '1';
        }
        return $raw;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $def = Addons::settingDefinition($this, $key);
        if (($def['type'] ?? '') === 'password') {
            $value = (string)Crypto::encrypt((string)$value);
        }
        Settings::set($this->settingKey($key), is_scalar($value) || $value === null ? (string)$value : (string)json_encode($value));
    }

    public function permission(string $key, string $label): self
    {
        Addons::$permissions[$this->permKey($key)] = __('{addon}: {permission}', ['addon' => __($this->name()), 'permission' => $label]);
        return $this;
    }

    public function can(string $permission, ?array $staff = null): bool
    {
        return Staff::can($staff ?? Auth::staff(), $this->permKey($permission));
    }

    public function db(): DB
    {
        return App::db();
    }

    public function table(string $name): string
    {
        return Addons::tablePrefix($this->slug) . $name;
    }

    public function log(string $message, string $details = '', string $level = 'info'): void
    {
        Log::write(in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info', '[' . $this->name() . '] ' . $message, $details);
    }

    public function settingKey(string $key): string
    {
        return 'addon.' . $this->slug . '.' . $key;
    }

    private function permKey(string $key): string
    {
        return str_starts_with($key, $this->slug . '.') ? $key : $this->slug . '.' . $key;
    }

    private function templateFile(string $template): string
    {
        if (str_contains($template, '..')) {
            throw new \InvalidArgumentException(__('Invalid template name'));
        }
        return $this->path . '/templates/' . $template . '.php';
    }

    private function cleanPath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '' : $path;
    }

    /** @return list<string> */
    private function methods(string $method): array
    {
        $m = strtoupper($method);
        return match ($m) {
            'GET' => ['GET', 'HEAD'],
            'ANY' => ['GET', 'HEAD', 'POST'],
            default => [$m],
        };
    }
}
