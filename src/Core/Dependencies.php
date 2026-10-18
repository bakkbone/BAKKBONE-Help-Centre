<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** Makes Composer optional for drag-and-drop installs. */
final class Dependencies
{
    public const PACKAGES = [
        'phpmailer' => [
            'name' => 'PHPMailer',
            'version' => '7.1.1',
            'url' => 'https://codeload.github.com/PHPMailer/PHPMailer/zip/refs/tags/v7.1.1',
            'page' => 'https://github.com/PHPMailer/PHPMailer/releases/tag/v7.1.1',
            'subdir' => 'src',
            'marker' => 'src/PHPMailer.php',
            'files' => 7,
            'tree_hash' => '087ad6acfad59aec7e4b4ac96f15ac4a70eb4032ac7e544bc88535d5ba10e3b2',
        ],
        'htmlpurifier' => [
            'name' => 'HTML Purifier',
            'version' => '4.19.1',
            'url' => 'https://codeload.github.com/ezyang/htmlpurifier/zip/refs/tags/v4.19.1',
            'page' => 'https://github.com/ezyang/htmlpurifier/releases/tag/v4.19.1',
            'subdir' => 'library',
            'marker' => 'library/HTMLPurifier.auto.php',
            'files' => 379,
            'tree_hash' => '33d14a09a415781f39f5556e2c0c7ee9f79f60f67ab95e6a1deac8365a299cee',
        ],
    ];

    private static bool $registered = false;

    public static function libDirs(): array
    {
        return [HC_ROOT . '/lib', HC_ROOT . '/storage/lib'];
    }

    public static function downloadDir(): string
    {
        return HC_ROOT . '/storage/lib';
    }

    public static function locate(string $key): ?string
    {
        $pkg = self::PACKAGES[$key];
        foreach (self::libDirs() as $dir) {
            if (!is_file("$dir/$key/{$pkg['marker']}")) {
                continue;
            }
            // A newer release pins a different version: treat the old download as missing so it's re-fetched.
            $stamp = "$dir/$key/.verified";
            if (is_file($stamp) && !str_contains((string)file_get_contents($stamp), $pkg['tree_hash'])) {
                continue;
            }
            return "$dir/$key";
        }
        return null;
    }

    public static function missing(): array
    {
        if (self::usingComposer()) {
            return [];
        }
        return array_values(array_filter(array_keys(self::PACKAGES), fn($k) => self::locate($k) === null));
    }

    public static function usingComposer(): bool
    {
        return is_file(HC_ROOT . '/vendor/autoload.php');
    }

    public static function register(): bool
    {
        if (self::usingComposer()) {
            return true;
        }
        if (self::$registered) {
            return self::missing() === [];
        }
        $mailer = self::locate('phpmailer');
        $purifier = self::locate('htmlpurifier');
        if ($mailer) {
            spl_autoload_register(static function (string $class) use ($mailer): void {
                $prefix = 'PHPMailer\\PHPMailer\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $mailer . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            });
        }
        if ($purifier) {
            if (!defined('HTMLPURIFIER_PREFIX')) {
                define('HTMLPURIFIER_PREFIX', $purifier . '/library');
            }
            require_once $purifier . '/library/HTMLPurifier/Bootstrap.php';
            spl_autoload_register(static function (string $class) use ($purifier): void {
                if (str_starts_with($class, 'HTMLPurifier')) {
                    $file = $purifier . '/library/' . str_replace('_', '/', $class) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            });
        }
        self::$registered = (bool)($mailer && $purifier);
        return self::$registered;
    }

    /** @param array<string,string> $uploads @return array<string,array{ok:bool,message:string}> */
    public static function install(array $uploads = []): array
    {
        $results = [];
        $base = self::downloadDir();
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            foreach (self::missing() as $k) {
                $results[$k] = ['ok' => false, 'message' => "Cannot create folder $base - make storage/ writable by the web server."];
            }
            return $results;
        }
        $lock = @fopen($base . '/.install.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            flock($lock, LOCK_EX); // Another request is installing; wait for it.
            flock($lock, LOCK_UN);
            fclose($lock);
            foreach (self::PACKAGES as $k => $p) {
                $results[$k] = self::locate($k) ? ['ok' => true, 'message' => 'Installed'] : ['ok' => false, 'message' => 'Installation by another request failed'];
            }
            return $results;
        }
        @set_time_limit(180);
        foreach (self::PACKAGES as $key => $pkg) {
            if (self::locate($key)) {
                $results[$key] = ['ok' => true, 'message' => 'Already installed'];
                continue;
            }
            try {
                $zip = $uploads[$key] ?? self::download($pkg['url']);
                self::extractAndVerify($zip, $key, $pkg);
                if (!isset($uploads[$key])) {
                    @unlink($zip);
                }
                $results[$key] = ['ok' => true, 'message' => "{$pkg['name']} {$pkg['version']} installed and verified"];
            } catch (\Throwable $e) {
                $results[$key] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $results;
    }

    private static function download(string $url): string
    {
        $tmp = self::downloadDir() . '/dl-' . bin2hex(random_bytes(6)) . '.zip';
        if (function_exists('curl_init')) {
            $fh = fopen($tmp, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'BAKKBONE-Help-Centre-Installer',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if (!$ok || $status !== 200) {
                @unlink($tmp);
                throw new \RuntimeException("Download failed ($url): " . ($err ?: "HTTP $status"));
            }
            return $tmp;
        }
        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(__('Neither cURL nor allow_url_fopen is available to download files.'));
        }
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'BAKKBONE-Help-Centre-Installer', 'follow_location' => 1]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') {
            throw new \RuntimeException("Download failed ($url)");
        }
        file_put_contents($tmp, $data);
        return $tmp;
    }

    private static function extractAndVerify(string $zipPath, string $key, array $pkg): void
    {
        $base = self::downloadDir();
        $work = $base . '/.tmp-' . $key . '-' . bin2hex(random_bytes(4));
        @mkdir($work, 0775, true);
        try {
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($zipPath) !== true) {
                    throw new \RuntimeException("{$pkg['name']}: the file is not a valid zip archive.");
                }
                // Refuse path traversal
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = (string)$zip->getNameIndex($i);
                    if (str_contains($n, '..') || str_starts_with($n, '/') || preg_match('/^[a-z]:/i', $n)) {
                        $zip->close();
                        throw new \RuntimeException("{$pkg['name']}: archive contains unsafe paths.");
                    }
                }
                $zip->extractTo($work);
                $zip->close();
            } elseif (class_exists(\PharData::class)) {
                $phar = new \PharData($zipPath);
                $phar->extractTo($work, null, true);
            } else {
                throw new \RuntimeException(__('The PHP zip extension (or phar) is needed to unpack downloads.'));
            }

            // Release zips contain a single top folder (e.g. PHPMailer-7.1.1/); also accept the subdir at the root.
            $root = null;
            if (is_dir("$work/{$pkg['subdir']}")) {
                $root = $work;
            } else {
                foreach (glob($work . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                    if (is_dir("$d/{$pkg['subdir']}")) {
                        $root = $d;
                        break;
                    }
                }
            }
            if ($root === null) {
                throw new \RuntimeException("{$pkg['name']}: archive does not contain a {$pkg['subdir']}/ folder - is it the right file?");
            }
            [$hash, $count] = self::treeHash("$root/{$pkg['subdir']}");
            if (!hash_equals($pkg['tree_hash'], $hash)) {
                throw new \RuntimeException("{$pkg['name']}: verification failed (expected version {$pkg['version']}, got $count files with a different checksum). Nothing was installed.");
            }
            $dest = "$base/$key";
            if (is_dir($dest)) {
                self::rmrf($dest);
            }
            @mkdir($dest, 0775, true);
            if (!@rename("$root/{$pkg['subdir']}", "$dest/{$pkg['subdir']}")) {
                self::copyDir("$root/{$pkg['subdir']}", "$dest/{$pkg['subdir']}");
            }
            foreach (['LICENSE', 'LICENSE.md', 'VERSION'] as $f) {
                if (is_file("$root/$f")) {
                    @copy("$root/$f", "$dest/$f");
                }
            }
            file_put_contents("$dest/.verified", $pkg['version'] . ' ' . $hash . "\n");
        } finally {
            self::rmrf($work);
        }
    }

    /** Line endings are normalised so CRLF checkouts hash the same. */
    public static function treeHash(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $dir)) + 1);
            }
        }
        sort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $rel) {
            hash_update($ctx, $rel . "\n" . hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents("$dir/$rel"))) . "\n");
        }
        return [hash_final($ctx), count($files)];
    }

    private static function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$src/$f") ? self::copyDir("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
        }
    }

    private static function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::rmrf("$path/$f");
            }
        }
        @rmdir($path);
    }

    public static function firstRun(): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "Installing required components...\n");
            foreach (self::install() as $k => $r) {
                fwrite($r['ok'] ? STDOUT : STDERR, sprintf("  %-13s %s\n", self::PACKAGES[$k]['name'], $r['message']));
            }
            if (!self::register()) {
                fwrite(STDERR, "Components could not be installed. Download them manually - see README (Drag-and-drop install).\n");
                exit(1);
            }
            return;
        }

        $results = [];
        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_FILES)) {
            $uploads = [];
            foreach (array_keys(self::PACKAGES) as $k) {
                if (!empty($_FILES[$k]['tmp_name']) && is_uploaded_file($_FILES[$k]['tmp_name'])) {
                    $uploads[$k] = $_FILES[$k]['tmp_name'];
                }
            }
            $results = $uploads ? self::install($uploads) : [];
            if (!$uploads) {
                $error = __('Choose the zip file(s) to upload.');
            }
        } else {
            // After a failed attempt wait 2 minutes so blocked servers don't hang every page; "Try again" retries at once.
            $failFile = self::downloadDir() . '/.last-failure';
            $recentFail = is_file($failFile) && time() - (int)@filemtime($failFile) < 120;
            if (!$recentFail || isset($_GET['retry'])) {
                $results = self::install();
                if (array_filter($results, fn($r) => !$r['ok'])) {
                    @touch($failFile);
                } else {
                    @unlink($failFile);
                }
            } else {
                $results = json_decode((string)@file_get_contents($failFile . '.json'), true) ?: [];
            }
            if ($results) {
                @file_put_contents($failFile . '.json', json_encode($results));
            }
        }
        if (self::register()) {
            @unlink(self::downloadDir() . '/.last-failure');
            @unlink(self::downloadDir() . '/.last-failure.json');
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), true, 303);
            exit;
        }
        self::renderPage($results, $error);
        exit;
    }

    private static function renderPage(array $results, ?string $error): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $maxUpload = ini_get('upload_max_filesize');
        echo '<!doctype html><html' . lang_attr() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . t('Finishing installation') . ' · BAKKBONE Help Centre</title><style>'
            . 'body{font:400 15px/1.5 Roboto,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#f9f9fc;color:#1a1c1e;margin:0;padding:24px 16px}'
            . '.c{max-width:680px;margin:6vh auto;background:#f3f3f6;border-radius:28px;padding:28px}'
            . 'h1{font-size:28px;line-height:36px;font-weight:400;margin:0 0 12px}.ok{color:#1b6d3a}.bad{color:#ba1a1a}code{background:#e1e2e5;padding:1px 5px;border-radius:4px;font-size:.9em}'
            . 'li{margin:6px 0}a{color:#0e7490}.btn{display:inline-flex;align-items:center;min-height:40px;background:#0e7490;color:#fff;border:0;border-radius:999px;padding:0 24px;font-weight:500;font-size:14px;line-height:20px;font-family:inherit;cursor:pointer;text-decoration:none}'
            . '.btn:hover{box-shadow:0 1px 2px rgba(0,0,0,.3),0 1px 3px 1px rgba(0,0,0,.15)}hr{border:0;border-top:1px solid #c3c6cf;margin:24px 0}h2{font-size:22px;line-height:28px;font-weight:400}'
            . 'label{display:block;font-weight:500;margin:16px 0 4px;color:#43474e}input[type=file]{width:100%;padding:10px 12px;border:1px solid #73777f;border-radius:4px;box-sizing:border-box}.muted{color:#43474e;font-size:14px}</style></head><body><div class="c">'
            . '<h1>' . t('Almost there') . '</h1><p>' . t('BAKKBONE Help Centre needs two open-source components. It tried to download them automatically, but this didn\'t work:') . '</p><ul>';
        foreach (self::PACKAGES as $k => $p) {
            $r = $results[$k] ?? ['ok' => (bool)self::locate($k), 'message' => self::locate($k) ? __('Installed') : __('Not installed')];
            echo '<li><strong>' . $h($p['name'] . ' ' . $p['version']) . '</strong>: <span class="' . ($r['ok'] ? 'ok' : 'bad') . '">' . $h($r['message']) . '</span></li>';
        }
        echo '</ul>';
        if ($error) {
            echo '<p class="bad">' . $h($error) . '</p>';
        }
        $retry = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?retry=1';
        echo '<p><a class="btn" href="' . $h($retry) . '">' . t('Try again') . '</a></p><hr>'
            . '<h2>' . t('No internet access from this server? Upload them instead') . '</h2>'
            . '<p class="muted">' . t('Download each zip on your own computer, then upload it here. Each file is checked against a built-in checksum before it is used, so only the exact expected versions are accepted.') . '</p>'
            . '<form method="post" enctype="multipart/form-data">';
        foreach (self::PACKAGES as $k => $p) {
            if (self::locate($k)) {
                continue;
            }
            echo '<label for="' . $h($k) . '">' . t('{package} zip', ['package' => $p['name'] . ' ' . $p['version']]) . '</label>'
                . '<div class="muted">' . t('Download: {link}', ['link' => raw('<a href="' . $h($p['url']) . '">' . $h($p['url']) . '</a>')]) . '</div>'
                . '<input id="' . $h($k) . '" type="file" name="' . $h($k) . '" accept=".zip">';
        }
        echo '<p style="margin-top:20px"><button class="btn" type="submit">' . t('Upload and install') . '</button></p></form>'
            . '<p class="muted">' . t('Upload limit on this server: {size}. Alternatively unzip them yourself so these files exist: <code>storage/lib/phpmailer/src/PHPMailer.php</code> and <code>storage/lib/htmlpurifier/library/HTMLPurifier.auto.php</code>. Requirements: the <code>storage/</code> folder must be writable by PHP.', ['size' => $maxUpload]) . '</p></div></body></html>';
    }
}
