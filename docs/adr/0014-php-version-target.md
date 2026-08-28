# ADR-0014: PHP version target — brief says 8.3, the development machine runs 8.4

- Status: **Accepted (Option B)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Core), §18 of `docs/BRIEF.md`; requirements ENV-1 … ENV-5, ARC-1

## Context
§3 and §18 both state PHP 8.3. The development machine has PHP 8.4.24 installed natively and no Docker, so there is no practical way to run 8.3 locally. Laravel 12 supports 8.2 through 8.4. If `composer.json` declares `^8.3` while development happens on 8.4, code using 8.4-only syntax (property hooks, asymmetric visibility, `new` in initialisers without parentheses) will install and run locally but fail on a production container built on 8.3 — a class of bug that only surfaces at deploy. This is a direct FIXED-versus-environment conflict and must be resolved before the M0 scaffold, because it is baked into `composer.json`, the CI matrix and the production Dockerfile.

## Options

### Option A — Declare `"php": "^8.3"`, run production and the primary CI job on **8.4**, add a second CI job on 8.3 as a compatibility gate
Pros
- Keeps the brief constraint honest: the codebase genuinely runs on 8.3, proven by CI on every pull request.
- Local development on 8.4 is safe because the 8.3 job fails fast on any 8.4-only syntax.
- Widest deployment flexibility; supports operators or hosts stuck on 8.3.
Cons
- Two CI PHP jobs (roughly doubles the Pest run time for the fast suite). Acceptable; the 8.3 job can run the fast group only.
- The team gives up 8.4 language features for the life of the constraint.

### Option B — Move the target to PHP 8.4 everywhere: `"php": "^8.4"`, one CI job, production image on 8.4
Pros
- Local, CI and production are identical — the strongest possible parity, which matters most given ADR-0015 already splits the database and cache layers.
- One CI job, faster pipeline; access to 8.4 features.
- 8.4 has security support well past this project realistic MVP horizon; 8.3 active support has already ended.
Cons
- Deviates from a stated (though not FIXED-marked) line in §3 and §18, so it needs the product owner to say yes.
- Anything that later needs to run on 8.3 (a shared-host deployment of the platform, which is not planned) would be blocked.
- Note: the WordPress plugin is separate and stays at PHP 8.1+ per §3; that constraint is unaffected either way.

### Option C — Declare `"php": "^8.3"` and run everything on 8.3, using a Windows PHP 8.3 build installed alongside 8.4
Pros
- Exact parity with the brief, no compatibility matrix.
Cons
- Two PHP installations on Windows with per-project switching is a persistent source of "wrong PHP on PATH" friction for a solo developer, and Composer scripts would need explicit interpreter paths.
- Buys nothing over Option A, which achieves the same guarantee automatically in CI.

## Recommendation
**Option B**, if the product owner is willing to move the line in §3 from 8.3 to 8.4: perfect environment parity is worth more than 8.3 compatibility that nothing needs, and the environment constraints already force divergence in the database and cache layers, so removing an avoidable divergence is valuable. If the 8.3 line must stand as written, take **Option A** — it preserves the constraint at the cost of one extra CI job. Do not take Option C.

## Consequences if accepted (Option B)
- `composer.json` requires `"php": "^8.4"`; production Dockerfile uses `php:8.4-fpm`; CI matrix is `[8.4]`.
- `CLAUDE.md` and `docs/spec.md` are updated to say 8.4, with this ADR cited as the amendment to §3.
- PHPStan `phpVersion` is set to 80400; Pint targets the same.
- The WordPress plugin keeps `"php": ">=8.1"` and is linted separately against 8.1 (it must run on operator hosting).

## Consequences if accepted (Option A)
- `composer.json` requires `"php": "^8.3"`; CI matrix is `[8.3, 8.4]` with 8.3 running at least the fast group; production Dockerfile uses `php:8.3-fpm`.
- PHPStan `phpVersion` is set to 80300, which flags 8.4-only syntax at analysis time as well.
