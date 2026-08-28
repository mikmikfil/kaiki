---
description: Fetch a GitHub issue, restate its acceptance criteria, and produce an implementation plan for approval.
---

Work on issue **#$ARGUMENTS**.

1. `gh issue view $ARGUMENTS --json number,title,body,labels,milestone,comments` — read the body **and every comment**. Comments carry ADR resolutions and corrections that the body does not.
2. Read `CLAUDE.md`, then the sections of `docs/spec.md` whose requirement IDs the issue cites. Read only the parts of `docs/data-model.md` that cover the tables you will touch (`grep -n "^### " docs/data-model.md` for the map) — it is 2,500 lines, do not read it whole.
3. Restate the acceptance criteria in your own words. If any is ambiguous or untestable, say so now rather than after implementing.
4. Check the issue's "Depends on" line. If it names an issue that is still open, or an ADR that is not `Accepted`, **stop and say so**.
5. Enter plan mode and produce a plan covering: the failing test you will write first, the files you will create or change, the migration ordering consequences (remember SQLite cannot add a foreign key later), anything that can only be verified in CI, and what you will deliberately leave out of scope.
6. Wait for approval. Do not edit anything before the plan is approved.
