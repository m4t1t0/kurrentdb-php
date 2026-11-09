<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/**
 * Optimistic-concurrency conflict: the stream's actual state at write time
 * differs from the expected one. Revision properties carry the server echo;
 * `null` means "no stream" (there was nothing to conflict with), while
 * string values appear only where servers echo symbolic shapes ("any",
 * "stream_exists").
 */
final class WrongExpectedVersionException extends KurrentException
{
    public function __construct(
        public readonly null|int $currentRevision,
        public readonly null|int|string $expectedRevision,
        string $message = 'The stream has changed since the expected version was read.',
    ) {
        parent::__construct($message);
    }
}
