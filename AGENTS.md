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

## Deep-Dive Navigation

| Path | Description | Keywords |
|------|-------------|----------|
| — | No child instruction files yet; single-directory project. | — |
