<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** Keys are the English text itself, so anything a pack doesn't translate falls back to English. See lang/README.md. */
final class I18n
{
    public const DEFAULT = 'en';
    private const CODE = '/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/';

    private static ?string $locale = null;
    /** @var array<string,string|list<string>> */
    private static array $strings = [];
    /** @var array<string,mixed> */
    private static array $meta = [];
    private static ?\Closure $plural = null;
    /** @var list<string> */
    private static array $extraDirs = [];

    public static function locale(): string
    {
        self::boot();
        return (string)self::$locale;
    }

    public static function dir(): string
    {
        self::boot();
        return (self::$meta['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }

    public static function htmlLang(): string
    {
        self::boot();
        return str_replace('_', '-', (string)(self::$meta['html_lang'] ?? self::$locale));
    }

    public static function meta(string $key, mixed $default = null): mixed
    {
        self::boot();
        return self::$meta[$key] ?? $default;
    }

    public static function use(string $locale): void
    {
        self::$locale = preg_match(self::CODE, $locale) ? $locale : self::DEFAULT;
        self::$strings = [];
        self::$meta = ['name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'plural' => 'n != 1', 'plural_count' => 2, 'decimal' => '.', 'thousands' => ','];
        $files = self::files(self::$locale);
        if (!$files && self::$locale !== self::DEFAULT) {
            self::$locale = self::DEFAULT;
            $files = self::files(self::DEFAULT);
        }
        foreach ($files as $file) {
            self::merge($file);
        }
        self::$plural = self::compilePlural((string)self::$meta['plural']);
    }

    /** Add-ons call this as they load so their lang/ folder is merged in. */
    public static function addDirectory(string $dir): void
    {
        if (!in_array($dir, self::$extraDirs, true)) {
            self::$extraDirs[] = $dir;
            if (self::$locale !== null && is_file($f = $dir . '/' . self::$locale . '.json')) {
                self::merge($f);
                self::$plural = self::compilePlural((string)self::$meta['plural']);
            }
        }
    }

    public static function translate(string $text, array $vars = [], ?string $context = null): string
    {
        self::boot();
        $hit = self::$strings[$context !== null ? $context . '::' . $text : $text] ?? null;
        $out = is_string($hit) && $hit !== '' ? $hit : (is_array($hit) && ($hit[0] ?? '') !== '' ? $hit[0] : $text);
        return $vars ? self::fill($out, $vars) : $out;
    }

    public static function plural(string $singular, string $plural, int|float $n, array $vars = [], ?string $context = null): string
    {
        self::boot();
        $vars += ['n' => self::number($n)];
        $hit = self::$strings[$context !== null ? $context . '::' . $singular : $singular] ?? null;
        if (is_array($hit) && $hit && ($hit[0] ?? '') !== '') {
            $i = (int)(self::$plural)((int)$n);
            $form = $hit[$i] ?? $hit[count($hit) - 1];
            return self::fill($form !== '' ? $form : ($n == 1 ? $singular : $plural), $vars);
        }
        return self::fill($n == 1 ? $singular : $plural, $vars);
    }

    public static function number(int|float $n, int $decimals = 0): string
    {
        self::boot();
        return number_format((float)$n, $decimals, (string)self::$meta['decimal'], (string)self::$meta['thousands']);
    }

    /** @return array<string,array{code:string,name:string,native:string,dir:string}> */
    public static function available(): array
    {
        $out = [self::DEFAULT => ['code' => self::DEFAULT, 'name' => 'English', 'native' => 'English', 'dir' => 'ltr']];
        foreach (self::searchDirs() as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                $code = basename($file, '.json');
                if (!preg_match(self::CODE, $code) || isset($out[$code]) && $code === self::DEFAULT) {
                    continue;
                }
                $data = json_decode((string)file_get_contents($file), true);
                if (!is_array($data)) {
                    continue;
                }
                $m = is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
                $out[$code] = ['code' => $code, 'name' => (string)($m['name'] ?? $out[$code]['name'] ?? $code),
                    'native' => (string)($m['native'] ?? $out[$code]['native'] ?? $m['name'] ?? $code), 'dir' => ($m['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr'];
            }
        }
        uasort($out, fn($a, $b) => $a['code'] === self::DEFAULT ? -1 : ($b['code'] === self::DEFAULT ? 1 : strcmp($a['name'], $b['name'])));
        return $out;
    }

    /** Translations app.js uses, for the page's JSON block. Empty in English, where app.js already has the text. */
    public static function jsStrings(): array
    {
        self::boot();
        if (!self::$strings) {
            return [];
        }
        $out = ['_decimal' => (string)self::$meta['decimal'], '_thousands' => (string)self::$meta['thousands'], '_plural' => []];
        // n of 200 or more uses the form for 100 + n % 100: no gettext rule looks further than n % 100 once n is past 100.
        for ($n = 0; $n < 200; $n++) {
            $out['_plural'][] = (int)(self::$plural)($n);
        }
        $src = (string)@file_get_contents(HC_ROOT . '/public/assets/js/app.js');
        preg_match_all("/\\b(__|_n)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m, PREG_SET_ORDER);
        foreach ($m as [, $fn, $raw]) {
            $key = stripcslashes($raw);
            $hit = self::$strings[$key] ?? null;
            if ($hit === null) {
                continue;
            }
            if ($fn === '_n') {
                $out['plural:' . $key] = (array)$hit;
            } else {
                $out[$key] = is_array($hit) ? (string)($hit[0] ?? '') : $hit;
            }
        }
        return $out;
    }

    private static function boot(): void
    {
        if (self::$locale !== null) {
            return;
        }
        // Anything translated while the setting is being read (an error, say) gets English instead of recursing.
        self::use(self::DEFAULT);
        $locale = self::DEFAULT;
        try {
            $locale = App::$installed ? (string)(Settings::get('locale') ?: self::DEFAULT) : (string)(App::$config['locale'] ?? self::DEFAULT);
        } catch (\Throwable) {
        }
        if ($locale !== self::DEFAULT) {
            self::use($locale);
        }
    }

    /** @return list<string> */
    private static function searchDirs(): array
    {
        return array_merge([HC_ROOT . '/lang'], self::$extraDirs, [HC_ROOT . '/custom/lang']);
    }

    /** custom/lang/ goes last so its wording wins. @return list<string> */
    private static function files(string $locale): array
    {
        $out = [];
        foreach (self::searchDirs() as $dir) {
            if (is_file($f = $dir . '/' . $locale . '.json')) {
                $out[] = $f;
            }
        }
        return $out;
    }

    private static function merge(string $file): void
    {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            error_log('[HelpCentre] Language file is not valid JSON: ' . $file);
            return;
        }
        if (is_array($data['_meta'] ?? null)) {
            // An add-on's pack can't change the language's plural rule or direction.
            self::$meta = in_array(dirname($file), self::$extraDirs, true) ? self::$meta + $data['_meta'] : array_merge(self::$meta, $data['_meta']);
        }
        unset($data['_meta']);
        foreach ($data as $k => $v) {
            if (is_string($k) && (is_string($v) || (is_array($v) && array_is_list($v)))) {
                if ($v !== '' && $v !== []) {
                    self::$strings[$k] = $v;
                }
            }
        }
    }

    private static function fill(string $text, array $vars): string
    {
        if (!$vars || !str_contains($text, '{')) {
            return $text;
        }
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = is_scalar($v) || $v instanceof \Stringable ? (string)$v : '';
        }
        return strtr($text, $map);
    }

    private static function compilePlural(string $expr): \Closure
    {
        return PluralRule::compile($expr) ?? fn(int $n): int => $n === 1 ? 0 : 1;
    }
}
