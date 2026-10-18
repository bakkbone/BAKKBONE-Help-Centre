<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Mail;

final class MimeParser
{
    private const MAX_DEPTH = 20;

    public static function parse(string $raw): ParsedEmail
    {
        $mail = new ParsedEmail();
        $mail->raw = $raw;
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $mail->headers = self::parseHeaders($headerBlock);

        $mail->subject = trim(self::decodeHeader($mail->headers['subject'][0] ?? ''));
        $from = self::parseAddressList($mail->headers['from'][0] ?? '');
        if ($from) {
            $mail->fromEmail = $from[0]['email'];
            $mail->fromName = $from[0]['name'];
        } elseif (!empty($mail->headers['sender'][0])) {
            $s = self::parseAddressList($mail->headers['sender'][0]);
            $mail->fromEmail = $s[0]['email'] ?? '';
            $mail->fromName = $s[0]['name'] ?? '';
        }
        $mail->to = self::parseAddressList(implode(', ', $mail->headers['to'] ?? []));
        $mail->cc = self::parseAddressList(implode(', ', $mail->headers['cc'] ?? []));
        $mail->replyTo = self::parseAddressList($mail->headers['reply-to'][0] ?? '');
        $mail->messageId = self::firstMessageId($mail->headers['message-id'][0] ?? '');
        $mail->inReplyTo = self::firstMessageId($mail->headers['in-reply-to'][0] ?? '');
        $mail->references = self::allMessageIds($mail->headers['references'][0] ?? '');
        if (!empty($mail->headers['date'][0])) {
            $ts = strtotime($mail->headers['date'][0]);
            $mail->date = $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
        }

        self::walk($mail, $mail->headers, $body, 0);

        if ($mail->messageId === '') {
            $mail->messageId = sha1($raw) . '@generated.local';
        }
        return $mail;
    }

    /** @return array{0:string,1:string} */
    private static function splitHeaderBody(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        if (str_starts_with($raw, 'From ')) {
            $nl = strpos($raw, "\n");
            $raw = $nl === false ? '' : substr($raw, $nl + 1);
        }
        if (preg_match("/\r?\n\r?\n/", $raw, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return [substr($raw, 0, $pos), substr($raw, $pos + strlen($m[0][0]))];
        }
        return [$raw, ''];
    }

    /** @return array<string,list<string>> */
    public static function parseHeaders(string $block): array
    {
        $block = str_replace("\r\n", "\n", $block);
        $block = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        $headers = [];
        foreach (explode("\n", $block) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $headers[$name][] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    /** @param array<string,list<string>> $headers */
    private static function walk(ParsedEmail $mail, array $headers, string $body, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        [$type, $params] = self::parseParamHeader($headers['content-type'][0] ?? 'text/plain; charset=us-ascii');
        $type = strtolower($type ?: 'text/plain');
        [$disposition, $dparams] = self::parseParamHeader($headers['content-disposition'][0] ?? '');
        $disposition = strtolower($disposition);
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));

        if (str_starts_with($type, 'multipart/')) {
            if ($type === 'multipart/report') {
                $mail->isReport = true;
            }
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                return;
            }
            foreach (self::splitMultipart($body, $boundary) as $part) {
                [$h, $b] = self::splitHeaderBody($part);
                self::walk($mail, self::parseHeaders($h), $b, $depth + 1);
            }
            return;
        }

        $content = self::decodeBody($body, $encoding);
        $filename = $dparams['filename'] ?? $params['name'] ?? '';
        $filename = self::cleanFilename(self::decodeHeader($filename));
        $contentId = isset($headers['content-id'][0]) ? trim($headers['content-id'][0], " <>\t") : null;

        if ($type === 'message/rfc822' || $type === 'message/global') {
            if ($filename === '') {
                $inner = self::parseHeaders(self::splitHeaderBody($content)[0]);
                $subj = self::decodeHeader($inner['subject'][0] ?? 'message');
                $filename = self::cleanFilename(($subj !== '' ? $subj : 'message') . '.eml');
            }
            $mail->attachments[] = ['filename' => $filename, 'mime' => 'message/rfc822', 'content' => $content, 'content_id' => null, 'inline' => false];
            return;
        }
        if ($type === 'message/delivery-status') {
            $mail->isReport = true;
            return;
        }

        $isText = $type === 'text/plain' || $type === 'text/html';
        $isAttachment = $disposition === 'attachment' || $filename !== '';
        if (!$isText && !$isAttachment) {
            // e.g. image/png inline with Content-ID but no name, text/calendar...
            $isAttachment = true;
        }

        if ($isText && !$isAttachment) {
            $text = self::toUtf8($content, $params['charset'] ?? '');
            if ($type === 'text/html') {
                $mail->htmlBody .= ($mail->htmlBody !== '' ? "\n" : '') . $text;
            } else {
                $mail->textBody .= ($mail->textBody !== '' ? "\n\n" : '') . $text;
            }
            return;
        }

        if ($filename === '') {
            $ext = self::extensionFor($type);
            $filename = ($contentId ? 'image-' . substr(md5($contentId), 0, 8) : 'attachment') . ($ext ? '.' . $ext : '');
        }
        $mail->attachments[] = [
            'filename' => $filename,
            'mime' => $type,
            'content' => $content,
            'content_id' => $contentId,
            'inline' => $contentId !== null && $disposition !== 'attachment',
        ];
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $parts = [];
        $lines = preg_split("/\r?\n/", $body) ?: [];
        $current = null;
        foreach ($lines as $line) {
            $trim = rtrim($line, " \t");
            if ($trim === $delim . '--') {
                if ($current !== null) {
                    $parts[] = implode("\n", $current);
                }
                $current = null;
                break;
            }
            if ($trim === $delim) {
                if ($current !== null) {
                    $parts[] = implode("\n", $current);
                }
                $current = [];
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null && $current !== []) {
            $parts[] = implode("\n", $current); // unterminated multipart
        }
        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode(str_replace("\r\n", "\n", $body)),
            default => $body,
        };
    }

    /** @return array{0:string,1:array<string,string>} */
    public static function parseParamHeader(string $header): array
    {
        if (trim($header) === '') {
            return ['', []];
        }
        $parts = self::splitOutsideQuotes($header, ';');
        $value = trim((string)array_shift($parts));
        $params = [];
        $continuations = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) {
                continue;
            }
            $k = strtolower(trim(substr($p, 0, $eq)));
            $v = trim(substr($p, $eq + 1));
            if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                $v = stripcslashes(substr($v, 1, -1));
            }
            if (preg_match('/^([^*]+)\*(\d+)(\*?)$/', $k, $m)) {
                $continuations[$m[1]][(int)$m[2]] = [$v, $m[3] === '*'];
            } elseif (str_ends_with($k, '*')) {
                $params[substr($k, 0, -1)] = self::decode2231($v, true);
            } else {
                $params[$k] = $v;
            }
        }
        foreach ($continuations as $name => $segments) {
            ksort($segments);
            $charset = null;
            $joined = '';
            foreach ($segments as $i => [$v, $encoded]) {
                if ($encoded) {
                    if ($i === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                        $charset = $m[1];
                        $v = $m[2];
                    }
                    $joined .= rawurldecode($v);
                } else {
                    $joined .= $v;
                }
            }
            $params[$name] = $charset ? self::toUtf8($joined, $charset) : $joined;
        }
        return [$value, $params];
    }

    private static function decode2231(string $v, bool $encoded): string
    {
        if ($encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
            return self::toUtf8(rawurldecode($m[2]), $m[1] ?: 'UTF-8');
        }
        return rawurldecode($v);
    }

    /** @return list<string> */
    private static function splitOutsideQuotes(string $s, string $sep): array
    {
        $out = [];
        $buf = '';
        $inQuote = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $inQuote && $i + 1 < $len) {
                $buf .= $c . $s[++$i];
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
            }
            if ($c === $sep && !$inQuote) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $out[] = $buf;
        return $out;
    }

    public static function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (!str_contains($value, '=?')) {
            return self::ensureUtf8($value);
        }
        // Remove whitespace between adjacent encoded words (RFC 2047 6.2)
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;
        $decoded = preg_replace_callback('/=\?([^?]+)\?([QqBb])\?([^?]*)\?=/', function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]) ?? $m[1];
            $text = strtoupper($m[2]) === 'B'
                ? (string)base64_decode($m[3])
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::toUtf8($text, $charset);
        }, $value);
        return self::ensureUtf8($decoded ?? $value);
    }

    public static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset, " \"'"));
        $aliases = [
            '' => '', 'us-ascii' => 'UTF-8', 'ascii' => 'UTF-8', 'utf8' => 'UTF-8', 'utf-8' => 'UTF-8',
            'ks_c_5601-1987' => 'CP949', 'ks_c_5601' => 'CP949', 'gb2312' => 'GBK', 'x-gbk' => 'GBK',
            'iso-8859-8-i' => 'ISO-8859-8', 'latin1' => 'ISO-8859-1', 'cp-850' => 'CP850', 'x-sjis' => 'SJIS',
            'shift_jis' => 'SJIS', 'windows-874' => 'CP874', 'macintosh' => 'MacRoman', 'unknown-8bit' => '', 'x-unknown' => '',
        ];
        $cs = $aliases[$charset] ?? $charset;
        if ($cs === 'UTF-8' || $cs === '') {
            return self::ensureUtf8($text);
        }
        $supported = array_map('strtolower', mb_list_encodings());
        if (in_array(strtolower($cs), $supported, true)) {
            $r = @mb_convert_encoding($text, 'UTF-8', $cs);
            if (is_string($r)) {
                return $r;
            }
        }
        if (function_exists('iconv')) {
            $r = @iconv($cs, 'UTF-8//TRANSLIT//IGNORE', $text);
            if (is_string($r) && $r !== '') {
                return $r;
            }
        }
        return self::ensureUtf8($text);
    }

    private static function ensureUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /** @return list<array{email:string,name:string}> */
    public static function parseAddressList(string $header): array
    {
        $out = [];
        $buf = '';
        $inQuote = false;
        $angle = 0;
        $len = strlen($header);
        $items = [];
        for ($i = 0; $i < $len; $i++) {
            $c = $header[$i];
            if ($c === '\\' && $inQuote && $i + 1 < $len) {
                $buf .= $header[++$i];
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
            } elseif (!$inQuote && $c === '<') {
                $angle++;
            } elseif (!$inQuote && $c === '>') {
                $angle = max(0, $angle - 1);
            }
            if (($c === ',' || $c === ';') && !$inQuote && $angle === 0) {
                $items[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $items[] = $buf;
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $name = '';
            if (preg_match('/^(.*)<([^>]*)>\s*$/s', $item, $m)) {
                $name = trim($m[1]);
                $email = trim($m[2]);
            } else {
                $email = trim(preg_replace('/\(.*?\)/', '', $item) ?? $item);
                if (preg_match('/\(([^)]*)\)/', $item, $cm)) {
                    $name = $cm[1];
                }
            }
            // Group syntax "undisclosed-recipients:" etc.
            if (str_contains($email, ':')) {
                $email = trim(substr($email, strrpos($email, ':') + 1));
            }
            $name = trim(self::decodeHeader(trim($name, " \"'")));
            $email = strtolower(trim($email, " \"'"));
            if ($email !== '' && filter_var(self::asciiEmail($email), FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email' => $email, 'name' => $name === $email ? '' : $name];
            }
        }
        return $out;
    }

    public static function asciiEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $domain = substr($email, $at + 1);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain)) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) {
                $domain = $ascii;
            }
        }
        $local = substr($email, 0, $at);
        if (preg_match('/[^\x20-\x7e]/', $local)) {
            $local = 'utf8';
        }
        return $local . '@' . $domain;
    }

    public static function firstMessageId(string $header): string
    {
        $ids = self::allMessageIds($header);
        return $ids[0] ?? '';
    }

    /** @return list<string> */
    public static function allMessageIds(string $header): array
    {
        if (preg_match_all('/<([^<>\s]+)>/', $header, $m)) {
            return array_values(array_unique($m[1]));
        }
        $t = trim($header);
        return $t !== '' && !str_contains($t, ' ') ? [$t] : [];
    }

    public static function cleanFilename(string $name): string
    {
        $name = str_replace(["\0", '/', '\\', "\r", "\n", "\t"], ['', '_', '_', '', '', ' '], $name);
        $name = trim($name, " .");
        if (mb_strlen($name) > 180) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 170) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    public static function extensionFor(string $mime): string
    {
        return [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'image/bmp' => 'bmp', 'image/heic' => 'heic', 'application/pdf' => 'pdf', 'text/calendar' => 'ics',
            'text/plain' => 'txt', 'text/html' => 'html', 'text/csv' => 'csv', 'application/zip' => 'zip',
            'application/ms-tnef' => 'dat', 'application/vnd.ms-tnef' => 'dat',
        ][strtolower($mime)] ?? '';
    }
}
