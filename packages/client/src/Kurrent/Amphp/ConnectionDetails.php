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
 * Trust posture (review-noted, pass 2): the URI can carry credentials, so
 * error messages built here **never echo the URI or any redacted variant of
 * it** — a malformed URI is reported positionally only. Credentials are
 * parsed for *presence* and then discarded: nothing in this VO can leak
 * them through `__debugInfo`, `var_dump`, `var_export`, `json_encode`, or
 * `(array)` casts, because they are simply not stored. v0.2 auth re-reads
 * the URI at transport-build time when the secret is actually consumed,
 * stored privately, and never in a public property.
 */
final class ConnectionDetails
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly bool $tls,
        public readonly bool $hasCredentials,
    ) {}

    /** Authority for http:// URLs (re-brackets IPv6 literals). */
    public function httpAuthority(): string
    {
        return \strpbrk($this->host, ':') !== false
            ? '[' . \trim($this->host, '[]') . ']' . ':' . $this->port
            : $this->host . ':' . $this->port;
    }

    public static function parse(string $uri): self
    {
        if (!\str_starts_with($uri, 'kurrentdb://')) {
            throw new KurrentException(\sprintf('Only kurrentdb:// uris are supported in v0.1 (got a %d-character string)', \strlen($uri)));
        }
        $rest = \substr($uri, \strlen('kurrentdb://'));
        $queryPos = \strpos($rest, '?');
        $targetPart = $queryPos === false ? $rest : \substr($rest, 0, $queryPos);
        $queryStr = $queryPos === false ? '' : \substr($rest, $queryPos + 1);

        // userinfo is everything before the LAST '@' (a password may itself
        // contain '@' only percent-encoded, but being liberal here prevents
        // silent credential drops); presence is tracked, value discarded —
        // the only storage shape that cannot leak
        $atPos = \strrpos($targetPart, '@');
        $hasCredentials = false;
        if ($atPos !== false && $atPos > 0) {
            $hasCredentials = true;
            $targetPart = \substr($targetPart, $atPos + 1);
        }

        \parse_str($queryStr, $query);
        $targets = \explode(',', $targetPart);
        if ($targetPart === '' || \count($targets) !== 1) {
            throw new KurrentException(\sprintf('v0.1 supports exactly one non-empty target (supported: %d chars of target text; no cluster discovery)', \strlen($targetPart)));
        }
        $target = $targets[0];

        if (\str_starts_with($target, '[')) { // IPv6 literal: [::1]:2113
            $hostEnd = \strpos($target, ']') ?: throw new KurrentException('malformed IPv6 target (missing close bracket)');
            $host = \trim(\substr($target, 1, $hostEnd - 1), '[]');
            $socketStr = \substr($target, $hostEnd + 1);
            $port = \str_starts_with($socketStr, ':') ? self::port((string) \substr($socketStr, 1)) : 2113;
        } elseif (\str_contains($target, ':')) {
            [$host, $portStr] = \explode(':', $target, 2);
            $port = self::port($portStr);
        } else {
            $host = $target;
            $port = 2113;
        }

        $tls = true;
        foreach ($query as $key => $value) {
            if (\strcasecmp((string) $key, 'Tls') === 0) {
                $tls = self::tlsFlag($value);
            }
        }
        $tls && throw new KurrentException('TLS is v0.2 work; use ?Tls=false in v0.1 (insecure testing)');
        // v0.1: TlsVerifyCert / TlsCaFile / KeepAlive* / NodePreference etc.
        // are parsed-and-ignored — their behavior is v0.2+ (documented; the
        // loud-rejection option is tracked with the TLS work)

        return new self(
            host: $host,
            port: $port,
            tls: false, // enforced above; the field keeps the shape TLS work will fill
            hasCredentials: $hasCredentials,
        );
    }

    private static function port(string $raw): int
    {
        if (!\ctype_digit($raw)) {
            throw new KurrentException(\sprintf('connection port must be numeric, got "%s"', $raw));
        }
        $port = (int) $raw;
        $port >= 1 && $port <= 65535 || throw new KurrentException(\sprintf('connection port out of range 1-65535: %d', $port));

        return $port;
    }

    /** Strict: only true/false (any case) and their numeric shorthand pass; anything else is loud. */
    private static function tlsFlag(mixed $raw): bool
    {
        $value = \is_string($raw) ? \strtolower($raw) : '';
        \in_array($value, ['true', '1', 'false', '0'], true)
            || throw new KurrentException(\sprintf('unrecognized Tls value (use true/false)'));

        return $value === 'true' || $value === '1';
    }
}
