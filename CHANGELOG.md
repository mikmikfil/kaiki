# Changelog

## M0 — Foundation

### #12 - i18n foundation: locale resolution, EL/EN parity and no-hardcoded-string gates, formatters, and the language switcher

Bilingual EL/EN "from the first commit" (VIS-7) was aspiration until now. **No locale was ever set on a real request.** There was no `SetLocale` middleware; `app()->setLocale()` appeared only inside tests, `config('app.locale')` is `en`, and a Greek operator signing into `/app` got an English panel while `tenants.default_locale` and `users.locale` both said `el`. `PanelLocaleTest` passed only because it set the locale by hand first — an assertion that Blade reads the locale, never that a request arrives with the right one.

**The fallback order is one class, and "requested locale" is four signals.** I18N-5 names three steps; the middle one is a `?lang=`, a session, a saved `users.locale` and an `Accept-Language` that can all disagree. The order is `?lang=` → session → `users.locale` → `Accept-Language` → `tenants.default_locale` → `en`: a deliberate click outranks a browser header the operator has never looked at, because a Greek operator on a laptop imaged in English is entirely ordinary. Nothing throws — a stray `?lang=fr` in a shared link falls through rather than breaking the page, and a locale the tenant has not enabled is refused so a widget cannot half-translate their site.

**Registering the middleware twice does not work, and the failure is invisible.** Filament runs its `middleware` stack for every panel route and appends `authMiddleware` only for authenticated ones — and `ResolveTenant` lives in the second list, because it resolves from the signed-in user. Putting `SetLocale` in both lists looks correct and is silently useless: `Router::uniqueMiddleware` deduplicates and keeps the *first* occurrence, so the surviving copy runs before any tenant exists and step 5 of the chain never fires. Nothing errors; the page renders, in the wrong language. The fix is Laravel's middleware **priority list**, so the order is a property of the application rather than of each route's argument order — including on routes M1 has not written yet. A test registers them backwards on purpose and is the assertion that fails if that configuration is removed.

Three tests in that file originally read "no `Accept-Language`, expect the tenant default" and failed. `Symfony\Component\HttpFoundation\Request::create()` injects `Accept-Language: en-us,en;q=0.5` when a caller supplies none, so an omitted header in a test is not an absent header — it is a request that asked for English. The middleware was right and the tests were wrong; the trap is documented at the top of the file, because the next person writing a locale test will hit it.

**Both CI gates were proven by sabotage, and one of them proves itself on every run.** An orphaned Greek key produced `not present in lang/en: panel.orphaned_key_for_sabotage`; a `->label('Key type')` on `ApiKeyResource` produced `app/Filament/App/Resources/ApiKeyResource.php:175 — "Key type" (literal passed to ->label())`. The issue asks a human to sabotage the tree and remember to revert; the hardcoded-string scanner is instead pointed at a fixture that is permanently wrong, so it demonstrates itself in CI forever. The fixture's second half matters as much as the first: it also contains an icon name, a column, a locale code and a Tailwind class list, and asserts those are **not** flagged — if loosening the scanner to stop crying wolf also stops it catching labels, it is worthless. A lint that fires on a CSS class earns an allow-list entry rather than a fix, and after a dozen of those it enforces nothing.

The scanner is a line scanner, not a PHP parser: `nikic/php-parser` is not on the ADR-0019 shortlist and adding it is a hard stop. It errs towards false negatives on purpose.

**`lang/el/validation.php` is hand-written.** `laravel-lang/lang` would have been an hour's work saved and an unapproved dependency, which ARC-21 makes a hard stop rather than a judgement call. `validation`, `auth`, `passwords` and `pagination` did not exist in either locale before this, so every form error in the panel was English-only in both languages.

**Every enum label moved into one `enums.php`.** Four enums justified four lang files; M1 adds a dozen more and the answer to "where is this label" has to stay one file rather than a search. `HasTranslatedLabel` derives the key from the class name, so an enum cannot drift from its own lang block by being renamed in one place. Three existing tests were re-pointed — their claim is unchanged (one home, no duplication, no literal) and only the address moved. `api.php` keeps the API error envelope, which is prose for an integrator rather than a label on a form.

**English is `en_GB`, not `en_US`.** I18N-6 pins Greek formatting and says nothing about English, and the tempting default is wrong: a Greek operator who switches the panel to English must not see `04/03/2026` for the departure that read `03/04/2026` a moment ago. That is a guest on the wrong boat, and it is invisible for eleven days of every month. Both locales are day-first and 24-hour. Money is `brick/money` (ARC-20, first use), cents in and string out with no float anywhere — an `amount()` helper was drafted and cut when it turned out to need `toFloat()`, which CNV-1 forbids outright. Formatting assertions normalise Unicode spaces rather than pinning CLDR's narrow no-break space, which is how these tests survive an ICU upgrade between Windows and `ubuntu-latest`.

`GreekText::fold()` arrives here rather than in #15 because ADR-0008's acceptance comment put it here. It is PHP rather than SQL for a specific reason: MySQL's `utf8mb4_unicode_ci` folds tonos and SQLite's `NOCASE` folds nothing outside ASCII, so a search implemented in the database returns different rows locally than in production — and the local result is the one a developer trusts.

**The switcher and dark mode** were asked for during the session. Flags are inline SVG, not emoji: Windows renders `🇬🇷` as the letters "GR" in Chrome, and Windows is what these operators use. It sits in the topbar *and* on the login page, because an operator staring at a sign-in form in a language they cannot read has no account yet to store a preference on. It is registered once, from `AppServiceProvider` — Filament render hooks are global unless scoped, so registering from both panel providers draws two switchers, and a test counts them. A tenant selling in one language gets no switcher rather than a control that half-translates their page. Light/dark was already Filament's default; it is now asserted rather than assumed, because it disappears silently the day a panel gains a `->darkMode(false)`.

`ENV-23` does not list an i18n check though I18N-2 and I18N-3 both say "enforced in CI". Following the precedent set by #4, the job is wired and the spec wording is proposed in the pull request rather than edited here.

**What the review changed.** `security-reviewer` and `architect` both ran; between them they found two blocking items and seven worth fixing, and the interesting ones were all invisible to the test suite as written.

*`users.locale` was `NOT NULL DEFAULT 'el'`*, so every authenticated request looked like an explicit Greek preference and steps 4 and 5 of the chain were unreachable in the panel — an English-speaking operator's staff would have got a Greek panel with nothing able to correct it. The tests missed it because every step-4/5 case was unauthenticated. The column is now nullable with no default, so "never chosen" is expressible; it is a change to a table that has not shipped, which is the cheapest this fix will ever be.

*The narrowing existed twice and the two copies disagreed* — the switcher lowercased where the resolver normalised, and softened an empty `supported_locales` where the resolver did not, so a tenant could be offered a locale the resolver would then refuse. Exactly the "control that does nothing when pressed" that class was written to prevent. `permitted()` is now public and the switcher calls it.

*`SetLocale` was not Livewire-persistent*, so on `POST /livewire/update` — every button in the panel — it ran from the `web` group before `ResolveTenant`, which Filament registers as persistent and which therefore runs later. The tenant was null when the locale was decided, so the panel would have been right on a full page load and wrong on every sort, filter and modal. **Same class of bug as `e62d7e1`.** Pinned structurally for now; the end-to-end assertion needs the real-`POST` harness #43 exists to build.

*The `?lang=` handler wrote `users.locale` on a GET*, with no CSRF token — so an `<img src="…/app?lang=en">` on any page an operator visited would permanently flip their saved language, surviving the session, the browser and the device. Nothing in I18N-5 asked for an account write; the session half is what makes the switcher work, and saving a preference belongs behind a POST in M7. Removed.

*The priority list was anchored after `Authorize`*, which pinned tenant resolution behind route-model binding for every future route — so an M1 `Route::middleware(['tenant', 'can:view,booking'])` would have resolved a tenant-owned model with no tenant initialised, even though the route lists `tenant` first. Re-anchored before `SubstituteBindings`.

*And the parity check had a blind spot of its own.* It used `data_get()` on flattened keys, which reads the dot in `enums.api_scope.products.read` as a path separator and returns null — so the untranslated, placeholder and empty-string checks silently skipped **every** API scope label. Under that cover two Greek strings had been reworded during what was supposed to be a pure move (`Ανάγνωση εμφάνισης` → `Ανάγνωση επωνυμίας`, and `Δοκιμαστικό` → `Δοκιμή`); both are restored, and the flatten now carries values out of the recursion where the real key is still in hand. A move should be a move.

Also from the review: `ApiScope` no longer needs a bespoke `label()`, because the trait is dot-safe and every enum now has one shape; a reflective `EnumLabelCoverageTest` fails when an enum has no lang block, which is the actual CNV-11 gate for the twelve resources M1 is about to add; the literal scanner reads whole files rather than lines, because Pint wraps a fluent chain the moment it passes the line limit and a per-line scanner cannot see a wrapped `->helperText()`; and `ext-intl` is declared, since both formatters hard-require it and its absence would have been a fatal at first render rather than a failed install.


### #10 - API keys in the panel: list, create with a one-time reveal, revoke

The first Filament resource in the project, and the one that closes M0 item 4. Until now the whole API-key domain from #6 existed but was reachable only from a test: an operator could not create the key their widget needs, and — the part that actually matters — **could not revoke one that had leaked**.

**The reveal is not a create page.** Filament's `CreateRecord` redirects when it finishes, and carrying a plaintext key across a redirect means the session or a flash bag, both of which CNV-13 forbids and both of which outlive the moment the operator is looking at the screen. So creation is a modal action instead: it mints the key, hands it to a `#[Locked]` property, and swaps its own modal for the reveal modal **inside one Livewire round trip**. Nothing is written to the session, nothing is redirected through, nothing is logged. A reload has nothing to show because the plaintext was never stored anywhere it could be read back from.

`#[Locked]` matters more than it looks: without it a crafted Livewire request could write that property and have the page echo it back, which is a self-inflicted XSS reflector in the one component that renders a credential.

Clearing it hangs off `unmountAction()` rather than the action's `->after()`. `->after()` fires when an action *runs*, and the reveal modal has nothing to run — it has no submit button. Escape, the X and the "I have saved it" button all land in `unmountAction()`, so that is the one place that catches every way out. The test that found this asserted the property was null after dismissal and got the key back.

**Type is the ceiling; scopes only narrow it** (SEC-5). The checkbox list is narrowed reactively from `ApiKeyType::allowedScopes()` — the same method the domain Action enforces with — and a validation rule mirrors it so a tampered payload is a form error rather than the Action's `InvalidArgumentException` arriving as a 500. The Action keeps its guard as the backstop; this is the surfacing the issue asked for, not a second copy of the rule.

**Revocation is a timestamp, never a delete.** The row stays in the list marked revoked, because deleting it would free its prefix for reissue and erase the evidence of what a leaked key could reach. Asserted end to end, as the issue insisted: press the button in the panel, then present the key to the API and get a 401. A test that only checks `revoked_at` proves the button wrote a timestamp, not that the key stopped working, and those are different claims.

SEC-16's audit trail is a **structured log line** carrying actor, key prefix and tenant. There is no audit table anywhere in the data model and SEC-16's own list does not include key revocation, so inventing one would have been a DECIDE — an ADR that stops for a human, not a decision to take while building a form. A queryable audit trail remains open.

The copy never implies that creating a key replaces an old one. Rotation is create-then-revoke (ADR-0013), and a confirmation dialog that says the wrong thing about what is about to break is worse than none.

**A bug in #6 fell out of the locale test.** `ApiScope::label()` asked for `api.scope.products.read`, but scope values contain a dot and Laravel reads a dot in a translation key as a path separator — so it searched for `scope → products → read`, found nothing, and returned the key. It had never worked; nothing rendered those labels until this form did. Operators would have seen `api.scope.products.read` beside a checkbox. Fixed by fetching the array and indexing it.

### #4 - The rest of the CI gates: coverage, audits, schema drift, widget and plugin jobs

ENV-23 lists the checks that must be required on `main`. #3 delivered five of them; this is the rest, and with it the list a human types into branch protection is finally the list the pipeline produces.

**Coverage is scoped to `app/Domain` and nothing else** (TST-1), through a second PHPUnit config that differs from the first only in its source filter. A whole-application threshold would reward writing tests for Filament resources instead of for the engine, which is the one part of this system that must not be wrong. It currently sits at **93.9%** against a floor of 80. The floor lives in one place - the `--min=` in `composer test:coverage` - and a test asserts the workflow never repeats the number. That test originally pinned `80` itself, which made it the second home for the very value it was guarding; it now asserts only that a threshold exists.

**The schema snapshot is deliberately not named `mysql-schema.sql`.** Laravel loads a dump at that path *instead of* running the migrations whenever none has run yet. Committing one there would have meant the drift job comparing the snapshot against itself, and `test-mysql`'s `migrate:fresh` quietly ceasing to exercise the migrations at all - neither of which would have turned a build red to say so. So the file is `mysql-schema.snapshot.sql`, `.gitignore` blocks the path Laravel watches, a test asserts no such file exists, and the job fails if the migrate output ever reads `Loading stored database schemas`. ENV-10 asks for a guarantee; a guarantee that can evaporate silently is not one.

Refreshing the snapshot is one command, `composer schema:snapshot` - but it needs MySQL, and the local stack is SQLite (ADR-0015), so the job uploads the regenerated file as an artifact on **every** run, red or green. That artifact is the refresh path, and this issue walked it: the first run went red with nothing committed, and the file that fixed it came out of that run unedited.

There is a second, cheaper half to the same guarantee. The snapshot header carries a fingerprint of the migrations that produced it, so changing a migration and forgetting to refresh fails on SQLite in seconds rather than after a MySQL round-trip. The fingerprint hashes normalised contents, because it is written on Linux and checked on Windows and a CRLF checkout would otherwise reject a snapshot that is perfectly correct.

**The audits split on severity** (SEC-12): high and critical fail, anything lower is printed for a human to open an issue about. Both tools exit non-zero on any finding, so the exit code is discarded and the severity read from the JSON - and a run producing no parseable JSON fails rather than being read as a clean bill of health. That distinction is the whole reason it is not a one-liner.

The audit and drift jobs are **reusable workflows** called by both `ci.yml` and `nightly.yml`. Two copies of a severity threshold is how one of them quietly stops blocking anything.

`widget-build` and `plugin-lint` run stubs until M3 and M4. They exist now so those milestones replace a `package.json` script rather than invent a pipeline, and so the required-check list never has to change again.

**Both gates were proven by sabotage**, as the issue asked. The threshold raised to 100 produced "Code coverage below expected 100.0 %, currently 93.9 %"; a single column widened by one byte in the snapshot produced a diff naming it. Every other job stayed green - which is the other half of the claim, that these gates fail for their own reasons and not for each other's.

`docs/ci.md` is new and holds the required-check list, what each red build means, and the refresh path. `docs/spec.md` was not edited: the wording is proposed in the pull request for the architect, as the issue directs.

### #9 - Filament panels /app and /admin with the owner/manager/crew role matrix

Two panels, strictly separate populations. An operator reaching `/admin` would see every operator's data; a super-admin landing in `/app` has no tenant to scope to. Both are refused outright rather than rendered partially - a partial render is how someone learns what exists behind a wall. Different colours on purpose, because someone who can see every operator's data should be able to tell at a glance which panel they are in.

`/app` gets `ResolveTenant` and `EnsureTenantIsWritable` in its auth middleware, so a lapsed subscription blocks writes without any resource having to remember, and reads keep working - the operator still needs to see their bookings while sorting out a payment. `/admin` deliberately has no tenant resolution: it is the one place in the application where that is correct.

**No tenant switcher.** ADR-0020 Option C gives each user exactly one tenant, and a super-admin reaches an operator through impersonation (M7) rather than a dropdown. A dropdown would make "which tenant am I acting as" session state, which is precisely where a cross-tenant mistake becomes possible.

The TEN-8 matrix lives in one `Capability` enum rather than spread across policies, so the answer to "what can crew do" is one file rather than a search. Three fixed roles with Laravel policies, **not** `spatie/laravel-permission` - conditionally approved only, and installing it pre-emptively is a hard stop (ARC-21a).

The matrix is asserted exhaustively: every capability against every role, grants *and* refusals. Testing only the grants would let a widened capability through unnoticed, and nobody files a bug saying "I can see more than I should". A meta-test asserts the test data covers every enum case, so adding a capability and forgetting to test it fails rather than passing silently.

`PolicyCoverageTest` exists because **Filament allows an action when no policy is registered**. That default is convenient and, in a multi-tenant back office, dangerous: a resource shipped without a policy is writable by every role and nothing in the code says so. Verified by removing a policy and watching the suite name the model.

**SEC-15 (two-factor) is not delivered.** Neither Laravel nor Filament ships an enrolment flow, so it needs a package, and §3.2 lists none - ARC-19 makes that a hard stop rather than a judgement call. Written up as ADR-0024 with a recommendation. Enforcing the requirement without an enrolment flow would be worse than not enforcing it: a super-admin who never enrolled would be permanently locked out with no way in to fix it.

### #8 - Cross-tenant isolation suite as a required CI gate

The single highest-value test in M0, and the reason ADR-0001 was acceptable at all: single-database tenancy means **one missing global scope is a cross-tenant data leak**, and Option A was accepted on the explicit condition that this suite exists and gates the build.

**Cases are generated from the models themselves, by reflection.** A hand-maintained list would be correct the day it was written and quietly wrong the first time somebody added a model and forgot a line - which is exactly the failure this suite exists to catch, so a list is the one shape that cannot be trusted. Adding a tenant-owned model without an isolation test is now impossible: the cases appear whether or not anyone remembers to write them.

Eight generated cases per model: read by id, `findOrFail`/`firstOrFail`, read by uuid, update by key, delete by key, create stamping the resolved tenant, each-tenant-sees-only-its-own, and a factory-exists check. The update and delete cases read the row back **outside any tenant** afterwards, to prove it is genuinely untouched rather than that the statement merely reported zero rows.

Where the generic case cannot build a model - `RoleAssignment` needs a user - the harness carries a documented **override**, never an exclusion. An exclusion removes a model from the gate; an override keeps it in and says what it needs. A tenant-owned model with no factory fails the suite with a message telling you to add one rather than silently skipping.

At the HTTP boundary: **404, never 403**. A 403 says "this exists, but not for you", which is a disclosure in itself - anyone holding a publishable key, which is designed to be readable in page source, could probe uuids and learn which are real. One test goes further and asserts the response for another tenant's record is **byte-identical** to the response for a record that never existed.

**Proven by sabotage, as the issue required.** Removing `BelongsToTenant` from `TenantDomain` turned the suite red with six failures naming `App\Models\TenantDomain` - both the structural check and the HTTP tests catching the actual leak. Restored, green. A guard nobody has watched fail is not evidence of anything.

Runs as its own CI job with the same three-shape guard as the MySQL group: no summary, any skip, or no passing test all fail the build. A gate that silently stops running reports green, which is worse than no gate.

Dropped `PlatformOwnedAllowListTest` from #5 - the isolation suite now covers the same ground more thoroughly, and two tests asserting one property is how one of them rots.

### #7 - Tenant resolution: four strategies in a fixed order, plus read-only mode

`ResolveTenant` tries four strategies and stops at the first match: API key, verified custom domain, hosted slug, panel session. **No match aborts 404** - there is no default tenant and no fallback (SEC-4), because the alternative to "I do not know which operator this is" is serving somebody else's data. 404 is also the right answer outward: it does not disclose whether a slug or hostname exists.

Each strategy is a small class implementing one interface, independently testable, returning `null` for "not my kind of request" rather than "no tenant, carry on".

**The ordering is a security property, not a preference.** API key first because the caller named a tenant explicitly and must never be reinterpreted by host. Panel session *last*, because an operator signed into their own back office opening a competitor's hosted page must see that operator's public page - a session is the weakest signal about what a request is *for*. Both precedence cases have their own test.

`tenant_domains` ships here per ADR-0010: `hostname` globally unique, stored lowercased and punycode-normalised at save. Normalising on write rather than comparing on read is what makes the unique constraint mean something - otherwise `Example.COM` and `example.com` are two rows answering the same question. Greek operators register Greek domains, so the Unicode form is a real input and must match the `xn--` form a browser actually sends; there is a test.

**Only `status = verified` resolves.** A pending or disabled row is not a claim of ownership, and honouring one would let anyone point a hostname at the platform and be served another operator's catalogue.

Read-only mode gates unsafe methods only. Guests keep seeing trips, existing bookings keep working, token pages keep opening; what stops is the operator changing anything. `past_due` still allows writes - that is the dunning window, not the punishment, and cutting an operator off the moment a card fails would break bookings over a payment that usually succeeds on retry.

Every log line now carries `tenant_id` and `request_id` (OBS-2). A log line without a tenant in a single-database multi-tenant system is close to useless: "booking confirmation failed" is unanswerable until you know whose.

Two test bugs of my own, both fixed rather than worked around: a catch-all route on `/` silently lost to `routes/web.php`, and a hand-written punycode string was simply wrong - PHP will tell you the real encoding if you ask it instead of guessing.

### #6 - API keys: table, generation and hashing, authentication middleware

`api_keys` from data-model section 2.1, key generation and one-way hashing, and the middleware that turns a presented key into a resolved tenant plus a capability set. This is strategy (1) of the four in TEN-4; #7 adds the rest.

Key shape is `{pk|sk}_{live|test}_{32 random chars}`. Type and environment live in the string itself, so anyone holding a key knows what it is without a lookup - and SEC-9's CI grep for a leaked `sk_` only works because of that.

**The raw key exists exactly once**, in the value the create action returns. Only `prefix`, `secret_hash` (SHA-256) and `last_four` are stored, so a database dump is not replayable against the live API. A test walks every column of the stored row asserting the plaintext appears in none of them, and the DTO's `toArray()` deliberately omits it so it cannot be serialised into a log line, a queued job payload or a Sentry breadcrumb by accident.

**Type is a ceiling, scopes only narrow it** (SEC-5), and it is enforced twice: at creation, so a key that could never be used cannot even be stored, and at read time, so a row widened by a bad migration or a hand edit still cannot be used. There is a test for the second case.

**A secret key arriving with an `Origin` header is refused outright.** Browsers always send one cross-origin, so that is precisely what a secret key leaking into front-end code looks like on the wire. Failing loudly beats working quietly - the quiet version means an operator ships their secret key in a page and never finds out.

The `api_keys` lookup necessarily runs before a tenant exists, since it is what resolves one. It goes through `Tenancy::withoutTenancy()` - the escape hatch built in #5, used here for its textbook case, and safe because `prefix` is globally unique.

`last_used_at` is throttled to one write per key per minute via `Cache::add`, which is atomic and driver-agnostic - database driver locally, Redis in production. Without it, authentication turns every read into a write, and the availability endpoint's 150 ms p95 budget goes with it.

Three test problems were fixed at the source rather than worked around. A DB-touching test was written under `tests/Unit` where `RefreshDatabase` does not apply, so it moved to `tests/Feature` - it was never a unit test. The guard asserting no direct `Redis::` call was grepping comments, and the docblock explaining *why* there is no Redis call made it fail; it now strips comments and greps code. And PHPStan cannot type `$this` inside a Pest closure, so both API test files use Pest's function API and local variables - the alternative, excluding `tests/` from analysis, would take the gate off the code most likely to lie.

### #5 - Tenancy foundation: single database, tenants/users/role_assignments, BelongsToTenant

Installed `stancl/tenancy` in single-database mode (ADR-0001 Option A) and landed the M0 tables from `docs/data-model.md` §6.

`App\Models\Tenant` implements stancl's `Tenant` contract directly rather than extending its model. That model is built around a string key and a `data` JSON blob, whereas every column here is one we filter, sort or index on - `slug` on every hosted-page request, `custom_domain` on every TLS handshake, `status` on the read-only middleware. Implementing the contract costs three small methods and keeps the schema honest. The Cashier columns ship now, unused until M7, because SQLite cannot add them to an existing table.

`BelongsToTenant` and `TenantScope` are ours rather than the package's, because the behaviour spec TEN-4 requires is not the package default: with **no tenant resolved the scope throws** instead of returning every operator's rows. Silently answering a question with the wrong tenant's data is the worst failure this product has. Deliberate cross-tenant access goes through `Tenancy::withoutTenancy()`, which is verbose and greppable on purpose.

`config/tenancy.php` was replaced rather than tuned. The published file is 200 lines of database-per-tenant machinery - connection managers, per-tenant filesystem roots, Redis prefixing, tenant migration paths - none of which applies here, and leaving it would tell the next reader that per-tenant databases exist somewhere. What replaces it is the single-database config plus the `platform_owned_models` allow-list TEN-5 requires, with `bootstrappers` deliberately empty and a note saying why an entry appearing there would be a silent move to per-tenant infrastructure.

The allow-list is enforced, not documented: a test walks `app/Models` and fails naming any model that neither uses the trait nor appears in the list. Verified by adding an unscoped model and watching it fail. This is the cheap structural half of what #8 completes.

`users` deliberately does **not** use the trait. `tenant_id` is nullable there and null means platform super-admin (ADR-0020 Option C), so a global scope would make super-admins invisible to their own panel. It is named in the allow-list with that reason.

Two demo operators seed deterministically: a Greek-first fleet operator on an active plan and an English-first single-vessel operator still trialing, so anything that only works for the first breaks visibly on the second. Isolation cannot be demonstrated with one tenant. `DatabaseSeeder` does not use `WithoutModelEvents` - model events are how `uuid` and `tenant_id` are assigned, and muting them would seed rows with a null public identifier.

One parity bug was caught before CI could: the scope test asserted `"role_assignments"."tenant_id"`, which is SQLite quoting. MySQL emits backticks, so it would have passed locally and failed in the MySQL job. It now strips quote characters and asserts the clause rather than the dialect.

The eleven PHPStan findings were fixed with real property and generic annotations - no baseline, no ignores. One was substantive: `Tenant::allowsWrites()` called an enum method on what PHPStan saw as a string, because the cast is invisible to it without the `@property` block.

### #3 - CI workflow: Pint, PHPStan, Pest on SQLite plus MySQL 8 and Redis

Five jobs on every pull request and every push to `main`: `lint`, `static-analysis`, `test-sqlite`, `test-mysql`, and a `ci-passed` aggregate so branch protection needs one required check and cannot silently miss a job added later. PHP 8.4 declared once in workflow-level `env` (ADR-0014 Option B). Composer installs cached on `composer.lock`.

`test-mysql` runs MySQL 8 and Redis 7 service containers, migrates from scratch, and runs the suite with cache, queue and session all on Redis so those code paths are exercised somewhere (ENV-1). Required on every PR, not nightly - ADR-0006 leans on it as the only place real parallelism is tested.

Two problems were found and fixed while building it. `SmokeTest` asserted `config('database.default') === 'sqlite'`, which would have failed the MySQL job for entirely the wrong reason; it now asserts the `.env.example` contract instead, which is what ENV-17 actually requires and holds on both engines. And the `mysql` group could have reported green without ever touching MySQL - PHPUnit *should* let the job environment win over `phpunit.xml`, but "should" is not evidence, so the group's test now asserts the driver really is `mysql` and CI fails on three separate shapes: no summary, a summary containing `skipped`, or a summary without `passed`. This matters well beyond this issue, because the overselling concurrency test joins that group in M2 and can only ever run there.

Also adds `docs/BUILD-LOG.md` - what has been built and the evidence it works, with the real command output rather than the intended output.

### #2 — Quality toolchain: Pint, PHPStan 6 with Larastan, Pest groups and the Composer scripts

Wired the full quality gate. `pint.json` uses the Laravel preset plus `declare_strict_types`, `strict_comparison`, `strict_param` and sorted imports; `phpstan.neon` runs level 6 with Larastan at `phpVersion: 80400` over `app`, `config`, `database`, `routes` and `tests`, with **no baseline** and `packages/wordpress-plugin` excluded because the plugin targets PHP 8.1 for operator hosting (ARC-9). Applying Pint reformatted the framework skeleton, which is the bulk of this diff.

The ENV-15 Composer scripts are in place: `setup`, `dev`, `test`, `test:fast`, `test:mysql`, `lint`, `lint:test`, `analyse`, `stan` and `ci`, each with a `scripts-descriptions` entry. `package.json` carries the seven ENV-16 npm script names as announced stubs so #3 and #4 have something to call before M3 and M4 fill them in.

`tests/Unit/ToolchainTest.php` guards the tooling itself: that the `fast` group runs, that a `mysql`-tagged test is excluded from `composer test`, that `Carbon::setTestNow` freezes time (TST-9), and that the suite runs in UTC.

**A real bug the guard test caught immediately:** Pest ignores a comma-separated `--exclude-group`. `--exclude-group=mysql,chromium,external` silently excluded nothing, so the MySQL-only test ran and passed locally — exactly the false green that ENV-11 exists to prevent. The flag is now repeated per group, and `composer test` correctly runs 8 of 9 tests while `composer test:mysql` runs the 1.

Two PHPStan findings were fixed at the source rather than suppressed: `$this->get()` inside a Pest closure is untypeable, so the smoke test now uses the `Pest\Laravel\get` function helper.

The §16 hooks deferred from #1 now live in `.claude/settings.json`: `PostToolUse` on Edit/Write runs Pint on the touched file when it is PHP and `vendor/bin/pint` exists, and `Stop` runs `composer test:fast` and prints a one-line result. There is no `jq` on this machine, so both parse the hook payload with PHP, which the project guarantees. Both were pipe-tested against synthesised payloads and re-verified verbatim from the stored JSON.

### #1 — Laravel 12 skeleton, SQLite local stack, `composer setup`

Scaffolded the application from an empty repository: Laravel 12.68 on PHP 8.4, with the local runtime contract fixed to SQLite at `database/database.sqlite` and cache, queue and session on the `database` driver, mail on `log`, and `APP_TIMEZONE=UTC` (storage is always UTC; display timezone is per tenant). `composer setup` takes a clean checkout to a migrated, seeded, asset-built application in one command with no manual step, and there is deliberately no `Makefile` and no root `docker-compose.yml`.

Added the `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` layout with an `Actions` directory in each, plus `app/Enums`. `tests/Feature/SmokeTest.php` asserts the app boots, `GET /` is 200, the timezone is UTC, the database is SQLite, every bounded context exists, and no local Docker or make entry point has crept in.

Two deviations from the issue as written, both deliberate: **Pest was pulled forward from #2** so the smoke test is not written in PHPUnit and rewritten a day later — #2 keeps Pint, PHPStan and Larastan. **`laravel/sail` was removed** from the skeleton's dev dependencies, since it is a local Docker tool and local development here is native (ENV-3, ENV-4).

Also needed, outside the repository: nine PHP extensions were commented out in the Windows `php.ini` — `fileinfo`, `pdo_sqlite`, `sqlite3`, `zip`, `gd`, `intl`, `exif`, `sodium`, `pdo_mysql`. Without `pdo_sqlite` the local stack cannot run at all. Documented in `README.md`.

### Project foundation

`CLAUDE.md`, eleven subagent definitions in `.claude/agents/`, and four slash commands in `.claude/commands/`. The §16 hooks are deferred to #2, where the toolchain they invoke actually exists.

`docs/spec.md` (~340 numbered requirements), `docs/data-model.md` (56 tables, four state machines), `docs/api.md` (17 endpoints, 60 schema components), and 23 accepted ADRs in `docs/adr/`. Thirty-seven issues for M0 and M1.
