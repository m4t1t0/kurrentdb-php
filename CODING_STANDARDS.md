# Coding Standards — kurrentdb-php-client

Conventions for the two packages (`m4t1t0/kurrentdb-contracts`,
`m4t1t0/kurrentdb-client`) and the test harness. The `code-review` skill's
Standards axis reads this file.

## Language & compatibility

1. **PHP floor**: packages declare `^8.4 || ^8.5` (the two currently supported
   PHP versions; CI runs both). 8.3 and shared hosting are out of scope
   (product spec).
2. **8.4-compatible interface syntax**: contracts code must not use
   language features that break on 8.4 — the frozen surface outlives 8.5.
   Newer features inside implementations are fine where they don't leak into
   signatures.

## Structure & namespaces

3. **Package PSR-4 roots**: contracts → namespace `Kurrent\`
   (`packages/contracts/src/Kurrent/`); client implementation → sub-namespace
   `Kurrent\Amphp\` (`packages/client/src/Kurrent/Amphp/`), never the same
   namespace as another package's classes.
4. **Value objects** are `final readonly` classes with promoted public
   properties and named constructors for each legitimate construction path
   (see `EventData::json()/bytes()/newId()`); constructors validate
   representation exclusivity (see `ReadOptions`).
5. **One responsibility per class** in the client: wire codec split from
   message shapes split from transport (`Protobuf.php`, `StreamsProtocol.php`,
   `ContentStream.php`, client class). No grab-bag util classes.
6. **No hidden default behavior** on reads: filters are explicit; there is no
   default exclusion of `$`-prefixed streams/system events (deliberate
   deviation from the Python client — ADR-0005 + mapping note).
7. **No transactions / no transaction-shaped methods** on the interface
   (server removed them ≥22.6; ADR-0004).

## Wire code (the gRPC layer)

8. **Cite the schema on every hand-built field**: comments name the proto
   message + field number (e.g. "shared.UUID: structured=1, string=2"). A
   magic number without a schema citation is a bug incubator — the spike's
   Option-vs-ProposedMessage and stream_name-field-3 incidents both started
   as uncited assumptions. Keep `../spike/` runnable as the executable
   reference while protoc-generated stubs are pending.
9. **Wire facts live in the handoff §5.9**, not in tribal memory: anything
   learned on the wire (required fields, header conventions, server
   versioning quirks) lands in `kurrentdb-php-client-handoff.md` §5.9 the
   same session it's discovered.
10. **Test the byte level, not assumptions**: whenever a parser crashes or a
    handler reports "Exception was thrown by handler", wire-capture against
    the official Go client before changing encoder logic.

## Exceptions

11. **Typed exceptions per ADR-0005**: contract-level codes are the
    exception namespace (`Kurrent\Exception\`); the frozen subset is
    WrongExpectedVersion, NotLeader, StreamDeleted, AccessDenied,
    DeadlineExceeded, ServiceUnavailable, ConsumerTooSlow +
    `KurrentException` catch-all; never string-sniff legacy UNKNOWN messages.
12. **Unsupported-but-frozen is an exception, not a stub that lies**:
    unimplemented frozen methods throw `UnsupportedOperationException` with
    their target version in the message; tests treat that as skip.

## Tests

13. **Acceptance-test-first**: a contract acceptance test exists before its
    implementation (product spec §Quality + the `acceptance-test-first`
    skill).
14. **Implementation-agnostic suites**: contract tests subclass
    `KurrentClientContractTest`; every implementation version runs the same
    tests; nothing in the base suite references a concrete client class.
15. **Fresh stream names per test** (`contract-<hex>` / `orders-<hex>`) —
    suites are re-runnable against a live server without cleanup.
16. **No test depends on server-side state order**; assert semantics
    (log-order `$all`, gapless revisions) not absolute positions except
    "> grows".

## Documentation & process

17. **Decisions are ADRs, language is the glossary**: everything "why did
    they do this?" lands in `docs/adr/`; vocabulary in `GLOSSARY.md`; the
    product spec stays product-rules-only (no ADR content in it).
18. **Commit conventions**: Conventional-Commit prefixes scoped by package
    (`feat(contracts)`, `feat(client)`), plus `docs`, `spike`, `test`. One
    artifact type per commit — grill-session doc outputs, spike validation,
    and package code are separate commits, never mixed.
19. **The repo overrides any tooling enforcement**: where php-cs-fixer or
    phpstan would contradict a rule above, that rule binds.
