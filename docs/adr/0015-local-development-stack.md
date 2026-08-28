# ADR-0015: Local development stack (SQLite, no Docker) versus the CI and production stack

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Infra), §13, §14 M0.2, §17, §18 of `docs/BRIEF.md`; requirements ENV-1 … ENV-30

## Context
§14 M0.2 asks for Docker Compose (app, horizon, scheduler, mysql, redis, chromium), Caddy and a Makefile as the local development environment, and §18 lists `make up` as the first command. The actual development machine is **Windows with PowerShell, PHP 8.4 and Composer installed natively, with no Docker, no local MySQL, no local Redis, no `make` and no bash**. MySQL 8 and Redis exist only in GitHub Actions and on the Hetzner production server. This is a hard environment constraint that overrides §14 and §17, and it must be recorded explicitly so that no future agent "fixes" the missing Makefile by adding one. It defines M0 and every subsequent testing decision.

## Options

### Option A — Three-tier stack with an explicit parity contract
| | Local (Windows) | CI (GitHub Actions, Ubuntu) | Production (Hetzner) |
|---|---|---|---|
| Database | SQLite file | MySQL 8 (service container) | MySQL 8 (Compose) |
| Cache / locks | `database` store | `redis` and `database` (matrix) | `redis` |
| Queue | `database` driver, `queue:work` | `database` and `redis` | `redis` + Horizon |
| Scheduler | `schedule:work` on demand | not run | supervised container |
| PDFs | Browsershot against local Chrome, opt-in | Chromium in the runner | chromium container |
| Web | `php artisan serve` + `npm run dev` | none | Caddy + php-fpm |
| Task runner | Composer and npm scripts | workflow steps | Compose |

Docker Compose, Caddyfile and the chromium image exist in `docker/` but are **production-only artefacts**, never invoked locally. Parity is enforced by rules rather than by identical infrastructure: no raw engine-specific SQL outside a guarded helper, no direct `Redis::` calls, no JSON-path ordering (ADR-0008), migrations must run green on both engines in CI, and anything that genuinely needs MySQL or Redis is a tagged test group that CI runs and local runs skip loudly (ADR-0005, ADR-0006).
Pros
- The developer can run the whole application, the full fast test suite, both Filament panels and the widget dev server with zero infrastructure.
- Feedback loop measured in seconds; SQLite in-memory makes the Pest suite fast.
- CI becomes the parity authority, which is where it belongs, and CI is a required check.
Cons
- Real divergence risk: MySQL strict-mode differences, `utf8mb4` collation and Greek sorting, date/time function behaviour, JSON functions, `FOR UPDATE`, index-length limits and enum handling all differ from SQLite. Some bugs will only appear in CI.
- Developers cannot reproduce a MySQL-only failure locally without spinning up an external instance.
- Horizon is unusable locally, so queue behaviour is exercised with a different driver than production.

### Option B — Require a cloud MySQL and Redis for local development (managed instance or a Hetzner dev database)
Pros
- Near-perfect parity with production; every test runnable locally.
Cons
- Every test run needs the internet and adds tens of milliseconds per query; the Pest suite becomes slow enough to stop being run.
- Shared dev database plus destructive migrations is a recipe for lost work.
- Ongoing cost and credentials on a developer laptop.

### Option C — Install MySQL and Redis natively on Windows
Pros
- Parity without Docker.
Cons
- Explicitly excluded by the environment constraints. Redis on Windows is unofficial and stale; maintaining native services on a development laptop is exactly the friction Docker was invented to remove.

## Recommendation
**Option A**, recorded as an explicit amendment to §14 M0.2 and §18. Accept the divergence, name it, and manage it with: (1) a required CI job on MySQL 8 plus Redis running the full suite on every pull request; (2) tagged groups (`mysql`, `chromium`, `external`) that fail the build if they report zero executed tests in CI; (3) a rule that migrations never use engine-specific SQL; (4) a nightly CI job that runs migrations from scratch on MySQL and asserts a schema dump matches a committed snapshot.

## Consequences if accepted
- §14 M0.2 is rewritten: "Docker Compose and Caddy for the Hetzner production target; `.env.example` for local SQLite; Composer and npm scripts instead of a Makefile."
- No Makefile, no shell scripts. Commands are `composer dev`, `composer test`, `composer test:fast`, `composer test:mysql`, `composer lint`, `composer analyse`, `composer ci`, `npm run widget:build`, `npm run widget:dev`, `npm run e2e`.
- `.env.example` defaults to `DB_CONNECTION=sqlite`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `MAIL_MAILER=log`, `SCOUT`/Horizon disabled.
- `database/database.sqlite` is gitignored; a Composer post-install script creates it if missing.
- Horizon is installed but only enabled when `QUEUE_CONNECTION=redis`; local queue work uses `queue:work --tries=1`.
- WordPress plugin tests run against a WordPress site URL taken from `.env` (`KAIKI_WP_TEST_URL`), not `wp-env`; §8 and §14 M4.27 are amended accordingly.
- `CLAUDE.md` replaces `make up` in the Commands section.
