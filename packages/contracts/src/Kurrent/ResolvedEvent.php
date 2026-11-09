<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * The read-side event envelope.
 *
 * Flat, schema-free, and carrying the full address of the event: its stream
 * and revision, its `$all` position, when it happened, and — when reading
 * with `resolveLinks` — the resolved `$>` link alongside the target event's
 * payload. When links are *not* resolved, the raw `$>` event arrives as its
 * own envelope.
 */
final class ResolvedEvent
{
    /**
     * @param string $eventId RFC 4122 UUID (string form) of the payload event
     *        (with resolved links: the **target** event's id — the `$>` link's
     *        own id sits on `$link`)
     * @param string $eventType event type; `$`-prefixed names are system/events
     * @param string $data opaque payload bytes
     * @param string|null $metadata opaque metadata bytes (null = none)
     * @param string $streamName stream the payload event was written to
     * @param int $revision zero-based, gapless position within that stream
     * @param Position $position `$all` commit/prepare pair of the write
     * @param \DateTimeImmutable|null $recordedAt when the server recorded it
     * @param self|null $link the `$>` link event when this envelope resolves
     *        one (its {@see $eventId} is the ack target — never the link's "")
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $data,
        public readonly null|string $metadata,
        public readonly string $streamName,
        public readonly int $revision,
        public readonly Position $position,
        public readonly null|\DateTimeImmutable $recordedAt = null,
        public readonly null|self $link = null,
    ) {}

    /** True when this envelope carries a `$>` link resolution. */
    public function isResolvedEvent(): bool
    {
        return $this->link !== null;
    }

    /** True for system events (`$`-prefixed type or `$`-prefixed stream). */
    public function isSystemEvent(): bool
    {
        return \str_starts_with($this->eventType, '$') || \str_starts_with($this->streamName, '$');
    }
}
