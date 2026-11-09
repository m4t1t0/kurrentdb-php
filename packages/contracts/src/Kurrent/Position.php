<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * Position in `$all` — the ordered pair the server reports for every write.
 *
 * Only the commit position is wire-usable as a read pointer; the pair exists
 * because both halves appear on envelopes and checkpoint payloads.
 */
final class Position
{
    public function __construct(
        public readonly int $commitPosition,
        public readonly int $preparePosition,
    ) {}
}
