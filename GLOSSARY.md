# KurrentDB PHP Client

The language of the KurrentDB PHP Client project: a pure-PHP gRPC client
for the KurrentDB event-native database.

## Language

### Server & protocol

**KurrentDB**:
The event-native database this project builds a client for; the renamed EventStoreDB (v25.0 was the first release under the new name).
_Avoid_: EventStore (ambiguous with the company), EDB

**EventStoreDB**:
The former name of KurrentDB. Use only when referring to historical versions (≤ 23.10) or identified third-party artifacts.
_Avoid_: using it for current server versions

**Kurrent**:
The company (Event Store Ltd. rebranded). Its official GitHub presence is `kurrent-io`.

**Stream**:
A named, append-only sequence of immutable events (e.g. `orders-42`).

**`$all`**:
The virtual stream containing every event in the database, in global order.
_Avoid_: global stream, all-events stream (in prose links to it, the literal `$all` is unambiguous)

**Event data**:
The opaque byte payload of an event, JSON when the content type is `application/json`. In PHP, `string`; callers may use a JSON helper.
_Avoid_: body, payload object

**Metadata**:
The structured (non-payload) attributes attached to an event.

**Revision**:
The zero-based position of an event within its stream.
_Avoid_: version (reserved for PHP class/vendor versions)

**Commit position / Prepare position**:
Positions within `$all`; used for global ordering and filters.

**Expected state**:
The optimistic-concurrency precondition of an append: `any`, `no_stream`, `stream_exists`, or an exact revision.
_Avoid_: expected version (KurrentDB's Go/Python clients treat revision and state separately), unless quoting their API

### Subscription types

**Catch-up subscription**:
A client-driven subscription that starts from a position and streams events from there to the head, then follows live events.
_Avoid_: volatile subscription

**Persistent subscription**:
A server-managed, competing-consumer group of subscribers created on the server with explicit ack/nack handling.

### Client API (decided 2026-11-07 grill session)

**ExpectedStreamState**:
The append precondition enum: `any`, `no_stream`, `stream_exists`, or an exact revision. Same three shared constant values prooph uses for the same server.
_Avoid_: expected version, expectedVersion

**Position**:
The global `$all` position value object carrying commit position and prepare position as a pair.
_Avoid_: logPosition, global position (in prose)

**EventData**:
The write-side value object: event ID, event type, opaque data bytes, optional metadata bytes. Schema-free by design; a `json()` named constructor covers the convenient case.
_Avoid_: Event, message

**ResolvedEvent**:
The read-side event envelope: identity, type, data, metadata, stream, revision, position, created timestamp, and whether it was a link resolution.

**Capability interface**:
An optional, additional interface a client may implement (subscriptions, stream management). Added after the core freeze; the frozen core never changes to accommodate one.
_Avoid_: module, extension interface

**Contract test suite**:
The implementation-agnostic acceptance suite defined against `KurrentClientInterface` (`tests/contracts/`), parameterized so every client implementation must pass the same tests unchanged.
_Avoid_: dummy project, unit tests (for integration acceptance runs)

**CallOptions**:
The readonly per-call value object (timeout today, future per-call metadata) carried as the nullable last parameter of every frozen interface method. Auth never rides on it — that is connection-level.
_Avoid_: per-call credentials, method kwargs

**KurrentException**:
The catch-all base exception for gRPC outcomes not covered by the frozen typed subset; mirrors the Python reference's `UnknownError` fallback.
_Avoid_: RuntimeException (general PHP one)

### Project artifacts (decided 2026-11-07 grill session)

**KurrentClientInterface**:
The shared async PHP interface both implementations implement; lives in the contracts package.

**Contracts package**:
Composer package `<gh-handle>/kurrentdb-contracts` — interface, value objects, typed exceptions only, no implementation.

**Client package**:
Composer package `<gh-handle>/kurrentdb-client` — the amphp implementation, depends on the contracts package.

**AmphpKurrentClient**:
Implementation A: pure-PHP gRPC client on amphp's async HTTP/2 client with protoc-generated stubs. This is where the gRPC wire protocol is implemented (the learning).

**FrankenPhpKurrentClient**:
Implementation B (deferred): a FrankenPHP Go extension wrapping the official Go client, behind the same interface — a raw-speed variant.
