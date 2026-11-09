---
name: port-spike
description: "Promote validated wire-level code from spike/ into packages/client (or packages/contracts): namespace/class hygiene, what stays throwaway (netdump, h2dump, goprobe, protos cache), how acceptance tests gate the port, and how the hand encoder is eventually replaced by committed protoc stubs. Use after a spike validates a behavior and you start the real implementation of that behavior."
---

# Porting spike code into packages

The spike is *validated learning*, the packages are *shipping code*. The port
is a rewrite with tests as the gate, not a copy.

## What graduates vs what stays

| Stays in `spike/` (throwaway) | Graduates to packages |
|---|---|
| `spike/netdump/`, `spike/dump/h2dump.py`, `spike/goprobe/`, `spike/protos/` (wire-debug toolkit) | Nothing — they exist only for debugging/deriving wire facts |
| `spike/lib/protobuf.php` (procedural encoder/decoder) | `packages/client/src/Kurrent/Amphp/Protobuf.php` (static final class; no bare functions — they break PSR-4 autoloading) |
| `spike/lib/messages.php` (message builders/parsers) | `packages/client/src/Kurrent/Amphp/StreamsProtocol.php` (per-schema fight-level clarity: each method cites the proto message + field numbers) |
| `spike/spike.php`, `backpressure.php` (drivers) | Method bodies of `AmphpKurrentClient` — but reshaped around the frozen contract, not the spike's I/O order |
| inline `ContentStream`, sync-execution sugar | `packages/client/src/Kurrent/Amphp/ContentStream.php` + the `sync()` helper on the client class |

## Port rules

1. **Namespace hygiene**: client code lives in `Kurrent\Amphp\`; the contracts
   namespace (`Kurrent\`) belongs to `m4t1t0/kurrentdb-contracts` only
   (CODING_STANDARDS.md §3).
2. **Reshape at the contract, don't keep the spike's I/O order**: the spike
   hardcodes a flow (wait-healthy → append → read); packages expose the
   frozen interface's methods. Health-waiting becomes private
   `ensureServerReady()`; the readiness loop is a *transport concern*.
3. **Contract tests gate the port**: an accepted port = `tests/contracts`
   green (new test first, if the behavior was newly accepted — the
   `acceptance-test-first` skill).
4. **The spike keeps running**: don't delete spike scripts when porting; they
   are the reference any future wire regression compares against. They leave
   when the committed stubs make them redundant.
5. **Deleted-together pairs**: when a spike file is ported, delete only the
   spike code that the port superseded *and* that nothing references. The
   lint/parse check (`php -l` per file inside the PHP container) covers both
   trees; no syntax lints are allowed broken at commit time.
6. **Version discipline**: `packages/client` composer version stays trailing
   the contracts tag (frozen methods not yet implemented =
   `UnsupportedOperationException` per ADR-0005).

## Eventually: committed protoc stubs

The hand encoder survives until the stub workflow exists (decision: stubs are
generated once and committed — no user-side protoc — per the 2026-11-09
decisions). When it lands:

- protos source: the official Go client's `protos` package (Apache-2.0,
  verified; v1 = services, v2 = newer `appendrecords`/`appendsession` — v1 is
  what this contract speaks).
- `StreamsProtocol.php`'s builders get replaced by generated message classes
  under a `Kurrent\Db\Generated\` or similar namespace — decide the namespace
  when wiring; the client's public contract doesn't change.
- Keep the brown-path: if generation drifts (e.g. plugin version changes),
  the wire-diff toolkit (the `wire-debug` skill) is the regression tripwire.
