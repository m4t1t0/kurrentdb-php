# kurrentdb-contracts

Implementation-free contracts for a KurrentDB PHP client: the frozen
`KurrentClientInterface`, value objects (`EventData`, `ResolvedEvent`,
`Position`, `ReadOptions`, `ReadFilter`, `CallOptions`), the
`ExpectedStreamState` enum, and the typed exception set.

Nothing in this package performs I/O. Implementations live in
`m4t1t0/kurrentdb-client` (amphp, first) and future transports behind the same
interface. The interface is frozen per
[ADR-0005](../../docs/adr/0005-frozen-core-interface.md); later additions are
separate capability interfaces (subscriptions, stream-metadata management).

**License:** MIT for this code; generated protobuf artifacts (none currently
in this package) carry Apache-2.0 attribution per adr-0002.

Status: pre-1.0 scaffolding — interface frozen in design (ADR-0005), tag lands
with the first release.
