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

    public static function parse(string $uri): self
    {
        if (!\str_starts_with($uri, 'kurrentdb://')) {
            throw new KurrentException(\sprintf('Only kurrentdb:// uris are supported in v0.1, got "%s"', $uri));
        }
        $rest = \substr($uri, \strlen('kurrentdb://'));
        $queryPos = \strpos($rest, '?');
        $targetPart = $queryPos === false ? $rest : \substr($rest, 0, $queryPos);
        $queryStr = $queryPos === false ? '' : \substr($rest, $queryPos + 1);

        \parse_str($queryStr, $query);
        $hostParts = \explode('@', $targetPart);
        $credentialPart = \count($hostParts) === 2 && $hostParts[0] !== '' ? $hostParts[0] : null;
        $targets = \explode(',', \end($hostParts));
        \count($targets) === 1 || throw new KurrentException('v0.1 supports exactly one target (no cluster discovery)');

        $target = $targets[0];
        if (\str_starts_with($target, '[')) { // IPv6 literal: [::1]:2113
            $hostEnd = \strpos($target, ']') ?: throw new KurrentException("malformed IPv6 target \"$target\"");
            $host = \substr($target, 1, $hostEnd - 1);
            $socketStr = \substr($target, $hostEnd + 1);
            $port = \str_starts_with($socketStr, ':') ? (int) \substr($socketStr, 1) : 2113;
        } elseif (\str_contains($target, ':')) {
            [$host, $portStr] = \explode(':', $target, 2);
            $port = (int) $portStr;
        } else {
            $host = $target;
            $port = 2113;
        }

        $tls = true;
        foreach ($query as $key => $value) {
            if (\strcasecmp((string) $key, 'Tls') === 0) {
                $tls = \in_array((string) $value, ['true', '1'], true);
            }
        }
        $tls && throw new KurrentException('TLS is v0.2 work; use ?Tls=false in v0.1 (insecure testing)');

        $credentials = $credentialPart === null
            ? [null, null]
            : [...\explode(':', $credentialPart, 2), null];

        return new self(
            host: $host,
            port: $port,
            tls: false, // enforced above; the field keeps the shape TLS work will fill
            user: $credentials[0] ?? null,
            password: $credentials[1] ?? null,
        );
    }
}
