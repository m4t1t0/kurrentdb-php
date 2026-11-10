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
| `.opencode/commands/code-review.md` | The `/code-review` command: two-axis Standards/Spec review via the code-review skill. |
| `.opencode/skills/grill-with-docs/` | The `/grill-with-docs` skill: grilling session that also writes ADRs and a glossary as it goes. |
| `.opencode/skills/code-review/` | Review changes since a fixed point along Standards + Spec axes (parallel sub-agents). |
| `.opencode/skills/adversarial-reviewer/` + `/adversarial-review` command | Hostile-persona review (Saboteur/New Hire/Security Auditor) — mandatory before package tags; personas MUST run on DeepSeek V4.1 Flash (`opencode-go/deepseek-v4.1-flash`), never the build model. |
| `.opencode/skills/acceptance-test-first/` | The ATDD process: contract suite first, red→green, capability-skip rules, harness usage. |
| `.opencode/skills/wire-debug/` | The gRPC wire debugging method: netdump tap + h2dump rendering + Go-client byte diff. |
| `.opencode/skills/port-spike/` | Promoting validated spike code into `packages/client`; spike-vs-packages split; stub-replacement plan. |
| `CODING_STANDARDS.md` | Repo coding standards — the Standards-axis input for code-review; process rules live here and in skills, not in agent memory. |
| `PROMPT.md` | Transient workspace file: paste the plan/design context for the next grill-with-docs session here before invoking it. |
| `docs/` | Permanent grill-session outputs: ADRs (`adr/`), product spec, Python-client API mapping note. |
| `GLOSSARY.md` | Project language: domain terms, client API value objects, package artifacts. |
| `packages/contracts/` | Composer package `m4t1t0/kurrentdb-contracts` — frozen `KurrentClientInterface`, value objects, typed exceptions (ADR-0005). |

## Deep-Dive Navigation

| Path | Description | Keywords |
|------|-------------|----------|
| — | No child instruction files yet; single-directory project. | — |
