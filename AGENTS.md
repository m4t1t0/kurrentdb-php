# KurrentDB PHP Client

## How to Use This File

**MANDATORY**: This project is documented through agent instruction files. At session start, read this file first — it maps the project — then follow the links in [Deep-Dive Navigation](#deep-dive-navigation) before exploring individual files. Do not start with broad searches.

## What Is This Project

A side project (not tied to a specific application) to build and publish a **pure-PHP gRPC client for KurrentDB** (formerly EventStoreDB). Dual purpose (set 2026-11-05):

1. **Learning** — deep understanding of gRPC, HTTP/2, protobuf, and async PHP by implementing them.
2. **Sharing** — fill the verified gap: no maintained, properly licensed gRPC PHP client; usable via `composer install` on plain PHP.

Key architecture decision (2026-11-05): pure PHP on **amphp** (async HTTP/2 client + protoc-generated stubs) is the first implementation. A FrankenPHP Go extension wrapping the official Go client is a deferred, optional speed variant behind the same shared `KurrentClientInterface`. `ext-grpc` is ruled out.

## Key Files

| File | Purpose |
|---|---|
| `kurrentdb-php-client-handoff.md` | Complete research handoff, verification checklist, roadmap, and **§9 open questions for the grill session** — the refinement agenda. |
| `.opencode/commands/grill-me.md` | The `/grill-me` command used to run the grilling session from this directory. |
| `.opencode/commands/grill-with-docs.md` | The `/grill-with-docs` command: invokes the grill-with-docs skill on the context in `PROMPT.md`. |
| `.opencode/skills/grill-with-docs/` | The `/grill-with-docs` skill: grilling session that also writes ADRs and a glossary as it goes. |
| `PROMPT.md` | Transient workspace file: paste the plan/design context for the next grill-with-docs session here before invoking it. |
| `docs/` | Permanent grill-session outputs: ADRs (`adr/`), product spec, Python-client API mapping note. |
| `GLOSSARY.md` | Project language: domain terms, client API value objects, package artifacts. |
| `packages/contracts/` | Composer package `m4t1t0/kurrentdb-contracts` — frozen `KurrentClientInterface`, value objects, typed exceptions (ADR-0005). |

## Deep-Dive Navigation

| Path | Description | Keywords |
|------|-------------|----------|
| — | No child instruction files yet; single-directory project. | — |
