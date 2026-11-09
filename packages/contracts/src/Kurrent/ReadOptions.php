<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * One {@see KurrentClientInterface::reader()} / {@see KurrentClientInterface::readAll()}
 * query: where to read from, direction, limits, link resolution, filter.
 *
 * Construct over the position axis in use:
 *  - `ReadOptions::stream($revision, ...)`: single stream (null = start)
 *  - `ReadOptions::all($position, ...)`: `$all` (null = start of log)
 *
 * There is no implicit filter and no `sys.maxsize` sentinel: `limit = null`
 * means unbounded.
 */
final class ReadOptions
{
    private function __construct(
        public readonly null|int $streamRevision,
        public readonly null|Position $allPosition,
        public readonly bool $backwards = false,
        public readonly bool $resolveLinks = false,
        public readonly null|int $limit = null,
        public readonly null|ReadFilter $filter = null,
    ) {}

    /** Read a single stream from an explicit revision, or from its start. */
    public static function stream(
        null|int $revision = null,
        bool $backwards = false,
        bool $resolveLinks = false,
        null|int $limit = null,
        null|ReadFilter $filter = null,
    ): self {
        return new self($revision, null, $backwards, $resolveLinks, $limit, $filter);
    }

    /** Read `$all` from an explicit position, or from the start of the log. */
    public static function all(
        null|Position $position = null,
        bool $backwards = false,
        bool $resolveLinks = false,
        null|int $limit = null,
        null|ReadFilter $filter = null,
    ): self {
        return new self(null, $position, $backwards, $resolveLinks, $limit, $filter);
    }
}
