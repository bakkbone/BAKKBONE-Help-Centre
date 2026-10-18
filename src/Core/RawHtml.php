<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

final class RawHtml implements \Stringable
{
    public function __construct(public readonly string $open, public readonly ?string $close = null)
    {
    }

    public function __toString(): string
    {
        return $this->open;
    }
}
