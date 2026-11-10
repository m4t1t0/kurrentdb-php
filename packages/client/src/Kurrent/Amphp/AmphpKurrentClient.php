<?php

declare(strict_types=1);

namespace Kurrent\Amphp;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;
use Amp\Http\Client\TimeoutException;
use Kurrent\CallOptions;
use Kurrent\EventData;
use Kurrent\ExpectedStreamState;
use Kurrent\Exception\AccessDeniedException;
use Kurrent\Exception\DeadlineExceededException;
use Kurrent\Exception\KurrentException;
use Kurrent\Exception\ServiceUnavailableException;
use Kurrent\Exception\UnsupportedOperationException;
use Kurrent\KurrentClientInterface;
use Kurrent\Position;
use Kurrent\ReadFilter;
use Kurrent\ReadFilterKind;
use Kurrent\ReadOptions;
use Kurrent\ResolvedEvent;

/**
 * The pure-PHP amphp implementation of {@see KurrentClientInterface}.
 *
 * v0.1 implemented surface: `append`, `readAll`, `reader`, `readAllEvents`,
 * `currentVersion`. `deleteStream`, `tombstoneStream`, and
 * `globalCommitPosition` throw {@see UnsupportedOperationException} until
 * v0.2 (the interface is frozen per ADR-0005; the implementation fills in).
 *
 * gRPC over cleartext HTTP/2 (prior knowledge) via amphp/http-client 5.x —
 * semantics validated in the spike: no content-length on client-streaming
 * calls, one DATA chunk per frame + half-close, trailers carry the final
 * grpc-status, `requires-leader: true` on appends.
 */
final class AmphpKurrentClient implements KurrentClientInterface
{
    private ?HttpClient $client = null;
    private bool $serverReady = false;

    private ConnectionDetails $connection;

    public function __construct(string $uri)
    {
        $this->connection = ConnectionDetails::parse($uri);
    }

    public function append(string $stream, iterable $events, int|ExpectedStreamState $expected, ?CallOptions $options = null): int
    {
        $frames = [Protobuf::grpcFrame(StreamsProtocol::appendReqOptions($stream, self::appendMode($expected)))];
        $eventCount = 0;
        foreach ($events as $event) {
            $event instanceof EventData || throw new \InvalidArgumentException('append expects EventData items');
            $frames[] = Protobuf::grpcFrame(StreamsProtocol::appendReqMessage(
                $event->eventId,
                $event->data,
                $event->type,
                $event->contentType,
            ));
            $eventCount++;
        }
        if ($eventCount === 0) {
            throw new \InvalidArgumentException('append requires at least one event (the wire shape has no zero-event call)');
        }

        $commitPosition = null;
        $this->sync(function () use (&$commitPosition, $stream, $frames, $options): void {
            $context = \sprintf('append to "%s"', $stream);
            $response = $this->grpc(self::path('Streams', 'Append'), $frames, $options, requiresLeader: true);
            $parsed = StreamsProtocol::parseAppendResponse($this->firstFrame($response, $context));

            if ($parsed['result'] === 'wrong_expected_version') {
                throw StreamsProtocol::wrongExpectedVersion(
                    $parsed['current'] ?? null,
                    $parsed['expected'] ?? null,
                    $stream,
                );
            }
            $this->assertCompleted($response, $context);

            $parsed['position']['commit'] ?? throw new KurrentException('AppendResp.Success carried no position');
            \is_int($commit = $parsed['position']['commit']) || throw new KurrentException('AppendResp position commit missing');
            $commitPosition = $commit;
        });

        \is_int($commitPosition) || throw new KurrentException('append finished without a commit position');

        return $commitPosition;
    }

    public function readAll(string $stream, ?ReadOptions $readOptions = null, ?CallOptions $options = null): array
    {
        $resolved = [];
        foreach ($this->reader($stream, $readOptions ?? ReadOptions::stream(), $options) as $event) {
            \assert($event instanceof ResolvedEvent);
            $resolved[] = $event;
        }

        return $resolved;
    }

    public function reader(string $stream, ?ReadOptions $readOptions = null, ?CallOptions $options = null): \Generator
    {
        $readOptions ??= ReadOptions::stream();
        $response = $this->startRead(
            fn (): string => Protobuf::grpcFrame(StreamsProtocol::readReq(
                $stream,
                self::effectiveLimit($readOptions),
                $readOptions->backwards ? 'end' : ($readOptions->streamRevision ?? 'start'),
                backwards: $readOptions->backwards,
            )),
            $options,
        );

        return $this->streamFrames($response);
    }

    public function readAllEvents(?ReadOptions $readOptions = null, ?CallOptions $options = null): \Generator
    {
        $readOptions ??= ReadOptions::all();
        $allPosition = $readOptions->allPosition;
        $response = $this->startRead(
            fn (): string => Protobuf::grpcFrame(StreamsProtocol::readAllReq(
                $allPosition === null
                    ? ($readOptions->backwards ? 'end' : 'start')
                    : [$allPosition->commitPosition, $allPosition->preparePosition],
                self::effectiveLimit($readOptions),
                $readOptions->backwards,
            )),
            $options,
        );

        return $this->streamFrames($response, $readOptions->filter);
    }

    public function deleteStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void
    {
        throw new UnsupportedOperationException('deleteStream lands in v0.2 (frozen interface, implementation fills in)');
    }

    public function tombstoneStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void
    {
        throw new UnsupportedOperationException('tombstoneStream lands in v0.2 (frozen interface, implementation fills in)');
    }

    public function currentVersion(string $stream, ?CallOptions $options = null): int|ExpectedStreamState
    {
        $result = null;
        $this->sync(function () use (&$result, $stream, $options): void {
            // backwards read of 1 from the end: the stream's last event (its
            // stream_revision is the current revision) or stream_not_found
            // (→ NoStream).
        $response = $this->grpc(self::path('Streams', 'Read'), [
            Protobuf::grpcFrame(StreamsProtocol::readReq($stream, 1, 'end', backwards: true)),
        ], $options);
            $outcome = null;
            $found = false;
            foreach (Protobuf::grpcFrames(self::bodyChunks($response)) as $frame) {
                $parsed = StreamsProtocol::parseReadResponse($frame);
                if (($parsed['type'] ?? null) === 'event') {
                    $outcome = $parsed['event']['stream_revision'] ?? 0;
                    $found = true;
                } elseif (($parsed['type'] ?? null) === 'stream_not_found') {
                    $outcome = ExpectedStreamState::NoStream;
                    $found = true;
                }
            }
            $this->assertCompleted($response, \sprintf('read "%s"', $stream));
            $found || throw new KurrentException('Read gave no outcome for stream ' . $stream);

            $result = $outcome;
        });

        $result !== null || throw new KurrentException('currentVersion finished without an outcome');

        return $result;
    }

    public function globalCommitPosition(?CallOptions $options = null): int
    {
        throw new UnsupportedOperationException('globalCommitPosition lands in v0.2');
    }

    // --- read plumbing ------------------------------------------------------

    /**
     * Frozen contract: `limit = null` means unbounded — encoded on the wire
     * as the maximum uint64 count (the same "all events" convention the Go
     * client uses for its `sys.maxsize` equivalent). Any non-positive
     * explicit value is invalid input.
     */
    private static function effectiveLimit(ReadOptions $readOptions): int
    {
        return $readOptions->limit === null
            ? 0x7fffffffffffffff
            : ($readOptions->limit > 0 ? $readOptions->limit : throw new \InvalidArgumentException('limit must be positive or null (unbounded)'));
    }

    private function startRead(\Closure $frameFactory, ?CallOptions $options): object
    {
        $result = null;
        $this->sync(function () use (&$result, $frameFactory, $options): void {
            $result = $this->grpc(self::path('Streams', 'Read'), [$frameFactory()], $options);
        });

        \is_object($result) || throw new KurrentException('read started without a response');

        return $result;
    }

    /**
     * Consume-driven Generator: each resumption drives transport reads
     * through a fresh Revolt suspension (lazy + cancellable — nothing runs
     * until iteration). Ends by verifying the trailers' grpc-status.
     */
    private function streamFrames(object $response, ?ReadFilter $filter = null): \Generator
    {
        $buffer = '';
        $done = false;
        while (!$done) {
            $chunk = null;
            $this->sync(function () use (&$chunk, &$done, $response): void {
                $chunk = $response->getBody()->read();
                $done = $chunk === null;
            });
            if ($chunk === null) {
                continue;
            }

            $buffer .= $chunk;
            while (\strlen($buffer) >= 5) {
                $len = \unpack('N', \substr($buffer, 1, 4))[1];
                if (\strlen($buffer) < 5 + $len) {
                    break; // wait for the next chunk
                }
                $payload = \substr($buffer, 5, $len);
                $buffer = \substr($buffer, 5 + $len);

                $parsed = StreamsProtocol::parseReadResponse($payload);
                $resolvedEvent = match ($parsed['type']) {
                    'event' => $this->resolvedEvent($parsed['event']),
                    'stream_not_found' => throw new KurrentException('stream not found'),
                    default => null,
                };
                if ($resolvedEvent !== null && self::passesFilter($resolvedEvent, $filter)) {
                    yield $resolvedEvent;
                }
            }
        }

        $buffer === '' || throw new KurrentException('stream ended with a truncated gRPC frame: ' . \bin2hex($buffer));
        $this->assertCompleted($response, 'read');
    }

    private static function passesFilter(ResolvedEvent $event, ?ReadFilter $filter): bool
    {
        if ($filter === null) {
            return true; // no hidden default filters — `$` events included
        }
        $matches = false;
        foreach ($filter->strings as $needle) {
            $hit = match ($filter->kind) {
                ReadFilterKind::StreamName => \str_starts_with($event->streamName, $needle),
                ReadFilterKind::EventTypePrefix => \str_starts_with($event->eventType, $needle),
            };
            if ($hit) {
                $matches = true;
                break;
            }
        }

        return $filter->include ? $matches : !$matches;
    }

    private function resolvedEvent(array $event): ResolvedEvent
    {
        $created = $event['created_ticks'] ?? null;

        return new ResolvedEvent(
            eventId: (string) $event['event_id'],
            eventType: (string) ($event['event_type'] ?? ''),
            data: (string) $event['data'],
            metadata: $event['custom_metadata'] ?? null, // raw user metadata bytes (custom_metadata field)
            streamName: (string) $event['stream_name'],
            revision: (int) ($event['stream_revision'] ?? 0), // proto3 omits zero
            position: new Position(
                (int) ($event['commit_position'] ?? 0),
                (int) ($event['prepare_position'] ?? 0),
            ),
            recordedAt: \is_string($created) && \ctype_digit($created)
                ? new \DateTimeImmutable(\sprintf('@%d', \intdiv((int) $created, 10_000_000)))
                : null,
            contentType: $event['content_type'] ?? EventData::CONTENT_TYPE_OCTET_STREAM,
            link: null, // $> resolution (resolveLinks:true) lands with v0.2's link polish
        );
    }

    // --- append / currentVersion plumbing ----------------------------------

    private static function path(string $service, string $method): string
    {
        return \sprintf('/event_store.client.streams.%s/%s', $service, $method);
    }

    private static function appendMode(int|ExpectedStreamState $expected): string|array
    {
        if ($expected instanceof ExpectedStreamState) {
            return match ($expected) {
                ExpectedStreamState::Any => 'any',
                ExpectedStreamState::NoStream => 'no_stream',
                ExpectedStreamState::StreamExists => 'stream_exists',
            };
        }

        return ['revision', $expected];
    }

    // --- transport ----------------------------------------------------------

    /**
     * Sends one gRPC request framed by $frames; returns the Response object
     * (headers only; body may still be streaming).
     */
    private function grpc(string $path, array $frames, ?CallOptions $options, bool $requiresLeader = false): object
    {
        $timeoutMs = $options?->timeoutMs ?? 30_000;
        $request = new Request(
            ($this->connection->tls ? 'https://' : 'http://') . $this->connection->host . ':' . $this->connection->port . $path,
            'POST',
        );
        $request->setProtocolVersions(['2']);
        $request->setHeader('content-type', 'application/grpc');
        $request->setHeader('te', 'trailers');
        $request->setHeader('grpc-timeout', ($timeoutMs * 1000) . 'u');
        $request->setHeader('user-agent', 'kurrentdb-amphp-client/0.1');
        if ($requiresLeader) {
            $request->setHeader('requires-leader', 'true');
        }
        $request->setBody(StreamedContent::fromStream(new ContentStream($frames)));
        $request->setTransferTimeout(\max(1, (int) \ceil($timeoutMs / 1000))); // ceil keeps sub-second deadlines alive
        $request->setInactivityTimeout(20);
        $request->setBodySizeLimit(0); // unlimited — server-streamed bodies may exceed 10 MiB

        $this->ensureServerReady();

        try {
            return $this->httpClient()->request($request);
        } catch (TimeoutException $error) {
            throw new DeadlineExceededException($error->getMessage(), previous: $error);
        } catch (\Amp\Http\Client\HttpException $error) {
            throw new ServiceUnavailableException($error->getMessage(), previous: $error);
        }
    }

    private function httpClient(): HttpClient
    {
        $this->client ??= (new HttpClientBuilder())
            ->intercept(new RemoveRequestHeader('accept'))
            ->intercept(new RemoveRequestHeader('accept-encoding'))
            ->build();

        return $this->client;
    }

    private function ensureServerReady(): void
    {
        if ($this->serverReady) {
            return;
        }
        $attempts = 0;
        while (true) {
            try {
                $response = $this->httpClient()->request(new Request(
                    ($this->connection->tls ? 'https://' : 'http://') . $this->connection->host . ':' . $this->connection->port . '/health',
                ));
                $response->getBody()->buffer();
                $this->serverReady = true;
                break;
            } catch (\Throwable $error) {
                if ($attempts++ >= 30) {
                    throw new ServiceUnavailableException('KurrentDB did not become healthy: ' . $error->getMessage(), previous: $error);
                }
                \Amp\delay(1);
            }
        }
    }

    private function bodyChunks(object $response): \Generator
    {
        $body = $response->getBody();
        while (null !== $chunk = $body->read()) {
            yield $chunk;
        }
    }

    private function firstFrame(object $response, string $context): string
    {
        $frame = null;
        foreach (Protobuf::grpcFrames(self::bodyChunks($response)) as $frame) {
            break;
        }
        if ($frame === null) {
            // throws the mapped exception when the exchange failed at all;
            // status 0 without a body is its own (rarer) transport failure
            $this->assertCompleted($response, $context);
            throw new KurrentException("$context completed without a gRPC frame");
        }

        return $frame;
    }

    /**
     * Verify a completed gRPC exchange: grpc-status 0 passes; failure throws
     * the mapped typed exception. Maps leader-endpoint trailers per the Go
     * client (NOT_FOUND + leader-endpoint-* = not-leader redirect; the
     * rich-status detail decode for tombstone/maximum-subscriptions etc. is
     * the later phase-2 addition — and *no* message string-sniffing).
     */
    private function assertCompleted(object $response, string $context): void
    {
        $trailers = $response->getTrailers()->await();
        $status = $trailers->getHeader('grpc-status') ?? (string) ($response->getHeader('grpc-status') ?? '?');
        $message = $trailers->getHeader('grpc-message') ?? $response->getHeader('grpc-message');
        if ($status === '0') {
            return;
        }

        $leaderHost = $trailers->getHeader('leader-endpoint-host');
        $leaderPort = $trailers->getHeader('leader-endpoint-port');
        if ($leaderHost !== null && $leaderPort !== null && \ctype_digit((string) $leaderPort)) {
            throw new NotLeaderException(
                (string) $leaderHost,
                (int) $leaderPort,
                \sprintf('%s (this node is no longer the leader; redirect to %s:%d)', $context, $leaderHost, $leaderPort),
            );
        }

        $typed = match ($status) {
            '4' => DeadlineExceededException::class,
            '7' => AccessDeniedException::class,
            '14' => ServiceUnavailableException::class,
            default => null,
        };

        throw new ($typed ?? KurrentException::class)(\sprintf('%s (grpc-status=%s%s)', $context, $status, $message !== null && $message !== '' ? ': ' . $message : ''));
    }

    /**
     * Runs $work on a Revolt coroutine synchronously (the spike pattern,
     * formalized): opens one suspension, resumes on completion, surfaces
     * failure. Values must be captured by reference from the closure.
     */
    private function sync(\Closure $work): void
    {
        $failure = null;
        $future = \Amp\async(function () use ($work, &$failure): void {
            try {
                $work();
            } catch (\Throwable $error) {
                $failure = $error;
            }
        });
        $suspension = \Revolt\EventLoop::getSuspension();
        $future->finally(static fn () => $suspension->resume());
        $suspension->suspend();

        if ($failure) {
            throw $failure;
        }
    }
}
