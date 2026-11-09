<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * The frozen, implementation-agnostic contract for a KurrentDB client.
 *
 * Vocabulary follows KurrentDB itself (expected state, stream revisions as
 * plain ints, `$all` positions as a commit/prepare pair, opaque byte payloads,
 * lazy reads). Implementations exist per transport; the amphp implementation
 * may ship a subset of the surface and throw
 * {@see Exception\UnsupportedOperationException} for the rest (documented in
 * the release notes).
 *
 * Nothing here covers subscriptions or stream-metadata management: those live
 * on separate capability interfaces added after this freeze.
 */
interface KurrentClientInterface
{
    /**
     * Append events atomically (all-or-none).
     *
     * @param string $stream stream name
     * @param iterable<EventData> $events events in append order (same call retries
     *        are idempotent: event ids are client-assigned and deduplicated by the server)
     * @param int|ExpectedStreamState $expected optimistic-concurrency precondition
     * @param CallOptions|null $options per-call knobs (timeout, headers)
     * @return int the `$all` **commit position** of the last appended event —
     *         poll downstream processors until they reach it
     * @throws Exception\WrongExpectedVersionException mismatch of expected state
     * @throws Exception\KurrentException on any other failure
     */
    public function append(string $stream, iterable $events, int|ExpectedStreamState $expected, ?CallOptions $options = null): int;

    /**
     * Eager read of one stream (materialized; aggregate-reload idiom).
     *
     * @param string $stream stream name
     * @param ReadOptions|null $options `ReadOptions::stream($revision, ...)`:
     *        null revision = start of stream; `limit = null` = unbounded
     * @return list<ResolvedEvent> in read direction order
     * @throws Exception\KurrentException
     */
    public function readAll(string $stream, ?ReadOptions $options = null): array;

    /**
     * Lazy (server-streaming) read of one stream.
     *
     * The generator yields {@see ResolvedEvent}s one at a time as they arrive
     * and can be cancelled mid-iteration (destroy it or return early). After
     * complete draining, iteration ends normally and grpc-status has been
     * verified.
     *
     * @param ReadOptions|null $options `ReadOptions::stream($revision, ...)`:
     *        null revision = start of stream; `limit = null` = unbounded
     * @return \Generator<int, ResolvedEvent, void, null>
     * @throws Exception\KurrentException
     */
    public function reader(string $stream, ?ReadOptions $options = null): \Generator;

    /**
     * Lazy (server-streaming) read of `$all`, optionally filtered.
     *
     * @param ReadOptions|null $options `ReadOptions::all($position, ...)`:
     *        null position = start of the log
     * @return \Generator<int, ResolvedEvent, void, null>
     */
    public function readAllEvents(?ReadOptions $options = null): \Generator;

    /**
     * Delete a stream: sets its truncate-before so reads report "not found"
     * (the stream continues at the same revision when written again).
     *
     * @throws Exception\WrongExpectedVersionException
     * @throws Exception\StreamDeletedException tombstoned stream
     */
    public function deleteStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void;

    /**
     * Tombstone a stream permanently: writes a `$streamDeleted` event; the
     * stream cannot be recreated or written again.
     *
     * @throws Exception\WrongExpectedVersionException
     * @throws Exception\StreamDeletedException already tombstoned
     */
    public function tombstoneStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void;

    /**
     * The optimistic-concurrency precondition of the stream's next append:
     * the zero-based revision of its last event, or
     * {@see ExpectedStreamState::NoStream} when the stream does not exist.
     *
     * @return int|ExpectedStreamState
     */
    public function currentVersion(string $stream, ?CallOptions $options = null): int|ExpectedStreamState;

    /**
     * The current `$all` commit position (checkpoint bootstrap for
     * subscriptions).
     */
    public function globalCommitPosition(?CallOptions $options = null): int;
}
