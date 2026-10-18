<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Admin;

use Bakkbone\HelpCentre\Core\Addon;
use Bakkbone\HelpCentre\Core\Addons;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Settings;

final class AddonController extends AdminController
{
    public function index(): void
    {
        $found = Addons::discover(true);
        $enabled = Addons::enabledList();
        $this->view('admin/addons', [
            'title' => __('Add-ons'), 'adminSection' => 'addons',
            'addons' => $found, 'enabled' => $enabled, 'errors' => Addons::errors(),
            'missing' => array_values(array_diff($enabled, array_keys($found))),
            'safeMode' => Addons::safeMode(), 'canUpload' => self::uploadsAllowed() && class_exists(\ZipArchive::class),
            'uploadsConfigured' => self::uploadsAllowed(),
        ]);
    }

    public function enable(string $slug): void
    {
        if (Addons::safeMode()) {
            $this->failWith(__('Add-ons can\'t be switched on while safe mode is set in config/config.php.'), '/panel/admin/addons');
        }
        try {
            Addons::enable($slug);
            $a = Addons::get($slug);
            $this->flash('success', $a && Addons::settingDefinitions($a) ? __('{name} is switched on. Check its settings below.', ['name' => $a->name()]) : __('{name} is switched on.', ['name' => $a ? $a->name() : $slug]));
        } catch (\Throwable $e) {
            Addons::noteError($slug, __('Could not be switched on: {error}', ['error' => $e->getMessage()]));
            $this->flash('error', __('Could not switch it on: {error}', ['error' => $e->getMessage()]));
        }
        $this->redirect('/panel/admin/addons');
    }

    public function disable(string $slug): void
    {
        Addons::disable($slug);
        Addons::clearError($slug);
        $this->flash('success', __('Add-on switched off. Its settings and data are kept if you switch it back on.'));
        $this->redirect('/panel/admin/addons');
    }

    public function settings(string $slug): void
    {
        $info = Addons::discover()[$slug] ?? null;
        if (!$info) {
            $this->notFound();
        }
        $addon = Addons::get($slug) ?? new Addon($slug, $info['manifest'], $info['path']);
        $defs = Addons::settingDefinitions($addon);
        $values = [];
        foreach ($defs as $key => $def) {
            $values[$key] = ($def['type'] ?? '') === 'password' ? '' : $addon->setting($key);
        }
        $this->view('admin/addon_settings', [
            'title' => __('{name} settings', ['name' => $info['name']]), 'adminSection' => 'addons', 'info' => $info, 'defs' => $defs, 'values' => $values,
            'hasPassword' => array_map(fn($k) => (string)Settings::get($addon->settingKey($k)) !== '', array_combine(array_keys($defs), array_keys($defs)) ?: []),
            'isOn' => Addons::isEnabled($slug),
        ]);
    }

    public function saveSettings(string $slug): void
    {
        $info = Addons::discover()[$slug] ?? null;
        if (!$info) {
            $this->notFound();
        }
        $addon = Addons::get($slug) ?? new Addon($slug, $info['manifest'], $info['path']);
        $errors = [];
        foreach (Addons::settingDefinitions($addon) as $key => $def) {
            $type = (string)($def['type'] ?? 'text');
            $label = (string)($def['label'] ?? $key);
            $raw = Request::post($key);
            switch ($type) {
                case 'checkbox':
                    $addon->setSetting($key, Request::bool($key) ? '1' : '0');
                    continue 2;
                case 'password':
                    if ((string)$raw !== '') {
                        $addon->setSetting($key, (string)$raw);
                    } elseif (Request::bool($key . '__clear')) {
                        Settings::set($addon->settingKey($key), '');
                    }
                    continue 2;
                case 'select':
                    $opts = (array)($def['options'] ?? []);
                    $val = (string)$raw;
                    $keys = array_is_list($opts) ? array_map('strval', $opts) : array_map('strval', array_keys($opts));
                    if (!in_array($val, $keys, true)) {
                        $errors[] = __('{field}: choose one of the options.', ['field' => $label]);
                        continue 2;
                    }
                    break;
                case 'number':
                    $val = trim((string)$raw);
                    if ($val !== '' && !is_numeric($val)) {
                        $errors[] = __('{field} must be a number.', ['field' => $label]);
                        continue 2;
                    }
                    break;
                case 'email':
                    $val = trim((string)$raw);
                    if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = __('{field} must be an email address.', ['field' => $label]);
                        continue 2;
                    }
                    break;
                case 'url':
                    $val = trim((string)$raw);
                    if ($val !== '' && !preg_match('#^https?://#i', $val)) {
                        $errors[] = __('{field} must start with https:// or http://.', ['field' => $label]);
                        continue 2;
                    }
                    break;
                default:
                    $val = is_string($raw) ? ($type === 'textarea' ? $raw : trim($raw)) : '';
            }
            if (!empty($def['required']) && $val === '') {
                $errors[] = __('{field} is required.', ['field' => $label]);
                continue;
            }
            $addon->setSetting($key, $val);
        }
        Audit::log('addon.settings', 'addon', null, ['addon' => $slug]);
        if ($errors) {
            $this->failWith($errors, '/panel/admin/addons/' . $slug);
        }
        $this->flash('success', __('Settings saved.'));
        $this->redirect('/panel/admin/addons/' . $slug);
    }

    public function upload(): void
    {
        if (!self::uploadsAllowed() || !class_exists(\ZipArchive::class)) {
            $this->failWith(__('Uploading add-ons is turned off. Copy the add-on folder into addons/ instead.'), '/panel/admin/addons');
        }
        $f = $_FILES['zip'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $this->failWith(__('Choose a .zip file to upload.'), '/panel/admin/addons');
        }
        try {
            $slug = self::installZip($f['tmp_name'], Request::bool('replace'));
        } catch (\Throwable $e) {
            $this->failWith(__('Upload failed: {error}', ['error' => $e->getMessage()]), '/panel/admin/addons');
        }
        Audit::log('addon.upload', 'addon', null, ['addon' => $slug]);
        $this->flash('success', __('Add-on "{name}" uploaded. Switch it on below when you\'re ready.', ['name' => $slug]));
        $this->redirect('/panel/admin/addons');
    }

    private static function uploadsAllowed(): bool
    {
        return !empty(App::$config['addon_uploads']);
    }

    private static function installZip(string $zipPath, bool $replace): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('That isn\'t a valid .zip file.'));
        }
        $names = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $n = str_replace('\\', '/', (string)$st['name']);
            if ($n === '' || str_starts_with($n, '__MACOSX/') || str_contains($n, '/.DS_Store')) {
                continue;
            }
            if (str_starts_with($n, '/') || preg_match('#(^|/)\.\.(/|$)#', $n) || str_contains($n, "\0") || preg_match('/^[A-Za-z]:/', $n)) {
                throw new \RuntimeException(__('The zip contains an unsafe path.'));
            }
            $total += (int)$st['size'];
            $names[$i] = $n;
        }
        if ($total > 50 * 1024 * 1024) {
            throw new \RuntimeException(__('The add-on is larger than 50 MB.'));
        }
        $root = null;
        foreach ($names as $n) {
            if ($n === 'addon.json') {
                $root = '';
                break;
            }
            if (preg_match('#^([^/]+)/addon\.json$#', $n, $m)) {
                $root = $m[1] . '/';
            }
        }
        if ($root === null) {
            throw new \RuntimeException(__('No addon.json found at the top of the zip.'));
        }
        $manifest = json_decode((string)$zip->getFromName($root . 'addon.json'), true);
        $slug = is_array($manifest) && is_string($manifest['slug'] ?? null) ? $manifest['slug'] : rtrim($root, '/');
        if ($slug === '' || !preg_match(Addons::SLUG_PATTERN, $slug) || strlen($slug) > 40) {
            throw new \RuntimeException(__('Add a "slug" (lower-case letters, numbers and dashes) to addon.json, or put the files in a folder with that name.'));
        }
        $dest = Addons::dir() . '/' . $slug;
        if (is_dir($dest)) {
            if (!$replace) {
                throw new \RuntimeException(__('An add-on called "{name}" is already installed. Tick "Replace" to update it.', ['name' => $slug]));
            }
        }
        if (!is_dir(Addons::dir()) && !@mkdir(Addons::dir(), 0755, true)) {
            throw new \RuntimeException(__('The addons/ folder can\'t be created. Check folder permissions.'));
        }
        $tmp = Addons::dir() . '/.upload-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        try {
            foreach ($names as $i => $n) {
                if ($root !== '' && !str_starts_with($n, $root)) {
                    continue;
                }
                $rel = substr($n, strlen($root));
                if ($rel === '') {
                    continue;
                }
                $target = $tmp . '/' . $rel;
                if (str_ends_with($n, '/')) {
                    @mkdir($target, 0755, true);
                    continue;
                }
                @mkdir(dirname($target), 0755, true);
                $stream = $zip->getStream($zip->getNameIndex($i));
                if (!$stream) {
                    throw new \RuntimeException(__('Couldn\'t read {file} from the zip.', ['file' => $n]));
                }
                $out = fopen($target, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
            $zip->close();
            if (is_dir($dest)) {
                self::rmdir($dest);
            }
            if (!@rename($tmp, $dest)) {
                throw new \RuntimeException(__('Couldn\'t move the add-on into place. Check folder permissions on addons/.'));
            }
        } finally {
            if (is_dir($tmp)) {
                self::rmdir($tmp);
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return $slug;
    }

    private static function rmdir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
