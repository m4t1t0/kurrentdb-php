<?php

declare(strict_types=1);

namespace Kurrent\Amphp;

use Kurrent\Exception\KurrentException;

/**
 * A parsed `kurrentdb://` connection (value object, CODING_STANDARDS §4).
 *
 * Grammar: `kurrentdb://[user:pass@]host[:port][?query]` — one target, port
 * default 2113, `Tls=false` required in v0.1 (insecure; TLS is v0.2 work on
 * the same VO). Cluster discovery (comma-separated targets,
 * `+discover://`) arrives with the v0.2 gossip work.
 *
 * Trust posture (review-noted): the URI can carry credentials, so error
 * messages built here are REDACTED, and `__debugInfo()` hides the
 * credentials on every `var_dump`/`var_export` path.
 *
 * `user`/`password` are parsed now and **unused until v0.2** — auth goes on
 * the connection (an `authorization: Basic …` header at transport build),
 * not per-call: keeping the grammar-complete parse documented here avoids
 * re-fighting the grammar when auth lands.
 */
final class ConnectionDetails
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly bool $tls,
        public readonly null|string $user,
        public readonly null|string $password,
    ) {}

    /** Authority for http:// URLs (re-brackets IPv6 literals). */
    public function httpAuthority(): string
    {
        return \str_starts_with($this->host, '[') || \strpbrk($this->host, ':') !== false
            ? '[' . \trim($this->host, '[]') . ']' . ':' . $this->port
            : $this->host . ':' . $this->port;
    }

    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'tls' => $this->tls,
            'user' => $this->user,
            'password' => $this->password === null ? null : '***',
        ];
    }

    /** Strips userinfo from a URI before it can reach an error message. */
    private static function redactUri(string $uri): string
    {
        return (string) \preg_replace('#(?<=//)[^@/]*@#', '***@', $uri);
    }

    public static function parse(string $uri): self
    {
        if (!\str_starts_with($uri, 'kurrentdb://')) {
            throw new KurrentException(\sprintf('Only kurrentdb:// uris are supported in v0.1, got "%s"', self::redactUri($uri)));
        }
        $rest = \substr($uri, \strlen('kurrentdb://'));
        $queryPos = \strpos($rest, '?');
        $targetPart = $queryPos === false ? $rest : \substr($rest, 0, $queryPos);
        $queryStr = $queryPos === false ? '' : \substr($rest, $queryPos + 1);

        // userinfo is everything before the LAST '@' (a password may itself
        // contain '@' only percent-encoded, but being liberal here prevents
        // silent credential drops)
        $atPos = \strrpos($targetPart, '@');
        $credentialPart = null;
        if ($atPos !== false && $atPos > 0) {
            $credentialPart = \substr($targetPart, 0, $atPos);
            $targetPart = \substr($targetPart, $atPos + 1);
        }
        \parse_str($queryStr, $query);
        $targets = \explode(',', $targetPart);
        if ($targetPart === '' || \count($targets) !== 1) {
            throw new KurrentException(\sprintf('v0.1 supports exactly one non-empty target, got "%s" (no cluster discovery)', self::redactUri($uri)));
        }
        $target = $targets[0];

        if (\str_starts_with($target, '[')) { // IPv6 literal: [::1]:2113
            $hostEnd = \strpos($target, ']') ?: throw new KurrentException(\sprintf('malformed IPv6 target "%s"', self::redactUri($uri)));
            $host = \trim(\substr($target, 1, $hostEnd - 1), '[]');
            $socketStr = \substr($target, $hostEnd + 1);
            $port = \str_starts_with($socketStr, ':') ? self::port((string) \substr($socketStr, 1), $uri) : 2113;
        } elseif (\str_contains($target, ':')) {
            [$host, $portStr] = \explode(':', $target, 2);
            $port = self::port($portStr, $uri);
        } else {
            $host = $target;
            $port = 2113;
        }

        $tls = true;
        foreach ($query as $key => $value) {
            if (\strcasecmp((string) $key, 'Tls') === 0) {
                $tls = self::tlsFlag($value, $uri);
            }
        }
        $tls && throw new KurrentException('TLS is v0.2 work; use ?Tls=false in v0.1 (insecure testing)');
        // v0.1: TlsVerifyCert / TlsCaFile / KeepAlive* / NodePreference etc.
        // are parsed-and-ignored — their behavior is v0.2+ (documented; the
        // loud-rejection option is tracked with the TLS work)

        [$user, $password] = $credentialPart === null
            ? [null, null]
            : [...\explode(':', $credentialPart, 2), null];

        return new self(
            host: $host,
            port: $port,
            tls: false, // enforced above; the field keeps the shape TLS work will fill
            user: $user === null || $user === '' ? null : $user,
            password: $password === null || $password === '' ? null : $password,
        );
    }

    private static function port(string $raw, string $uri): int
    {
        if (!\ctype_digit($raw)) {
            throw new KurrentException(\sprintf('connection port must be numeric, got "%s" ("%s")', $raw, self::redactUri($uri)));
        }
        $port = (int) $raw;
        $port >= 1 || $port <= 65535 || throw new KurrentException(\sprintf('connection port out of range 1-65535: %d ("%s")', $port, self::redactUri($uri)));

        return $port;
    }

    /** Strict: only true/false (any case) and their numeric shorthand pass; anything else is loud. */
    private static function tlsFlag(mixed $raw, string $uri): bool
    {
        $value = \is_string($raw) ? \strtolower($raw) : '';
        \in_array($value, ['true', '1', 'false', '0'], true)
            || throw new KurrentException(\sprintf('unrecognized Tls value (use true/false); got "%s" ("%s")', \json_encode($raw), self::redactUri($uri)));

        return $value === 'true' || $value === '1';
    }
}
