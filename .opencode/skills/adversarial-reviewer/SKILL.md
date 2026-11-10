---
name: adversarial-reviewer
description: "Tough, critical review of a diff or change before merge, through hostile reviewer personas — run by a DIFFERENT model (DeepSeek V4.1 Flash) than the one that built the code. Use when the user wants a change torn apart, a harsh second opinion, or when the build model's own review felt agreeable. Original by ekreloff (MIT), claude-skills repo; adapted for this repo's model routing."
disable-model-invocation: true
---

# Adversarial Code Reviewer (model-routed)

Adversarial review that forces a genuine perspective shift by breaking the
shared mental model twice:

1. **Different personas** — Saboteur, New Hire, Security Auditor; each with
   different priorities, fears, and definitions of "bad code".
2. **Different weights** — all three personas run as sub-agents pinned to
   **DeepSeek V4.1 Flash** (`opencode-go/deepseek-v4.1-flash`), *never* on the
   build model (GLM-5.3 Flash), because the model that produced the code
   shares its own blind spots. The build model's job in this skill is
   mechanical only: gather context, drive the sub-agents, deduplicate,
   promote severities, format the report.

**If the DeepSeek model is unavailable, STOP and tell the user** — running
this skill's personas on the build model defeats its entire purpose.

## Usage

```
@adversarial-reviewer                     # review working tree (staged+unstaged; falls back to last commit)
@adversarial-reviewer since ad05394       # review a fixed point (commit/tag/branch)
```

## Workflow

### Step 1 — gather changes (mechanical, build model)

- No argument: `git diff` + `git diff --cached`; both empty → `git diff HEAD~1`.
- `since <ref>`: `git diff <ref>...HEAD` (three-dot; verify the ref resolves
  and the diff is non-empty first).

### Step 2 — full context (mechanical, build model)

For every file in the diff:

1. Read the **full file**, not just changed lines — bugs hide in
   interactions.
2. Identify the change's purpose and the packages it touches
   (`packages/contracts`, `packages/client`, `tests/`, `spike/`).
3. Collect what each persona needs, and paste it into their prompt (they run
   remotely and only see what you give them):
   - the relevant part of `CODING_STANDARDS.md` (New Hire's rules)
   - the wire facts from `kurrentdb-php-client-handoff.md` §5.9 (Saboteur's
     protocol truths: trailers, uuidOption requirement, read_direction,
     AllOptions.end=3, no content-length, proto3 omission of zero scalars)
   - the security surface relevant to the change under review (Security
     Auditor)

### Step 3 — three personas on DeepSeek (parallel sub-agents)

Issue **three `subagent` calls in one block**, each:

- `agent: general`
- `model: opencode-go/deepseek-v4.1-flash`
- its own prompt from the persona briefs below (include the gathered context).

Each persona MUST produce at least one finding. If a persona returns "LGTM"
or "no issues", it is reported as **FAILED TO REVIEW**, not folded away —
re-run that persona once telling it pass-1 said nothing, then flag it in the
report.

Do not run the personas yourself. Do not let sub-agents "be brief to
impression" — the prompts instruct them to be direct.

**Persona 1: The Saboteur** — *"I am trying to break this code in production."*
Priorities: unvalidated input; state that can become inconsistent; concurrent
access; error paths that swallow or mislead; assumptions about data format /
size / availability that the server could violate; off-by-one, overflow,
null-derefs; resource leaks (sockets, subscriptions, generator bodies mid-
`suspension`); **genre-specific in this repo**: wire-shape assumptions
(correlate with §5.9 facts), the sync-Revolt suspension pattern (nested
`suspension->suspend()`, generator-resume replays), interplay with
`streamFrames`/`ContentStream` chunk heuristics, and the
same-event-twice/idempotent-retry contract.

**Persona 2: The New Hire** — *"I joined today; I must modify this in six
months with zero original-author context."*
Priorities: names that don't reveal intent; needing 3+ files open to follow
one path; magic numbers / uncited wire fields; functions that do X+Y+Z;
inconsistency with `CODING_STANDARDS.md` and the glossary's `_Avoid_` list;
comments that say *what* instead of *why*; tests that test implementation
rather than behavior. The **handoff §5.9 + CODING_STANDARDS} rule-8 wire
citation rule is this persona's home turf.

**Persona 3: The Security Auditor** — *"This code will be attacked; find the
hole first."*
Priorities (repo-specific): **credentials in `kurrentdb://` URIs** (logged?
cached? leaked into error messages?); **TLS disabled by default in v0.1**
(`?Tls=false` mandatory today — flag any place an insecure default could
become production behavior); server-directed `content-type`, metadata, and
`grpc-message` interpolation into thrown messages (spoofing / log injection);
connection-name / requires-leader header spoofing; shell-facing scripts
(`sh`, `docker run`, `curl`) interpolating file paths or model output;
composer.lock / dependency pin risk; committed dump files (wire captures can
reveal credentials); the spike's Docker exposure (ports published to host).

**Every persona:** find at least one real issue; direct language, no
hedging; substance before style; read code bottom-up; state each function's
contract before reading its body; assume every external call fails; if the
code is genuinely bulletproof, name the most fragile assumption it's built
on.

### Step 4 — adjust, deduct, dedupe (mechanical, build model)

1. Merge duplicates (same issue, multiple personas).
2. **Promotion rule**: a finding caught by 2+ personas moves up one severity.
3. Format the final report (below). The build model adds *no* new findings of
   its own and softens nothing — its only judgment calls are severity via
   the promotion rule and verdict arithmetic.

## Severity

| Severity | Meaning | Action |
|---|---|---|
| **CRITICAL** | data loss, security breach, production outage | blocks merge |
| **WARNING** | edge-case bugs, perf/degradation, maintainability traps | fix or explicitly accept with justification |
| **NOTE** | style, minor improvement, docs gaps | author's discretion |

## Output format

```md
## Adversarial Review: <what was reviewed>

**Scope:** <files, lines, change kind>
**Model:** personas on opencode-go/deepseek-v4.1-flash
**Verdict:** BLOCK / CONCERNS / CLEAN

### Critical findings
### Warnings
### Notes
### Summary
```

- **BLOCK** — ≥1 CRITICAL. **CONCERNS** — no criticals, ≥2 warnings.
  **CLEAN** — notes only.

## Anti-patterns (from the original — keep verbatim as guidance)

- "LGTM, no issues found" → every change has at least one risk or assumption.
- Cosmetic-only findings without substance.
- Pulling punches / "this might possibly be a minor concern…".
- Restating the diff instead of finding what's wrong with it.
- Ignoring test gaps: new behavior without a contract test is a finding.
- Reviewing only the changed lines.

## When to use

- Before merging self-authored PRs with no human reviewer.
- When the build model said "looks good to me" on its own code — that is the
  moment the shared-weights trap is closed by running this instead.
- Security-sensitive changes: connection handling, auth, TLS, metadata
  plumbing.
- **By policy in this repo**: mandatory before tagging any package release
  (the contracts 1.0.0 moment) — if not run, note the skip, why, and who
  accepted it.
