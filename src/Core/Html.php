<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Html
{
    /** @var array<string,\HTMLPurifier> */
    private static array $purifiers = [];

    /** $profile: thread, signature (adds images and tables) or basic. */
    public static function clean(?string $html, string $profile = 'thread'): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }
        // The purifier would percent-encode %{tags} inside href/src, so they'd never be filled in.
        $tags = [];
        $html = (string)preg_replace_callback('/%\{\s*[a-zA-Z0-9_.\-]+\s*\}/', function ($m) use (&$tags) {
            $tags[] = $m[0];
            return 'bhcmergetag' . (count($tags) - 1) . 'x';
        }, $html);
        $clean = self::purifier($profile)->purify($html);
        return $tags === [] ? $clean : (string)preg_replace_callback('/bhcmergetag(\d+)x/', fn($m) => $tags[(int)$m[1]] ?? $m[0], $clean);
    }

    /** Merge tags saved before clean() protected them were percent-encoded inside links. */
    public static function restoreMergeTags(string $html): string
    {
        if (stripos($html, '%25%7B') === false) {
            return $html;
        }
        return (string)preg_replace('/%25%7B(?:%20)*([a-zA-Z0-9_.\-]+)(?:%20)*%7D/i', '%{$1}', $html);
    }

    private static function purifier(string $profile): \HTMLPurifier
    {
        if (isset(self::$purifiers[$profile])) {
            return self::$purifiers[$profile];
        }
        $config = \HTMLPurifier_Config::createDefault();
        $cacheDir = App::storage('cache/htmlpurifier');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0770, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);
        $config->set('CSS.AllowTricky', false);
        $config->set('AutoFormat.RemoveEmpty', false);

        if ($profile === 'basic') {
            $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,a[href],ul,ol,li,blockquote,code,pre,h2,h3,h4');
        } else {
            $config->set('HTML.Allowed', implode(',', [
                'p[style|align]', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'small', 'big',
                'span[style]', 'div[style|align]', 'font[color|face|size]', 'a[href|title|target]',
                'ul[style]', 'ol[style|start|type]', 'li[style]', 'blockquote[style]', 'pre', 'code', 'hr',
                'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5', 'h6',
                'img[src|alt|width|height|style|title]',
                'table[style|width|border|cellpadding|cellspacing|align|bgcolor]', 'thead', 'tbody', 'tfoot',
                'tr[style|bgcolor|align|valign]', 'td[style|width|colspan|rowspan|align|valign|bgcolor]',
                'th[style|width|colspan|rowspan|align|valign|bgcolor]', 'caption', 'col[width]', 'colgroup',
            ]));
            $config->set('CSS.AllowedProperties', [
                'color', 'background-color', 'font-weight', 'font-style', 'font-size', 'font-family', 'text-decoration',
                'text-align', 'vertical-align', 'width', 'height', 'max-width', 'margin', 'margin-top', 'margin-bottom',
                'margin-left', 'margin-right', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
                'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'border-collapse', 'border-radius',
                'line-height', 'list-style-type', 'white-space', 'display',
            ]);
        }
        return self::$purifiers[$profile] = new \HTMLPurifier($config);
    }

    /** @return array{0:string,1:int} the HTML with off-site image addresses moved to data-remote-src, and how many were hidden */
    public static function hideRemoteImages(string $html): array
    {
        $ownHost = strtolower((string)parse_url(App::baseUrl(), PHP_URL_HOST));
        $hidden = 0;
        $out = (string)preg_replace_callback('~<img\b[^>]*>~i', function ($m) use ($ownHost, &$hidden) {
            return (string)preg_replace_callback('~\ssrc\s*=\s*(["\'])(.*?)\1~is', function ($s) use ($ownHost, &$hidden) {
                $src = html_entity_decode($s[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('~^(https?:)?//~i', $src) || strtolower((string)parse_url($src, PHP_URL_HOST)) === $ownHost) {
                    return $s[0];
                }
                $hidden++;
                return ' data-remote-src=' . $s[1] . $s[2] . $s[1];
            }, $m[0], 1);
        }, $html);
        return [$out, $hidden];
    }

    public static function fromText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('~(?<![="\'>])\b(https?://[^\s<]+[^\s<.,;:!?)\]\'"])~i', '<a href="$1">$1</a>', $html) ?? $html;
        $paras = preg_split("/\n{2,}/", $html) ?: [];
        return implode('', array_map(fn($p) => '<p>' . nl2br($p, false) . '</p>', $paras));
    }

    public static function toText(string $html): string
    {
        $html = preg_replace('~<(script|style|head)[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(p|div|h[1-6]|li|tr|blockquote|table)>~i', "\n", $html) ?? $html;
        $html = preg_replace('~<li[^>]*>~i', ' - ', $html) ?? $html;
        $html = preg_replace_callback('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', function ($m) {
            $label = trim(strip_tags($m[2]));
            return $label !== '' && $label !== $m[1] && !str_starts_with($m[1], 'mailto:') ? "$label ({$m[1]})" : $label;
        }, $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    public static function excerpt(string $html, int $length = 160): string
    {
        $t = preg_replace('/\s+/', ' ', self::toText($html)) ?? '';
        return mb_strlen($t) > $length ? rtrim(mb_substr($t, 0, $length - 1)) . '…' : $t;
    }
}
