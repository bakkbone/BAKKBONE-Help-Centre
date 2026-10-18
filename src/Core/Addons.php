<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Addons
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string,Addon> */
    private static array $loaded = [];
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $found = null;
    private static bool $booted = false;

    /** @internal Filled in by add-ons as they load. */
    public static array $routes = [];
    public static array $nav = [];
    public static array $portalNav = [];
    public static array $permissions = [];
    public static array $cron = [];

    public static function dir(): string
    {
        return HC_ROOT . '/addons';
    }

    public static function safeMode(): bool
    {
        return !empty(App::$config['safe_mode']) || (App::$config['addons'] ?? true) === false;
    }

    /** @return array<string,array<string,mixed>> */
    public static function discover(bool $refresh = false): array
    {
        if (self::$found !== null && !$refresh) {
            return self::$found;
        }
        $found = [];
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            if (str_starts_with($slug, '.') || str_starts_with($slug, '_')) {
                continue;
            }
            $info = ['slug' => $slug, 'path' => $path, 'name' => $slug, 'version' => '', 'description' => '', 'author' => '', 'url' => '', 'problems' => [], 'manifest' => []];
            $manifestFile = $path . '/addon.json';
            if (!preg_match(self::SLUG_PATTERN, $slug) || strlen($slug) > 40) {
                $info['problems'][] = __('The folder name must be lower-case letters, numbers and dashes (at most 40 characters).');
            }
            if (!is_file($manifestFile)) {
                $info['problems'][] = __('addon.json is missing.');
                $found[$slug] = $info;
                continue;
            }
            $m = json_decode((string)file_get_contents($manifestFile), true);
            if (!is_array($m)) {
                $info['problems'][] = __('addon.json is not valid JSON.');
                $found[$slug] = $info;
                continue;
            }
            $info['manifest'] = $m;
            if (is_dir($path . '/lang')) {
                I18n::addDirectory($path . '/lang');
            }
            foreach (['name', 'version', 'description', 'author', 'url'] as $k) {
                $info[$k] = is_string($m[$k] ?? null) ? $m[$k] : $info[$k];
            }
            if (!is_file($path . '/' . ($m['main'] ?? 'addon.php'))) {
                $info['problems'][] = __('{file} is missing.', ['file' => $m['main'] ?? 'addon.php']);
            }
            foreach (self::requirementProblems($m['requires'] ?? []) as $p) {
                $info['problems'][] = $p;
            }
            $found[$slug] = $info;
        }
        ksort($found);
        return self::$found = $found;
    }

    /** @return list<string> */
    public static function enabledList(): array
    {
        $v = json_decode((string)Settings::get('addons_enabled', '[]'), true);
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public static function isEnabled(string $slug): bool
    {
        return in_array($slug, self::enabledList(), true);
    }

    public static function isLoaded(string $slug): bool
    {
        return isset(self::$loaded[$slug]);
    }

    public static function get(string $slug): ?Addon
    {
        return self::$loaded[$slug] ?? null;
    }

    /** @return array<string,Addon> */
    public static function loaded(): array
    {
        return self::$loaded;
    }

    public static function boot(): void
    {
        if (self::$booted || !App::$installed) {
            return;
        }
        self::$booted = true;
        if (self::safeMode() || !is_dir(self::dir())) {
            return;
        }
        $enabled = self::enabledList();
        if (!$enabled) {
            return;
        }
        register_shutdown_function([self::class, 'shutdown']);
        $found = self::discover();
        $versions = self::storedVersions();
        foreach ($enabled as $slug) {
            $info = $found[$slug] ?? null;
            if (!$info || $info['problems']) {
                continue; // folder removed or now broken: skip quietly (shown on the Add-ons page)
            }
            try {
                $addon = self::load($info);
                if (($versions[$slug] ?? null) !== $addon->version()) {
                    self::install($addon); // first load after an update: add any new tables/columns
                }
            } catch (\Throwable $e) {
                self::noteError($slug, 'Failed to load: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                Hooks::forgetOwner($slug);
                unset(self::$loaded[$slug]);
            }
        }
    }

    public static function enable(string $slug): void
    {
        $info = self::discover(true)[$slug] ?? null;
        if (!$info) {
            throw new \RuntimeException(__('That add-on was not found.'));
        }
        if ($info['problems']) {
            throw new \RuntimeException(implode(' ', $info['problems']));
        }
        $addon = self::$loaded[$slug] ?? self::load($info); // a fatal error here is caught by shutdown() and reported
        self::install($addon);
        foreach ($addon->enableCallbacks as $cb) {
            $cb($addon);
        }
        $list = self::enabledList();
        if (!in_array($slug, $list, true)) {
            $list[] = $slug;
        }
        Settings::set('addons_enabled', json_encode(array_values($list)));
        self::clearError($slug);
        Audit::log('addon.enable', 'addon', null, ['addon' => $slug, 'version' => $addon->version()]);
    }

    public static function disable(string $slug, ?string $reason = null): void
    {
        if ($addon = self::$loaded[$slug] ?? null) {
            foreach ($addon->disableCallbacks as $cb) {
                try {
                    $cb($addon);
                } catch (\Throwable $e) {
                    error_log("[HelpCentre] Add-on $slug onDisable failed: " . $e->getMessage());
                }
            }
        }
        Settings::set('addons_enabled', json_encode(array_values(array_diff(self::enabledList(), [$slug]))));
        Hooks::forgetOwner($slug);
        unset(self::$loaded[$slug]);
        if ($reason !== null) {
            self::noteError($slug, $reason);
        }
        Audit::log('addon.disable', 'addon', null, ['addon' => $slug] + ($reason !== null ? ['reason' => $reason] : []));
    }

    public static function noteError(string $slug, string $message): void
    {
        try {
            $errors = self::errors();
            $message = mb_substr($message, 0, 500);
            if (($errors[$slug]['message'] ?? null) === $message) {
                return; // don't rewrite the setting on every request for a repeating error
            }
            $errors[$slug] = ['message' => $message, 'at' => DB::now()];
            Settings::set('addon_errors', json_encode($errors));
        } catch (\Throwable) {
        }
    }

    public static function clearError(string $slug): void
    {
        $errors = self::errors();
        if (isset($errors[$slug])) {
            unset($errors[$slug]);
            Settings::set('addon_errors', json_encode($errors));
        }
    }

    /** @return array<string,array{message:string,at:string}> */
    public static function errors(): array
    {
        $v = json_decode((string)Settings::get('addon_errors', '{}'), true);
        return is_array($v) ? $v : [];
    }

    public static function shutdown(): void
    {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        $slug = self::slugForFile((string)$err['file']);
        if ($slug === null) {
            return;
        }
        $msg = str_replace([HC_ROOT . DIRECTORY_SEPARATOR, HC_ROOT . '/'], '', (string)$err['message']);
        $where = ' (' . basename((string)$err['file']) . ':' . $err['line'] . ')';
        $wasOn = false;
        try {
            $wasOn = self::isEnabled($slug);
            $reason = ($wasOn ? 'Switched off automatically after a fatal error: ' : 'Could not be switched on: ') . $msg . $where;
            $wasOn ? self::disable($slug, $reason) : self::noteError($slug, $reason);
            Log::error('Add-on "' . $slug . '" crashed', $reason);
        } catch (\Throwable) {
        }
        if (PHP_SAPI !== 'cli') {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            $name = raw('<strong>' . htmlspecialchars((string)(self::discover()[$slug]['name'] ?? $slug), ENT_QUOTES, 'UTF-8') . '</strong>');
            echo '<!doctype html><html' . lang_attr() . '><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . t('Add-on error') . '</title>'
                . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 24px">'
                . '<h1 style="font-weight:400;font-size:28px">' . t('Something went wrong') . '</h1>'
                . '<p>' . ($wasOn ? t('The add-on {name} caused an error and has been switched off automatically.', ['name' => $name]) : t('The add-on {name} caused an error, so it was not switched on.', ['name' => $name])) . ' '
                . t('{link}Reload the page{/link} to carry on.', ['link' => tag('<a href="">')]) . '</p><p style="color:#5f6368">' . t('Details are under Admin → Add-ons and in the System log.') . '</p></div>';
        }
    }

    public static function registerRoutes(Router $r): void
    {
        foreach (self::$routes as [$methods, $pattern, $handler, $opts]) {
            $r->add($methods, $pattern, $handler, $opts);
        }
    }

    public static function navItems(?array $staff): array
    {
        return array_values(array_filter(self::$nav, fn($n) => $n['perm'] === null || \Bakkbone\HelpCentre\Services\Staff::can($staff, $n['perm'])));
    }

    public static function portalNavItems(bool $signedIn): array
    {
        return array_values(array_filter(self::$portalNav, fn($n) => !$n['signed_in'] || $signedIn));
    }

    /** @return array<string,array<string,mixed>> */
    public static function settingDefinitions(array|Addon $addon): array
    {
        $m = $addon instanceof Addon ? $addon->manifest : ($addon['manifest'] ?? []);
        $out = [];
        foreach ((array)($m['settings'] ?? []) as $k => $def) {
            if (is_array($def)) {
                $key = is_string($k) ? $k : (string)($def['key'] ?? '');
                if ($key !== '' && preg_match('/^[a-z0-9_]+$/', $key)) {
                    $out[$key] = $def + ['label' => $key, 'type' => 'text'];
                }
            }
        }
        return $out;
    }

    public static function settingDefinition(Addon $addon, string $key): ?array
    {
        return self::settingDefinitions($addon)[$key] ?? null;
    }

    public static function tablePrefix(string $slug): string
    {
        return 'addon_' . str_replace('-', '_', $slug) . '_';
    }

    public static function assetFile(string $slug, string $file): ?string
    {
        if (!self::isEnabled($slug) || !preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }
        return self::safeFile(self::dir() . '/' . $slug . '/assets', $file);
    }

    public static function safeFile(string $base, string $file): ?string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext]) || str_contains($file, "\0")) {
            return null;
        }
        $realBase = realpath($base);
        $real = realpath($base . '/' . $file);
        if ($realBase === false || $real === false || !is_file($real) || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    public const MIME = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'map' => 'application/json', 'txt' => 'text/plain; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public static function sendFile(string $file): never
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mtime = (int)filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex((int)filesize($file)) . '"';
        header('Content-Type: ' . self::MIME[$ext]);
        header('Cache-Control: public, max-age=604800');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    private static function load(array $info): Addon
    {
        $slug = $info['slug'];
        $addon = new Addon($slug, $info['manifest'], $info['path']);
        self::$loaded[$slug] = $addon;
        foreach ((array)($info['manifest']['autoload'] ?? []) as $prefix => $dir) {
            if (!is_string($prefix) || !is_string($dir) || $prefix === '' || str_starts_with(ltrim($prefix, '\\'), 'Bakkbone\\')) {
                continue;
            }
            $prefix = trim($prefix, '\\') . '\\';
            $base = rtrim($info['path'] . '/' . trim($dir, '/'), '/') . '/';
            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (str_starts_with($class, $prefix)) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file) && !str_contains($file, '..')) {
                        require $file;
                    }
                }
            });
        }
        if (is_dir($info['path'] . '/lang')) {
            I18n::addDirectory($info['path'] . '/lang');
        }
        $main = $info['path'] . '/' . ($info['manifest']['main'] ?? 'addon.php');
        (static function (Addon $addon, string $__file): void {
            require $__file;
        })($addon, $main);
        return $addon;
    }

    private static function install(Addon $addon): void
    {
        $file = $addon->path . '/schema.php';
        if (is_file($file)) {
            $defs = (static fn(string $__f) => require $__f)($file);
            if (!is_array($defs)) {
                throw new \RuntimeException(__('schema.php must return an array of tables.'));
            }
            $prefix = self::tablePrefix($addon->slug);
            $prefixed = [];
            foreach ($defs as $table => $def) {
                if (!is_string($table) || !preg_match('/^[a-z0-9_]+$/', $table) || !is_array($def) || empty($def['columns'])) {
                    throw new \RuntimeException("schema.php: invalid table definition \"$table\".");
                }
                $prefixed[str_starts_with($table, $prefix) ? $table : $prefix . $table] = $def;
            }
            $log = Schema::sync(App::db(), $prefixed);
            if ($log) {
                Log::info('Add-on "' . $addon->name() . '" database updated', implode("\n", $log));
            }
        }
        $versions = self::storedVersions();
        $versions[$addon->slug] = $addon->version();
        Settings::set('addon_versions', json_encode($versions));
    }

    /** @return array<string,string> */
    private static function storedVersions(): array
    {
        $v = json_decode((string)Settings::get('addon_versions', '{}'), true);
        return is_array($v) ? $v : [];
    }

    private static function slugForFile(string $file): ?string
    {
        $dir = realpath(self::dir());
        $real = realpath($file) ?: $file;
        if ($dir === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $slug = explode(DIRECTORY_SEPARATOR, substr($real, strlen($dir) + 1))[0] ?? '';
        return preg_match(self::SLUG_PATTERN, $slug) ? $slug : null;
    }

    /** @return list<string> */
    private static function requirementProblems(mixed $requires): array
    {
        $problems = [];
        if (!is_array($requires)) {
            return $problems;
        }
        $checks = ['helpcentre' => [HC_VERSION, 'BAKKBONE Help Centre'], 'php' => [PHP_VERSION, 'PHP']];
        foreach ($checks as $key => [$have, $label]) {
            $want = $requires[$key] ?? null;
            if (!is_string($want) || $want === '') {
                continue;
            }
            if (!preg_match('/^(>=|<=|>|<|==|=|!=)?\s*([0-9][0-9a-z.\-]*)$/i', trim($want), $m)) {
                continue;
            }
            $op = $m[1] ?: '>=';
            if (!version_compare($have, $m[2], $op === '=' ? '==' : $op)) {
                $problems[] = __('Needs {software} {version} (this server has {have}).', ['software' => $label, 'version' => $op . ' ' . $m[2], 'have' => $have]);
            }
        }
        foreach ((array)($requires['extensions'] ?? []) as $ext) {
            if (is_string($ext) && !extension_loaded($ext)) {
                $problems[] = __('Needs the PHP \'{extension}\' extension.', ['extension' => $ext]);
            }
        }
        return $problems;
    }
}
