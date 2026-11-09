# Design Note — Python `kurrentdbclient` → PHP `KurrentClientInterface` mapping

Status: **decided** (freeze precondition (c) of Q14; the §9 divergence points
were settled 2026-11-09 — see `docs/adr/0005-frozen-core-interface.md`).
Reference: `pyeventsourcing/kurrentdbclient` **v1.3.6** (branch `1.3`; formerly
`esdbclient`, PyPI `kurrentdbclient`, BSD-3-Clause, John Bywater). Verified
2026-11-08 from source.

This note maps the reference's full API surface onto the decisions recorded in
the 2026-11-07 grill session (ADR-0004: KurrentDB-native semantics, lazy
Generators, capability interfaces, no transactions).

---

## 1. Connection

**Sources:** `connection_spec.py`, `client.py` ×299–530, `connection.py`, README §Connection strings

### 1.1 URI grammar

```
kurrentdb-uri          = "kurrentdb://" | "esdb://" | "kdb://"
                       , [ user-info "@" ] , grpc-target, { "," , grpc-target } , [ "?" , query ] ;
kurrentdb-discover-uri = "kurrentdb+discover://" ("esdb+discover://" | "kdb+discover://")
                       , [ user-info "@" ] , cluster-domainname , [ ":" , port ] , [ "?" , query ] ;
user-info              = username , ":" , password ;
grpc-target            = ( hostname | ip-address ) , ":" , port-number ;   # missing port → 2113
```

- Non-discover: ≥1 comma-separated targets. 1 target → direct; ≥2 → gossip in order.
- `+discover`: one cluster domain; DNS resolution; preferred node selected from gossip.
- `Tls=true` is the **default** (must say `Tls=false` explicitly for insecure);
  with Tls, `username:password` are required.
- Unknown query fields → error; duplicate fields: first value wins; case-insensitive.

### 1.2 Query-string options

| Field | Values / default | Meaning |
|---|---|---|
| `Tls` | true(default)/false | channel security; must match server config |
| `TlsVerifyCert` | true(default)/false | **ignored by the reference client** |
| `ConnectionName` | fresh UUIDv4 | sent as `connection-name` metadata on **every** call |
| `NodePreference` | leader(default)/follower/random/readonlyreplica | node choice from gossip |
| `DefaultDeadline` | int s, None | default timeout of **write** methods only |
| `GossipTimeout` | int s, 5 | gossip call deadline |
| `MaxDiscoverAttempts` | int, 10 | gossip retry rounds → `DiscoveryFailedError` |
| `DiscoveryInterval` | int ms, 100 | sleep between rounds |
| `KeepAliveInterval` | int ms, None | → `grpc.keepalive_time_ms` |
| `KeepAliveTimeout` | int ms, None | → `grpc.keepalive_timeout_ms` + `grpc.http2.ping_timeout_ms` |
| `TlsCaFile` / `UserCertFile` / `UserKeyFile` | abs path | PEM material; overrides ctor args |

### 1.3 Construction & lifecycle

```python
KurrentDBClient(uri: str | None = None, *,
                root_certificates: str | bytes | None = None,
                private_key: str | bytes | None = None,
                certificate_chain: str | bytes | None = None) -> None
```

- Hard-coded `grpc.max_receive_message_length = 17 MiB`; `GRPC_DNS_RESOLVER=native` forced (Python artifacts, not to copy).
- Properties: `connection_target: str`, `connection_id: UUID`, `is_closed: bool`; service accessors `streams`, `v2streams`, `persistent_subscriptions`, `gossip`, `projections`.
- `close()` stops all in-flight streaming iterators (a per-connection registry) then the channel; context-manager + best-effort `__del__`.
- Reconnect is idempotent (lock + connection-id + probe call); the reference **auto-retries once** on `NodeIsNotLeaderError` / `ServiceUnavailableError` / closed-channel errors — retry-once semantics, not endless.
- Discovery unfulfilled preference → `DiscoveryFailedError` (+ `LeaderNotFoundError` / `FollowerNotFoundError` / `ReadOnlyReplicaNotFoundError`) after `MaxDiscoverAttempts`.

### 1.4 PHP shape

```php
// factory concern, not interface concern
KurrentClient::create(string $uri, ?ConnectionOptions $options = null): self;
public function close(): void;
// ConnectionOptions mirrors the table above incl. NodePreference enum
// {Leader, Follower, Random, ReadOnlyReplica} and DefaultDeadline (writes only)
```

PHP divergences: no per-call `CallCredentials` (auth = `authorization: Basic …` header at transport build); no `GRPC_DNS_RESOLVER` knob; no `max_receive_message_length` equivalent; `connection-name` header still sent per call.

---

## 2. Appending

**Sources:** `client.py`, `streams.py` `_generate_append_reqs`, README §Synopsis

```python
append_to_stream(stream_name: str, /, *,
                 events: NewEvent | Iterable[NewEvent],
                 current_version: int | StreamState, ...) -> int     # returns COMMIT position of last event
append_events(stream_name: str, *, events: Iterable[NewEvent], current_version, ...) -> int
append_event(stream_name: str, *, event: NewEvent, current_version, ...) -> int
delete_stream(stream_name: str, *, current_version: int | StreamState, ...) -> None
tombstone_stream(stream_name: str, *, current_version: int | StreamState, ...) -> None
```

v2 path (out of frozen scope, listed for completeness): `multi_append_to_stream(events: NewEvents|Iterable[NewEvents]) -> int`; `append_records(records, checks) -> AppendRecordsResult` (server ≥ 25.1, cross-stream consistency checks).

Semantics:
- One v1 `Streams.Append` streaming-unary call: first message = Options (oneof `no_stream`/`stream_exists`/`any`/`revision`), rest = ProposedMessage (id, data, metadata map with `"type"` + `"content-type"`).
- **Atomic all-or-none per call.** Server-side **transactions do not exist** (removed ≥22.6) — consistent with our no-transaction decision.
- `current_version: int|StreamState` **is** the protobuf oneof shape — direct precedent for PHP `int|ExpectedStreamState`.
- Conflict → `WrongCurrentVersionError(stream_name, actual, expected)`.
- **Return is the COMMIT POSITION of the last appended event** (not a stream revision) — poll downstream processors until this point.
- Stream positions are zero-based and gapless.
- Event ids are **client-assigned**; server dedupes by id → idempotent retries with the same `NewEvent`s are correct by design. PHP docs must state: generate `EventData::id` once, never per retry.

PHP proposals:

```php
public function append(string $stream, iterable $events, int|ExpectedStreamState $expected, ?CallOptions $options = null): int;
public function deleteStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void;
public function tombstoneStream(string $stream, int|ExpectedStreamState $expected, ?CallOptions $options = null): void;
```

---

## 3. Reads

**Sources:** `client.py`, `streams.py ReadResponse`, README §Streams/§Event objects

```python
# EAGER — materialized tuple (aggregate-reload idiom)
get_stream(stream_name: str, *, stream_position: int | None = None, backwards: bool = False,
           resolve_links: bool = False, limit: int = sys.maxsize, ...) -> tuple[RecordedEvent, ...]
read_stream(stream_name: str, *, stream_position=None, backwards=False, resolve_links=False,
            limit=sys.maxsize, ...) -> AbstractReadResponse                  # LAZY iterator + stop()
read_all(*, commit_position: int | None = None, backwards=False, resolve_links=False,
         filter_exclude=DEFAULT_EXCLUDE_FILTER, filter_include=(), filter_by_stream_name=False,
         filter_by_prefix=False, limit=sys.maxsize, ...) -> AbstractReadResponse
read_index(index_name, *, commit_position=None, limit=sys.maxsize, ...) -> AbstractReadResponse  # out of scope
get_current_version(stream_name: str, *, ...) -> int | Literal[StreamState.NO_STREAM]
get_commit_position(*, filter_exclude=DEFAULT_EXCLUDE_FILTER, filter_include=(),
                    filter_by_stream_name=False, ...) -> int
```

Behavior:
- Eager pairs pause at server-limited end and materialize; lazy returns an *open* iterator consumed `for event in response`, stopped via `stop()`; cancellable mid-iteration; events are yielded one at a time.
- `$all` **pointers are commit-position ints; no Position VO exists on requests** — the commit/prepare pair lives only on returned `RecordedEvent`s and subscription sentinels. PHP `Position(commit, prepare)` envelope VO stays, but a read *request* pointer only carries `commit` wire-wise.
- Direction: `backwards=False` = oldest→newest; `True` = newest→oldest *starting at* the requested (newest-end) position.
- `resolve_links`: link+target pair in one envelope (`is_resolved_event`, `link: Self | None`, `ack_id` refers to the `$>`).
- **`DEFAULT_EXCLUDE_FILTER = (r"$.+", r"PersistentConfig\d+", r"Result")`** — reads/subscriptions *silently exclude* system events by default. **Recommendation: don't copy it into PHP** — reads stay explicit, `$`-events pass through.
- Filters: `filter_exclude`/`filter_include` sequences + two booleans (`filter_by_stream_name` XOR `filter_by_prefix` — a gRPC oneof; PHP should validate or express as an enum in `ReadOptions`).
- `sys.maxsize` = "unbounded" → PHP uses `null`.
- `get_current_version` = `int | NO_STREAM` sentinel; `get_commit_position` scans the `$all` tail → current global commit position (checkpoint bootstrap).

PHP proposals:

```php
public function readAll(string $stream, ?ReadOptions $options = null): list<ResolvedEvent>;   // eager
public function reader(ReadOptions $options): Generator<ResolvedEvent>;                        // lazy
public function readAllEvents(?ReadOptions $options = null): Generator<ResolvedEvent>;        // lazy $all
public function currentVersion(string $stream, ?CallOptions $options = null): int|ExpectedStreamState;
public function globalCommitPosition(?CallOptions $options = null): int;
```

---

## 4. Subscriptions

**Sources:** `client.py`, `streams.py CatchupSubscription`, `persistent.py`

### 4.1 Catch-up (lazy Generator semantics; the same read with `subscribe` flag)

```python
subscribe_to_stream(stream_name: str, *, stream_position=None, from_end=False, resolve_links=False,
                    include_caught_up=False, include_fell_behind=False, ...) -> CatchupSubscription
subscribe_to_all(*, commit_position=None, from_end=False, resolve_links=False,
                 filter_exclude=DEFAULT_EXCLUDE_FILTER, filter_include=(), filter_by_stream_name=False,
                 filter_by_prefix=False, include_checkpoints=False, window_size=30,
                 checkpoint_interval_multiplier=5, include_caught_up=False, include_fell_behind=False,
                 ...) -> CatchupSubscription
```

- The iterator **blocks after caught-up and continues live**; server `subscription_id` (UUID) exposed as a property; missing confirmation → `SubscriptionConfirmationError`.
- `CaughtUp`/`FellBehind`/`Checkpoint` are **sentinel RecordedEvent subclasses** (booleans `is_caught_up`/`is_fell_behind`/`is_checkpoint`; zero-UUID for checkpoints). PHP: envelope flags or discriminated envelope, not subclasses; sentinel UUID stays an implementation detail.

### 4.2 Persistent (management on the client; consumption returns an ack/nack object)

```python
create_subscription_to_stream(group_name, stream_name, *, stream_position=None, from_end=False,
    resolve_links=False, consumer_strategy='DispatchToSingle', message_timeout=30.0,
    max_retry_count=10, min_checkpoint_count=10, max_checkpoint_count=1000, checkpoint_after=2.0,
    max_subscriber_count=5, live_buffer_size=500, read_batch_size=200, history_buffer_size=500,
    extra_statistics=False, ...) -> None
create_subscription_to_all(group_name, *, commit_position=None, from_end=False, resolve_links=False,
    filter_exclude=DEFAULT_EXCLUDE_FILTER, filter_include=(), filter_by_stream_name=False,
    window_size=30, checkpoint_interval_multiplier=5, consumer_strategy='DispatchToSingle',
    message_timeout=30.0, ..., extra_statistics=False, ...) -> None
update_subscription_to_stream/group(...)-> None      # every arg Optional = differential update
read_subscription_to_stream(group_name, stream_name, *, event_buffer_size=150, max_ack_batch_size=50,
    max_ack_delay=0.2, stopping_grace=0.2, ...) -> PersistentSubscription
read_subscription_to_all(group_name, *, ...) -> PersistentSubscription
get_subscription_info(group_name, stream_name: str | None = None, ...) -> SubscriptionInfo
list_subscriptions(*, ...) -> Sequence[SubscriptionInfo]
list_subscriptions_to_stream(stream_name, *, ...) -> Sequence[SubscriptionInfo]
delete_subscription(group_name, stream_name: str | None = None, ...) -> None
replay_parked_events(group_name, stream_name: str | None = None, ...) -> None
```

`PersistentSubscription`:

```python
subscription_id: str
ack(item: UUID | RecordedEvent) -> None
nack(item: UUID | RecordedEvent, action: Literal['unknown','park','retry','skip','stop']) -> None
stop(*, cancel: bool = False) -> None
```

- **Acks batch internally** (`max_ack_batch_size=50`, `max_ack_delay=0.2s`, `stopping_grace=0.2s` flushes pending acks before teardown) — transport knobs, not interface semantics: hide or expose as one options VO.
- `ack`/`nack` accept the UUID **or the whole envelope** (with links: `ack_id` resolves to the link's id).
- `retry_count: int | None` on the envelope informs poison-pill handling.
- Errors: `MaximumSubscriptionsReachedError`, `ConsumerTooSlowError`, `StreamIsDeletedError`, `NotFoundError`.

### 4.3 Explicitly out of the frozen interface

`read_gossip` / `ClusterMember`, projections management (`create/update/delete/enable/abort/reset/…`), `subscribe_to_index`, v2 `append_records`. Listed to guard scope.

---

## 5. Stream metadata

```python
get_stream_metadata(stream_name, *, ...) -> tuple[dict[str, Any], int | StreamState.NO_STREAM]
set_stream_metadata(stream_name, *, metadata: dict[str, Any],
                    current_version: int | StreamState = StreamState.ANY, ...) -> None
```

JSON-shaped via protobuf `Struct`; reserved keys (`maxAge`, `maxCount`, …) not
enforced client-side. `get` returns the version to enable optimistic
read-modify-write. Tombstoned → `StreamIsDeletedError`. PHP: `array<string,mixed>` + guard, `get` returns version too.

---

## 6. Value objects & enums

**Source:** `events.py`

```python
ContentType = Literal["application/json", "application/octet-stream"]

class StreamState(Enum):                # str-valued
    ANY = "ANY"; NO_STREAM = "NO_STREAM"; EXISTS = "EXISTS"

@dataclass(frozen=True)
class NewEvent:
    type: str
    data: bytes
    metadata: bytes = b""
    content_type: ContentType = "application/json"
    id: UUID = field(default_factory=uuid4)

@dataclass(frozen=True)
class RecordedEvent:
    type: str
    data: bytes
    metadata: bytes
    content_type: str
    id: UUID
    stream_name: str
    stream_position: int        # zero-based, gapless
    commit_position: int
    prepare_position: int       # legacy; v2 may drop
    recorded_at: datetime | None = None
    link: Self | None = None    # $> link when resolved
    retry_count: int | None = None
    # properties: ack_id, is_system_event($), is_link_event("$>"), is_resolved_event,
    #             is_checkpoint / is_caught_up / is_fell_behind (sentinels)

@dataclass(frozen=True) class Checkpoint(RecordedEvent): ...
@dataclass(frozen=True) class CaughtUp(RecordedEvent): ...   # stream_position + commit/prepare
@dataclass(frozen=True) class FellBehind(RecordedEvent): ...

# v2-only supporting records (out of frozen scope): NewEvents, NewRecord, StreamStateCheck
```

The only int sentinels (server echo, never written): `MAPPING_INT_TO_STREAM_STATE = {-1: NO_STREAM, -2: ANY, -4: EXISTS}`.

PHP implications:
- `int|ExpectedStreamState` union for expected version (no backing `−1/−2/−4` — echo values surface only in exception detail).
- `EventData` mirrors `NewEvent` (opaque bytes, `json()` helper, client-assigned immutable id).
- `ResolvedEvent` mirrors `RecordedEvent` (flat envelope with `stream_name`, `stream_position`, `Position(commit, prepare)`, `recorded_at`, `retry_count`, `link`).
- Sentinels → envelope flags/discriminated envelope; sentinel UUID stays internal.
- `ConsumerStrategy: 'DispatchToSingle'|'RoundRobin'|'Pinned'` — PHP enum optional.

---

## 7. Exception hierarchy & gRPC mapping

**Sources:** `exceptions.py`, `common.py handle_rpc_error`, `unpack_error_status.py`

```
Exception
├── ProgrammingError
└── KurrentDBClientError
    ├── GrpcError(├ ExceptionThrownByHandlerError, ├ ServiceUnavailableError └─ SSLError,
    │              ├ GrpcDeadlineExceededError, ├ CancelledError ─ CancelledByClientError,
    │              ├ OperationFailedError, └ InternalError)
    ├── DeadlineExceededError ─ AppendDeadlineExceededError
    ├── AbortedByServerError (├ ConsumerTooSlowError, ├ TransactionMaxSizeExceededError,
    │                          └ MultiAppendToSameStreamError)
    ├── NotFoundError, AlreadyExistsError, SubscriptionConfirmationError
    ├── WrongCurrentVersionError(stream_name, actual, expected)
    ├── AccessDeniedError, StreamIsDeletedError, UnknownError, InvalidTransactionError
    ├── MaximumAppendSizeExceededError, BadRequestError, InvalidArgumentError ─ RecordMaxSizeExceededError
    ├── DiscoveryFailedError (Leader/Follower/ReadOnlyReplicaNotFound)
    ├── FailedPreconditionError (NodeIsNotLeaderError(host,port,node_id), StreamTombstonedError,
    │                            MaximumSubscriptionsReachedError)
    ├── UnauthenticatedError, InvalidCommitPositionError, ConsistencyChecksFailedError(…)
```

gRPC code / rich-detail → exception (from `handle_rpc_error`):

| gRPC | Exception |
|---|---|
| FAILED_PRECONDITION + NotLeaderNodeErrorDetails | NodeIsNotLeaderError(host, port, node_id) |
| FAILED_PRECONDITION + StreamRevisionConflictErrorDetails | WrongCurrentVersionError |
| FAILED_PRECONDITION + StreamTombstonedErrorDetails | StreamTombstonedError |
| FAILED_PRECONDITION + AppendConsistencyViolationErrorDetails | ConsistencyChecksFailedError |
| FAILED_PRECONDITION "Maximum subscriptions reached" | MaximumSubscriptionsReachedError |
| FAILED_PRECONDITION (else) | FailedPreconditionError |
| INVALID_ARGUMENT + AppendRecordSizeExceededErrorDetails | RecordMaxSizeExceededError(event_id, size, max_size) |
| ABORTED + AppendTransactionSizeExceededErrorDetails | TransactionMaxSizeExceededError |
| ABORTED + StreamAlreadyInAppendSessionErrorDetails | MultiAppendToSameStreamError |
| ABORTED "Consumer too slow" | ConsumerTooSlowError |
| ABORTED (else) | AbortedByServerError |
| UNKNOWN legacy string-sniffs ("Exception was thrown by handler", "Envelope callback …", "Unexpected FilteredReadAllResult") | mapped; else UnknownError |
| CANCELLED ("Locally cancelled by application!") | CancelledByClientError / CancelledError |
| DEADLINE_EXCEEDED | GrpcDeadlineExceededError |
| UNAUTHENTICATED | UnauthenticatedError |
| UNAVAILABLE (SSL_ERROR / empty address list / handshake) | SSLError; else ServiceUnavailableError |
| ALREADY_EXISTS | AlreadyExistsError |
| NOT_FOUND + `leader-endpoint-host`/`-port` trailers | NodeIsNotLeaderError (v1 append redirect) |
| NOT_FOUND (else) | NotFoundError |
| PERMISSION_DENIED | AccessDeniedError |
| INTERNAL | InternalError |

---

## 8. Implicit behaviors

- **autoreconnect decorator**: single retry after ~0.1 s on `NodeIsNotLeaderError` (when preference is leader), `ServiceUnavailableError`, closed-channel; **not** in the PHP interface — either userland or an explicit wrapper.
- **retrygrpc**: retry once on `GrpcError` (gossip reads only) — reference quirk, don't copy.
- **Reconnect idempotent** + probe call; PHP/amphp reconnect mechanics will differ (no goroutine lazy channel) — the interface must not promise auto-reconnect.
- **timeout**: per-call float seconds; write methods pre-fill from `DefaultDeadline`; lifetime of a lazy stream ignores `timeout` after first response. `AppendDeadlineExceededError` ≠ `GrpcDeadlineExceededError`.
- **connection-name** metadata on every call (server profiling parity).
- **Subscription confirmation**: first `ReadResp` must confirm ⇒ surface `$subscription->id(): string`.
- **close()**: stops all iterators + closes channel ⇒ PHP needs explicit `close()`; subscriptions own `stop()`.

---

## 9. Three divergence points to settle (grill session)

### 9.1 Eager vs lazy reads — carry both, freeze both

Reference has both idioms; PHP eager `readAll(): list<ResolvedEvent>` +
lazy `reader(ReadOptions): Generator<ResolvedEvent>`; settle now so the frozen
surface doesn't split later.

### 9.2 `timeout`/`credentials`: CallOptions carrier (recommended)

Reference repeats `timeout=` + `credentials=` on ~40 signatures. Proposals:
- **(A) recommended** — one `CallOptions` readonly VO per call (`append(..., ?CallOptions $options = null)`); honest about amphp (no per-call credentials), keeps signatures crisp.
- **(B)** keep `float $timeout = null` verbatim, drop credentials (connection-level in PHP).
- **(C)** nothing at interface level; lives in connection config / CallOptions.

### 9.3 Rich-status detail decoding: phased

- **Phase 1 (freeze):** minimal typed subset + catch-all `KurrentException` (mirrors `UnknownError` fallback).
- **Phase 2:** `grpc-status-details-bin` decoding into specific exceptions (per §7 table). **Never** copy the string-sniffed `UNKNOWN` legacy fallbacks — server-version workarounds, not interface promises.
  Recommended mandatory subset: version-mismatch, node-not-leader/redirect, stream-deleted, access-denied, deadline-exceeded, service-unavailable, consumer-too-slow.

---

## 10. Mapping summary

| Python `KurrentDBClient` v1.3.6 | Proposed PHP `KurrentClientInterface` |
|---|---|
| `KurrentDBClient(uri, root_certificates, …)` | `KurrentClient::create(string $uri, ?TlsOptions $tls = null)` |
| `append_to_stream(stream, events, current_version)` → commit pos | `append(string $stream, iterable $events, int\|ExpectedStreamState $expected, ?CallOptions = null): int` |
| `delete_stream` / `tombstone_stream` | `deleteStream` / `tombstoneStream` (same guard) |
| `get_stream(...)` eager tuple | `readAll(string $stream, ?ReadOptions): list<ResolvedEvent>` |
| `read_stream / read_all` lazy | `reader(ReadOptions): Generator<ResolvedEvent>`; `$all` lazy variant |
| `subscribe_to_stream/all` → CatchupSubscription | catch-up capability interface (`\Generator`, `stop()`, `id()`, checkpoint) |
| persistent create/read/update/delete/replay/info/list | persistent capability interface |
| `PersistentSubscription.ack/nack/stop` | `acked/`nack(NackAction $action)`/`stop()` |
| stream metadata get/set (Struct dict) | `readStreamMetadata(id): array{array<string,mixed>, int\|ExpectedStreamState}`; `writeStreamMetadata(id, array, guard)` |
| `get_current_version`, `get_commit_position` | `currentVersion(id): int\|ExpectedStreamState`; `globalCommitPosition(): int` |
| `StreamState.ANY/NO_STREAM/EXISTS` | `ExpectedStreamState` enum (no backing ints) |
| esdb/kdb/kurrentdb[+discover]:// | same URI grammar frozen |
| exceptions (§7) | typed subset + catch-all `KurrentException` |

---

## 11. Sources

- Repo (branch `1.3`, v1.3.6): https://github.com/pyeventsourcing/kurrentdbclient
- `client.py` (client + decorators + discovery + reconnect), `events.py` (VOs), `exceptions.py` (+ int echo map), `common.py` (error mapping + SUB defaults), `connection_spec.py` (URI), `streams.py` (append/read construction), `persistent.py`, `v2streams.py`, `gossip.py`, `__init__.py`, `unpack_error_status.py`
- Docs: README lifecycle/connection strings; `docs/api/{appending-events,reading-events,subscriptions,persistent-subscriptions,delete-stream,connection-strings}.md`
- Protos: `protos/kurrentdbclient` (`kurrent/rpc/errors.proto`, `v2/streams/errors.proto`)
