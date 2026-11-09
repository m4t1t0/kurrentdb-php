# Interface is KurrentDB-native transport semantics, not framework-neutral

`KurrentClientInterface` speaks KurrentDB's own vocabulary (prooph-shaped:
append with expected state, lazy reads, subscriptions, capability interfaces
for extras). Framework adapters for EventSauce, Ecotone, and Broadway are thin
layers over it, not implied by it. Research into all four frameworks (2026-11-07
session) showed they fundamentally disagree on payload shape and locking, so a
neutral abstraction would serve none of them well, while prooph maps to
KurrentDB at near-identity.

Considered options: a framework-neutral event-sourcing contract (rejected:
incompatible framwords); shipping framework adapters inside the contracts
package (rejected: keeps the contracts tiny and implementation-free).

Consequences:
- Revisions are plain 64-bit ints; `$all` positions are a `Position` value
  object (commit/prepare pair); expected state is an `ExpectedStreamState`
  enum (`any`, `no_stream`, `stream_exists`, exact revision). One int is never
  overloaded for both revision and position.
- `EventData` carries opaque bytes plus a `json()` named constructor; payload
  and metadata are never schema-enforced. Reads return PHP Generators — no
  eager arrays.
- No transactions on the interface (KurrentDB removed them from gRPC in
  ≥22.6). Where a framework needs them (prooph `TransactionalEventStore`),
  adapters implement per-stream buffered commit as a documented capability,
  never faked cross-stream atomicity.
- Subscriptions and management (metadata, streams listing) are separate
  capability interfaces added after the core freeze, so they don't break
  the frozen core. The frozen core contains: append-to-stream, read-stream.
- Connection config: `kurrentdb://` URIs parsed like the official clients,
  plus a programmatic options path; cluster discovery (gossip/DNS) is out of
  the v0.1 spike.

Decided 2026-11-07.
