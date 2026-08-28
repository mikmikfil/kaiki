---
description: Run the full quality gate, update docs and changelog, and propose a commit message.
---

Close out the current issue.

1. Run, in this order, and report each result verbatim rather than summarising it away:
   - `composer lint` (Pint)
   - `composer stan` (PHPStan level 6 + Larastan)
   - `composer test` (Pest)
   - `npm -w packages/widget run build` — only if `packages/widget` exists and the change touched it
   - the WordPress plugin lint — only if `packages/wordpress-plugin` exists and the change touched it
2. If anything fails, fix it. Do not proceed with a red suite, and do not describe a failing run as "mostly passing".
3. State explicitly which acceptance criteria are verified **locally** and which can only be verified **in CI** (the overselling concurrency test, `app/Domain` coverage, Horizon, PDF snapshots without a local Chrome path).
4. If a route or a payload changed, update `docs/api.md`. If a column or a state transition changed, update `docs/data-model.md`. If a requirement changed meaning, update `docs/spec.md`. Say which you touched and why.
5. Add a one-paragraph `CHANGELOG.md` entry under the current milestone.
6. Propose a commit message in the form `area(scope): summary` — areas: `core`, `availability`, `payments`, `compliance`, `widget`, `wp`, `ops`, `saas`, `docs`, `infra`. Include the issue number.
7. Do not commit until asked.
