<?php

declare(strict_types=1);

namespace Kurrent\Amphp;

/**
 * A ReadableStream over pre-split body chunks: amphp sends the request body
 * without a content-length header and with one chunk per gRPC frame, which is
 * exactly how gRPC client-streaming calls must be sent (frames + half-close,
 * grpc-go behavior; verified against Kestrel in the spike).
 */
final class ContentStream implements \IteratorAggregate, \Amp\ByteStream\ReadableStream
{
    /** @var list<string> */
    private array $chunks;
    private int $chunkIndex = -1;
    private bool $closed = false;

    /** @param list<string> $chunks */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function read(?\Amp\Cancellation $cancellation = null): ?string
    {
        $this->chunkIndex++;

        return $this->chunks[$this->chunkIndex] ?? null;
    }

    public function getIterator(): \Traversable
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
