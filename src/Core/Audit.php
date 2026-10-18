<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

use Bakkbone\HelpCentre\Services\Auth;

final class Audit
{
    public static function log(string $action, ?string $objectType = null, ?int $objectId = null, string|array $details = ''): void
    {
        try {
            App::db()->insert('audit_log', [
                'staff_id' => Auth::staffId(),
                'user_id' => Auth::staffId() ? null : Auth::userId(),
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $details,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : Request::ip(),
                'created_at' => DB::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('[HelpCentre] audit failed: ' . $e->getMessage());
        }
    }
}
