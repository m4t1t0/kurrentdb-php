# Frozen contracts interface: full core surface, both read idioms, CallOptions

The contracts package (`m4t1t0/kurrentdb-contracts`) freezes with the full
KurrentDB-native core surface — not just the v0.1 shipping subset — so that
alternate implementations never face a BC break from later additions:

- `append(string $stream, iterable $events, int|ExpectedStreamState $expected, ?CallOptions $options = null): int`
  (returns the commit position of the last appended event — Python-client
  semantics: poll downstream processors until this point).
- `readAll(string $stream, ?ReadOptions $readOptions = null, ?CallOptions $options = null): list<ResolvedEvent>`
  — eager (aggregate-reload idiom).
- `reader(string $stream, ?ReadOptions $readOptions = null, ?CallOptions $options = null): Generator<ResolvedEvent>`
  — lazy single-stream, cancellable mid-iteration (limit carries a default
  batch window as implementation detail; direction and `resolveLinks` come
  from `ReadOptions`; `$>`-link *pairing* is a documented v0.2 deferral —
  link events currently render as their own envelopes).
- `readAllEvents(?ReadOptions $readOptions = null, ?CallOptions $options = null): Generator<ResolvedEvent>`
  — lazy `$all`; the enum-shaped `ReadFilter` VO applies here (one filter
  axis at a time, explicit — no default filter excluding `$`-events,
  unlike the Python reference).
- `deleteStream` / `tombstoneStream` (same `int|ExpectedStreamState` guard).
- `currentVersion(string $stream): int|ExpectedStreamState`,
  `globalCommitPosition(): int` (checkpoint bootstrap).
- Stream metadata management stays a separate capability interface (ADR-0004),
  post-freeze.
- Persistent/catch-up subscriptions: capability interfaces, post-freeze.

Every method carries a nullable `CallOptions` readonly VO as its last
parameter (per-call timeout + future per-call knobs); auth stays
connection-level (amphp has no per-call credentials). This replaces Python's
repeated `timeout=`/`credentials=` kwargs.

Typed exceptions at freeze: `WrongExpectedVersionException` (carrying
current/expected), `NotLeaderException` (with redirect target),
`StreamDeletedException`, `AccessDeniedException`, `DeadlineExceededException`,
`ServiceUnavailableException`, `ConsumerTooSlowException` — plus a catch-all
`KurrentException`. Rich `grpc-status-details-bin` decoding into further typed
exceptions is a later minor release; legacy `UNKNOWN` string-sniffing
workarounds from the Python reference are explicitly not copied.

`AmphpKurrentClient` ships v0.1 implementing append + single-stream reads and
throws `UnsupportedOperationException` for the remaining frozen methods until
v0.2 — the interface is stable; the implementation fills in.

Decided 2026-11-09 (grill session follow-up round). Precedent:
`docs/python-client-api-mapping.md` (kurrentdbclient v1.3.6).
