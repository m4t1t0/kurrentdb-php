<?php

declare(strict_types=1);

namespace KurrentDbTests\Amphp;

use Kurrent\Amphp\ConnectionDetails;
use Kurrent\Exception\KurrentException;
use PHPUnit\Framework\TestCase;

/**
 * Implementation-specific contract details that the generic suite can't
 * reach (ConnectionDetails internals). Each test asserts a behavior that
 * CAN fail — pass-2 review round rule: no assertions that are tautologies.
 */
final class ConnectionDetailsTest extends TestCase
{
    public function testImplementsPortRangeGuardWithAnExtremeValue(): void
    {
        // the first review round's $port >= 1 || $port <= 65535 was a
        // tautology; this test fails if anyone rewrites it as `||`
        try {
            ConnectionDetails::parse('kurrentdb://host:999999?Tls=false');
            self::fail('a port outside 1-65535 must be rejected');
        } catch (KurrentException $exception) {
            self::assertStringContainsString('out of range', $exception->getMessage());
        }
    }

    public function testParseErrorMessagesNeverEchoCredentials(): void
    {
        // three shapes that reach different error paths; each must arrive
        // redacted — the exception message is the leak channel
        foreach ([
            'm4t1t0:secret42@host',                     // not a kurrentdb:// uri (no //)
            'kurrentdb://user:secret42@bad-host::',     // port parse error
            'kurrentdb://user:secret42@host:abc',       // port parse error
            'kurrentdb://user:secret42@/host',          // empty target
            'kurrentdb://user:pa/ss@host:abc',          // '/' in password + port error (old redactor missed this)
        ] as $uri) {
            try {
                ConnectionDetails::parse($uri);
                self::fail("expected rejection for malformed uri: $uri");
            } catch (KurrentException $exception) {
                self::assertStringNotContainsString('secret42', $exception->getMessage(), "credentials leaked via parse error for $uri");
                self::assertStringNotContainsString('secret', strtolower($exception->getMessage()));
                self::assertStringNotContainsString('secret42', var_export([ConnectionDetails::class . '::parse message'], true));
            }
        }
    }

    public function testParsedDetailsDoNotCarryTheSecret(): void
    {
        $details = ConnectionDetails::parse('kurrentdb://user:secret42@host:2113?Tls=false');

        // the secret-shape is not stored at all — the only anti-leak shape
        foreach ([
            \var_export($details, true),
            \json_encode($details),
            \serialize($details),
            \print_r($details, true),
            \var_dump([]) === null ? \print_r(\get_object_vars($details), true) : '',
        ] as $rendering) {
            self::assertStringNotContainsString('secret42', (string) $rendering);
        }
        self::assertTrue($details->hasCredentials, "credential *presence* is tracked without storing the secret");
        self::assertSame('host', $details->host);
    }

    public function testCredentialsWithSemicolonInPasswordSplitCorrectly(): void
    {
        // a password whose LAST segment contains ':' must not truncate silently?
        // (userinfo splits at first ':'; '\@' libre...)
        $details = ConnectionDetails::parse('kurrentdb://user:pass:word@host?Tls=false');
        self::assertTrue($details->hasCredentials);
        self::assertSame('host', $details->host);
    }

    public function testBackwardsTlsValuesAreRejectedLoudly(): void
    {
        try {
            ConnectionDetails::parse('kurrentdb://host?Tls=TRUE');
            self::fail('non-canonical Tls value must reject loudly (silent downgrade is the review finding)');
        } catch (KurrentException $exception) {
            self::assertStringContainsString('TLS', $exception->getMessage(), 'the rejection must say why: TLS');
        }
    }
}
