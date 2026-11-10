---
description: "Adversarial review (hostile personas) of a diff — executed by a different model, DeepSeek V4.1 Flash, per the adversarial-reviewer skill"
disable-model-invocation: true
---
Use the adversarial-reviewer skill on the requested scope (default: the working tree; pass `since <ref>` for a fixed point). The three hostile personas (Saboteur, New Hire, Security Auditor) MUST run as parallel sub-agents pinned to `opencode-go/deepseek-v4.1-flash` — never on the build model. Gather full-file context and repo facts (CODING_STANDARDS.md, handoff §5.9 wire facts), deduplicate persona findings with the severity promotion rule, and end with a BLOCK/CONCERNS/CLEAN verdict.
