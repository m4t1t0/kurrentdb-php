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
use Kurrent\Exception\StreamDeletedException;
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

    /** @var array{host:string, port:int, tls:bool, user:string|null, password:string|null} */
    private array $connection;

    public function __construct(string $uri)
    {
        $this->connection = self::parseConnection($uri);
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
            $response = $this->grpc(self::path('Streams', 'Append'), $frames, $options, requiresLeader: true);

            [$status, $message] = $this->grpcStatus($response);
            $parsed = StreamsProtocol::parseAppendResponse($this->firstFrame($response));

            if ($parsed['result'] === 'wrong_expected_version') {
                throw StreamsProtocol::wrongExpectedVersion(
                    $parsed['current'] ?? null,
                    $parsed['expected'] ?? null,
                    $stream,
                );
            }
            $this->assertGrpcOk($status, $message);

            $parsed['position']['commit'] ?? throw new KurrentException('AppendResp.Success carried no position');
            \is_int($commit = $parsed['position']['commit']) || throw new KurrentException('AppendResp position commit missing');
            $commitPosition = $commit;
        });

        \is_int($commitPosition) || throw new KurrentException('append finished without a commit position');

        return $commitPosition;
    }

    public function readAll(string $stream, ?ReadOptions $options = null): array
    {
        $resolved = [];
        foreach ($this->reader($stream, $options ?? ReadOptions::stream()) as $event) {
            \assert($event instanceof ResolvedEvent);
            $resolved[] = $event;
        }

        return $resolved;
    }

    public function reader(string $stream, ?ReadOptions $options = null): \Generator
    {
        $options ??= ReadOptions::stream();
        $response = $this->startRead(fn (): string => self::buildReadRequest(
            StreamsProtocol::readReq(
                $stream,
                \max(1, $options->limit ?? 8192),
                $options->backwards ? 'end' : ($options->streamRevision ?? 'start'),
            ),
        ));

        return $this->streamFrames($response);
    }

    public function readAllEvents(?ReadOptions $options = null): \Generator
    {
        $options ??= ReadOptions::all();
        $allPosition = $options->allPosition;
        $response = $this->startRead(fn (): string => self::buildReadRequest(
            StreamsProtocol::readAllReq(
                $allPosition === null ? 'start' : [$allPosition->commitPosition, $allPosition->preparePosition],
                \max(1, $options->limit ?? 100_000),
            ),
        ));

        return $this->streamFrames($response, $options->filter);
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
            $response = $this->grpc(self::path('Streams', 'Read'), [StreamsProtocol::readReq($stream, 1, 'end')], $options);
            [$status, $message] = $this->grpcStatus($response);
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
            $this->assertGrpcOk($status, $message);
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

    private static function buildReadRequest(string $inner): string
    {
        return Protobuf::grpcFrame($inner);
    }

    private function startRead(\Closure $frameFactory): object
    {
        $result = null;
        $failure = null;
        $this->sync(function () use (&$result, &$failure, $frameFactory): void {
            try {
                $result = $this->grpc(self::path('Streams', 'Read'), [$frameFactory()], null);
            } catch (\Throwable $error) {
                $failure = $error;
            }
        });
        if ($failure) {
            throw $failure;
        }

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
            $failure = null;
            $this->sync(function () use (&$chunk, &$failure, &$done, $response): void {
                try {
                    $chunk = $response->getBody()->read();
                    if ($chunk === null) {
                        $done = true;
                    }
                } catch (\Throwable $error) {
                    $failure = $error;
                }
            });
            if ($failure) {
                throw $failure;
            }
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
        [$status, $message] = $this->grpcStatus($response);
        $this->assertGrpcOk($status, $message);
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
            ($this->connection['tls'] ? 'https://' : 'http://') . $this->connection['host'] . ':' . $this->connection['port'] . $path,
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
        $request->setTransferTimeout($timeoutMs / 1000);
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
                    ($this->connection['tls'] ? 'https://' : 'http://') . $this->connection['host'] . ':' . $this->connection['port'] . '/health',
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

    private function firstFrame(object $response): string
    {
        $frame = null;
        foreach (Protobuf::grpcFrames(self::bodyChunks($response)) as $frame) {
            break;
        }
        if ($frame === null) {
            [$status, $message] = $this->grpcStatus($response);
            throw self::statusToException($status, $message, 'server returned no gRPC frame');
        }

        return $frame;
    }

    /** @return array{0:string, 1:null|string} [grpcStatus, grpcMessage] */
    private function grpcStatus(object $response): array
    {
        $trailers = $response->getTrailers()->await();

        return [
            $trailers->getHeader('grpc-status') ?? (string) ($response->getHeader('grpc-status') ?? '?'),
            $trailers->getHeader('grpc-message') ?? $response->getHeader('grpc-message'),
        ];
    }

    private function assertGrpcOk(string $status, null|string $message): void
    {
        if ($status !== '0') {
            throw self::statusToException($status, $message, 'gRPC failed');
        }
    }

    private static function statusToException(string $status, null|string $message, string $context): \Throwable
    {
        $text = $message ?? '';
        $typed = match ($status) {
            '4' => DeadlineExceededException::class,
            '7' => AccessDeniedException::class,
            '14' => ServiceUnavailableException::class,
            default => null,
        };
        $messageText = \sprintf('%s (grpc-status=%s%s)', $context, $status, $text !== '' ? ': ' . $text : '');
        if ($status !== '0' && \str_contains($text, 'stream deleted')) {
            return new StreamDeletedException($messageText);
        }

        return new ($typed ?? KurrentException::class)($messageText);
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

    /**
     * Minimal `kurrentdb://` parser (v0.1: one target, insecure mode).
     *
     * @return array{host:string, port:int, tls:bool, user:string|null, password:string|null}
     */
    private static function parseConnection(string $uri): array
    {
        if (!\str_starts_with($uri, 'kurrentdb://')) {
            throw new KurrentException(\sprintf('Only kurrentdb:// uris are supported in v0.1, got "%s"', $uri));
        }
        $rest = \substr($uri, \strlen('kurrentdb://'));
        $qPos = \strpos($rest, '?');
        $targetPart = $qPos === false ? $rest : \substr($rest, 0, $qPos);
        $queryStr = $qPos === false ? '' : \substr($rest, $qPos + 1);

        \parse_str($queryStr, $query);
        $hostParts = \explode('@', $targetPart);
        $credentials = \count($hostParts) === 2 && $hostParts[0] !== '' ? \explode(':', $hostParts[0]) : null;
        $targets = \explode(',', \end($hostParts));
        \count($targets) === 1 || throw new KurrentException('v0.1 supports exactly one target (no cluster discovery)');

        $target = $targets[0];
        if (\str_starts_with($target, '[')) { // IPv6 literal: [::1]:2113
            $hostEnd = \strpos($target, ']') ?: throw new KurrentException("malformed IPv6 target \"$target\"");
            $host = \substr($target, 1, $hostEnd - 1);
            $socketStr = \substr($target, $hostEnd + 1);
            $port = \str_starts_with($socketStr, ':') ? (int) \substr($socketStr, 1) : 2113;
        } else {
            $host = $target;
            $port = 2113;
            if (\str_contains($target, ':')) {
                [$host, $portStr] = \explode(':', $target, 2);
                $port = (int) $portStr;
            }
        }
        $tls = true;
        foreach ($query as $key => $value) {
            if (\strcasecmp((string) $key, 'Tls') === 0) {
                $tls = \in_array((string) $value, ['true', '1'], true);
            }
        }
        $tls && throw new KurrentException('TLS is v0.2 work; use ?Tls=false in v0.1 (insecure testing)');

        return [
            'host' => $host,
            'port' => (int) $port,
            'tls' => false,
            'user' => $credentials === null ? null : ($credentials[0] ?? null),
            'password' => $credentials === null ? null : ($credentials[1] ?? null),
        ];
    }
}
