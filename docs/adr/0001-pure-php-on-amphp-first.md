# Pure-PHP gRPC client on amphp first; native wrapper deferred

We build the gRPC implementation itself in pure PHP on amphp (async HTTP/2
client + protoc-generated stubs) as Implementation A, rather than wrapping the
official Go client from a PHP extension (FrankenPHP route = Implementation B,
deferred) or using the PECL `ext-grpc` C extension. The project's two goals —
learning gRPC internals and filling the ecosystem gap for any PHP 8.4+
runtime — both favor owning the wire protocol: wrapping outsources exactly what
teaches HTTP/2, trailers, and streaming, and `ext-grpc` hides the same
internals while adding a compile burden.

Considered options: wrapping the Go client (rejected as first step: teaching
value lost; kept as deferred speed variant behind `KurrentClientInterface`);
`ext-grpc` (rejected: hides internals, painful builds).

Consequences: we implement the gRPC frame conventions (5-byte prefix,
content-type, end-of-stream), `grpc-status`/`grpc-message` trailer handling,
metadata, deadlines, and keepalive ourselves. Verified 2026-11-07:
amphp/http-client 5.x surfaces HTTP/2 trailers (`Response::getTrailers()`,
regression-tested), so the route is viable; flow-control under long-lived
streams remains to be validated in the spike.

Decided 2026-11-05 (priority flip), revalidated 2026-11-07. Mirrors §4 of the
handoff; the handoff remains background, this file is the decision.
