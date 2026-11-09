<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * A write-side event: schema-free opaque bytes.
 *
 * Payload and metadata are never interpreted by the client; the JSON shape is
 * a convenience via {@see EventData::json()}.
 *
 * The event id is **client-assigned** (RFC 4122 UUID, string form). Generating
 * it once and reusing it verbatim on retries keeps appends idempotent — the
 * server deduplicates by id. Never regenerate ids per retry.
 */
final class EventData
{
    /**
     * @param string $eventId RFC 4122 UUID (string form), generated once per event
     * @param string $type event type name (goes into the `type` metadata key)
     * @param string $data opaque payload bytes
     * @param string|null $metadata opaque metadata bytes (null = none); the
     *        wire metadata map also always carries `type` and `content-type`
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $data,
        public readonly null|string $metadata = null,
    ) {}

    /**
     * Convenience constructor for JSON-shaped payloads (content type
     * `application/json`); folds both payload and metadata through
     * `json_encode`.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata user metadata (empty = none)
     */
    public static function json(string $type, array $payload, array $metadata = [], null|string $eventId = null): self
    {
        return new self(
            $eventId ?? self::newId(),
            $type,
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
            $metadata === [] ? null : (string) json_encode($metadata, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Convenience constructor for binary payloads (content type
     * `application/octet-stream`).
     */
    public static function bytes(string $type, string $data, null|string $metadata = null, null|string $eventId = null): self
    {
        return new self($eventId ?? self::newId(), $type, $data, $metadata);
    }

    /**
     * Fresh RFC 4122 v4 UUID (string form) for client-side id assignment.
     */
    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
    }
}
