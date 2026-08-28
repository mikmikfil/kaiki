---
description: Generate the GitHub issues for a milestone from the spec, with acceptance criteria.
---

Generate the issues for milestone **$ARGUMENTS**.

Delegate to the `architect` subagent. Give it:

- `docs/spec.md` §14 (repository layout and milestones) for the scope of this milestone, and the requirement IDs each bullet maps to.
- The state of the previous milestone's issues (`gh issue list --milestone "..." --state all`) so it knows what actually shipped versus what was deferred, and can carry deferrals forward explicitly instead of losing them.
- `docs/adr/README.md` — every ADR is accepted, but note any whose caveat still applies (ADR-0002's VAT rate and ADR-0022's numbering gap policy both still need an accountant; ADR-0023 is revisited at the M2 close).

Each issue must have: Context (citing requirement IDs), Depends on, Given/When/Then acceptance criteria that a test can verify, Files likely touched, Test plan (naming anything CI-only), Out of scope. Keep each to **one day or less** — split and say why when a bullet is bigger than that.

Labels: `milestone:$ARGUMENTS` plus the relevant `area:*` labels. Assign to the GitHub milestone, creating it if it does not exist.

Create them in dependency order so the numbering reads sensibly, and cross-reference by number.

Report the issue table and anything in the milestone that could not be reduced to a one-day issue.
