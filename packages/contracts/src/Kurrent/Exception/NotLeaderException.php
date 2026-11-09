<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/**
 * The write landed on a node that is no longer the leader; the exception
 * carries the redirect target so callers (or implementations' auto-retry
 * wrappers) can route the retry at the right host. This maps
 * `NodeIsNotLeaderErrorDetails` / `leader-endpoint-*` trailers.
 */
final class NotLeaderException extends KurrentException
{
    public function __construct(
        public readonly string $leaderHost,
        public readonly int $leaderPort,
        string $message = 'The node is no longer the leader for this operation.',
    ) {
        parent::__construct($message);
    }
}
