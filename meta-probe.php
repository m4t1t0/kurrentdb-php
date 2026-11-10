<?php
require __DIR__ . '/vendor/autoload.php';

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Kurrent\Amphp\AmphpKurrentClient;

$failure = null;
$result = null;
$work = function () use (&$failure, &$result) {
    try {
        $client = new AmphpKurrentClient('kurrentdb://kurrentdb:2113?Tls=false');
        $stream = 'meta-probe-' . bin2hex(random_bytes(3));
        $client->append($stream, [\Kurrent\EventData::json('meta', [])], \Kurrent\ExpectedStreamState::NoStream);
        $reflection = new ReflectionClass($client);
        $prop = $reflection->getProperty('serverReady');
        $prop->setValue($client, true); // skip readiness for brevity
        foreach (['read' => true] as $k) {}

        // raw read via streamFrames is generator-internal; instead: use readAll and
        // capture the parsed metadata by reading the raw event bytes via a custom hook —
        // simplest: use the client's reader and reparse nothing; print what we can via raw call
        $client2 = new AmphpKurrentClient('kurrentdb://kurrentdb:2113?Tls=false');
        $prop2 = (new ReflectionClass($client2))->getProperty('serverReady');
        $prop2->setValue($client2, true);
        // dig into the private method result: call currentVersion path? simpler:
        // replicate the append response? Use raw grpc: build read via private methods inaccessible.
        // Instead: use streamFrames indirectly and intercept through a subclass is overkill.
        // Just run readAll with a debug hook on resolvedEvent? Not accessible.
        // → Run a second client read and print raw response body frames via the network tap clone:
        $http = HttpClientBuilder::buildDefault()
            ->intercept(new RemoveRequestHeader('accept'))
            ->intercept(new RemoveRequestHeader('accept-encoding'));
        $result = 'built';
    } catch (Throwable $e) {
        $failure = $e;
    }
};

$future = \Amp\async($work);
$suspension = \Revolt\EventLoop::getSuspension();
$future->finally(static fn () => $suspension->resume());
$suspension->suspend();

if ($failure) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "OK {$result}\n";
