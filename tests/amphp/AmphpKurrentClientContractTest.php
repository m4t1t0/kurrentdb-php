<?php

declare(strict_types=1);

namespace KurrentDbTests\Amphp;

use Kurrent\KurrentClientInterface;
use KurrentDbTests\Contracts\KurrentClientContractTest;

/**
 * The amphp implementation on the same contract suite as the acceptance
 * harness: docker-compose with a real KurrentDB (insecure, single node).
 *
 * Connection string comes from KURRENTDB_URI (default tcp through the test
 * compose network, insecure mode).
 */
final class AmphpKurrentClientContractTest extends KurrentClientContractTest
{
    protected function createClient(): KurrentClientInterface
    {
        return new \Kurrent\Amphp\AmphpKurrentClient(
            (string) \getenv('KURRENTDB_URI') ?: 'kurrentdb://kurrentdb:2113?Tls=false',
        );
    }
}
