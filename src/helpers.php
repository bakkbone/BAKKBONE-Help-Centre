<?php
declare(strict_types=1);

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Csrf;
use Bakkbone\HelpCentre\Core\I18n;
use Bakkbone\HelpCentre\Core\RawHtml;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Plain text in the help desk's language; {name} placeholders are filled from $vars. */
function __(string $text, array $vars = [], ?string $context = null): string
{
    return I18n::translate($text, $vars, $context);
}

/** Plural form; {n} is filled in with the formatted count. */
function _n(string $singular, string $plural, int|float $n, array $vars = [], ?string $context = null): string
{
    return I18n::plural($singular, $plural, $n, $vars, $context);
}

/** HTML-safe __(): the translation and every var are escaped, except vars wrapped in raw(). */
function t(string $text, array $vars = [], ?string $context = null): string
{
    return t_fill(e(I18n::translate($text, [], $context)), $vars);
}

/** t() for HTML that's saved and edited later (templates, starter content): quotes are left readable. Not for attributes. */
function t_html(string $text, array $vars = []): string
{
    return t_fill(htmlspecialchars(I18n::translate($text), ENT_NOQUOTES, 'UTF-8'), $vars);
}

/** HTML-safe _n(). */
function tn(string $singular, string $plural, int|float $n, array $vars = [], ?string $context = null): string
{
    $vars += ['n' => I18n::number($n)];
    $pattern = I18n::plural($singular, $plural, $n, ['n' => '{n}'] + array_map(fn($k) => '{' . $k . '}', array_combine(array_keys($vars), array_keys($vars))), $context);
    return t_fill(e($pattern), $vars);
}

/** Marks a var for t()/tn() as HTML that's already safe, e.g. a link or <code>. */
function raw(string $html): RawHtml
{
    return new RawHtml($html);
}

/** Wraps {name}…{/name} in a translation, e.g. t('See the {link}ban list{/link}', ['link' => tag('<a href="…">')]). */
function tag(string $openTag): RawHtml
{
    if (!preg_match('/^<([a-z][a-z0-9]*)\b/i', $openTag, $m)) {
        throw new InvalidArgumentException('tag() needs an opening HTML tag');
    }
    return new RawHtml($openTag, '</' . strtolower($m[1]) . '>');
}

function t_fill(string $escaped, array $vars): string
{
    // Simple formatting tags (no attributes) may appear in translations; everything else stays escaped.
    $escaped = (string)preg_replace('#&lt;(/?)(strong|em|b|i|code|kbd|small|sup|sub|br)\s*/?&gt;#i', '<$1$2>', $escaped);
    if (!$vars) {
        return $escaped;
    }
    $map = [];
    foreach ($vars as $k => $v) {
        if ($v instanceof RawHtml && $v->close !== null) {
            $map['{' . $k . '}'] = $v->open;
            $map['{/' . $k . '}'] = $v->close;
            continue;
        }
        $map['{' . $k . '}'] = $v instanceof RawHtml ? (string)$v : e(is_scalar($v) || $v instanceof Stringable ? (string)$v : '');
    }
    return strtr($escaped, $map);
}

function fmt_num(int|float $n, int $decimals = 0): string
{
    return I18n::number($n, $decimals);
}

function lang_attr(): string
{
    return ' lang="' . e(I18n::htmlLang()) . '" dir="' . I18n::dir() . '"';
}

/** DateTime::format() with month/day names, am/pm and ordinal suffixes in the help desk's language. */
function local_format(DateTimeInterface $d, string $format): string
{
    if (I18n::locale() === I18n::DEFAULT && !is_file(HC_ROOT . '/custom/lang/en.json')) {
        return $d->format($format);
    }
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $out .= $format[++$i];
            continue;
        }
        $out .= match ($c) {
            'D' => __($d->format('D'), [], 'short weekday'),
            'l' => __($d->format('l')),
            'M' => __($d->format('M'), [], 'short month'),
            'F' => __($d->format('F')),
            'a' => __($d->format('a'), [], 'time'),
            'A' => __($d->format('A'), [], 'time'),
            'S' => __($d->format('S'), [], 'ordinal suffix'),
            default => preg_match('/[a-zA-Z]/', $c) ? $d->format($c) : $c,
        };
    }
    return $out;
}

function url(string $path = '', array $query = []): string
{
    return App::url($path, $query);
}

function asset(string $path): string
{
    return App::asset($path);
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function old(string $key, mixed $default = ''): mixed
{
    return Session::old($key, $default);
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function local_dt(?string $utc): ?DateTimeImmutable
{
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(Settings::timezone());
    } catch (Throwable) {
        return null;
    }
}

function fmt_date(?string $utc): string
{
    $d = local_dt($utc);
    return $d ? local_format($d, (string)setting('date_format')) : '';
}

function fmt_dt(?string $utc): string
{
    $d = local_dt($utc);
    return $d ? local_format($d, setting('date_format') . ' ' . setting('time_format')) : '';
}

function to_utc(?string $local): ?string
{
    if ($local === null || trim($local) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($local, Settings::timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function input_dt(?string $utc): string
{
    $d = local_dt($utc);
    return $d ? $d->format('Y-m-d\TH:i') : '';
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $diff = time() - strtotime($utc . ' UTC');
    $future = $diff < 0;
    $diff = abs($diff);
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $secs => $unit) {
        if ($diff >= $secs) {
            $n = intdiv($diff, $secs);
            return match ($unit) {
                'year' => $future ? _n('in {n} year', 'in {n} years', $n) : _n('{n} year ago', '{n} years ago', $n),
                'month' => $future ? _n('in {n} month', 'in {n} months', $n) : _n('{n} month ago', '{n} months ago', $n),
                'week' => $future ? _n('in {n} week', 'in {n} weeks', $n) : _n('{n} week ago', '{n} weeks ago', $n),
                'day' => $future ? _n('in {n} day', 'in {n} days', $n) : _n('{n} day ago', '{n} days ago', $n),
                'hour' => $future ? _n('in {n} hour', 'in {n} hours', $n) : _n('{n} hour ago', '{n} hours ago', $n),
                default => $future ? _n('in {n} minute', 'in {n} minutes', $n) : _n('{n} minute ago', '{n} minutes ago', $n),
            };
        }
    }
    return $future ? __('in under a minute') : __('just now');
}

function human_size(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return __('{size} {unit}', ['size' => $i === 0 ? fmt_num((int)$bytes) : fmt_num($bytes, 1), 'unit' => __($units[$i], [], 'file size')]);
}

function selected(mixed $a, mixed $b): string
{
    if (is_array($b)) {
        return in_array((string)$a, array_map('strval', $b), true) ? ' selected' : '';
    }
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(mixed $value): string
{
    return $value && $value !== '0' ? ' checked' : '';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $i .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $i ?: '?';
}

/** Prefer _n() with whole phrases; this English-only shape is kept for add-ons written before translation existed. */
function plural(int $n, string $singular, ?string $pluralForm = null): string
{
    return _n('{n} ' . $singular, '{n} ' . ($pluralForm ?? $singular . 's'), $n);
}

function contrast_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#ffffff';
    }
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#ffffff';
}

function valid_hex_color(string $c): string
{
    return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $c) ? $c : '#0e7490';
}

function pager(int $page, int $pages, string $path, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pager" aria-label="' . t('Pagination') . '">';
    $link = function (int $p, string $label, bool $active = false) use ($path, $query) {
        $q = array_merge($query, ['page' => $p]);
        return '<a class="' . ($active ? 'active' : '') . '" href="' . e(url($path, $q)) . '">' . $label . '</a>';
    };
    if ($page > 1) {
        $out .= $link($page - 1, t('‹ Prev'));
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $out .= $link(1, fmt_num(1)) . ($start > 2 ? '<span>…</span>' : '');
    }
    for ($p = $start; $p <= $end; $p++) {
        $out .= $link($p, fmt_num($p), $p === $page);
    }
    if ($end < $pages) {
        $out .= ($end < $pages - 1 ? '<span>…</span>' : '') . $link($pages, fmt_num($pages));
    }
    if ($page < $pages) {
        $out .= $link($page + 1, t('Next ›'));
    }
    return $out . '</nav>';
}

/** Decorative: pair with visible text or an aria-label. */
function icon_exists(string $name): bool
{
    static $ids = null;
    if ($ids === null) {
        preg_match_all('/<symbol id="([^"]+)"/', (string)@file_get_contents(HC_ROOT . '/public/assets/icons.svg'), $m);
        $ids = array_flip($m[1]);
    }
    return isset($ids[$name]);
}

function icon(string $name, string $class = ''): string
{
    static $sprite = null;
    $sprite ??= asset('icons.svg');
    if (I18n::dir() === 'rtl' && in_array($name, ['arrow_forward', 'chevron_right', 'logout', 'login', 'reply', 'open_in_new', 'arrow_back', 'chevron_left'], true)) {
        $class = trim($class . ' mirror');
    }
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false"><use href="'
        . e($sprite) . '#' . e($name) . '"></use></svg>';
}

function theme_attr(): string
{
    $t = $_COOKIE['hc_theme'] ?? '';
    return in_array($t, ['light', 'dark'], true) ? ' data-theme="' . $t . '"' : '';
}
