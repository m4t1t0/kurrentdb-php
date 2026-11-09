<?php

declare(strict_types=1);

namespace Kurrent;

/**
 * A server-side `$all` read/subscription filter — one axis, one direction.
 *
 * The wire filter is a gRPC oneof (stream-name XOR event-type); carrying both
 * kinds in one VO with an explicit direction beats the reference client's two
 * booleans + two sequences because it makes the invalid combinations
 * unrepresentable.
 *
 * Strings are regexes for the reference-compatible shapes (exclude lists) and
 * prefixes for include lists — implementations validate against the protocol
 * (one of regex/oneof member per filter kind), see the mapping note §3.
 *
 * **No default filter exists in this contract**: reads stay explicit and
 * `$`-stream/system events pass through unless filtered by the caller.
 */
final class ReadFilter
{
    /**
     * @param ReadFilterKind $kind filter axis
     * @param list<string> $strings match expression(s) per kind
     * @param bool $include true = only matching events; false = exclude matches
     */
    public function __construct(
        public readonly ReadFilterKind $kind,
        public readonly array $strings,
        public readonly bool $include = false,
    ) {}
}
