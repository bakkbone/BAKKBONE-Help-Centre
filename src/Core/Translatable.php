<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** Labels kept in constants are translated where they're shown, so bin/i18n.php can't see them as literals; this lists them for it. */
final class Translatable
{
    private const SOURCES = [
        [\Bakkbone\HelpCentre\Controllers\Staff\TicketController::class, 'QUEUES', null],
        [\Bakkbone\HelpCentre\Services\Tickets::class, 'SOURCES', null],
        [\Bakkbone\HelpCentre\Services\Webhooks::class, 'EVENTS', null],
        [\Bakkbone\HelpCentre\Services\Staff::class, 'PERMISSIONS', null],
        [\Bakkbone\HelpCentre\Services\FilterEngine::class, 'FIELDS', null],
        [\Bakkbone\HelpCentre\Services\FilterEngine::class, 'OPS', null],
        [\Bakkbone\HelpCentre\Services\FilterEngine::class, 'ACTIONS', null],
        [\Bakkbone\HelpCentre\Services\OsTicketImporter::class, 'STEPS', 0],
        [\Bakkbone\HelpCentre\Services\BhcSync::class, 'TABLES', 0],
    ];

    /** Shown through a variable, e.g. stored in import state. */
    private const EXTRA = [
        'catch-up import', 'copied during setup', 'copied from an export file during setup',
        'Ticket', 'User (ticket owner)', 'Recipient', 'Agent (actor)', 'Content', 'Help desk', 'Auth emails',
    ];

    /** @return array<string,?string> text => plural (always null here) */
    public static function strings(): array
    {
        $out = array_fill_keys(self::EXTRA, null);
        foreach (self::SOURCES as [$class, $const, $index]) {
            foreach ((array)(new \ReflectionClassConstant($class, $const))->getValue() as $v) {
                $label = $index === null ? $v : ($v[$index] ?? null);
                if (is_string($label) && $label !== '') {
                    $out[$label] = null;
                }
            }
        }
        return $out + self::dateWords();
    }

    /** Words looked up by a computed key (date parts, file size units), keyed as the pack stores them. */
    private static function dateWords(): array
    {
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $d = new \DateTimeImmutable('2024-01-01 +' . $i . ' days');
            $out['short weekday::' . $d->format('D')] = null;
            $out[$d->format('l')] = null;
        }
        for ($i = 1; $i <= 12; $i++) {
            $d = new \DateTimeImmutable(sprintf('2024-%02d-01', $i));
            $out['short month::' . $d->format('M')] = null;
            $out[$d->format('F')] = null;
        }
        foreach (['am', 'pm', 'AM', 'PM'] as $w) {
            $out['time::' . $w] = null;
        }
        foreach (['st', 'nd', 'rd', 'th'] as $w) {
            $out['ordinal suffix::' . $w] = null;
        }
        foreach (['B', 'KB', 'MB', 'GB'] as $w) {
            $out['file size::' . $w] = null;
        }
        return $out;
    }
}
