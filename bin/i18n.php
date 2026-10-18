#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$usage = <<<'USAGE'
Tools for translators. Language packs are JSON files keyed by the English text (see lang/README.md).

  php bin/i18n.php extract [--addon=slug]       Rebuild lang/template.json (or the add-on's lang/template.json)
                                                  from every translatable string in the code.
  php bin/i18n.php update <file.json>           Add any new strings from the template to a pack (keeps translations).
  php bin/i18n.php check <file.json>            Report untranslated, unused and suspicious entries in a pack.
  php bin/i18n.php pseudo <code> [--rtl]        Write custom/lang/<code>.json, a fake translation for testing
                                                  (any plain English left on screen hasn't been made translatable).
USAGE;

$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';
if ($cmd === '' || in_array($cmd, ['-h', '--help', 'help'], true)) {
    echo $usage, "\n";
    exit(0);
}

$root = dirname(__DIR__);
define('HC_ROOT', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Bakkbone\\HelpCentre\\') && is_file($f = $root . '/src/' . str_replace('\\', '/', substr($class, 20)) . '.php')) {
        require $f;
    }
});
$opt = [];
$pos = [];
foreach (array_slice($args, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $a;
    }
}

/** @return array<string, array{plural:?string, refs:list<string>}> */
function extract_strings(array $dirs, string $root): array
{
    $found = [];
    $add = function (string $key, ?string $plural, string $ref) use (&$found) {
        $found[$key] ??= ['plural' => $plural, 'refs' => []];
        if ($plural !== null) {
            $found[$key]['plural'] = $plural;
        }
        if (count($found[$key]['refs']) < 5) {
            $found[$key]['refs'][] = $ref;
        }
    };
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            $rel = ltrim(substr($path, strlen($root)), '/');
            if (str_contains($rel, 'storage/') || str_contains($rel, 'vendor/') || str_contains($path, '/lang/')) {
                continue;
            }
            if (str_ends_with($path, '.php')) {
                extract_php(file_get_contents($path), $rel, $add);
            } elseif (str_ends_with($path, '.js') && !str_ends_with($path, 'qrcode.js')) {
                extract_js(file_get_contents($path), $rel, $add);
            }
        }
    }
    return $found;
}

function extract_php(string $src, string $rel, callable $add): void
{
    $toks = token_get_all($src);
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['__', 't', 't_html', '_n', 'tn'], true)) {
            continue;
        }
        $prev = $i - 1;
        while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev >= 0 && is_array($toks[$prev]) && in_array($toks[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($toks[$j] ?? null) !== '(') {
            continue;
        }
        $argsTok = [[]];
        $depth = 0;
        for ($k = $j + 1; $k < $n; $k++) {
            $x = $toks[$k];
            if (in_array($x, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($x, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($x === ',' && $depth === 0) {
                $argsTok[] = [];
                continue;
            }
            if (!(is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT], true))) {
                $argsTok[count($argsTok) - 1][] = $x;
            }
        }
        $lit = function (array $arg): ?string {
            if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                return stripcslashes_php($arg[0][1]);
            }
            return null;
        };
        $ref = $rel . ':' . $t[2];
        if (in_array($t[1], ['__', 't', 't_html'], true)) {
            $text = $lit($argsTok[0] ?? []);
            $ctx = isset($argsTok[2]) ? $lit($argsTok[2]) : null;
            if ($text !== null) {
                $add($ctx !== null ? $ctx . '::' . $text : $text, null, $ref);
            }
        } else {
            $one = $lit($argsTok[0] ?? []);
            $many = $lit($argsTok[1] ?? []);
            $ctx = isset($argsTok[4]) ? $lit($argsTok[4]) : null;
            if ($one !== null && $many !== null) {
                $add($ctx !== null ? $ctx . '::' . $one : $one, $many, $ref);
            }
        }
    }
}

function stripcslashes_php(string $literal): string
{
    $q = $literal[0];
    $body = substr($literal, 1, -1);
    return $q === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body) : stripcslashes($body);
}

function extract_js(string $src, string $rel, callable $add): void
{
    $str = "'((?:[^'\\\\]|\\\\.)*)'";
    preg_match_all("/\\b__\\(\\s*$str/", $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$s, $off]) {
        $add(stripcslashes($s), null, $rel . ':' . (substr_count(substr($src, 0, $off), "\n") + 1));
    }
    preg_match_all("/\\b_n\\(\\s*$str\\s*,\\s*$str/", $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $idx => [$s, $off]) {
        $add(stripcslashes($s), stripcslashes($m[2][$idx][0]), $rel . ':' . (substr_count(substr($src, 0, $off), "\n") + 1));
    }
}

function read_pack(string $file): array
{
    if (!is_file($file)) {
        fwrite(STDERR, "Can't find $file\n");
        exit(1);
    }
    $d = json_decode((string)file_get_contents($file), true);
    if (!is_array($d)) {
        fwrite(STDERR, "$file isn't valid JSON: " . json_last_error_msg() . "\n");
        exit(1);
    }
    return $d;
}

function write_json(string $file, array $data): void
{
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

function placeholders(string $s): array
{
    preg_match_all('/\{\/?[a-z0-9_]+\}|<\/?(?:strong|em|b|i|code|kbd|small|sup|sub|br)>/i', $s, $m);
    $p = $m[0];
    sort($p);
    return $p;
}

function template_path(string $root, array $opt): string
{
    return !empty($opt['addon']) ? $root . '/addons/' . $opt['addon'] . '/lang/template.json' : $root . '/lang/template.json';
}

function addon_strings(string $dir, string $root): array
{
    $found = extract_strings([$dir], $root);
    $m = json_decode((string)@file_get_contents($dir . '/addon.json'), true);
    if (is_array($m)) {
        $texts = [$m['name'] ?? null, $m['description'] ?? null];
        foreach ((array)($m['settings'] ?? []) as $def) {
            $texts[] = $def['label'] ?? null;
            $texts[] = $def['hint'] ?? null;
            foreach ((array)($def['options'] ?? []) as $label) {
                $texts[] = $label;
            }
        }
        foreach ($texts as $s) {
            if (is_string($s) && $s !== '') {
                $found[$s] ??= ['plural' => null, 'refs' => ['addon.json']];
            }
        }
    }
    return $found;
}

/** With --addon, only the add-on's own strings (the core pack covers the rest). */
function build_template(string $root, array $opt, bool $withAddons = false): array
{
    $found = extract_strings([$root . '/src', $root . '/templates', $root . '/public/assets/js', $root . '/bin'], $root);
    foreach (\Bakkbone\HelpCentre\Core\Translatable::strings() as $key => $plural) {
        $found[$key] ??= ['plural' => $plural, 'refs' => ['(labels)']];
    }
    if (!empty($opt['addon'])) {
        $dir = $root . '/addons/' . $opt['addon'];
        if (!is_dir($dir)) {
            fwrite(STDERR, "There's no add-on folder addons/{$opt['addon']}\n");
            exit(1);
        }
        $found = array_diff_key(addon_strings($dir, $root), $found);
    } elseif ($withAddons) {
        foreach (glob($root . '/addons/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $found += addon_strings($dir, $root);
        }
    }
    ksort($found, SORT_STRING | SORT_FLAG_CASE);
    $tpl = !empty($opt['addon']) ? [] : ['_meta' => [
        'name' => 'English name of the language, e.g. French', 'native' => 'Its own name, e.g. Français', 'dir' => 'ltr',
        'html_lang' => 'fr', 'plural' => 'n != 1', 'plural_count' => 2, 'decimal' => '.', 'thousands' => ',',
        'authors' => '', 'version' => '1',
    ]];
    foreach ($found as $k => $info) {
        $tpl[$k] = $info['plural'] !== null ? ['', ''] : '';
    }
    return [$tpl, $found];
}

switch ($cmd) {
    case 'extract':
        [$tpl, $found] = build_template($root, $opt);
        $out = template_path($root, $opt);
        write_json($out, $tpl);
        echo 'Wrote ', count($tpl) - (isset($tpl['_meta']) ? 1 : 0), ' strings to ', substr($out, strlen($root) + 1), "\n";
        break;

    case 'update':
        $file = $pos[0] ?? '';
        $pack = read_pack($file);
        [$tpl] = build_template($root, $opt);
        $added = 0;
        foreach ($tpl as $k => $v) {
            if ($k === '_meta') {
                $pack['_meta'] = ($pack['_meta'] ?? []) + $v;
                continue;
            }
            if (!array_key_exists($k, $pack)) {
                $pack[$k] = is_array($v) ? array_fill(0, (int)($pack['_meta']['plural_count'] ?? 2), '') : '';
                $added++;
            }
        }
        $meta = $pack['_meta'] ?? null;
        unset($pack['_meta']);
        ksort($pack, SORT_STRING | SORT_FLAG_CASE);
        write_json($file, ($meta !== null ? ['_meta' => $meta] : []) + $pack);
        echo "Added $added new strings to $file\n";
        break;

    case 'check':
        $file = $pos[0] ?? '';
        $pack = read_pack($file);
        [$tpl] = build_template($root, $opt);
        [$known] = empty($opt['addon']) ? build_template($root, [], true) : [$tpl];
        $meta = $pack['_meta'] ?? [];
        $problems = 0;
        $say = function (string $m) use (&$problems) {
            $problems++;
            echo "  - $m\n";
        };
        foreach (empty($opt['addon']) ? ['name', 'native', 'dir', 'plural', 'plural_count'] : [] as $k) {
            if (!isset($meta[$k])) {
                $say("_meta is missing \"$k\"");
            }
        }
        if (isset($meta['dir']) && !in_array($meta['dir'], ['ltr', 'rtl'], true)) {
            $say('_meta.dir must be "ltr" or "rtl"');
        }
        if (isset($meta['plural']) && \Bakkbone\HelpCentre\Core\PluralRule::compile((string)$meta['plural']) === null) {
            $say('_meta.plural is not a valid plural expression: ' . $meta['plural']);
        }
        $count = (int)($meta['plural_count'] ?? 2);
        $untranslated = 0;
        foreach ($tpl as $k => $v) {
            if ($k === '_meta') {
                continue;
            }
            $have = $pack[$k] ?? null;
            if ($have === null || $have === '' || (is_array($have) && implode('', $have) === '')) {
                $untranslated++;
                continue;
            }
            $source = str_contains($k, '::') ? substr($k, strpos($k, '::') + 2) : $k;
            if (is_array($v) !== is_array($have)) {
                $say("\"$k\" should be " . (is_array($v) ? "a list of $count plural forms" : 'a single string'));
                continue;
            }
            foreach ((array)$have as $form) {
                if ($form !== '' && placeholders($form) !== placeholders($source) && !(is_array($v) && placeholders($form) === array_values(array_diff(placeholders($source), ['{n}'])))) {
                    $say("\"$k\": placeholders or tags differ (" . implode(' ', placeholders($source)) . ' → ' . implode(' ', placeholders($form)) . ')');
                }
            }
            if (is_array($have) && count($have) !== $count) {
                $say("\"$k\" has " . count($have) . " plural forms; _meta.plural_count is $count");
            }
        }
        foreach ($pack as $k => $v) {
            if ($k !== '_meta' && !array_key_exists($k, $known)) {
                $say("\"$k\" isn't used any more");
            }
        }
        $total = count($tpl) - 1;
        printf("%s: %d of %d strings translated (%d%%), %d problem(s)\n", basename($file), $total - $untranslated, $total, $total ? round(100 * ($total - $untranslated) / $total) : 100, $problems);
        exit($problems ? 1 : 0);

    case 'pseudo':
        $code = $pos[0] ?? '';
        if (!preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $code)) {
            fwrite(STDERR, "Give a language code, e.g. php bin/i18n.php pseudo xx\n");
            exit(1);
        }
        [$tpl] = build_template($root, [], true);
        $rtl = !empty($opt['rtl']);
        $map = array_combine(str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), mb_str_split('áƀçđéƒĝĥíĵķłɱñóþǫŕšŧúṽŵẋýžÁƁÇĐÉƑĜĤÍĴĶŁṀÑÓÞǪŔŠŦÚṼŴẊÝŽ'));
        if ($rtl) {
            $map += ['←' => '→', '→' => '←', '‹' => '›', '›' => '‹'];
        }
        $fake = function (string $s) use ($map) {
            $parts = preg_split('/(\{\/?[a-z0-9_]+\}|<[^>]+>|%\{[^}]+\}|&[a-z]+;)/i', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $out = '';
            foreach ($parts as $i => $p) {
                $out .= $i % 2 ? $p : strtr($p, $map);
            }
            return '⟦' . $out . '⟧';
        };
        $pack = ['_meta' => ['name' => 'Pseudo' . ($rtl ? ' (RTL)' : ''), 'native' => 'Ƥšéúđó' . ($rtl ? ' (RTL)' : ''), 'dir' => $rtl ? 'rtl' : 'ltr',
            'html_lang' => $rtl ? 'ar' : 'en-XA', 'plural' => 'n != 1', 'plural_count' => 2, 'decimal' => ',', 'thousands' => '.']];
        foreach ($tpl as $k => $v) {
            if ($k === '_meta') {
                continue;
            }
            $source = str_contains($k, '::') ? substr($k, strpos($k, '::') + 2) : $k;
            $pack[$k] = is_array($v) ? [$fake($source), $fake($source) . '₂'] : $fake($source);
        }
        $file = $root . '/custom/lang/' . $code . '.json';
        write_json($file, $pack);
        echo 'Wrote ', count($pack) - 1, " strings to custom/lang/$code.json. Pick it under Admin → Settings → General → Language.\n";
        break;

    default:
        fwrite(STDERR, "Unknown command \"$cmd\". Run with --help.\n");
        exit(1);
}
