# KurrentDB PHP Client — Product Spec

Product rules for the two Composer packages `<gh-handle>/kurrentdb-contracts`
and `<gh-handle>/kurrentdb-client`, built around the
`KurrentClientInterface`. The options collected below were resolved in the
2026-11-07 grill session; background lives in
`kurrentdb-php-client-handoff.md`, architectural decisions in `docs/adr/`.

## Identity

- Solo repo under the maintainer's personal GitHub account (`m4t1t0`); repo
  name `kurrentdb-php-client` (or a rename preserving "kurrentdb … php" meaning).
- Composer namespaces: `m4t1t0/kurrentdb-contracts`,
  `m4t1t0/kurrentdb-client`. Names deliberately avoid looking official:
  the vendor is personal (Kurrent's official GitHub presence is
  `kurrent-io`), no org today.
- No company-adjacent branding; the name says what it is: a PHP client for
  KurrentDB.

## Runtime & API shape

- PHP 8.4 and 8.5 — the two currently supported PHP versions (constraint
  `^8.4 || ^8.5`; CI matrix runs both). The earlier single-floor decision
  (2026-11-07) was superseded on 2026-11-09 when PHP 8.5 shipped; the
  interface keeps 8.4-compatible syntax.
- amphp async-first API with an in-package thin blocking facade (block-style
  helper) so synchronous event-sourcing stacks can use the client directly.
- Event payloads are raw bytes (`string`); helper e.g.
  `EventData::json(type, payload)` provides convenience.
- Typed exceptions shared between implementations (WrongExpectedVersion,
  StreamNotFound, StreamDeleted, NotLeader, connection/auth/timeout).

## Interface design (details in ADR-0004, ADR-0005)

- KurrentDB-native transport-semantics contract; adapters, not a
  framework-neutral abstraction.
- Stream revisions = plain 64-bit ints; `$all` positions = `Position` VO
  (commit/prepare); expected state = `ExpectedStreamState` enum
  (`any`/`no_stream`/`stream_exists`/exact revision).
- `EventData` = opaque bytes + metadata bytes + eventId (UUID string) +
  event type; `json()` named constructor for convenience; never
  schema-enforced.
- Reads return lazy Generators; **and** an eager `readAll(): list<ResolvedEvent>`
  (both idioms frozen — ADR-0005).
- No transactions anywhere on the interface (server removed them ≥22.6);
  prooph's TransactionalEventStore, if adapted, is documented per-stream
  buffered commit.
- Per-call knobs (`timeout`, future metadata): one nullable `CallOptions` VO
  as the last parameter of every frozen method (ADR-0005).
- Typed exceptions at freeze: WrongExpectedVersion, NotLeader (redirect),
  StreamDeleted, AccessDenied, DeadlineExceeded, ServiceUnavailable,
  ConsumerTooSlow + catch-all `KurrentException`; rich detail decoding later.
- Frozen core surface (ADR-0005): append, eager+lazy reads incl. `$all`,
  deleteStream, tombstoneStream, currentVersion, globalCommitPosition. The
  amphp implementation ships v0.1 with append + single-stream reads and
  `UnsupportedOperationException` for the rest until v0.2.
- Subscriptions & metadata management: separate capability interfaces, added
  after the core freeze (non-breaking additions).
- Connection: `kurrentdb://` URI parsed like official clients + programmatic
  options constructor; cluster discovery out of the spike.

## Stack & build

- Protobuf runtime: `google/protobuf` composer package at runtime (portable);
  optional `ext-protobuf` C accelerator is opportunistically used if present.
- Protoc-generated stubs are committed to the repo and regenerated only when
  adopting newer protos; users never run protoc.

## Milestones

- **Spike (pre-v0.1)**: Docker KurrentDB → committed stubs generated from the
  Go client's protos → hand-built append RPC via amphp → verify trailers →
  single-stream server read. Extended with one item from the trailers
  research: verify flow-control/backpressure on a slow consumer over a
  long-lived stream. *(Status 2026-11-08: append, trailers, single-stream
  read, expected-state sequencing all validated in `spike/`; the
  slow-consumer backpressure test also passed (no stalls, normal stream
  end, trailers delivered over a deliberately slow consumer).)**
- **Interface freeze (CI gate)**: contracts interface freezes only after
  (a) the event-sourcing framework expectations study is reviewed as a doc,
  (b) the spike validated append + reads against a real server, and (c) the
  Python client's API is mapped 1:1 in a design note. contracts hits its
  1.0 tag then and CI starts.

## Release scope

- **v0.1** (amphp client): stream append + single-stream reads, **and** the
  `$all` lazy read (`readAllEvents`) landed early with the frozen interface —
  the drift was accepted 2026-11-10 because ADR-0005 freezes that method in
  the core surface. `deleteStream`, `tombstoneStream`,
  `globalCommitPosition` throw `UnsupportedOperationException` until v0.2.
- **Contract tests** damp the surface at the frozen methods' shape only;
  wire-buffered v0.2 items (link `$>`-pairing, TLS, cluster discovery) are
  documented deferrals on their docblocks/README.
- **v0.2 (pre-v1.0)**: `$all` read polish (filters wiring, links, resolve)
  + delete/tombstone/globalCommitPosition.
- **Deferred to post-v0.2**: catch-up subscriptions, then persistent
  subscriptions (create/update/delete, ack/nack) as separate capability
  interfaces.
- **v1.0 (definition of done)**: core API parity with the Python
  `kurrentdbclient` — append with expected state, single-stream + `$all`
  reads, both subscription types, typed exceptions, blocking facade — plus
  documentation and a runnable Docker quickstart. The blocking facade is a
  package-level add around the same frozen methods; nothing on the frozen
  contract changes shape for it.
- **Explicitly out of scope for v1.0**: projection management, user
  management, operations APIs.
- Adapters (EventSauce / Ecotone): started only after the client API has
  stabilized; not in parallel from day one.
- **Example/consumer app from the quality flow** ships with the v1.0 docs
  (it needs the stable surface to make sense).

## Quality & release flow

- **Acceptance-test-first (per feature)**: each feature's acceptance test is
  written before its implementation and validated against a real KurrentDB
  (Docker, insecure mode). The spike is the only pre-test phase: the very
  first acceptance test (append with expected state + read back revision 0 +
  conflict on second append) depends on spike-verified wire/trailer behavior
  and cannot precede it.
- **In-repo contract test suite**: `tests/contracts/` is an
  implementation-agnostic suite defined against `KurrentClientInterface`,
  parameterized by client implementation. Requirements: `AmphpKurrentClient`
  passes it today; any future implementation (e.g. the Go-wrapping one) is
  dropped in and must pass the same suite unchanged. Alongside the suite, a
  small example/consumer app (`composer require` of both packages) runs the
  harness as an end user would, via `docker-compose.yml` (PHP container +
  KurrentDB).
- **Test-project growth layers (one repo)**: contract tests + harness
  (spike onward) → small PHP example app → EventSauce/FrankenPHP demo app
  only after the adapter phase, where FrankenPHP requires a custom
  `xcaddy`-built binary (its own Dockerfile, gated behind Implementation B).
- Local development and testing scripts first; CI (GitHub Actions, Docker
  matrix with current + previous KurrentDB LTS) begins only after the
  contracts interface freezes.
- Cross-client behavioral checks against the Go and Python clients are manual
  scripts for now, not CI steps.
- `protoc` PHP stubs are generated from the official Go client's `protos`
  package (Apache-2.0, verified); heroyt's vendored `proto/` set is kept as a
  cross-check reference only.

## Community

- No upstream outreach. Neither Tomáš Vojík (`heroyt/eventstoredb` — reviewed
  as a one-day spike on the ext-grpc stack, not a revival candidate) nor
  friendsofouro's maintainer is contacted before or around launch; the
  ecosystem review stays internal background.
