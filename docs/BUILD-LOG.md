# Kaiki — build log

What has actually been built, and the evidence it works. One entry per issue, newest first.

Each entry records the **verification actually run** and its **real output** — not what was supposed to happen. Where something can only be checked in CI, it says so rather than claiming a local pass. Where an issue was delivered differently from how it was written, the deviation is stated with its reason.

`CHANGELOG.md` is the narrative for a reader; this file is the audit trail.

---

## Status

| | |
|---|---|
| Milestone | **M1 — Catalogue and availability engine** |
| M0 | closed by #11 — #1 … #12, with #13 and #14 moved to `M8 — Launch & deployment` |
| M1 | #15 |
| Pulled forward | #44, a read-only slice of M7's `/admin` |
| Local stack | Laravel 12.68 · PHP 8.4.25 · SQLite · database/file drivers |
| Quality gate | Pint · PHPStan level 6 + Larastan · Pest (351) · **cross-tenant isolation gate** · **ENV-8 JSON-path gate** · EL/EN parity · OpenAPI drift · coverage of `app/Domain` · dependency audits · schema drift — all green |
| Deployment | Deliberately last (#13, #14 moved to `M8 — Launch & deployment`) |

**Open, not blocking, and not mine to close:**

| What | Who | Needed before |
|---|---|---|
| VAT **rates** (mechanism settled in ADR-0002; the numbers are not) | accountant | M6 |
| Invoice-numbering **gap policy** (ADR-0022) | accountant | M6 |
| Revisit ADR-0023 against the NFR-1 p95 benchmark | benchmark result | end of M2 |
| **ADR-0024** — two-factor authentication, the mechanism and its timing | product owner | SEC-15 (see #9) |

---

## #15 — Translatable infrastructure: fallback on attributes, search and sort columns, the ENV-8 gate

**Files:** `app/Observers/SearchIndexObserver.php`, `app/Rules/TranslatableRequired.php`, `app/Exceptions/MissingTranslationException.php`, `app/Support/Locale/TranslationValue.php`, `app/Console/Commands/BackfillTranslationsCommand.php`, `app/Models/Concerns/{HasKaikiTranslations,HasTranslatableSearch}.php`, `config/kaiki.php`, `lang/{el,en}/validation.php`, `tests/Support/{Translatable,Query}/**`, `tests/TestCase.php`, six test files

Completes the work `c27b2f2` landed as deliberate WIP. That commit shipped the two traits, the contract and `LocaleResolver::required()` and said so in its own message: the observer they reference did not exist, `composer stan` was red, and the rule, the backfill, the config block, the gate and every test were unwritten.

### The red build was red in a way PHPStan could not see

`composer stan` on `c27b2f2` reported **two `trait.unused` errors and nothing else**. It never mentioned the missing `SearchIndexObserver` that `HasTranslatableSearch::boot()` references, because PHPStan skips a trait no class uses — so the one real defect on the branch was invisible behind a lint about tidiness. `TranslatableFixture` is what changes that: it is the first class to use either trait, and with it the traits are analysed like any other code.

### A bug found while writing the tests, in code that was already committed

The §1.6 requirement is enforced twice — by the observer for imports and the API, by `TranslatableRequired` for a person looking at a form. Written as two loops they disagreed, and the disagreement had a direction.

`getTranslations()` drops null and empty-string entries but **keeps `"   "`**. So a whitespace-only English title was refused by the form and accepted by the observer: the only writer that could get the value into the database was the CSV import, which is the one writer with no form in front of it. `TranslationValue::missingLocales()` is now the single implementation both call — and the same class decides blankness for the I18N-5 fallback, so "empty" means one thing in all three places. The whitespace case is a test, and it failed before the fix.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | `{"tool":"pint","result":"passed"}` |
| `composer stan` | `[OK] No errors` — from 2 `trait.unused` on `c27b2f2` |
| `composer test` | **351 passed** (1121 assertions), 56 new; baseline was 295 |
| `composer test:fast` | 346 passed, **8.61s** (budget: under 30s) |
| `composer i18n:check` | 125 passed |
| ENV-8 gate, green half | no `json_extract`, `JSON_UNQUOTE`, `->>` or JSON-path column anywhere in `app/` or `database/` |
| ENV-8 gate, red half | all six forbidden shapes detected in a permanently-wrong fixture; four supported shapes not flagged |
| Observer writes in one statement | asserted by reading `search_index` back **out of the database**, not off the model |
| Two-engine folding | tonos, final sigma and case folded on both sides in PHP; no SQL collation relied on |
| Backfill repair | stale columns rebuilt; `updated_at` unchanged (`2026-01-01` before and after) |
| Backfill drift check | `--dry-run` exits **1** on drift and writes nothing; exits 0 clean |

### The eight acceptance criteria, against the issue text

Read after the fact — `gh` was authenticated once the work was already committed as `d788863`, so the issue body and its one comment were checked against what had been built rather than the other way round. Seven pass as written; one cannot be satisfied portably and is answered below.

| # | Criterion | Where |
|---|---|---|
| 1 | Attribute read in `el` then `en`, missing locale falls back per I18N-5 | `TranslatableFallbackTest` — one test per step, plus the de-duplication case and the short-circuit |
| 2 | Empty locale refused for fields required in both, message itself localised | `RequiredTranslationsTest` — including the EL message asserted in Greek |
| 3 | Observer writes folded, final-sigma-normalised, lower-cased concatenation of all locales to a plain column | `TranslatableSearchIndexTest` — **"indexed" is the exception, see below** |
| 4 | `καΐκι` and `καικι` match the same records | `TranslatableSearchIndexTest` — the issue's own example verbatim, four spellings including `ΐ` (two marks on one letter) |
| 5 | A search ending in final sigma matches stored medial sigma | `TranslatableSearchIndexTest` — asserted in both directions |
| 6 | Static check fails CI naming `file:line` | `NoJsonPathQueryTest` — reports `{file}:{line} — "{match}" ({why})`; runs in the `i18n` CI job via `composer i18n:check` |
| 7 | Ordering uses the companion column, not the JSON | `TranslatableSortTest` — Greek and English orders that genuinely disagree |
| 8 | A new model's only work is declaring the attribute lists | `TranslatableAdoptionCostTest` — reflection over the fixture: **zero methods** of its own |

**AC 3 says "plain indexed column", and `search_index` is not indexed.** The sort columns are (`varchar(191)`, one index each); the haystack is a `text` column with none, and no declaration would have worked:

- MySQL 8 **refuses** an index on a `TEXT` column without a key length — `$table->index('search_index')` is an error, not a slow query.
- SQLite has no prefix indexes, so there is no single portable declaration (ENV-12 forbids branching on the driver).
- A prefix index would buy nothing regardless: the search is `LIKE '%term%'`, and a leading wildcard cannot use a B-tree.
- The index that *would* help is `FULLTEXT`, which `docs/data-model.md` §0 forbids outright for the same both-engines reason.

`docs/spec.md` CAT-6 — which is authoritative over the issue text — asks for "a single per-row `search_index` text column containing all locales concatenated and accent-folded" and does **not** say indexed; ADR-0008's "plain, indexed" is written around the `*_sort_{locale}` example. So catalogue search is a tenant-scoped scan over tens to hundreds of rows, and the answer at real volume is the search backend ADR-0008 already parks as a future ADR. **Flagged rather than silently skipped, and the reasoning is in the migration beside the column.**

### Only verified on SQLite

Local runs on SQLite (ENV table above). Three things here can only be confirmed by the CI MySQL job:

1. The fixture table's `json` columns and its two `varchar(191)` indexes on MySQL 8 — the 191 length exists precisely for the `utf8mb4` index-prefix limit that SQLite does not have.
2. That registering the test-only migration path from `TestCase::refreshApplication()` behaves the same under MySQL. This is the reason the fixture table is a migration rather than a `Schema::create()` in a `beforeEach`: DDL inside `RefreshDatabase`'s transaction is harmless on SQLite and an implicit commit on MySQL, so the wrong choice would leak only in CI.
3. That `LIKE` against `search_index` returns the same rows on both engines. Both sides are folded in PHP specifically so it should — but "should" is what this project's environment split exists to stop anyone saying.

### Deviations and decisions worth naming

1. **The issue text was read only after the work was committed.** `gh` was unauthenticated for the whole implementation (`gh auth status`: *"You are not logged into any GitHub hosts"*), so `d788863` was built from the remaining-work list `c27b2f2` wrote for itself, checked against `docs/spec.md` (I18N-4, I18N-5, I18N-7, CAT-6, ENV-8, EXT-7), ADR-0008 and `docs/data-model.md` §1.6 / §3.14 — the contracts the issue cites. The acceptance criteria were then verified against the issue in the table above; the gap it exposed was AC 3's word "indexed", and two ACs (4 and 5) needed their literal examples added as tests rather than approximations of them.
2. **`GreekTextNormalizer` was not written.** The issue's file list names `app/Support/Text/GreekTextNormalizer.php`, but its own comment says *"Accent folding reuses the shared helper from #12 — do not write a second one"*, and #12 shipped that helper as `app/Support/Text/GreekText.php` with `tests/Unit/Text/GreekTextTest.php`. The comment wins over the file list; a second normaliser is the exact duplication ADR-0008's acceptance note forbids.
3. **`config/kaiki.php` gained the required-locale policy only.** The issue asks for "supported locales, required-in-both-locales field policy" in that file; supported locales already live in `config('app.available_locales')` and per-tenant in `tenants.supported_locales`, both shipped by #12, and moving them would give the same question two homes.
4. **`MissingTranslationException` was not on that list.** `docs/data-model.md` §1.6 is explicit — *"a model observer rejects a translation set missing `el` or `en`"* — and a rule that is only ever a form error is not a rejection. It is thrown from the observer and expected to be unreachable in normal use; the humane version is `TranslatableRequired`.
5. **`app/Rules` added to the I18N-2 scan.** A validation rule is a sentence an operator reads. It was not scanned because no rule existed until now.
6. **The backfill runs per tenant.** Not stated anywhere as a requirement, but a sort key resolves against `tenants.default_locale`, so a global run would rewrite a Greek operator's sort keys with English text. `--tenant=` narrows it, mirroring §1.9's `kaiki:reconcile-counters [--tenant=]`.
7. **`--dry-run` exits 1 on drift.** A preview would exit 0; this is meant to be gateable from the nightly workflow. It is **not yet wired into** `.github/workflows/nightly.yml` — there is nothing to backfill until #16 creates the first translatable table, and a nightly job over zero models is noise.
8. **PHP 8.4 is not first on this machine's PATH.** `php` resolves to `C:\Users\Mike\php83\php.exe` and every `composer` and `phpstan` invocation dies on Composer's platform check (*"Your Composer dependencies require a PHP version >= 8.4.1. You are running 8.3.33"*). Everything above was run with `C:\Users\Mike\php84` prepended. Worth fixing in the environment rather than per command.

---

## #10 — API keys in the panel: list, create with a one-time reveal, revoke

**Files:** `app/Filament/App/Resources/ApiKeyResource.php`, `.../ApiKeyResource/Pages/ListApiKeys.php`, `resources/views/filament/api-key-reveal.blade.php`, `lang/{el,en}/api_keys.php`, `config/kaiki.php`, `app/Enums/ApiScope.php`, `tests/Support/OperatorUser.php`, three test files

**The first Filament resource in the project**, so it sets the pattern M1 copies. It also closes M0 item 4: the #6 domain has been complete since then but reachable only from a test.

### The reveal, and why it is not a create page

`CreateRecord` redirects when it finishes. Carrying a plaintext key across a redirect means the session or a flash bag — CNV-13 forbids both, and both outlive the moment the operator is looking at the screen.

So creation is a **modal action**: mint, hand to a `#[Locked]` property, `replaceMountedAction('reveal')`, all inside one Livewire round trip. Filament resolves a `revealAction()` method by name, so the reveal is mountable without also rendering a button nobody should press — there is nothing to reveal until a key has just been made.

Clearing the property hangs off `unmountAction()`, not the action's `->after()`. `->after()` fires when an action *runs*; the reveal modal has no submit button and never runs. Escape, the X and "I have saved it" all land in `unmountAction()`. **The test caught this** — it asserted null after dismissal and got the key back.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **190 passed** (688 assertions), 17 new |
| Reveal shown once | asserted, plus a reload asserting the key is absent |
| Revocation end to end | panel button → API returns **401** for that key |
| Role matrix | `manager` and `crew` both refused the page |
| Forbidden scope | publishable + `webhooks.receive` → form error, no row written |
| EL/EN parity | key sets identical both directions, placeholders preserved |
| Manual | signed in as maria (EL) and elena (EN); created, revealed, reloaded, revoked |

### A bug in #6, found by the locale test

`ApiScope::label()` called `__("api.scope.{$this->value}")`. Scope values contain a dot, and Laravel reads a dot in a translation key as a path separator — so it looked for `scope → products → read`, found nothing, and returned the key itself. **It had never worked.** Nothing rendered those labels until this form did, so nothing caught it; operators would have seen `api.scope.products.read` beside a checkbox. Now fetches the array and indexes it.

Worth noting what this says about the I18N-3 parity check: identical key sets in both files would not have caught this. Both files were equally correct and equally unreachable.

### SEC-16, deliberately small

A structured log line — actor, key prefix, tenant, and the record's own timestamp. There is no audit table anywhere in `docs/data-model.md`, and SEC-16's list names deletions, cancellations, refunds and purges, not key revocation. Inventing a general audit log is a **DECIDE** that stops for a human (CLAUDE.md); doing it silently while building a form is exactly how an architecture arrives without anyone choosing it. Flagged rather than assumed.

### Deviation from the issue as written

The issue names `app/Filament/Resources/ApiKeyResource.php`. `AppPanelProvider` discovers `app_path('Filament/App/Resources')`, so the resource follows the panel. It also lists `app/Filament/Actions/RevokeApiKeyAction.php` as a separate class; the revoke stayed a table action calling `RevokeApiKey` directly, because a dedicated Filament action class with one caller is indirection without a second reader.

---

## #4 — CI gates: coverage, dependency audits, migration-from-zero, widget and plugin jobs

**Files:** `.github/workflows/{ci,nightly,dependency-audit,schema-drift}.yml`, `app/Console/Commands/SchemaSnapshotCommand.php`, `database/schema/mysql-schema.snapshot.sql`, `phpunit.coverage.xml`, `tests/Unit/CiGatesTest.php`, `docs/ci.md`, `composer.json`, `bootstrap/app.php`, `.gitignore`

With this, **`main` can be branch-protected honestly**: the check list ENV-23 asks for now exists, and one aggregating check makes all of it required.

### The deviation, and why it is not optional

The issue names `database/schema/mysql-schema.sql`. That is the path Laravel itself loads — `MigrateCommand::prepareDatabase()` calls `loadSchemaState()` whenever `! hasRunAnyMigrations()`, and executes the dump **instead of** the migrations. Committing the snapshot there would have meant `migrate-from-zero` comparing the snapshot with itself, and `test-mysql`'s `migrate:fresh` no longer exercising the migrations — both of them reporting green while verifying nothing.

So: the file is `mysql-schema.snapshot.sql`, `.gitignore` blocks `/database/schema/*-schema.sql`, a test asserts no file sits at the loaded path, and the job greps the migrate output for `Loading stored database schemas` and fails on it. Four guards for one trap, because this is the failure mode that leaves no trace.

### The refresh path, walked rather than described

The snapshot cannot be generated locally — it needs MySQL, and the local stack is SQLite (ADR-0015). So the job uploads the regenerated file as an artifact on **every** run, not only on failure. The first CI run went red with nothing committed; the file that made it green came out of that run's artifact unedited. AC 4 asked for a one-command refresh; this is what that command amounts to on a Windows workstation, and it has now been done once for real.

A second, cheaper layer sits in front of it: the snapshot header carries a SHA-256 fingerprint of the migrations that produced it, and a test compares that against the current `database/migrations`. Changing a migration and forgetting to refresh now fails on SQLite in seconds. The fingerprint hashes **normalised** contents — it is written on Linux and checked on Windows, and a CRLF checkout would otherwise reject a correct snapshot.

### Where the numbers ended up

`app/Domain` is at **93.9%** against a floor of 80. Nothing had to be written to reach it: the resolvers and actions from #6 and #7 were already covered by their own feature tests.

### One flaw found in my own guard

`CiGatesTest` asserted `--min=80` literally, which made the test the second place the threshold lived — the exact property it was written to protect. It now asserts that a threshold exists and that `ci.yml` never repeats it, and says nothing about the value. Caught while designing the sabotage, which is the argument for doing sabotage before writing the changelog rather than after.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **173 passed** (447 assertions), 11 new |
| `npm run widget:build` / `widget:size` / `plugin:lint` | all exit 0 against the M0 stubs |
| Full pipeline green | run [33484640743](https://github.com/mikmikfil/kaiki/actions/runs/33484640743), commit `fcce8f2` — all 11 jobs |
| `app/Domain` coverage | **93.9%**, floor 80 |
| Migrations ran for real | drift job log shows `Running migrations`, never `Loading stored database schemas` |

### CI evidence — sabotage, run [33486716892](https://github.com/mikmikfil/kaiki/actions/runs/33486716892), commit `e07367b`

Both gates broken in one commit, so that the blast radius of each was visible against the other.

| Sabotage | Result |
|---|---|
| Coverage threshold → 100 | `coverage` red: *"Code coverage below expected 100.0 %, currently 93.9 %"* |
| One column widened by a byte in the snapshot | `migrate-from-zero` red, diff naming it: `- varchar(81)` / `+ varchar(80)`, with the refresh instruction |
| Everything else | **green** — Pint, PHPStan, both Pest jobs, the isolation gate, both audits, both stub jobs |

Reverted in `a113af5`. That last row is the half of the claim that is easy to skip: a gate that goes red when something unrelated breaks is not evidence that it watches what it says it watches.

### Review — two blocking findings, both mine

`security-reviewer` and `architect` over the diff. Neither found a way to leak tenant data; this change touches no model, route or migration. Both found real holes anyway.

**The guards missed the path Laravel checks first.** `MigrateCommand::schemaPath()` tries `{connection}-schema.dump` *before* `-schema.sql`. My ignore rule and my test covered only the second, and only for `mysql` — so a developer running `php artisan schema:dump` on the local SQLite stack would write `sqlite-schema.sql` and silently neuter their own `migrate`, with nothing to warn them. Now both extensions are ignored for every connection, and the test globs rather than naming one path.

**ENV-23 lists twelve checks and I shipped eleven.** The missing one is the widget Playwright smoke — and `CiGatesTest` asserted "every ENV-23 job" against a hand-written list that did not include it, so the test was green while the requirement it names was incomplete. That is this file's own doctrine turned inward: a gate that reports green while measuring less than it claims. `widget-e2e` now runs the existing `npm run e2e` stub, symmetrical with `widget-build` and `plugin-lint`.

The audit gate had three ways to **fail open**, all now closed: Composer's severity is nullable and an unclassified advisory sorted into the harmless pile; `.advisories[]?[]` returned zero for output with no `advisories` key at all; and `jq empty` succeeds on an empty file, so "the tool died before writing anything" read as "no advisories". Unclassified now blocks, and the shape is asserted before anything is counted.

The coverage job was the only gate here **without a proof that it ran** — php-code-coverage reports 100% for a source set with zero executable lines, so a broken source filter would have passed at 100% against nothing. It now asserts the clover report measured a non-zero statement count. One reviewer reached this from a false premise (that `app/Domain` is empty — it holds 8 files at 93.9%); the recommendation was right regardless.

**Deferred, with reasons.** Pinning third-party actions to commit SHAs is correct and is *not* done here: it is a repo-wide convention that also covers #3's jobs, and pinning only the new files would be worse than pinning none. Its own issue. Likewise the coverage run excluding the `mysql` group — harmless today, wrong from M2 when AVL-44 can only run on MySQL and its `app/Domain` code would count as uncovered; and the ARC-21c test asserting `composer.json` requires nothing outside the approved list, which is pre-existing and is the one guard that would make ARC-19 mechanical.

### Left for the architect

`docs/spec.md` is not edited — the issue says to propose the wording instead. The proposal, in the pull request and in `docs/ci.md`: the required status check on `main` is the single aggregating job **`CI passed`**, not ten individual checks, so that adding a gate later cannot be forgotten in branch protection.

---

## #9 — Filament panels `/app` and `/admin`, with the TEN-8 role matrix

**Files:** `app/Providers/Filament/{AppPanelProvider,AdminPanelProvider}.php`, `app/Support/Authorization/Capability.php`, `app/Policies/*` (5), `app/Http/Middleware/AddSecurityHeaders.php`, `app/Models/User.php`, `lang/{el,en}/panel.php`, `config/kaiki.php`, five test files, `docs/adr/0024-two-factor-authentication.md`

**This is the first thing in the project you can look at and click.** Both panels are live; the seeded accounts sign in.

### Two panels, strictly separate populations

An operator reaching `/admin` would see every operator's data. A super-admin landing in `/app` has no tenant to scope to. Both are refused outright rather than rendered partially — a partial render is how someone learns what exists behind a wall. Different colours on purpose: someone who can see every operator's data should be able to tell at a glance which panel they are in, because a mistake made there is not recoverable by the person making it.

`/app` carries `ResolveTenant` and `EnsureTenantIsWritable` in its auth middleware, so read-only mode works without any resource having to remember. `/admin` deliberately has no tenant resolution — the one place in the application where that is correct.

**No tenant switcher** (ADR-0020 Option C). A dropdown would make "which tenant am I acting as" a piece of session state, which is exactly where cross-tenant mistakes become possible. Super-admins reach an operator through impersonation in M7.

### The matrix in one place

TEN-8 lives in a single `Capability` enum, so "what can crew do" is one file rather than a search. Three fixed roles with Laravel policies — **not** `spatie/laravel-permission`, which is conditionally approved only and whose pre-emptive installation is a hard stop (ARC-21a).

Asserted **exhaustively**: every capability against every role, grants *and* refusals. Testing only grants would let a widened capability through unnoticed — and nobody files a bug saying "I can see more than I should". A meta-test asserts the test data covers every enum case, so adding a capability and forgetting to test it fails rather than passing silently.

### Why `PolicyCoverageTest` exists

**Filament allows an action when no policy is registered.** Convenient, and in a multi-tenant back office dangerous: a resource shipped without a policy is writable by every role, and nothing in the code says so. This test turns that silence into a failure — verified by removing `TenantDomainPolicy` and watching the suite name the model, then restoring.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **162 passed** (407 assertions), 43 new |
| panels reachable | `/app`, `/app/login`, `/admin`, `/admin/login` all routed |
| seeded logins | 5 operator users across 2 tenants + 1 super-admin |
| policy-coverage sabotage | removed a policy → suite named the model → restored → green |

### One acceptance criterion deliberately not delivered

**SEC-15 (two-factor: optional for operators, required for super-admins) is not implemented.** The `two_factor_*` columns exist from #5, but nothing writes them, because neither Laravel nor Filament v3 ships an enrolment flow. Delivering it needs a package and §3.2 lists none — ARC-19 makes that a hard stop, not a judgement call, and installing one quietly is exactly the drift ADR-0019 exists to prevent.

Written up as **ADR-0024** recommending `laravel/fortify` (whose column names already match the schema, which strongly suggests it was the intent) scheduled with the M7 super-admin work.

Worth being explicit about why I did not simply enforce the flag: **without an enrolment flow, requiring 2FA would permanently lock out every super-admin with no way in to fix it.** A half-delivered security control that bricks the platform panel is worse than an honestly deferred one.

---

## #8 — Cross-tenant isolation suite as a required CI gate

**Files:** `tests/Support/TenantIsolationHarness.php`, `tests/Feature/Tenancy/{ModelIsolationTest,HttpIsolationTest}.php`, `.github/workflows/ci.yml`, `composer.json`

The highest-value test in M0, and the reason ADR-0001 was acceptable at all. Single-database tenancy means **one missing global scope is a cross-tenant data leak**; Option A was accepted on the explicit condition that this suite exists and gates the build. It is the price of the schema.

### Generated, not written

Cases come from the models themselves by reflection. A hand-maintained list would be correct the day it was written and quietly wrong the first time somebody added a model and forgot a line — which is *precisely* the failure this suite exists to catch, making a list the one shape that cannot be trusted here. Adding a tenant-owned model without an isolation test is now impossible.

Eight cases per model: read by id, `findOrFail`/`firstOrFail`, read by uuid, update by key, delete by key, create stamps the tenant, each-tenant-sees-only-its-own, and factory-exists. The update and delete cases **read the row back outside any tenant afterwards** — proving the row is genuinely untouched, not merely that the statement reported zero rows.

Where the generic case cannot build a model — `RoleAssignment` needs a user — the harness carries a documented **override, never an exclusion**. An exclusion removes a model from the gate; an override keeps it in and says what it needs.

### 404, never 403

A 403 says *"this exists, but not for you"*, which is a disclosure in itself. A publishable key is designed to be readable in page source, so anyone could then probe uuids and learn which are real, how many an operator has, roughly when they were created. One test goes past the status code and asserts the response for another tenant's record is **byte-identical** to the response for a record that never existed.

That test initially failed for an instructive reason: `APP_DEBUG=true` attaches a stack trace, so the two bodies differed by a line number. Debug is now off for that file — with it on, the assertion measures the debug renderer rather than what production ships.

### Proven by sabotage

Removing `BelongsToTenant` from `TenantDomain`:

```
⨯ it serves a tenant its own record
⨯ it returns 404 and not 403 for another tenant record
⨯ it answers identically for another tenant record and one that never existed
⨯ it does not leak another tenant hostname in the response body
⨯ it does not let a key from one tenant read across after another tenant was resolved
⨯ it leaves no model unscoped and unlisted
  Neither tenant-scoped nor listed as platform-owned:
  App\Models\TenantDomain
Tests: 6 failed, 17 passed
```

Both layers fired: the structural check named the model, and the HTTP tests caught the actual leak. Restored, green. **A guard nobody has watched fail is not evidence of anything.**

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **119 passed** (260 assertions) |
| `composer test:tenancy` | **31 passed** (58 assertions) |
| sabotage drill | 6 failures naming the model, then green after restore |

Runs as its own CI job with the same three-shape guard as the MySQL group — no summary, any skip, or no passing test all fail the build.

Two PHPStan findings fixed properly rather than suppressed: the harness returns a generic `Model`, so `->uuid` and `->tenant_id` are not statically known. `getAttribute()` is the accurate call, not a workaround.

`PlatformOwnedAllowListTest` from #5 was dropped — the isolation suite covers the same ground more thoroughly, and two tests asserting one property is how one of them rots.

---

## #7 — Tenant resolution: four strategies, fixed order, plus read-only mode

**Files:** `app/Http/Middleware/{ResolveTenant,EnsureTenantIsWritable}.php`, `app/Domain/Tenancy/Resolvers/` (interface + four strategies), `app/Models/TenantDomain.php`, `app/Enums/DomainStatus.php`, `app/Logging/{TenantContextProcessor,AddTenantContext}.php`, one migration, factory, `config/kaiki.php`, `lang/{el,en}/{errors,domains}.php`, four test files

Order: **API key → verified custom domain → hosted slug → panel session**, stopping at the first match, 404 when none matches.

### The ordering is a security property

API key first: the caller named a tenant explicitly, and a request holding a valid key must never be reinterpreted by host. Panel session **last**: an operator signed into their own back office who opens a competitor's hosted page must see *that* operator's public page, not their own catalogue bleeding through. A session says who you are, not what the request is for.

Both precedence cases are tested directly, because a resolver that works alone and loses to the wrong neighbour is exactly how that leak happens.

### No fallback, tested as its own failure

`NoFallbackTenantTest` covers unknown host, unknown slug, no signal at all, and an invalid API key that must **not** quietly degrade into "resolve by host instead". It also covers the tempting bug: *"there is only one operator, so obviously they meant that one"* — true on day one, catastrophic the day a second signs up.

These live here rather than in #8 because they are a different failure: #8 proves the scope cannot leak *between* resolved tenants; this proves the system refuses to proceed with *no* tenant.

### Custom domains

`tenant_domains` per ADR-0010, `hostname` globally unique and normalised **on write** — lowercased, port stripped, punycode-encoded. Normalising on write rather than comparing on read is what makes the unique constraint mean anything; otherwise `Example.COM` and `example.com` are two rows answering the same question, which is the whole security property gone.

Only `status = verified` resolves. Pending and disabled both 404.

### Read-only mode

Unsafe methods only. `past_due` still writes — that is the dunning window, not the punishment; cutting an operator off the moment a card fails would break bookings over a payment that usually succeeds on retry. `read_only` and `suspended` refuse, in Greek and English.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **90 passed** (207 assertions), 32 new |

### Two test bugs of my own

1. **A catch-all route on `/` silently lost to `routes/web.php`.** Six tests failed with "Invalid JSON was returned from the route" — they were asserting against the Laravel welcome page. Test routes now use a path that does not collide.
2. **A hardcoded punycode constant was wrong, twice, in two different ways.** First I asserted an encoding I had guessed; the real one differs. Then, with the value taken from my own machine, the test passed locally and **failed in CI on both engines** — the exact punycode output depends on the ICU version the PHP build links against, and CI's differs from Windows'.

   Rewritten to derive the expected hostname through the same normalisation instead of naming it. The property worth proving was never *what the encoding string is* — it is that **a hostname stored in Unicode is matched by the request that arrives for it**, whatever form that takes. Split into three deterministic tests: ASCII case-insensitivity, the Greek round trip, and idempotence (normalising an already-normalised hostname must not yield a third value, or the unique index means nothing).

   This one is worth remembering: it is the first environment difference that had nothing to do with the database or the cache. **A library version can be a parity boundary too.**

3. **A real bug found while chasing that.** `normalise()` used `strtolower`, which is byte-based and leaves `ΑΙΓΑΙΟ.GR` untouched. IDN processing folds the case anyway, so nothing was broken today — but the lowercase step silently did nothing for exactly the hostnames this product's customers register. Now `mb_strtolower`.

---

## #6 — API keys: `api_keys`, generation and hashing, authentication middleware

**Files:** `app/Models/ApiKey.php`, `app/Enums/{ApiKeyType,ApiKeyEnvironment,ApiScope}.php`, `app/Domain/Tenancy/Actions/{GenerateApiKey,RevokeApiKey}.php`, `app/Domain/Tenancy/Data/GeneratedApiKeyData.php`, `app/Http/Middleware/{AuthenticateApiKey,RequireApiKeyCapability}.php`, `config/kaiki.php`, one migration, factory, `lang/{el,en}/api.php`, three test files

Key shape `{pk|sk}_{live|test}_{32 random}`. Type and environment are in the string, so a key is readable on sight — which is also the only reason SEC-9's CI grep for a leaked `sk_` can work.

### The three things that must not be got wrong

**The raw key exists exactly once.** Only `prefix`, `secret_hash` (SHA-256) and `last_four` are stored, so a database dump is not replayable against the live API. Verified by a test that walks *every column* of the stored row asserting the plaintext appears in none of them — not by inspecting the two columns we expect to be safe.

**Type is a ceiling; scopes only narrow it** (SEC-5). Enforced twice: at creation, so a key that could never be used cannot be stored, and again at read time, so a row widened by a bad migration or a hand edit still cannot be used. There is a test that writes `quotes.write` directly into a publishable key's row with raw SQL and asserts it is still refused.

**A secret key arriving with an `Origin` header is refused.** Browsers always send one cross-origin, so that is exactly what a secret key leaking into front-end code looks like on the wire. Loud failure beats quiet success — the quiet version means an operator ships their secret key in a page and never finds out.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **58 passed** (149 assertions), 24 of them new |
| `php artisan migrate:fresh --seed` | 5 migrations green on SQLite |

Authentication is covered end to end through the real HTTP stack — valid, missing, malformed, unknown prefix, **right prefix with the wrong secret**, revoked, expired, publishable-on-a-secret-route, secret-with-Origin, origin allow-list hit and miss, empty allow-list, and both locales in the error body. Middleware that works in isolation but not on a route is a familiar way to ship a hole, so none of it is tested by calling the class directly.

A query-count test asserts authentication costs **exactly one** indexed `api_keys` read. It runs on every public API request, so an extra read multiplies across the whole API against NFR-1's 150 ms p95.

### Three test problems fixed at the source

1. **A DB-touching test was written under `tests/Unit`**, where `RefreshDatabase` does not apply — it failed with `no such table: tenants`. Moved to `tests/Feature`: it was never a unit test, and the issue's file list was wrong about that.
2. **The "no direct `Redis::` call" guard was grepping comments.** The docblock in `ApiKey` explaining *why* there is no Redis call contains the string `Redis::`, so the guard failed on its own prose. It now strips comments with `token_get_all` and greps code. A guard that can be defeated — or triggered — by a comment is not a guard.
3. **PHPStan cannot type `$this` inside a Pest closure.** Both API test files use Pest's function API and local variables instead. The alternative, excluding `tests/` from analysis, would take the static-analysis gate off the code most likely to lie about itself.

### The MySQL job earned its keep: a real bug, green locally, red in CI

CI run [33172994149](https://github.com/mikmikfil/kaiki/actions/runs/33172994149) failed on **Pest on MySQL 8 + Redis** while SQLite passed. One test: *"it writes again once the window has passed"* — `last_used_at` stayed at `12:00:00` instead of moving to `12:01:01`.

The cause is worth writing down, because it will recur.

The throttle used `Cache::add($key, true, 60)` and let the **cache entry's TTL** decide whether the window had passed. Laravel's `array` driver — what the suite uses locally — evaluates expiry against **Carbon**, so `Carbon::setTestNow()` moved the clock and the entry looked expired. Redis expires keys on **real wall-clock time**, and 61 simulated seconds take no real time at all, so the entry was still there and the write was correctly skipped.

So the local suite was green for a reason that does not hold in production. The test was not wrong; the code was relying on a behaviour that differs by driver.

**Fixed in the code, not the test.** The window is now decided by comparing a stored timestamp against current application time, which is identical on every driver and controllable with frozen time. The trade is that two simultaneous requests could both write — a harmless duplicate update, since the throttle is a performance measure and not a correctness guarantee — bought in exchange for behaviour that does not depend on which cache driver happens to be configured.

This is the second parity bug in two database-touching issues, and the first one CI caught rather than review. **A cache-driver difference is subtler than a SQL-dialect one**: nothing about `Cache::add(…, 60)` looks environment-specific.

### …and immediately a second one underneath it

The next run failed differently, on the neighbouring test. Root cause: **`RefreshDatabase` rolls back the database, so auto-increment ids restart at 1 in every test — but the cache is not rolled back.** With the array driver that is harmless, because the store dies with the process. In CI the cache is real Redis, shared across the whole run, so an entry keyed `apikey:1:touched` written by one test is read by the *next* test's different key that also happens to have id 1.

Fixed globally in `tests/Pest.php`: the cache is flushed before every test. Anything that caches per-id would have hit this, and the symptom — order-dependent flakiness that only appears with a real cache — is among the most expensive to diagnose once a suite is large.

### …and a third, one level deeper still

The run after that failed on the same test again. **Laravel's Redis store round-trips numeric values as strings** — `RedisStore::serialize()` leaves anything numeric raw rather than serialising it — while the array driver keeps the int. So the guard `is_int($lastTouched)` was `false` on Redis and `true` in the local suite: the throttle **silently never engaged in production** while passing every local test.

Fixed by checking `is_numeric()` and casting. Note what this one would have cost: not a failing test, but a `last_used_at` write on every single authenticated API request, discovered eventually as unexplained database load.

### What these three have in common

All three were the same shape: **code that reads correctly, passes locally, and behaves differently in production because of an implicit driver assumption.** A TTL that means Carbon time in one driver and wall-clock in another. A cache that survives a rolled-back database. A numeric type that survives one store and not another. None of them looks environment-specific at the call site, and none would have been caught by review.

This is the concrete return on ADR-0015's parity contract, three issues in — and the argument for the MySQL + Redis job being a required check on every pull request rather than a nightly.

### CI-only

`api_keys` migrating on MySQL 8 — in particular `char(64)` for the hash and the `api_keys_tenant_type_idx` composite, neither of which SQLite validates the way MySQL does.

---

## #5 — Tenancy foundation: single database, `tenants` / `users` / `role_assignments`, `BelongsToTenant`

**Files:** `config/tenancy.php`, `app/Models/{Tenant,User,RoleAssignment}.php`, `app/Models/Concerns/{BelongsToTenant,HasUuid}.php`, `app/Models/Scopes/TenantScope.php`, `app/Support/Tenancy.php`, `app/Exceptions/{TenantContextMissingException,LastOwnerException}.php`, `app/Enums/{Role,Plan,TenantStatus}.php`, two migrations, three factories, `DemoTenantSeeder`, `lang/{el,en}/*`, five test files

`stancl/tenancy` in single-database mode (ADR-0001 Option A). `tenant_id` on every tenant-owned table, mandatory `BelongsToTenant`, and an explicit platform-owned allow-list with no implicit exemption.

### Decisions worth knowing

**The scope throws when no tenant is resolved.** Spec TEN-4 has no default tenant and no fallback, so the absence of context is an error rather than a wildcard. Returning every operator's rows because nobody resolved a tenant is the worst failure mode this product has, and it is silent. Deliberate cross-tenant access goes through `Tenancy::withoutTenancy()` — verbose and greppable on purpose, so it shows up in review.

**`config/tenancy.php` was replaced, not tuned.** The published file is 200 lines of database-per-tenant machinery. None of it applies, and leaving it would tell the next reader that per-tenant databases exist somewhere in the system. `bootstrappers` is deliberately empty, with a note that an entry appearing there is a silent move to per-tenant infrastructure.

**`users` deliberately has no tenant scope.** `tenant_id` is nullable and null means platform super-admin (ADR-0020 Option C); a global scope would make super-admins invisible to their own panel. It is in the allow-list with that reason attached.

**The last-owner guard is in the application, not the database.** No portable constraint expresses "at least one row matching this predicate", and a trigger would not survive the SQLite/MySQL split. Its message is an operator-facing string, so it exists in Greek and English.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` — 11 findings fixed with real annotations, no baseline, no ignores |
| `composer test` | **30 passed** (67 assertions) |
| `php artisan migrate:fresh --seed` on SQLite | 4 migrations, 2 tenants, 6 users, 5 role assignments, uuids assigned |

**The allow-list guard was tested by breaking it.** Adding a bare `App\Models\LeakProbe` made the suite fail, naming the model and saying what to do:

```
These models are neither tenant-scoped nor listed as platform-owned:
  App\Models\LeakProbe
Tests:    1 failed, 29 passed
```

Removing it returned the suite to green. A guard that has never been seen to fail is not evidence of anything.

**The scope assertion reads compiled SQL, not row counts.** A query returning the right number of rows proves nothing if the filtering happened in PHP; the isolation guarantee rests on the `WHERE` clause reaching the database.

### One parity bug caught before CI could

That same assertion originally expected `"role_assignments"."tenant_id"` — SQLite quoting. MySQL emits backticks, so it would have passed locally and gone red in the MySQL job. It now strips quote characters and asserts the clause rather than the dialect. **This is the SQLite/MySQL split producing exactly the class of bug ADR-0015 predicted**, on the first issue that touches the database.

### CI evidence — run [33171491076](https://github.com/mikmikfil/kaiki/actions/runs/33171491076), commit `4942bd0`

All five jobs green, including **Pest on MySQL 8 + Redis**. The part local SQLite could not prove:

```
0001_01_01_000000_create_users_table ......................... 224.87ms DONE
0001_01_01_000003_create_role_assignments_table .............. 111.73ms DONE
mysql group executed for real: Tests:    1 passed (2 assertions)
```

Both migrations run on MySQL 8 — so the column types, the index names, and the `utf8mb4` key limit on `users.email` (190 chars = 760 bytes) all hold on the engine production actually uses, not just on SQLite.

---

## #3 — CI workflow: Pint, PHPStan, Pest on SQLite, plus MySQL 8 + Redis

**Commit:** `927f13e` · **Files:** `.github/workflows/ci.yml`, `tests/Feature/SmokeTest.php`, `tests/Unit/ToolchainTest.php`

Five jobs on every pull request and every push to `main`: `lint`, `static-analysis`, `test-sqlite`, `test-mysql`, and a `ci-passed` aggregate gate that exists so branch protection needs exactly one required check and cannot silently miss a job added later. PHP 8.4 is declared once, in workflow-level `env` (ADR-0014 Option B — a single version, not a matrix). Composer installs are cached by `ramsey/composer-install` keyed on `composer.lock`.

`test-mysql` runs MySQL 8 and Redis 7 service containers, migrates from scratch, and runs the suite with `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` all on Redis, so the Redis code paths are exercised somewhere (ENV-1). It is a required check on every PR, not nightly — ADR-0006 leans on it as the only place real parallelism is ever tested.

### Two problems found and fixed while building this

**1. `SmokeTest` would have failed the MySQL job for the wrong reason.** It asserted `config('database.default') === 'sqlite'`, which is false — and correctly so — inside a job whose whole purpose is running against MySQL. Rewritten to assert the *contract* in `.env.example` (that a clean checkout lands on SQLite with database/file drivers), which is what ENV-17 actually requires and which holds on both engines.

**2. The `mysql` group could have reported green without touching MySQL.** PHPUnit applies an `<env>` entry only when the variable is not already set, so the job environment should win over `phpunit.xml`'s SQLite defaults — but "should" is not evidence. The group's test now asserts `DB::connection()->getDriverName() === 'mysql'`, and CI parses the Pest summary and fails on three distinct failure shapes:

| Shape | Why it must fail |
|---|---|
| no `Tests:` summary at all | the group matched nothing |
| summary contains `skipped` | the MySQL service or `DB_*` env never reached PHPUnit |
| summary lacks `passed` | nothing actually ran green |

This matters beyond this issue: the overselling concurrency test (AVL-44, ADR-0006) joins this group in M2 and can *only* ever run here. A silent skip would mean the single most expensive bug class in the product is untested while CI shows green.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | `{"tool":"pint","result":"passed"}` |
| `composer stan` | `[OK] No errors` |
| `composer test` | **8 passed** (25 assertions) |
| `composer test:mysql` locally | **1 skipped** — `"Needs MySQL 8. Runs in CI only — local development is SQLite (ADR-0015)."` |
| workflow YAML parses | 5 jobs, services `mysql` + `redis`, `PHP_VERSION` declared once |

### CI evidence — run [33169705588](https://github.com/mikmikfil/kaiki/actions/runs/33169705588), commit `927f13e`

| Job | Result |
|---|---|
| Pint | success |
| PHPStan level 6 | success |
| Pest on SQLite | success |
| Pest on MySQL 8 + Redis | success |
| CI passed (aggregate gate) | success |

The guard's own output, which is the assertion that matters:

```
✓ it runs the mysql group against a real MySQL 8 connection            0.09s
  Tests:    1 passed (2 assertions)
mysql group executed for real: Tests:    1 passed (2 assertions)
```

`1 passed`, not `1 skipped` — the job environment does reach PHPUnit, MySQL 8 was genuinely connected, and the driver assertion held. That is the evidence M2's overselling test depends on.

**Known warning, not failing:** GitHub reports `actions/checkout@v4` and the `actions/cache` pulled in transitively by `ramsey/composer-install@v3` still target Node.js 20, which is deprecated and force-run on Node 24. Cosmetic today; worth clearing when those actions publish updates, so a real warning is not lost in the noise.

---

## #2 — Quality toolchain: Pint, PHPStan 6 + Larastan, Pest groups, Composer scripts

**Commit:** `85830f7` · **Files:** `pint.json`, `phpstan.neon`, `composer.json`, `package.json`, `tests/Unit/ToolchainTest.php`, `.claude/settings.json`

Pint on the Laravel preset plus `declare_strict_types`, `strict_comparison`, `strict_param` and sorted imports. PHPStan level 6 with Larastan at `phpVersion: 80400` over `app`, `config`, `database`, `routes`, `tests` — **no baseline**, and `packages/wordpress-plugin` excluded because the plugin targets PHP 8.1 for operator hosting (ARC-9). Applying Pint reformatted the framework skeleton, which is most of that commit's diff.

All ten ENV-15 Composer scripts and all seven ENV-16 npm script names (the latter as announced stubs until M2–M4 fill them in).

### The bug the guard test caught on its first run

**Pest ignores a comma-separated `--exclude-group`.** Written as `--exclude-group=mysql,chromium,external`, it excluded **nothing** — the MySQL-only test ran locally and passed. That is exactly the false green ENV-11 exists to prevent, and it would have quietly disabled every CI-only exclusion in the project. The flag must be repeated per group.

This is the reason `tests/Unit/ToolchainTest.php` exists at all: tests that verify the tooling, not the application. Without it the mistake would have been invisible until someone wondered why a MySQL test passed on a machine with no MySQL.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | 8 of 9 — `mysql`/`chromium`/`external` correctly excluded |
| `composer test:fast` | 3 passed, **0.84s** (budget: under 30s) |
| `composer test:mysql` | the 1 mysql-tagged test, run explicitly |
| `composer ci` | lint:test → analyse → test, in order |

PHPStan findings were fixed at the source, never suppressed: `$this->get()` inside a Pest closure is untypeable, so the smoke test imports the `Pest\Laravel\get` function helper. No baseline, no `@phpstan-ignore`.

### Hooks (§16)

Deferred here from #1, because a `PostToolUse` hook calling `vendor/bin/pint` cannot exist before `vendor/` does. Both live in `.claude/settings.json` — committed, so the whole team gets them:

- `PostToolUse` on Edit/Write → Pint on the touched file, only when it is `.php` and `vendor/bin/pint` exists
- `Stop` → `composer test:fast`, printing one line, e.g. `Tests: 3 passed (5 assertions)`

There is no `jq` on this machine, so both parse the hook payload with PHP, which this project guarantees. Both were pipe-tested against synthesised payloads, then **re-extracted from the stored JSON and re-run verbatim** to prove the escaping survived serialisation — a hook that works in the shell but breaks in JSON is the standard way this goes wrong.

---

## #1 — Laravel 12 skeleton, SQLite local stack, `composer setup`

**Commit:** `4f4c822` · Scaffolded from an empty repository.

Laravel 12.68 on PHP 8.4. Local runtime fixed to SQLite at `database/database.sqlite`, cache/queue/session on the `database` driver, mail on `log`, `APP_TIMEZONE=UTC`. The `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` layout with an `Actions` directory in each, plus `app/Enums`.

### Verification — all eight acceptance criteria

| Criterion | Result |
|---|---|
| `composer install` clean, Laravel 12 | `Laravel Framework 12.68.0` |
| `composer setup` from clean checkout | `.env` → key → SQLite file → migrate → seed → assets, one command |
| `.env.example` contract | `DB_CONNECTION=sqlite`, `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER=database`, `MAIL_MAILER=log`, `APP_TIMEZONE=UTC`, plus `KAIKI_WP_TEST_URL`, `KAIKI_CHROME_PATH`, Viva/Stripe sandbox placeholders |
| `.env` and SQLite file gitignored | `git status` clean of both |
| timezone | `php artisan tinker --execute="echo config('app.timezone');"` → `UTC` |
| bounded-context directories | all nine present, plus `app/Enums` |
| no `Makefile`, no root `docker-compose.yml` | asserted **by a test**, not by inspection |
| `GET /` | 200 |

### Deviations from the issue as written

1. **Pest pulled forward from #2.** The issue's test plan called for `tests/Feature/SmokeTest.php` while listing Pest as out of scope — which meant writing it in PHPUnit and rewriting it a day later. Agreed with the product owner before starting.
2. **`laravel/sail` removed** from the skeleton's dev dependencies. It is a local Docker tool and local development here is native (ENV-3, ENV-4).

### Environment change outside the repository

Nine PHP extensions were commented out in the Windows `php.ini` and are now enabled: `fileinfo`, `pdo_sqlite`, `sqlite3`, `zip`, `gd`, `intl`, `exif`, `sodium`, `pdo_mysql`. **Without `pdo_sqlite` nothing in the local stack runs at all.** Backup at `php.ini.kaiki-backup`; documented in `README.md` so the next machine does not hit the same wall.

---

## Project foundation (pre-#1)

`CLAUDE.md`, eleven subagents in `.claude/agents/`, four slash commands in `.claude/commands/`.

`docs/spec.md` (~340 numbered requirements), `docs/data-model.md` (56 tables, four state machines, migration ordering), `docs/api.md` (17 endpoints, 60 schema components, machine-verified OpenAPI 3.1), **23 ADRs, all accepted 2026-08-28**. Thirty-eight issues across M0, M1, M6 and M8.

### Three contradictions caught between the accepted ADRs and the documents

Found during the post-acceptance pass, before any of them reached code:

1. **`docs/data-model.md` modelled the *rejected* VAT option** — `products.vat_rate_bp` defaulting to `1300`. A hardcoded VAT rate in the schema, which brief §10 and `CLAUDE.md` both forbid outright. Corrected to the `vat_rates` table with `vat_rate_id` foreign keys and per-line snapshots. Issue #18 was hard-blocked on exactly this, and SQLite cannot add the column later.
2. **`seats_held` was defined two incompatible ways.** The data model had it as a subset of `seats_sold`; the spec had them disjoint. The subset reading lets an unpaid draft flip a departure to `guaranteed` — emailing guests "your trip is confirmed" — and hides it from the at-risk dashboard. The spec was right; fourteen places in the data model were corrected.
3. **`MYD-4` required strictly gapless invoice numbering**, which ADR-0022 Option A explicitly permits gaps in. Superseded, with the change called out inline rather than edited in silently.
