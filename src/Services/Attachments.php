<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Settings;

/** Files live outside the web root and are only served through the permission-checked download controller. */
final class Attachments
{
    /** Never SVG or HTML: both can run script. */
    public const INLINE_SAFE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'];

    public static function find(int $id): ?array
    {
        return App::db()->find('attachments', $id);
    }

    public static function fullPath(array $att): string
    {
        return App::storage('attachments/' . $att['path']);
    }

    /** @return array<int,list<array<string,mixed>>> */
    public static function forEntries(array $entryIds): array
    {
        if (!$entryIds) {
            return [];
        }
        $rows = App::db()->all('SELECT * FROM attachments WHERE entry_id IN ' . DB::in($entryIds) . ' ORDER BY id', array_values($entryIds));
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['entry_id']][] = $r;
        }
        return $out;
    }

    public static function maxBytes(): int
    {
        return max(1, Settings::int('max_file_mb')) * 1024 * 1024;
    }

    public static function extensionAllowed(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'htaccess', 'htpasswd'], true)) {
            return false;
        }
        $allowed = Settings::list('allowed_ext');
        return !$allowed || in_array('*', $allowed, true) || in_array($ext, array_map('strtolower', $allowed), true);
    }

    /** @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files @return array{0:list<array<string,mixed>>,1:list<string>} */
    public static function validateUploads(array $files): array
    {
        $ok = [];
        $errors = [];
        $max = self::maxBytes();
        $maxFiles = max(1, Settings::int('max_files'));
        if (count($files) > $maxFiles) {
            $errors[] = _n('You can attach up to {n} file at a time.', 'You can attach up to {n} files at a time.', $maxFiles);
            $files = array_slice($files, 0, $maxFiles);
        }
        foreach ($files as $f) {
            $name = \Bakkbone\HelpCentre\Mail\MimeParser::cleanFilename($f['name']);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? __('"{file}" is too large.', ['file' => $name])
                    : __('"{file}" could not be uploaded.', ['file' => $name]);
                continue;
            }
            if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') {
                $errors[] = __('"{file}" is not a valid upload.', ['file' => $name]);
                continue;
            }
            if ($f['size'] > $max) {
                $errors[] = __('"{file}" is larger than the {size} MB limit.', ['file' => $name, 'size' => Settings::int('max_file_mb')]);
                continue;
            }
            if (!self::extensionAllowed($name)) {
                $errors[] = __('Files of type "{type}" are not allowed.', ['type' => pathinfo($name, PATHINFO_EXTENSION)]);
                continue;
            }
            $ok[] = ['filename' => $name, 'mime' => self::detectMime($f['tmp_name'], $name, $f['type']), 'tmp_path' => $f['tmp_name']];
        }
        return [$ok, $errors];
    }

    /** $file: filename, mime, content or tmp_path, and optionally content_id and inline. */
    public static function store(array $file, ?int $ticketId, ?int $entryId = null, ?int $taskId = null): ?int
    {
        $content = $file['content'] ?? (isset($file['tmp_path']) ? (string)file_get_contents($file['tmp_path']) : null);
        if ($content === null) {
            return null;
        }
        $sub = gmdate('Y/m');
        $dir = App::storage('attachments/' . $sub);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create attachment directory ' . $dir);
        }
        $key = bin2hex(random_bytes(16));
        $rel = $sub . '/' . $key;
        if (file_put_contents($dir . '/' . $key, $content, LOCK_EX) === false) {
            throw new \RuntimeException(__('Cannot write attachment'));
        }
        $filename = \Bakkbone\HelpCentre\Mail\MimeParser::cleanFilename((string)($file['filename'] ?? 'file')) ?: 'file';
        return App::db()->insert('attachments', [
            'ticket_id' => $ticketId,
            'entry_id' => $entryId,
            'task_id' => $taskId,
            'filename' => $filename,
            'mime' => strtolower((string)($file['mime'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
            'size' => strlen($content),
            'path' => $rel,
            'sha256' => hash('sha256', $content),
            'content_id' => $file['content_id'] ?? null,
            'inline' => !empty($file['inline']) ? 1 : 0,
            'created_at' => DB::now(),
        ]);
    }

    public static function detectMime(string $path, string $filename, string $fallback = ''): string
    {
        if (function_exists('finfo_open') && is_file($path)) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m = $fi ? finfo_file($fi, $path) : false;
            if ($fi) {
                finfo_close($fi);
            }
            if ($m && $m !== 'application/octet-stream') {
                return $m;
            }
        }
        $byExt = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'eml' => 'message/rfc822', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
        ];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $byExt[$ext] ?? ($fallback !== '' ? $fallback : 'application/octet-stream');
    }

    public static function delete(array $att): void
    {
        $path = self::fullPath($att);
        // Only remove the file if no other row references it.
        if (is_file($path) && (int)App::db()->val('SELECT COUNT(*) FROM attachments WHERE path = ? AND id <> ?', [$att['path'], $att['id']]) === 0) {
            @unlink($path);
        }
        App::db()->delete('attachments', 'id = ?', [$att['id']]);
    }

    public static function deleteForTicket(int $ticketId): void
    {
        foreach (App::db()->all('SELECT * FROM attachments WHERE ticket_id = ?', [$ticketId]) as $a) {
            self::delete($a);
        }
    }

    public static function output(array $att, bool $inline = false): never
    {
        $path = self::fullPath($att);
        if (!is_file($path)) {
            http_response_code(404);
            exit(__('File not found'));
        }
        $mime = (string)$att['mime'];
        $inline = $inline && in_array($mime, self::INLINE_SAFE, true);
        $name = (string)$att['filename'];
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }
}
