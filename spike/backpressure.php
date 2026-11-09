<?php

declare(strict_types=1);

/**
 * Spike: backpressure / flow-control on a long-lived server-streaming read.
 * Plan: append ~200 JSON events quickly, then read $all from start with a
 * large count while the consumer dwells between chunks. Assertions:
 *   - consumption makes continuous progress (no stall),
 *   - the connection survives to normal end-of-stream,
 *   - grpc-status=0 arrives (via trailers).
 * Run:  docker compose exec php php backpressure.php
 */

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Http\Client\Interceptor\RemoveResponseHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;

require __DIR__ . '/vendor/autoload.php';

foreach (['lib/protobuf.php', 'lib/messages.php'] as $lib) {
    require_once $lib;
}

final class ContentStream implements \IteratorAggregate, Amp\ByteStream\ReadableStream
{
    private array $chunks;
    private int $chunkIndex = -1;
    private bool $closed = false;

    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function read(?Amp\Cancellation $cancellation = null): ?string
    {
        $this->chunkIndex++;

        return $this->chunks[$this->chunkIndex] ?? null;
    }

    public function getIterator(): Iterator
    {
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $onClose();
    }
}

function spike_client(): \Amp\Http\Client\HttpClient
{
    static $client = null;
    if ($client === null) {
        $client = (new HttpClientBuilder())
            ->intercept(new RemoveRequestHeader('accept'))
            ->intercept(new RemoveRequestHeader('accept-encoding'))
            ->build();
    }

    return $client;
}

function grpc_request(string $uri, array $frames, int $timeoutSeconds = 120): Request
{
    $request = new Request($uri, 'POST');
    $request->setProtocolVersions(['2']);
    $request->setHeader('content-type', 'application/grpc');
    $request->setHeader('te', 'trailers');
    $request->setHeader('grpc-timeout', $timeoutSeconds . 'S');
    $request->setHeader('user-agent', 'kurrentdb-php-spike/0.0(backpressure)');
    $request->setHeader('requires-leader', 'true'); // harmless on reads, mirrors Go
    $request->setBody(StreamedContent::fromStream(new ContentStream($frames)));
    $request->setTransferTimeout($timeoutSeconds);
    $request->setInactivityTimeout($timeoutSeconds);
    $request->setBodySizeLimit(0); // unlimited — the whole server stream may exceed 10 MiB

    return $request;
}

function grpc_status_of(object $response): array
{
    $trailers = $response->getTrailers()->await();
    $status = $trailers->getHeader('grpc-status') ?? $response->getHeader('grpc-status');

    return [$status ?? '?', $trailers->getHeader('grpc-message') ?? $response->getHeader('grpc-message')];
}

function print_green(string $text): void
{
    echo "\033[32m$text\033[0m\n";
}

$failure = null;
$main = \Amp\async(function () use (&$failure): void {
    try {
        $client = spike_client();
        $base = getenv('KURRENTDB_SPIKE_BASE') ?: 'http://kurrentdb:2113';

        echo "Waiting for KurrentDB …\n";
        for ($attempt = 0; ; $attempt++) {
            try {
                $health = $client->request(new Request("$base/health"));
                $health->getBody()->buffer();
                break;
            } catch (Throwable $error) {
                if ($attempt >= 30) {
                    throw new RuntimeException('KurrentDB did not become healthy: ' . $error->getMessage(), previous: $error);
                }
                \Amp\delay(1);
            }
        }
        echo "KurrentDB is up.\n\n";

        // ---- seed a stream with enough bytes to matter (200 × ~450 B ≈ 90 KiB)
        $stream = 'backpressure-' . bin2hex(random_bytes(4));
        $count = 200;

        // option frames + events; each Append RPC = options + a batch of events
        $eventFrames = [];
        for ($i = 0; $i < $count; $i++) {
            $eventFrames[] = grpc_frame(append_req_message(
                uuid_pair()[0],
                json_encode(['i' => $i, 'blob' => bin2hex(random_bytes(200))]),
                ['type' => 'backpressure-seed', 'content-type' => 'application/json'],
            ));
        }
        $optionsFrame = grpc_frame(append_req_options($stream, 'no_stream'));
        foreach (array_chunk($eventFrames, 50) as $index => $batch) {
            $mode = $index === 0 ? 'no_stream' : ['revision', 0]; // exact revision keeps batches in sync
            $response = $client->request(grpc_request("$base/event_store.client.streams.Streams/Append", [
                grpc_frame(append_req_options($stream, $mode)),
                ...$batch,
            ]));
            [$status, $message] = grpc_status_of($response);
            if ($status !== '0') {
                throw new RuntimeException("seed append batch $index failed: $status $message");
            }
        }
        printf("Seeded %d events into %s\n\n", $count, $stream);

        // ---- slow consumer on a server-streaming $all read
        echo "=== Backpressure — slow consumer on server-streamed Read(\$all) ===\n";
        $started = hrtime(true);
        $readResponse = $client->request(grpc_request(
            "$base/event_store.client.streams.Streams/Read",
            [grpc_frame(read_all_req('start', 100000))],
            300,
        ));

        $bytesSeen = 0;
        $chunksSeen = 0;
        $stallLog = [];
        $previousAt = hrtime(true);
        while (null !== $chunk = $readResponse->getBody()->read()) {
            $bytesSeen += strlen($chunk);
            $chunksSeen++;
            $dwellMs = 80; // deliberate slow consumer
            \Amp\delay($dwellMs / 1000);
            $gapMs = (hrtime(true) - $previousAt) / 1e6;
            $previousAt = hrtime(true);
            if ($gapMs > 3000 && $bytesSeen > 64) { // progress gaps that aren't our dwell
                $stallLog[] = sprintf('chunk %d, gap %.1f ms between reads', $chunksSeen, $gapMs);
            }
        }
        $finishedAt = hrtime(true);

        [$status, $message] = grpc_status_of($readResponse);
        $seconds = ($finishedAt - $started) / 1e9;
        printf(
            "Read   → %d chunks, %d bytes in %.1f s (%.1f KiB/s incl. dwell), grpc-status=%s %s\n",
            $chunksSeen,
            $bytesSeen,
            $seconds,
            $bytesSeen / 1024 / $seconds,
            $status,
            $message ?? '',
        );

        if ($status !== '0') {
            throw new RuntimeException("expected clean end: got $status $message");
        }
        if ($stallLog !== []) {
            throw new RuntimeException('unexpected stalls: ' . json_encode($stallLog));
        }
        print_green('✅ Slow consumer: no stalls, normal stream end, trailers delivered');
    } catch (\Throwable $error) {
        $failure = $error;
    }
});

$suspension = \Revolt\EventLoop::getSuspension();
$main->finally(static fn () => $suspension->resume());
$suspension->suspend();

if ($failure) {
    throw $failure;
}
