<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** A throwing listener is logged and skipped, so one faulty add-on can't break a page. */
final class Hooks
{
    /** @var array<string,list<array{cb:callable,priority:int,owner:?string,seq:int}>> */
    private static array $listeners = [];
    private static int $seq = 0;
    /** @var array<string,bool> */
    private static array $sorted = [];

    public static function on(string $name, callable $callback, int $priority = 10, ?string $owner = null): void
    {
        self::$listeners[$name][] = ['cb' => $callback, 'priority' => $priority, 'owner' => $owner, 'seq' => self::$seq++];
        unset(self::$sorted[$name]);
    }

    public static function has(string $name): bool
    {
        return !empty(self::$listeners[$name]);
    }

    public static function fire(string $name, mixed ...$args): void
    {
        foreach (self::listeners($name) as $l) {
            try {
                ($l['cb'])(...$args);
            } catch (\Throwable $e) {
                self::failed($name, $l['owner'], $e);
            }
        }
    }

    public static function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::listeners($name) as $l) {
            try {
                $value = ($l['cb'])($value, ...$args);
            } catch (\Throwable $e) {
                self::failed($name, $l['owner'], $e);
            }
        }
        return $value;
    }

    public static function render(string $name, array $context = []): string
    {
        $html = '';
        foreach (self::listeners($name) as $l) {
            try {
                ob_start();
                $out = ($l['cb'])($context);
                $echoed = (string)ob_get_clean();
                $html .= $echoed . (is_string($out) ? $out : '');
            } catch (\Throwable $e) {
                ob_end_clean();
                self::failed($name, $l['owner'], $e);
            }
        }
        return $html;
    }

    /** For when an add-on is switched off mid-request. */
    public static function forgetOwner(string $owner): void
    {
        foreach (self::$listeners as $name => $list) {
            self::$listeners[$name] = array_values(array_filter($list, fn($l) => $l['owner'] !== $owner));
        }
    }

    /** @return list<array{cb:callable,priority:int,owner:?string,seq:int}> */
    private static function listeners(string $name): array
    {
        if (empty(self::$listeners[$name])) {
            return [];
        }
        if (!isset(self::$sorted[$name])) {
            usort(self::$listeners[$name], fn($a, $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);
            self::$sorted[$name] = true;
        }
        return self::$listeners[$name];
    }

    private static function failed(string $hook, ?string $owner, \Throwable $e): void
    {
        $who = $owner !== null ? "Add-on \"$owner\"" : 'A hook listener';
        error_log("[HelpCentre] $who failed in $hook: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        try {
            Log::error("$who failed in $hook", $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        } catch (\Throwable) {
        }
        if ($owner !== null) {
            Addons::noteError($owner, "$hook: " . $e->getMessage());
        }
    }
}
