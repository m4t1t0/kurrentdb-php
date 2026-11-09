<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * Optimistic-concurrency precondition of an append or stream deletion.
 *
 * Mirrors the `expected_stream_revision` oneof of the gRPC protocol — the
 * three symbolic cases carry no backing ints (`-1/-2/-4` are server-side
 * echo values for conflict details, never written by clients).
 */
enum ExpectedStreamState
{
    /** Ignore the current state; always accept. */
    case Any;

    /** Accept only while the stream has no events. */
    case NoStream;

    /** Accept only while the stream has at least one event. */
    case StreamExists;
}
