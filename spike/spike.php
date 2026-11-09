<?php

declare(strict_types=1);

/**
 * Spike driver: hand-built gRPC over amphp against a real KurrentDB.
 * Executes product-spec milestone steps 2–5:
 *   2. hand-built Append RPC (client-streaming, length-prefixed frames)
 *   3. trailers check: grpc-status must arrive via $response->getTrailers()
 *   4. server-side single-stream Read (server-streaming, parsed frames)
 *   5. expected-revision round trip (no_stream → exact revision append)
 * Run inside the php container:  docker compose exec php php spike.php
 */

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;

require __DIR__ . '/vendor/autoload.php';

/**
 * A ReadableStream over pre-split body chunks: lets amphp send the request
 * body without a content-length header and with one chunk per gRPC frame,
 * mirroring how a real gRPC client streams messages (each message = one
 * DATA frame, then half-close END_STREAM).
 */
final class ContentStream implements \IteratorAggregate, Amp\ByteStream\ReadableStream
{
    /** @var non-empty-list<string> */
    private array $chunks;
    private int $chunkIndex = -1;
    private bool $closed = false;

    /** @param non-empty-list<string> $chunks */
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
        $onClose(); // one-shot stream
    }
}

$failure = null;

$main = \Amp\async(function () use (&$failure): void {
    try {
        $client = spike_client();
        $base = getenv('KURRENTDB_SPIKE_BASE') ?: 'http://kurrentdb:2113';

        // Wait for the server to accept connections (first start takes a few seconds)
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

        // A fresh stream name per run keeps the spike re-runnable without state cleanup
        $stream = 'orders-' . bin2hex(random_bytes(4));

        // ------------------------------------------------------------------ step 2
        echo "=== Step 2 — Append (client-streaming RPC)\n";
        [$eventUuidBytes, $eventUuid] = uuid_pair();
        $payload = json_encode(['orderId' => 42, 'total' => 99.99]);
        $response = $client->request(grpc_request("$base/event_store.client.streams.Streams/Append", [
            grpc_frame(append_req_options($stream, 'no_stream')),
            grpc_frame(append_req_message($eventUuidBytes, $payload, [
                'type' => 'order-placed',
                'content-type' => 'application/json',
            ])),
        ]));
        echo "HTTP {$response->getStatus()} {$response->getProtocolVersion()}\n";

        [$status, $message] = grpc_status($response);
        $resp = parse_append_response(current_or_die($response));
        printf(
            "Append → %s (current_revision=%s, commit=%s, prepare=%s)\n",
            $resp['result'],
            var_export($resp['current_revision'], true),
            var_export($resp['position']['commit'], true),
            var_export($resp['position']['prepare'], true),
        );
        assert_grpc_ok($status, $message);
        print_green('✅ Append accepted; grpc-status=0 via ' . grpc_status_source($response));

        // ------------------------------------------------------------------ step 3
        echo "\n=== Step 3 — Read (server-streaming RPC)\n";
        $readResponse = $client->request(grpc_request(
            "$base/event_store.client.streams.Streams/Read",
            [grpc_frame(read_req($stream, 10))],
        ));

        $frames = [];
        foreach (grpc_frames(body_chunks($readResponse)) as $frame) {
            $frames[] = $frame;
        }
        printf("Read → %d frame(s)\n", count($frames));
        [$readStatus, $readMessage] = grpc_status($readResponse);
        assert_grpc_ok($readStatus, $readMessage);

        $event = parse_read_response($frames[0] ?? '');
        $recorded = $event['event'] ?? null;
        if ($event['type'] !== 'event' || $recorded === null) {
            throw new RuntimeException('expected one event frame, got: ' . json_encode($event));
        }
        printf(
            "Read   → sent id=%s event id=%s revision=%s commit=%s data=%s\n",
            $eventUuid,
            $recorded['event_id'],
            $recorded['stream_revision'],
            $recorded['commit_position'],
            $recorded['data'],
        );
        if ($recorded['event_id'] !== $eventUuid
            || $recorded['data'] !== $payload
            || ($recorded['stream_revision'] ?? 0) !== 0) {
            throw new RuntimeException('read-back mismatch: id=' . var_export($recorded['event_id'], true)
                . ' revision=' . var_export($recorded['stream_revision'], true)
                . ' data=' . var_export($recorded['data'], true));
        }
        print_green('✅ Server-streamed read round-trips id + payload');

        // ------------------------------------------------------------------ step 4
        echo "\n=== Step 4 — wrong expected revision surfaces in the body, not the status\n";
        $conflictResponse = $client->request(grpc_request("$base/event_store.client.streams.Streams/Append", [
            grpc_frame(append_req_options($stream, 'no_stream')),
            grpc_frame(append_req_message(uuid_pair()[0], '{"run":2}', ['type' => 'second', 'content-type' => 'application/json'])),
        ]));
        [$conflictStatus, $conflictMessage] = grpc_status($conflictResponse);
        $conflict = parse_append_response(current_or_die($conflictResponse));
        printf(
            "Append → %s (current=%s, expected=%s), grpc-status=%s\n",
            $conflict['result'],
            var_export($conflict['current'], true),
            var_export($conflict['expected'], true),
            $conflictStatus,
        );
        if ($conflictStatus !== '0' || $conflict['result'] !== 'wrong_expected_version') {
            throw new RuntimeException('expected gRPC OK + wrong_expected_version in body');
        }
        print_green('✅ WrongExpectedVersion is a body outcome (future typed exception), grpc-status stays 0');

        // ------------------------------------------------------------------ step 5
        echo "\n=== Step 5 — optimistic write with exact revision\n";
        $thirdResponse = $client->request(grpc_request("$base/event_store.client.streams.Streams/Append", [
            grpc_frame(append_req_options($stream, ['revision', 0])),
            grpc_frame(append_req_message(uuid_pair()[0], '{"run":3}', ['type' => 'third', 'content-type' => 'application/json'])),
        ]));
        [$thirdStatus, $thirdMessage] = grpc_status($thirdResponse);
        $third = parse_append_response(current_or_die($thirdResponse));
        assert_grpc_ok($thirdStatus, $thirdMessage);
        if ($third['result'] !== 'success' || $third['current_revision'] !== 1) {
            throw new RuntimeException('expected success with current_revision=1, got ' . json_encode($third));
        }
        print_green('✅ Exact-revision expectation works (revision 0 → accepted, now at 1)');

        echo "\nAll checks passed. The pure-PHP route is validated for single-stream append + read.\n";
        echo "Follow-up: Docker slow-consumer test for flow-control/backpressure (not yet written).\n";
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

// --- helpers -----------------------------------------------------------

function grpc_request(string $uri, array $frames): Request
{
    $request = new Request($uri, 'POST');
    $request->setProtocolVersions(['2']); // cleartext HTTP/2 prior knowledge (h2c) — gRPC requirement
    $request->setHeader('content-type', 'application/grpc');
    $request->setHeader('te', 'trailers');
    $request->setHeader('grpc-timeout', '30S');
    $request->setHeader('user-agent', 'kurrentdb-php-spike/0.0');
    $request->setHeader('requires-leader', 'true'); // the official clients always send this on Append
    // no content-length: StreamedContent + half-close (grpc-go behavior for
    // client-streaming calls), one chunk per gRPC frame
    $request->setBody(StreamedContent::fromStream(new ContentStream($frames)));
    $request->setTransferTimeout(60);
    $request->setInactivityTimeout(20);

    return $request;
}

function spike_client(): \Amp\Http\Client\HttpClient
{
    static $client = null;
    if ($client === null) {
        $client = (new HttpClientBuilder())
            ->intercept(new \Amp\Http\Client\Interceptor\RemoveRequestHeader('accept'))
            ->intercept(new \Amp\Http\Client\Interceptor\RemoveRequestHeader('accept-encoding'))
            ->build();
    }

    return $client;
}

function body_chunks(object $response): \Generator
{
    $body = $response->getBody();
    while (null !== $chunk = $body->read()) {
        yield $chunk;
    }
}

/** Read the (single) response frame of a unary response. */
function current_or_die(object $response): string
{
    $frame = null;
    foreach (grpc_frames(body_chunks($response)) as $frame) {
        break; // first frame only
    }

    if ($frame === null) {
        $trailers = $response->getTrailers()->await();
        throw new RuntimeException(
            "no gRPC frame in response:\n"
            . '  status: ' . $response->getStatus() . "\n"
            . '  headers: ' . json_encode($response->getHeaders()) . "\n"
            . '  trailers: ' . json_encode($trailers->getHeaders()) . "\n"
        );
    }

    return $frame;
}

/** grpc-status from trailers (the gRPC convention), falling back to response headers. */
function grpc_status(object $response): array
{
    $trailers = $response->getTrailers()->await();
    $status = $trailers->getHeader('grpc-status') ?? $response->getHeader('grpc-status');
    $message = $trailers->getHeader('grpc-message') ?? $response->getHeader('grpc-message');

    return [$status ?? '?', $message ?? null];
}

function grpc_status_source(object $response): string
{
    $trailers = $response->getTrailers()->await();

    return $trailers->hasHeader('grpc-status') ? 'trailers' : 'response headers';
}

function assert_grpc_ok(string $status, ?string $message): void
{
    if ($status !== '0') {
        throw new RuntimeException("gRPC failed with status=$status message=" . ($message ?? '(none)'));
    }
}

function print_green(string $text): void
{
    echo "\033[32m$text\033[0m\n";
}
