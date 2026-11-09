<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * Per-call knobs, carried as the nullable final parameter of every frozen
 * interface method.
 *
 * Transport today: `timeoutMs` (the `grpc-timeout` deadline). Authentication
 * is deliberately **not** here — it is connection-level in the amphp stack
 * (no per-call gRPC credentials exist there). Future per-call metadata
 * headers will be added here as new readonly fields.
 */
final class CallOptions
{
    /**
     * @param int|null $timeoutMs per-call deadline in milliseconds
     *        (null = the caller-level/connection default is used)
     */
    public function __construct(
        public readonly null|int $timeoutMs = null,
    ) {}

    public static function timeout(int $milliseconds): self
    {
        return new self($milliseconds);
    }
}
