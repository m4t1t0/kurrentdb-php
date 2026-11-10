# kurrentdb-php-client

A **pure-PHP client for KurrentDB** (the renamed EventStoreDB) — built on
amphp's async HTTP/2 client, using **no PHP extensions, nothing to compile,
no custom binary**. Installable from any PHP 8.4/8.5 runtime with Composer.

> 🚧 **Early development.** The frozen contracts package is tagged
> [`contracts/v1.0.0`](../../releases); the amphp client implementation is at
> v0.1 (append + reads). Nothing is on Packagist yet — see
> [Status](#status).

## Why

Two goals, set deliberately (see [ADR-0001](docs/adr/0001-pure-php-on-amphp-first.md)):

1. **Learning** — gRPC, HTTP/2, and protobuf are implemented in this repo's
   own PHP layer rather than wrapped, because wrapping the official client
   hides the internals this project exists to teach.
2. **Sharing** — there is no maintained, properly licensed gRPC client for
   plain PHP (the [research handoff](kurrentdb-php-client-handoff.md) enumerates
   the options and the gap). This repo fills it for everyone who can run
   `composer install`.

The full FrankenPHP/Go speed variant stays a deferred second implementation
behind the same interface (ADR-0001) — the expensive part was ruling it out
as the first step, not researching it.

## Status

| Package | Version | Surface | Notes |
|---|---|---|---|
| `m4t1t0/kurrentdb-contracts` | **1.0.0** (tagged) | frozen: `KurrentClientInterface`, value objects, `ExpectedStreamState`, typed exceptions | implementation-free; other transports may implement it |
| `m4t1t0/kurrentdb-client` | v0.1 (untagged, in-repo) | `append`, `readAll`, `reader`, `readAllEvents`, `currentVersion` — validated against a real KurrentDB via the docker-compose harness | `deleteStream`/`tombstoneStream`/`globalCommitPosition` throw `UnsupportedOperationException` until v0.2 |

The interface is **frozen** (ADR-0005): later additions are separate
capability interfaces (subscriptions, stream-metadata management) that any
implementation can opt into — the core never churns.

## Quick start (development)

The docker-compose harness runs a real KurrentDB (insecure mode) and the
test runner:

```sh
docker compose up -d --build        # kurrentdb + php (8.4 + composer)
docker compose exec php composer install
docker compose exec php vendor/bin/phpunit       # acceptance suite vs the real server
```

To use the packages in another project while they are not yet published,
add this repo as a Composer path repository:

```json
{
    "repositories": [{ "type": "path", "url": "path/to/kurrentdb-php-client/packages/*" }]
}
```

A more end-user-friendly quickstart (git clone → docker compose → append /
read demo) is part of the v1.0 definition of done.

## Documentation map

| Read | For |
|---|---|
| [`docs/product-spec.md`](docs/product-spec.md) | product rules: identity, runtime floors, release scope, quality flow |
| [`docs/adr/`](docs/adr/) | the five frozen decisions (pure-PHP first, licenses, packaging, interface shape) |
| [`GLOSSARY.md`](GLOSSARY.md) | the project's language — what "revision", "expected state", "capability interface" mean here |
| [`docs/python-client-api-mapping.md`](docs/python-client-api-mapping.md) | the 1:1 mapping against the Python `kurrentdbclient` reference |
| [`kurrentdb-php-client-handoff.md`](kurrentdb-php-client-handoff.md) | research ground truth, incl. **§5.9 wire facts** (gRPC-over-HTTP2 gotchas verified on a live server) |
| [`CODING_STANDARDS.md`](CODING_STANDARDS.md) | the 19 repo rules the review gates enforce |
| [`spike/`](spike/) | the throwaway validation spike (wire-tap proxy, Go-client reference probe) — kept until protoc stubs land |

## Quality & process

- **Acceptance-test-first**: behavior tests exist before implementation and
  run against a real KurrentDB in Docker (16 tests / 57 assertions at
  contracts freeze).
- **Two review families** are run as skills in this repo:
  `/code-review` (Standards vs Spec axes) and `/adversarial-review`
  (hostile personas — Saboteur/New Hire/Security Auditor — executed by a
  second model, DeepSeek V4.1 Flash, to break the shared-weights self-review
  trap). Contracts 1.0.0 shipped after two rounds of each; the gate trail is
  recorded in [`docs/product-spec.md`](docs/product-spec.md) §Milestones.
- **Wire debugging is a дисциплина**: when anything fails opaquely, raw
  HTTP/2 bytes are captured through a TCP tap and byte-diffed against the
  official Go client ([`wire-debug` skill](.opencode/skills/wire-debug/SKILL.md)).

## License

MIT for the client code (ADR-0002). The protobuf stubs derived from the
official Go client's `protos` package keep their Apache-2.0 attribution
headers (that package is Apache-2.0 © Kurrent, Inc — verified). The Kurrent
server itself is separately licensed (Kurrent License v1) and doesn't
govern network clients.

## Links

- KurrentDB docs: https://docs.kurrent.io — clients doc: https://docs.kurrent.io/clients/
- This project's research lineage: the [handoff doc](kurrentdb-php-client-handoff.md)
