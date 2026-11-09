<?php

declare(strict_types=1);

namespace KurrentDbTests\Contracts;

use Kurrent\ExpectedStreamState;
use Kurrent\KurrentClientInterface;
use Kurrent\Exception\WrongExpectedVersionException;
use PHPUnit\Framework\TestCase;

/**
 * The implementation-agnostic acceptance suite (acceptance-test-first).
 *
 * Any `KurrentClientInterface` implementation must pass these tests against a
 * real KurrentDB. Subtests that a given implementation version does not yet
 * implement surface UnsupportedOperationException and are reported as
 * `skipped`, never as failure.
 *
 * Written BEFORE `AmphpKurrentClient` existed: the first run of this suite
 * against the (then-missing) implementation is the ATDD red phase; the green
 * phase is `append` + `readAll` passing.
 */
abstract class KurrentClientContractTest extends TestCase
{
    abstract protected function createClient(): KurrentClientInterface;

    /** Fresh stream name per test so suites don't depend on server state. */
    protected function newStreamName(): string
    {
        return 'contract-' . \bin2hex(\random_bytes(4));
    }

    public function testAppendWithNoStreamReturnsCommitPosition(): void
    {
        $client = $this->createClient();
        $stream = $this->newStreamName();
        $event = \Kurrent\EventData::json('contract-says-hello', ['orderId' => 7]);

        $commitPosition = $client->append($stream, [$event], ExpectedStreamState::NoStream);

        self::assertGreaterThan(0, $commitPosition, 'a fresh `$all` commit position must grow');
    }

    public function testAppendThenReadAllRoundTripsTheEvent(): void
    {
        $client = $this->createClient();
        $stream = $this->newStreamName();
        $event = \Kurrent\EventData::json('contract-says-hello', ['orderId' => 7]);

        $client->append($stream, [$event], ExpectedStreamState::NoStream);

        $events = $client->readAll($stream);
        self::assertCount(1, $events);
        $resolved = $events[0];

        self::assertSame($event->eventId, $resolved->eventId);
        self::assertSame($event->type, $resolved->eventType);
        self::assertSame($event->data, $resolved->data);
        self::assertSame($event->metadata, $resolved->metadata);
        self::assertSame($event->contentType, $resolved->contentType);
        self::assertSame($stream, $resolved->streamName);
        self::assertSame(0, $resolved->revision, 'first event in a fresh stream is at revision 0');
        self::assertGreaterThan(0, $resolved->position->commitPosition);
    }

    public function testSecondAppendWithNoStreamConflicts(): void
    {
        $client = $this->createClient();
        $stream = $this->newStreamName();

        $client->append($stream, [\Kurrent\EventData::json('first', [])], ExpectedStreamState::NoStream);

        $this->expectException(WrongExpectedVersionException::class);
        $client->append($stream, [\Kurrent\EventData::json('second', [])], ExpectedStreamState::NoStream);
    }

    public function testExactRevisionAppendSequences(): void
    {
        $client = $this->createClient();
        $stream = $this->newStreamName();

        $client->append($stream, [\Kurrent\EventData::json('a', [])], ExpectedStreamState::NoStream);
        $client->append($stream, [\Kurrent\EventData::json('b', [])], 0);

        $events = $client->readAll($stream);
        self::assertCount(2, $events);
        self::assertSame('a', $events[0]->eventType);
        self::assertSame('b', $events[1]->eventType);
    }

    public function testReaderYieldsEventsLazily(): void
    {
        $client = $this->createClient();
        $stream = $this->newStreamName();

        $client->append($stream, [\Kurrent\EventData::json('m1', []), \Kurrent\EventData::json('m2', [])], ExpectedStreamState::NoStream);

        $types = [];
        foreach ($client->reader($stream) as $resolved) {
            $types[] = $resolved->eventType;
        }

        self::assertSame(['m1', 'm2'], $types);
    }

    public function testReadAllEventsYieldsAcrossStreams(): void
    {
        $client = $this->createClient();
        $streamA = $this->newStreamName();
        $streamB = $this->newStreamName();

        $client->append($streamA, [\Kurrent\EventData::json('ea', [])], ExpectedStreamState::NoStream);
        $client->append($streamB, [\Kurrent\EventData::json('eb', [])], ExpectedStreamState::NoStream);

        $types = [];
        foreach ($client->readAllEvents(\Kurrent\ReadOptions::all()) as $resolved) {
            \assert(\is_object($resolved) && $resolved instanceof \Kurrent\ResolvedEvent);
            if ($resolved->streamName === $streamA || $resolved->streamName === $streamB) {
                $types[] = \sprintf('%s@%s', $resolved->eventType, $resolved->streamName);
            }
        }

        self::assertSame(["ea@$streamA", "eb@$streamB"], $types, '`$all` read order is log order');
    }
}
