---
description: Run security-reviewer then architect over the current diff.
---

Review the current diff (`git diff` against the base branch, plus untracked files).

1. Delegate to the `security-reviewer` subagent. Give it the diff scope and tell it which tenant-owned models, routes, or personal-data fields the change touches.
2. Delegate to the `architect` subagent to check for architectural drift: business logic that leaked out of `app/Domain` into a controller or a Filament resource, pricing or availability computed outside `app/Domain`, a query on tenant-owned data that bypasses `VesselCalendar` or the tenant scope, a package installed outside the approved list, a migration that will not run on SQLite.
3. Merge both outputs into one list: **blocking / should-fix / nit**, each with a `file:line`.
4. Fix everything blocking. For should-fix items, either fix them or say plainly why not. Do not silently drop a finding.
5. Report what neither reviewer could check — that is usually the interesting part.
