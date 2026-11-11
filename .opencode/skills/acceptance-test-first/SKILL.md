---
name: acceptance-test-first
description: "Drive implementation work at this repo with acceptance tests that exist before the code: the implementation-agnostic contract suite in tests/contracts runs for every KurrentClientInterface implementation, red before green. Use when adding client features (append/reads/delete/tombstone/subscriptions), writing new contract tests, or setting up another implementation's test subclass."
---

# Acceptance-test-first (ATDD) for the KurrentDB PHP client

The rule: **a behavior's test exists before its implementation**, validated
against a real KurrentDB (Docker, insecure). The only pre-test phase allowed
is the wire-level *spike* (a behavior that depends on unverified protocol
shape gets a spike first, then its test).

## Layout

```
packages/contracts/     m4t1t0/kurrentdb-contracts  (frozen interface)
packages/client/        m4t1t0/kurrentdb-client     (amphp implementation)
tests/
├── bootstrap.php                     requires repo /vendor/autoload.php
├── contracts/
│   └── KurrentClientContractTestCase.php  IMPLEMENTATION-AGNOSTIC suite (abstract)
├── amphp/
│   └── AmphpKurrentClientContractTest.php  subclass; createClient() wires the real client
├── Dockerfile                         PHP 8.4 + composer harness image
docker-compose.yml                     kurrentdb (insecure) + php tests service
```

## Rules

1. **The base suite is implementation-agnostic.** It references only the
   `KurrentClientInterface` and contracts VOs — never a concrete client
   class, and never transport internals. Adding a test there = extending the
   frozen behavior contract for *every* implementation, deliberately.
2. **Unimplemented frozen surface = skip, not fail.** An implementation may
   ship behind the frozen interface; the base suite catches
   `UnsupportedOperationException` per test and `markTestSkipped()`s (the
   client class documents which methods it fills in for which version).
3. **Fresh names per run**: streams are named `contract-<hex>` (or the
   domain-appropriate prefix) inside each test. Nothing depends on prior
   server state; suites re-run green against a live server without cleanup.
4. **No hidden defaults**: tests assert the *absence* of surprising behavior
   where relevant (e.g. `$`-system events pass through `readAllEvents()` with
   no filter — that's deliberate per ADR-0005).
5. **Red before green**: new capability = write test(s) first, run them
   (red: class missing / wire failing), implement, run again (green). When
   red is a *wire* failure (not missing class), capture the wire bytes and
   diff against the official client before touching encoder logic — see the
   `wire-debug` skill.
6. **Harness:** `docker compose up -d --build` at repo root; then
   `docker compose exec php composer install` (first time) and
   `docker compose exec php vendor/bin/phpunit`. Env hook: `KURRENTDB_URI`
   (default `kurrentdb://kurrentdb:2113?Tls=false`). A wire-tap proxy can be
   inserted by pointing the uri at a netdump container (see `wire-debug`).
7. **Autoload discipline**: new source dirs go in the root `composer.json`
   `autoload`/`autoload-dev` PSR-4 map *before* running
   (`composer dump-autoload` inside the container afterwards).

## Version metadata

Package composer versions live at the tag, not in composer.json. `packages/
client` may trail the contracts tag version (frozen-surface gap = the
documented UnsupportedOperationException set).
