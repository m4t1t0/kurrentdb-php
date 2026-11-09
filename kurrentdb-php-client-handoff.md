# KurrentDB PHP Client — Research & Project Handoff

> Handoff notes for continuing this side project with another agent.
> Research compiled on **2026-11-04**. Facts marked *(verified)* were checked against sources at that date; items marked *(to verify)* are open questions or my own reasoning.

---

## 1. Goal

Build a PHP client for **KurrentDB** and publish it as useful open source.

This is a side project **not tied to any specific application**. Its two purposes (set 2026-11-05):

1. **Learning** — build deep understanding of gRPC and the stack around it (HTTP/2, protobuf, async PHP) by implementing them, not by wrapping someone else's client.
2. **Sharing** — fill the verified gap: no maintained, properly licensed gRPC client for PHP, usable by anyone from any runtime via `composer install`.

Audience constraint: shared hosting is **not** a target. Plain PHP + Composer is.

Architecture (priority flipped 2026-11-05 — pure PHP first because it teaches gRPC and serves the widest audience):

- A shared PHP interface (`KurrentClientInterface`) plus PHP value objects and exceptions.
- **Implementation A (first):** a pure-PHP gRPC client on **amphp** (amphp's async HTTP/2 client + protoc-generated stubs). No extensions, no custom binary, nothing to compile.
- **Implementation B (later, optional):** a PHP extension written in Go via **FrankenPHP's extension generator**, wrapping the **official KurrentDB Go client** — a raw-speed variant behind the same interface.
- Ruled out for now: `ext-grpc` — the compile burden is acceptable, but it hides exactly the gRPC internals this project exists to teach.

---

## 2. Background: what KurrentDB is

- KurrentDB is the renamed **EventStoreDB**. Event Store (company and product) rebranded as Kurrent; **v25.0** was the first release under the new name. Older EventStoreDB docs/tutorials mostly still apply. *(verified)*
- It is an **event-native database**: instead of overwriting rows, you append immutable events to named **streams** (e.g. `orders-42` containing `OrderPlaced`, `PaymentReceived`, `OrderShipped`). Current state is derived by replaying events.
- Key capabilities: full history / audit trail, replay and time-travel, projections into new read models, real-time subscriptions (catch-up and persistent), optimistic concurrency via expected stream revision.
- Main use cases: event sourcing, microservices, CDC, sync/local-first, and (per Kurrent's marketing) agentic AI.
- Deployment: Kurrent Cloud (managed: AWS, GCP, Azure), Kurrent Enterprise (self-hosted, licensed), Kurrent Community (local dev, core features).
- Official clients: **.NET, Java, Go, Python, Node.js, Rust**. **No official PHP client.** *(verified)*

### Licensing of the server

- Since v25.0 the server is under **Kurrent License v1 (KLv1)**: source-available, not OSI open source. Enterprise features need a license key. *(verified)*
- License-gated features include: auto-scavenge, most connectors (Kafka, MongoDB, RabbitMQ, Elasticsearch; the HTTP sink is free), stream policies, encryption-at-rest, LDAPS/OAuth auth, logs/OTLP endpoints, archiving. *(verified)*
- KLv1 governs the server; it should not affect a client that talks to it over the network.

### Protocols

- **gRPC** is the only officially supported client protocol. *(verified)*
- **TCP** (legacy) was removed; last version with it was EventStoreDB 23.10. Now only available to paying customers as a licensed plugin. *(verified)*
- **HTTP / AtomPub**: disabled by default since v20, less efficient, and Kurrent plans to remove it in a future version (the management HTTP API will remain). *(verified)*

---

## 3. Existing PHP options (and why there's still a gap)

| Project | Protocol | Status / notes |
|---|---|---|
| `friendsofouro/kurrentdb` (core: `friendsofouro/kurrentdb-core`, formerly `dbellettini/geteventstore-core`) | HTTP / AtomPub | Most maintained. PHP 8.4+, MIT, PSR-7/PSR-18, Symfony bundle available. Not endorsed by Kurrent. Depends on a protocol being phased out. |
| `prooph/event-store-client` | Legacy TCP | Effectively unusable with current KurrentDB (TCP removed / paid plugin). |
| `heroyt/eventstoredb` | gRPC (`ext-grpc`) | Right approach, but only `dev-master`, no license info, appears to be early-stage personal work. |

**Gap:** a maintained, properly licensed **gRPC** PHP client. Worth contacting the friendsofouro maintainer and checking heroyt's repo before starting.

Links:
- https://github.com/FriendsOfOuro/kurrentdb-php-core
- https://packagist.org/packages/friendsofouro/kurrentdb
- https://packagist.org/packages/heroyt/eventstoredb

---

## 4. Architecture decisions so far

### Why not just Swoole or FrankenPHP for a pure-PHP client?

- A gRPC client needs **outgoing HTTP/2** with long-lived streams.
- **Swoole** provides a coroutine runtime and HTTP/2 client — but it's itself a C extension.
- **FrankenPHP** is an application server for **incoming** requests; it does *not* give PHP code an HTTP/2 client.
- Only **amphp** is truly pure PHP (runs anywhere).
- `ext-grpc` (PECL C extension) is the official route but is painful to compile and unavailable on shared hosting.

### The FrankenPHP Go-extension route (now Implementation B — deferred)

- FrankenPHP can build PHP extensions **in Go** using `frankenphp extension-init`, with directives like `//export_php:function`, `//export_php:class`, `//export_php:method`, `//export_php:const`, `//export_php:namespace`. No C/CGO needed for simple cases. *(verified)*
- Wrapping the **official Go client** means discovery, TLS, leader redirection, and reconnection are already done and tested by Kurrent.
- **Trade-off:** only works with a custom FrankenPHP binary (built with `xcaddy`). Not usable on PHP-FPM / Apache / standard PHP images. Hence the shared interface so a portable implementation can follow.

### 2026-11-05 decision: flip the order — pure PHP on amphp first

Reframing the project (learning gRPC + widest usefulness) changed the calculus:

- Wrapping the Go client outsources **everything that teaches gRPC** (HTTP/2, trailers, deadlines, streaming) — it becomes a bridging exercise, not a gRPC education.
- Pure PHP on amphp gives the artifact to the **widest audience**: any PHP 8.4+ runtime, `composer require`, no extension, no custom binary, nothing to compile.
- The `ext-grpc` compile pain was re-examined: with shared hosting out of scope it only burdens CI and `pecl`/`pie` users — an acceptable cost — but it still hides the internals, so amphp wins on both goals.
- FrankenPHP remains a potential later **raw-speed variant** behind the shared interface; all verified FrankenPHP research below stays valid for that attempt.

### Target layering

```
Your app / EventSauce / Ecotone adapters
        │
KurrentClientInterface  (PHP, shared)
PHP value objects + typed exceptions  (PHP, shared)
        │
 ┌──────┴────────────────────┐
 │                           │
FrankenPhpKurrentClient     (future) AmphpKurrentClient
 (Go extension wrapping       (pure PHP: amphp HTTP/2 client
  the official Go client,      + protoc-generated protos —
  thin strings-and-arrays      the gRPC implementation itself
  driver)                      lives here; this is the learning)
 │.
gRPC over HTTP/2 (implemented in the PHP layer)
 │
KurrentDB
```

The Go extension should be a **thin native driver** exchanging only strings and arrays. All ergonomics (value objects, exceptions) live in the shared PHP layer.

---

## 5. Verification checklist

Priority note (2026-11-05): 5.1, 5.2, 5.4, 5.7, 5.8 apply to the pure-PHP route; 5.3 and 5.5–5.6 are deferred along with FrankenPHP (Implementation B).

### 5.1 Licensing
- [x] Go client is **Apache-2.0** per pkg.go.dev. *(verified)*
- [ ] One repo aggregator lists it as "other" — **read the LICENSE file in the repo directly**.
- [x] FrankenPHP is **MIT**. *(verified)*
- [ ] Apache-2.0 requires preserving attribution / NOTICE — include in distribution.
- [ ] The Go client ships a `protos` package. **Confirm those proto files are Apache-2.0** — if so, use them (not the KLv1 server repo) to generate PHP classes for the future pure-PHP implementation.

### 5.2 Go client version
- [ ] pkg.go.dev shows v1.0.1 (Aug 2025) with a warning that it's **not the latest version of the module** → find the current major version.
- [ ] Check supported server versions (repo has CI for LTS and previous LTS) and API stability.
- [ ] Do **not** use the old `eventstore-client-go` (archived, deprecated).

Links:
- https://github.com/kurrent-io/KurrentDB-Client-Go
- https://pkg.go.dev/github.com/kurrent-io/KurrentDB-Client-Go

### 5.3 FrankenPHP generator type limits
- Supported: `string`, `int`, `float`, `bool`, `array`, `void` (return), nullable scalars. *(verified)*
- Arrays: `frankenphp.AssociativeArray` (ordered) or packed arrays ↔ `[]any`; nested arrays OK; objects inside arrays become `null`. *(verified)*
- **Objects not supported** as params/returns. **Opaque classes** backed by Go structs are supported (good for client handles and subscription handles). *(verified)*
- `callable` via `frankenphp.CallPHPCallable()` — **experimental**. *(verified)*
- [ ] Docs changed across versions (an older revision said class methods couldn't take arrays). **Pin a FrankenPHP version and re-read its docs.**

Links:
- https://frankenphp.dev/docs/extensions/
- https://les-tilleuls.coop/ (blog post: "Writing PHP extensions in Go with FrankenPHP", Aug 2025)

### 5.4 Errors / exceptions
- [ ] Can generator-built functions **throw PHP exceptions**? *(to verify)*
- If not: Go returns a structured error array; the PHP layer throws typed exceptions:
  - `WrongExpectedVersionException`
  - `StreamNotFoundException`
  - `StreamDeletedException`
  - `NotLeaderException`
  - plus connection / auth / timeout errors
- Needed anyway so both implementations behave identically.

### 5.5 Subscriptions & threading (highest risk — prototype early)
- The Go client delivers subscription events from goroutines; PHP runs on its own thread.
- **Proposed design:** pull-based opaque class, e.g. `$sub->next(int $timeoutMs): ?array`, with Go buffering events in a channel. Avoid pushing into PHP callbacks from goroutines. *(to verify with FrankenPHP docs/maintainers)*
- [ ] In **worker mode**, can one Go client / gRPC connection be created once and reused across requests? Would be a real performance win. *(to verify)*

### 5.6 CLI & distribution
- [x] `frankenphp php-cli script.php` works with Go extensions (shown by the `franken-zstd-go` project). Needed for long-running subscription workers. *(verified)*
- [ ] `franken-zstd-go` reports possible **module registration issues with `php-server`** and recommends a Caddyfile — test both.
- [ ] Use `franken-zstd-go` as a reference for build tooling (it uses `xcaddy` + a justfile).
- [ ] Ship a **Dockerfile** and clear build instructions from day one (users need a custom binary).

Link: https://pkg.go.dev/github.com/phillarmonic/franken-zstd-go

### 5.7 PHP interface design
- [ ] Study what PHP event-sourcing libs expect before freezing the interface: **EventSauce** (message repository), **Ecotone**, **prooph**, **Broadway**.
- [ ] Decide representations for:
  - stream positions / revisions and `$all` positions (commit/prepare)
  - expected state: `any`, `no_stream`, `stream_exists`, exact revision
  - event data as **bytes/strings** (JSON encoded by caller or helper)
  - metadata, event IDs (UUID), content type

### 5.8 People / community
- [ ] Review `heroyt/eventstoredb` code.
- [ ] Contact the friendsofouro maintainer (dbellettini).
- [ ] Reach out to the amphp community if HTTP/2 trailers support turns out to be missing.
- [ ] Reach out to the FrankenPHP community — a real-world DB client via the generator may interest them (when Implementation B is revisited).

### 5.9 Pure-PHP (amphp) route — primary
- [x] **Trailers: amphp HTTP/2 client surfaces HTTP/2 trailers.** *(verified twice)*
  Source-level: `Response::getTrailers(): Future<Trailers>` in amphp/http-client 5.x, regression-tested HEADERS→DATA→trailing HEADERS. Runtime: the 2026-11-08 spike received `grpc-status` via trailers from a real KurrentDB 26.2.3 (see `spike/`).
- [x] gRPC wire conventions: 5-byte message prefix, `application/grpc` content-type, client-streaming message framing, half-close sign-off. *(verified in `spike/` 2026-11-08)*
  Wire facts: send one DATA chunk per frame, no `content-length`, `requires-leader: true` on appends, `ReadReq` requires `uuid_option`, `StreamIdentifier.stream_name`=field 3 (bytes), `shared.UUID{structured=1|string=2}`, server generates `created` metadata, `WrongExpectedVersion` comes in the response body with `grpc-status` still 0.
- [ ] Metadata and deadlines: per-call metadata, deadline propagation (`grpc-timeout` header sent; parsing verified incoming), TLS options from `kurrentdb://`.
- [x] Keepalive / ping / idle-connection behavior in amphp: HTTP/2-level PING frames observed on connections during the spike via the netdump capture. *(partially verified — no long-idle soak test yet)*
- [x] Confirm the Go client's `protos` package is Apache-2.0 and use it for `protoc` PHP stub generation. *(verified: repo LICENSE is Apache-2.0 © Kurrent, Inc; protos for v1 and the new v2 protocols captured in `spike/protos/`)*
- [x] PHP protobuf runtime: `google/protobuf` composer package chosen by the 2026-11-07 grill session (portable at runtime, optional `ext-protobuf` acceleration).
- [ ] KurrentDB 26.x ships **v2 protocols** (`kurrentdb.protocol.v2.streams.streamsservice`: `appendsession`, `appendrecords`) alongside v1 — decide alignment (v1 vs v2) when freezing the *interface* (grill ADR-0004 uses v1 vocabulary).

---

## 6. Proposed roadmap

**Spike (one weekend):**
1. Run KurrentDB in Docker (`kurrentdb://localhost:2113?tls=false`, insecure mode).
2. Generate PHP stubs with `protoc` from the Go client's `protos` package.
3. Using amphp's HTTP/2 client, hand-build a single RPC: append one event to a stream (gRPC frame prefix + content type).
4. Verify the response — and that `grpc-status` arrives both in headers and trailers (this kills the biggest risk).
5. One single-stream read, then one server-streaming call (the future subscription primitive).

If 1–5 work, the rest is filling in API surface.

**Old FrankenPHP spike (deferred, kept for Implementation B):** Go extension with `connect` / `append_to_stream` / `read_stream`, build via `xcaddy`, test in `frankenphp php-cli` + Caddyfile, add a minimal pull-based subscription.

**Then, in order:**
1. Append + read single stream (forwards/backwards, limits, expected revision)
2. Read `$all` (with filters)
3. Catch-up subscriptions (stream and `$all`)
4. Persistent subscriptions (create/update/delete, ack/nack)
5. Stream management (delete, tombstone, metadata)
6. Projection management, user management, operations
7. Adapters for EventSauce / Ecotone
8. (Optional) pure-PHP implementation behind the same interface

**Reference implementation for API design:** the Python client `kurrentdbclient` (by John Bywater) — dynamic language, well documented, translates to PHP more naturally than Java/.NET.

---

## 7. Useful snippets & notes

### Official homepage examples (append "order-placed" to `orders-42`)

Go:
```go
settings, err := kurrentdb.ParseConnectionString("kurrentdb://localhost:2113?tls=false")
if err != nil { panic(err) }

client, err := kurrentdb.NewClient(settings)
if err != nil { panic(err) }

data, _ := json.Marshal(map[string]any{"orderId": 42, "total": 99.99})

_, err = client.AppendToStream(context.Background(), "orders-42",
  kurrentdb.AppendToStreamOptions{},
  kurrentdb.EventData{
      EventID:     uuid.New(),
      EventType:   "order-placed",
      ContentType: kurrentdb.ContentTypeJson,
      Data:        data,
  },
)
```

Python (note `b'...'` = bytes literal; event data is opaque bytes):
```python
client.append_to_stream(
    stream_name="orders-42",
    events=[NewEvent(type="OrderPlaced", data=json.dumps(payload).encode("utf-8"))],
    current_version=StreamState.NO_STREAM,
)
```

**Lesson for the PHP API:** event payloads are **raw bytes**. In PHP that's just `string`; offer a helper like `EventData::json(string $type, array $payload)` that calls `json_encode` for convenience.

### Sketch of a generator-friendly Go surface (Implementation B — deferred; illustrative, not tested)

```go
// export_php:namespace Kurrent\Native

// export_php:class Client
type Client struct { /* wraps *kurrentdb.Client */ }

// export_php:method Client::connect(string $connectionString): bool
// export_php:method Client::append(string $stream, array $events, ?int $expectedRevision): array
// export_php:method Client::readStream(string $stream, int $fromRevision, int $limit, bool $backwards): array

// export_php:class Subscription
// export_php:method Subscription::next(int $timeoutMs): array   // empty array = timeout
// export_php:method Subscription::close(): void
```
Return values are arrays like `['ok' => true, 'next_revision' => 5]` or `['ok' => false, 'error' => 'wrong_expected_version', 'message' => '...']`; the PHP adapter converts these into value objects and exceptions. Check exact directive syntax and method-parameter limits against the pinned FrankenPHP version.

---

## 8. Key links

- KurrentDB site: https://kurrentdb.kurrent.io/
- Docs: https://docs.kurrent.io
- Quickstart: https://docs.kurrent.io/getting-started/quickstart/
- Clients docs: https://docs.kurrent.io/clients/
- KurrentDB 25.0 release notes (rebrand, license change): https://kurrentdb.kurrent.io/releases/kurrentdb/25-0/
- Go client: https://github.com/kurrent-io/KurrentDB-Client-Go
- FrankenPHP extensions docs: https://frankenphp.dev/docs/extensions/
- franken-zstd-go (reference Go extension): https://pkg.go.dev/github.com/phillarmonic/franken-zstd-go
- friendsofouro HTTP client: https://github.com/FriendsOfOuro/kurrentdb-php-core
- heroyt gRPC client: https://packagist.org/packages/heroyt/eventstoredb
- amphp HTTP/2 client: https://amphp.org/http-client/
- PHP protobuf runtime: https://github.com/protocolbuffers/protobuf/tree/main/php
- Kurrent community / Discord: https://discord.gg/Phn9pmCw3t

---

## 9. Open questions for the grill session

Use this doc as the starting point for the project-refinement session. Nothing below is decided.

1. **Identity:** project/package name (must not collide with `friendsofouro/kurrentdb`), new GitHub org or solo repo?
2. **License:** MIT for our code? NOTICE/attribution obligations coming from the Apache-2.0 protos?
3. **PHP support floor:** ~~8.4 only, or also 8.3?~~ Decided 2026-11-07 (8.4
   only); superseded 2026-11-09 — build for **8.4 and 8.5**, the two supported
   PHP versions (`^8.4 || ^8.5`, CI runs both).
4. **Async model:** amphp/Revolt is async — do we also ship a blocking facade (e.g. Revolt's blocking wait) since most PHP event-sourcing code is synchronous?
5. **v0.1 scope:** stream append + read only, or subscriptions included in the first release?
6. **Adapters:** EventSauce / Ecotone adapters now, or after a stable client?
7. **Packaging:** monorepo, or separate interface/contracts package (reusable by the future FrankenPHP implementation and others)?
8. **Upstream relations:** review `heroyt/eventstoredb` and contact dbellettini (friendsofouro) before announcing — possibly revive his work rather than start fresh?
9. **Testing:** CI matrix against recent KurrentDB versions via Docker; cross-check behavior against the Go/Python clients.
10. **Definition of done for v1.0:** API parity with the Python client? Persistent subscriptions included? Docs + a runnable Docker example?
