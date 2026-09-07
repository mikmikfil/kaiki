# CI — what runs, what blocks, and how to fix it

Implements ENV-10, ENV-22, ENV-23, ENV-25, TST-1, SEC-12.

`docs/spec.md` is the contract; this file is operational detail about the pipeline that enforces it. Where the two disagree, the spec wins.

---

## Branch protection

Make **one** required status check on `main`:

```
CI passed
```

That is the `ci-passed` job in `.github/workflows/ci.yml`. It succeeds only when every other job in that workflow succeeded, so adding a job later makes it required automatically — nobody has to remember to tick a new box, and nobody can forget to. `tests/Unit/CiGatesTest.php` fails the build if a job is ever defined without being aggregated, and if this document and the workflow stop agreeing.

Also enable **Require branches to be up to date before merging**. Without it, two branches that are each green can merge into a red `main` — which is precisely how a migration lands beside a snapshot that never saw it.

### The jobs it aggregates (ENV-23)

<!-- required-checks:start -->

| Job | What a red build means |
|---|---|
| `static-checks` | Pint would reformat something, PHPStan level 6 found an issue (TST-10), EL and EN disagree on a lang key or a string is hardcoded (I18N-2, I18N-3), or a route is missing from `docs/api.md` §5 (ENV-28). **The failing step is named**, so the four are still told apart at a glance. |
| `test-suite` | Pest against SQLite — the stack developers actually run on — with the coverage gate on `app/Domain` (TST-1, ENV-22). One run, under PCOV, rather than the same two thousand tests twice. |
| `test-mysql` | Pest against MySQL 8 and Redis, plus the `mysql` group — which must execute for real, not skip (ENV-11, TST-8). |
| `runtime-gates` | A cross-tenant leak — the condition ADR-0001 was accepted on — or the availability group failing under a **third** machine timezone (ENV-14). UTC would hide an unconverted value and Europe/Athens an unconverted tenant, so the runner is set to `America/Los_Angeles`. |
| `pdf-chromium` | The `chromium` group — the e-ticket PDF actually rendered by Browsershot (ENV-20, BKG-13.1). Excluded from `composer test`, because a developer with no browser must not get a false green, which makes this the **only** place the PDFs are ever rendered. |
| `widget-build` | The widget failed to build (WGT-1), exceeded its **80 KB gzipped** budget (WGT-2, NFR-3), tripped one of the three bundle guards — a hardcoded brand colour (WGT-9), arithmetic on a price (WGT-13), or the two locale bundles disagreeing (WGT-14) — or its Vitest suite went red. |
| `widget-e2e` | The **smoke subset** of the end-to-end run (TST-3): all four mounts, in a real Chromium, against a real server with a seeded database and the freshly published bundle. A red build here means the widget does not work in a browser — which no other job on this list can tell you. The full suite runs nightly. |
| `node-checks` | The WordPress plugin standard (WPP-11). Still a stub until M4. |
| `security-audit` | A high or critical advisory in Composer or npm dependencies (SEC-12). |
| `migrate-from-zero` | The migrations no longer produce the committed schema (ENV-10). |

<!-- required-checks:end -->

**Ten jobs, and it used to be sixteen (#90).** On a private repository every job is billed separately
and rounded up to the minute, and each one pays for a runner start, a checkout, a PHP install and a
`composer install` before it does anything. That overhead is about a minute and a half a job — so
sixteen jobs spent roughly twenty-four minutes of it to do perhaps six minutes of work. The cost was
the *number* of jobs, not the work, so the four fast static checks were merged, the suite stopped
running twice (once plain and once under coverage), the two must-execute gates joined the timezone
run, and the four Node stubs shared one runner — **two of which have since split back out**, the
widget build with #106 and the end-to-end run with issue 111, each on the day it stopped being a
stub and started taking real time. **Nothing was dropped**: every command that ran before
still runs, as a named step.

`pdf-chromium` installs Puppeteer without its bundled Chromium and points it at the runner's own
Google Chrome — the browser is already on the image, and a second 150 MB download every run buys
nothing.

The widget checks left `node-checks` in **#106**, which is when they stopped being stubs and got a
bundle to measure — the split the previous version of this paragraph promised. WGT-2's 80 KB budget
now has its own red square, which is what it deserves: it is the constraint that shapes every decision
inside the widget, and a budget buried in a job with three stubs is a budget somebody raises rather
than enforces.

The two that remain in `node-checks` are stubs still, and leave the same way: `e2e` with #111 and
`plugin-lint` with M4.

The **branch-protection** list never has to change again — it is one entry, `CI passed`. The job list behind it will still grow, and two are known to be owed: the ENV-28 Scramble docs-drift check (issue #11) and the M3 replacement of the Playwright stub with a real run. Each becomes required the moment it joins `ci-passed`'s `needs:`, with nothing to configure. The third, ENV-14's alternate-timezone run, arrived with #32.

---

## The coverage gate (TST-1, ENV-22)

- Scoped to **`app/Domain` only**, via `phpunit.coverage.xml`. A whole-application threshold rewards writing tests for Filament resources instead of for the engine, which is the one part of this system that must not be wrong.
- The threshold lives in **one place**: the `--min=80` in `composer test:coverage`. Raising it is a one-line change, and the workflow never repeats the number.
- Needs PCOV, which the local Windows stack does not have. **Coverage is a CI-only figure** — `composer test` deliberately runs without it.

---

## The schema snapshot (ENV-10)

`migrate-from-zero` migrates an **empty** MySQL 8 database and compares the resulting schema against `database/schema/mysql-schema.snapshot.sql`. It is the only rehearsal of the path a brand-new production database takes exactly once.

### Refreshing it after a migration change

```
composer schema:snapshot
```

> **Point it at a throwaway database only.** Laravel's dumper passes the password on the `mysqldump` command line, so it is visible in the process list for the life of the dump. That is fine for the ephemeral root/root container in CI; it is not fine for staging.

One command — but it needs a MySQL 8 connection, and the local stack is SQLite (ADR-0015), so in practice:

1. Push the branch. `migrate-from-zero` goes red and says the snapshot is stale.
2. Download the **`mysql-schema-snapshot`** artifact from that run — it is uploaded on every run, red or green, and it is the output of exactly that command.
3. Commit the file. The job goes green.

`tests/Unit/CiGatesTest.php` also checks a fingerprint of the migrations recorded in the snapshot's header, so "changed a migration, forgot to refresh" fails locally in seconds instead of after a MySQL round-trip.

The MySQL service image for this job is pinned to a **patch** version, unlike the floating `mysql:8.0` in `test-mysql`. The comparison is byte-for-byte, and the server's rendering of display widths and default collations moves between patches. **Bumping that image means regenerating the snapshot** in the same commit.

### Why the file is not called `mysql-schema.sql`

Because Laravel would load it. `MigrateCommand::prepareDatabase()` runs a dump found at `database/schema/{connection}-schema.dump` or `{connection}-schema.sql` **instead of** the migrations whenever no migration has run yet. Committing the snapshot there would mean `migrate-from-zero` compared the snapshot with itself, and `test-mysql`'s `migrate:fresh` stopped exercising the migrations — and neither would have gone red to say so. Laravel checks `database/schema/{connection}-schema.dump` **first** and `{connection}-schema.sql` second, and the connection name is part of the filename — so a local `php artisan schema:dump` on the SQLite stack writes `sqlite-schema.sql` and silently neuters that developer's own `migrate`. `.gitignore` therefore ignores both extensions for every connection, the job asserts the migrate output never says `Loading stored database schemas`, and a test asserts no such file exists.

---

## The dependency audit (SEC-12)

`composer audit` and `npm audit` both run. **High, critical and unclassified fail the build**; medium, moderate, low and info are printed as a notice with the package and title, for a human to open an issue about.

Both tools exit non-zero on any finding, so the exit code is discarded and the severity read from the JSON. Everything about that reading **fails closed**: Composer's severity is nullable, so an advisory with no severity blocks rather than sorting into the harmless pile; output that is not a JSON object, or that lacks the key the counts read, fails the job rather than counting zero; and an advisory suppressed through `config.advisories.ignore` fails too, because muting one is a decision that needs a human rather than a quietly green build.

The audit and schema jobs live in `.github/workflows/dependency-audit.yml` and `.github/workflows/schema-drift.yml` and are called by both `ci.yml` and `nightly.yml`. Extracted rather than copied: two divergent copies of a severity threshold is how one of them quietly stops blocking anything.

---

## Nightly (ENV-25)

`.github/workflows/nightly.yml`, 02:00 UTC, plus manual dispatch. Runs the dependency audit and the schema-drift check — the checks that can fail without anyone pushing anything.

Two of the four things ENV-25 lists do not exist yet and are recorded as comments in that file rather than omitted:

- the **full Playwright suite** including the WordPress site run and the axe-core pass (TST-3, A11Y-1) — arrives with the widget in **M3**;
- the **NFR-1 availability benchmark**, 150 ms at p95 — first runs at the **close of M2**, pulled forward from M8 by AVL-12b, because missing it is the documented trigger to reopen ADR-0023 and that is only affordable while the shape is still cheap to change.

---

## Running the gates locally

| Gate | Local | Notes |
|---|---|---|
| Pint, PHPStan, Pest | `composer ci` | The SQLite stack. |
| i18n parity and lint | `composer i18n:check` | Runs fully locally; no database needed. |
| OpenAPI contract drift | `composer api:docs && composer test:api-docs` | Runs fully locally. `build/` is gitignored. |
| Widget and plugin stubs | `npm run widget:build`, `npm run widget:size`, `npm run e2e`, `npm run plugin:lint` | Stubs until M3/M4. |
| Coverage | — | CI only; no PCOV locally (ENV-22). |
| MySQL suite, schema snapshot, audits | — | CI only; no local MySQL (ADR-0015). |
