<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class Log
{
    public static function info(string $title, string $message = ''): void
    {
        self::write('info', $title, $message);
    }

    public static function warning(string $title, string $message = ''): void
    {
        self::write('warning', $title, $message);
    }

    public static function error(string $title, string $message = ''): void
    {
        self::write('error', $title, $message);
    }

    public static function write(string $level, string $title, string $message): void
    {
        try {
            App::db()->insert('system_log', [
                'level' => $level,
                'title' => mb_substr($title, 0, 250),
                'message' => mb_substr($message, 0, 60000),
                'created_at' => DB::now(),
            ]);
        } catch (\Throwable $e) {
            error_log("[HelpCentre][$level] $title: $message");
        }
    }
}
