<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

/** The clock restarts when the customer replies to an answered ticket, so the SLA measures time to the next response. */
final class Sla
{
    public static function dueAt(?int $slaId, ?int $from = null): ?string
    {
        $sla = $slaId ? Tickets::lookup('sla', $slaId) : null;
        if (!$sla || !$sla['active'] || (int)$sla['grace_hours'] <= 0) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', ($from ?? time()) + (int)$sla['grace_hours'] * 3600);
    }
}
