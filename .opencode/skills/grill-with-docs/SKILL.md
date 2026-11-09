---
name: grill-with-docs
description: A relentless interview to sharpen a plan or design, which also creates docs (ADR's and glossary) as we go.
disable-model-invocation: true
---

Session lifecycle:

1. **Input**: read the plan/design context from `PROMPT.md` (the transient
   workspace file — usually filled by the user before invoking this skill).
2. Call the Skill tool twice, for "grilling" and "domain-modeling". The
   interview runs in rounds until the frontier is empty (shared understanding).
3. **Output**: conclusions land in permanent docs only — `GLOSSARY.md`
   (glossary), `docs/adr/` (new numbered ADRs), `docs/product-spec.md`
   (product rules). Never write documentation into `PROMPT.md`.
4. **Wrap-up (after the user confirms shared understanding and the docs are
   written)**: reset `PROMPT.md` to the blank workspace state by copying
   [prompt-template.md](./prompt-template.md) (a file next to this SKILL.md)
   over it. Do this last, exactly once — the session context itself is
   supposed to be gone. Skip the reset and say so if any conclusion may not
   have been captured in the permanent docs yet.
