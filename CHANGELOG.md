# Changelog

## M1 — Catalogue and availability engine

### #15 - Translatable infrastructure: the I18N-5 fallback on model attributes, observer-maintained search and sort columns, and the ENV-8 gate

ADR-0008 chose JSON columns over a translations table on one condition, stated as a rule rather than a preference: **no query may sort or filter on a JSON path**. Everything here is the machinery that makes catalogue text obey that rule before there is any catalogue text — `Product`, `Vessel`, `Port` and the rest are #16 and later, which is exactly why this lands now. Building it alongside the first catalogue screen would mean discovering its behaviour halfway through building the catalogue, where every bug looks like a catalogue bug.

**The rule is not about taste, it is about two engines disagreeing.** `JSON_UNQUOTE(JSON_EXTRACT(…))` under MySQL's `utf8mb4_unicode_ci` folds tonos; SQLite's `json_extract` under `NOCASE` folds nothing outside ASCII. Local development runs on SQLite (ADR-0015) and production on MySQL, so a search written in SQL returns different boats in each — and the developer sees the answer that is *not* the one operators get. Folding on both sides in PHP means the database only ever compares bytes this application produced. `search_index` for the haystack, `{attribute}_sort_{locale}` for ordering, `GreekText` for the folding, and `SearchIndexObserver` to keep them true.

**The observer runs on `saving`, not `saved`.** The columns are set before the INSERT or UPDATE is built, so they travel in the same statement. On `saved` they need a second write — which doubles the statements on every catalogue edit, and leaves a window inside a transaction where the row exists with an empty `search_index`. A concurrent read in that window returns a product that cannot be found by its own name. The test reads the value back out of the database rather than off the model, because the `saved` version looks perfectly correct in memory.

**It rebuilds unconditionally rather than on `isDirty()`, and that is not laziness.** A sort key is built from the *fallback-resolved* value, so it depends on the tenant's `default_locale` as much as on the JSON — an operator switching their house language changes every sort key in their catalogue without touching a single product. The cost of being right is one `mb_strtolower` and a `strtr` per field, against a database round trip that is already happening.

**Two enforcements of `docs/data-model.md` §1.6, and they disagreed.** §1.6 requires both `el` and `en` on every translatable column and says an observer rejects a set missing either; a form that throws a 500 after twenty minutes of typing loses the twenty minutes, so `TranslatableRequired` states the same rule where an operator can act on it. Written as two loops, they answered differently: `getTranslations()` drops null and empty-string entries but **keeps `"   "`**, so an operator who tabbed past the English field was refused by the form and accepted by the observer — meaning the one writer with no form on it, the CSV import, was also the only one that could get the value in. `TranslationValue::missingLocales()` is now the single implementation both call, and it is the same call that decides blankness for the fallback chain, so a value the form calls empty is empty everywhere. The whitespace case is a test, not a note.

**The fallback chain is applied per field, which the middleware cannot do.** `SetLocale` (#12) runs once per request; a product with no English summary is a per-field question. The package's own `getFallbackLocale()` hook returns a **single** locale, and I18N-5 has two steps after the requested one — a Greek-default operator whose product has only an English summary and an English-default operator whose product has only Greek are both completely ordinary, and one fallback serves one of them while showing the other a blank field. So `HasKaikiTranslations` calls the package once per candidate with fallback disabled and the package's chain never runs. It short-circuits on the requested locale, so a product list rendering forty rows in the operator's own language does no extra work at all — asserted by a test that resolves no tenant and still gets its value.

The chain is also deliberately **not** "any locale that happens to have something in it". With no tenant resolved there is nothing after `en`, and a row whose English title was deleted sorts under an empty key rather than quietly borrowing the Greek one — because the alternative is the panel and the public API disagreeing about what a product is called depending on how the request arrived.

**`kaiki:backfill-translations` exists because three ordinary things go around an observer**: a bulk `insert()`, a `saveQuietly()` in an importer, and a migration that adds a column only PHP knows how to fill. `--dry-run` is a drift **check** rather than a preview — it exits 1 when any row would change, so it can gate the nightly workflow. The failure it catches is otherwise completely silent: a boat stays on the list, stays bookable, and simply stops being findable by name, and nobody files a bug about a boat they cannot see. It runs **per tenant**, because every translatable table in §1.6 is tenant-owned and a sort key resolves against the tenant's `default_locale` — run outside tenant context it would rewrite a Greek operator's sort keys with English text. It repairs with `saveQuietly()` and `timestamps = false`: fixing a derived column is not an edit, and bumping `updated_at` across a catalogue would show up as the operator's own change in every "recently modified" list and every future audit trail.

**The ENV-8 gate is wired now, while it costs nothing to make green.** There is not one `json_extract`, `->>` or JSON-path column name in `app/` today, so the scanner passes on an empty diff — and from here every catalogue screen either uses the companion columns or turns the build red. Left ungated the failure is invisible in development, because `json_extract` works fine on SQLite: the local product list sorts, the local search finds things, the tests pass, and it is production where Greek sorts into a different order. Like #12's lints it is proved by sabotage rather than by assertion — a fixture that is permanently wrong carries every forbidden shape (`orderBy('title->el')`, `pluck('summary->el')`, hand-written `json_extract`, `JSON_UNQUOTE`, `->>`), and a second test pins the shapes that must **not** be flagged, because a gate that fires on `orderByTranslation('title')` is a gate somebody switches off within a week. Comment lines are skipped, or documenting the rule would mean breaking it.

**A fixture model, because the alternative is shipping this unexecuted.** `TranslatableFixture` and its table live under `tests/` and are the whole per-model adoption cost the traits advertise — two `use` statements and four attribute lists. If a real catalogue model ever needs more than that, the arrangement has failed at the thing it exists for, and this fixture is where that shows first. It is also what makes PHPStan *analyse* the traits at all: a trait nothing uses is skipped entirely as `trait.unused`, which is how the missing observer went unreported through the whole red build that preceded this one. The table is a migration under `tests/`, registered with the migrator from `Tests\TestCase::refreshApplication()` — `Schema::create()` in a `beforeEach` would be DDL inside `RefreshDatabase`'s transaction, harmless on SQLite and an implicit commit on MySQL 8, so the isolation would disappear in CI and nowhere else.

`config('kaiki.i18n.required_locales')` is a **third** locale list beside the installed and permitted ones, and is not env-settable. EXT-7 says adding a locale is a lang-file change only; derive the requirement from the installed locales and shipping German lang files would invalidate every product in every catalogue overnight. An env var that relaxed it would mean staging and production disagreed about what a valid product is, and the import that passed in staging is the one that fails on launch day.

`app/Rules` joins the I18N-2 hardcoded-string scan. A validation rule is a sentence an operator reads, and `TranslatableRequired` is the first one in the project — its message names the language ("Ελληνικά, English"), not the ISO code, because the operator picked their language from a switcher that said Ελληνικά.

**One acceptance criterion could not be met as written, and it is worth knowing why.** The issue asks for the search haystack in "a plain **indexed** column". The `*_sort_{locale}` columns are indexed; `search_index` is not, and no declaration would have worked: MySQL 8 refuses an index on a `TEXT` column without a key length, SQLite has no prefix indexes, and a prefix index would buy nothing anyway because `LIKE '%term%'` cannot use a B-tree. The index that *would* help is `FULLTEXT`, which `docs/data-model.md` §0 forbids outright for the same both-engines reason. CAT-6 — authoritative over the issue text — asks only for "a single per-row `search_index` text column containing all locales concatenated and accent-folded", so catalogue search is a tenant-scoped scan over tens to hundreds of rows, exactly as the spec describes it. At real volume the answer is the search backend ADR-0008 already parks as a future ADR, not an index here. The reasoning sits beside the column in the migration rather than only in this file.


## M0 — Foundation

### #44 - The platform merchant list in /admin, and a stat row

`/admin` has been an empty panel since #9. It had the super-admin boundary, the role matrix and the policy-coverage gate, and nothing inside — so the platform owner had no way to see their own operators, and the only screen proving multi-tenancy works was a dashboard with nothing on it.

`SAA-1 (FIXED)` contracts the whole surface — tenants, plans, impersonation, feature flags, platform health, error feeds, announcement banner — but it is M7, and no M7 issues exist, so it was a spec line with nothing tracking it. This pulls forward the read-only merchant list and four counters, requested twice by the product owner during the #11/#12 session.

**Read-only is load-bearing, not timidity.** The moment `/admin` can change an operator's record, SEC-16's "confirmed and audit-logged with actor, timestamp and reason" applies — and that audit log is exactly what #42's ADR-0025 has not decided. Refusing every write is what lets this ship before that decision, and the refusals are asserted rather than left implicit: **Filament allows an action when nothing forbids it**, so "we did not build an edit page" is not the same as "editing is refused". A test pins `getPages()` to exactly `['index']` and asserts a super-admin can neither create, update, delete, restore nor force-delete.

**`TenantPolicy` deliberately does not extend `TenantOwnedPolicy`.** Every other policy does, but that base gates on the TEN-8 `Capability` matrix and compares `tenant_id` — and `tenants` is not tenant-owned, it *is* the tenant. A super-admin has no role assignment and a null `tenant_id`, so inheriting it would have made every method return false. Consistency that produces the wrong answer is not consistency. `forceDelete` is refused outright for a separate reason: `tenant_id` cascades across the whole schema, so a force delete from a list screen would erase every booking, invoice and ναυλοσύμφωνο an operator has. That belongs behind M6's GDPR erasure tooling.

**This is the one resource in the application that is deliberately cross-tenant**, and the test says so. Everywhere else a query returning two operators' rows is the defect #8 exists to catch; here it is the feature. The assertion that two merchants appear is what would fail the day someone "helpfully" adds `BelongsToTenant` to the `Tenant` model — the list would otherwise quietly show one row and look perfectly fine.

Plan and status render through `Plan::label()` and `TenantStatus::label()` from #12's consolidated `enums.php`, so the super-admin panel and the operator panel cannot disagree about what "past due" is called. Soft-deleted operators are hidden by default and reachable through `TrashedFilter` — a cancelled account's data still exists and the platform owner is the one person who may need to see it, but not by default, or the list stops meaning "our merchants".

The stat row is **one grouped query, not four counts**, because it renders on every `/admin` page load, and it is **not lazy**: Filament defers widgets to a second Livewire request by default, which earns its keep for an expensive chart and costs a round trip and a layout shift for four integers. It is also the first thing on the first screen, which is the worst place for numbers that pop in a moment late. Deleted accounts are excluded everywhere, so the total cannot disagree with the list directly beneath it.

The lang file says **"Merchants", not "Tenants"** — `tenant` is our word for a row in a table, and the audience here is the person who owns the platform and thinks of them as the businesses paying for it.

**Two things not done.** The issue asked for vessel and booking counts "where cheap"; those tables do not exist yet — `vessels` is #16 and `bookings` is M2 — so the columns are omitted rather than stubbed, and become a one-line `withCount()` when the tables land. And the browser walkthrough in the plan could not be run: the Chrome extension stopped responding twice, so the rendering is covered by thirteen tests including the real HTTP render of both the list and the landing page, but nobody has yet clicked it.


### #11 - OpenAPI skeleton, the `/api/v1` group, and the docs/api.md drift gate

`docs/api.md` is a **4,100-line contract for sixteen endpoints, none of which existed**. It was written ahead of the code on purpose — §10.5 is explicit that the direction of authority runs contract → code and that the file is *never* regenerated from the routes. Nothing checked that the two agreed, and nothing ever had. Wiring that gate is cheapest now, while there is nothing to reconcile; from here every M1 endpoint either lands matching its contract or turns the build red. **This closes M0.**

**The pipeline §10 describes cannot be built, and the reason is a package.** Steps 2 and 4 need to parse YAML — `spectral lint`, `$ref` resolution, required request-body fields — and there is no parser available: `symfony/yaml` is not installed, PHP's `yaml` extension is not enabled in any tier, Node ships none, and every candidate is outside ARC-20 and ADR-0019, which CLAUDE.md calls "a hard stop, not a judgement call". So this issue built the strongest gate a **line scanner** supports — the same call `tests/Support/WorkflowFile.php` made in #4, for the same reason — and raised **ADR-0026** for the rest rather than quietly adding a dependency to finish the job. The ADR is proposed, not decided.

**The diff is directional, and that is a deliberate deviation.** §10.4 compares "the set of paths and methods", which is red from today until M3 — and a gate that is red for months is a gate somebody switches off. So every route the application exposes must appear in the contract with matching method and security schemes, while a documented path with no route yet is *not built* rather than drift. The unbuilt list is derived, never maintained by hand, and printed on every run: `contract surface: 1 of 18 operations built`. When the last endpoint lands it becomes the full equality §10.4 asks for with no code change. The §10.4 wording is proposed to the architect rather than edited here.

**`/api/v1/health` was not in the contract.** The issue requires the endpoint; building it would have created exactly the undocumented endpoint the gate exists to catch. §10.5 prescribes the fix — "change the code, or change this file **and** say why in `CHANGELOG.md`" — so the path was added to §5 with `operationId`, `summary`, `security` and a `4xx`, plus a `Meta` tag. **This is that CHANGELOG line.** The endpoint is authenticated on purpose: an unauthenticated route would be the only endpoint in the document with no tenant, and it answers the more useful question — *is this key accepted, and which version is answering* — rather than "is the server up", which is what `/up` is for. It returns no tenant identifier; a key already implies its tenant, and echoing a slug would make the cheapest endpoint in the API a tenant-enumeration oracle.

**A regression from #12 surfaced here, and it was not visible in #12.** Adding `ResolveTenant` to the middleware priority map to fix a locale bug also hoisted it above `AuthenticateApiKey` on `/api/v1/health` — even though `routes/api.php` lists `api.key` first. A request with **no key at all returned 404 instead of 401**: tenant resolution ran first, found nothing to resolve from, and aborted before the middleware whose entire job is to say "no key". The sorter reorders only what it knows about, so **once one middleware is in the priority map, they all have to be**; the full chain is now pinned and asserted by `ApiMiddlewareOrderTest` on a route that deliberately lists it backwards. A 404 where a 401 belongs tells an integrator "this endpoint does not exist" when the truth is "your key is missing" — an afternoon instead of five minutes.

**One envelope, in one place.** §4.1's shape was hand-rolled in three middlewares; three copies is how the fourth comes out subtly different. `ApiErrorResponse` now owns it, and `ApiExceptionRenderer` extends it to the failures no controller ever sees — a mistyped path, the wrong verb, a validation failure — which Laravel otherwise answers with HTML or its own `{"message": …}`, handing a widget that branches on `error.code` a parse error instead of a reason. Scoped to `/api/v1`, so the panel keeps rendering Laravel's error view. `details` is omitted rather than sent as `null`, because a client checking `if (error.details)` and one checking `if ('details' in error)` must agree.

**Both halves of the gate were proven by sabotage**, as #12's lints were: an undocumented route was named by both the route check and the generated-document check, and a security scheme changed in the contract produced `get /api/v1/health: contract says [SecretKey], route enforces [PublishableKey, SecretKey]`. The generated document is compared too, not only the route table — Scramble infers from code and gives up quietly on shapes it does not understand, so a route it cannot see is a real failure mode. `build/` is gitignored: a generated file sitting beside `docs/api.md` is how someone eventually edits the wrong one.

`ENV-23` gains `api-docs-drift`, the same gap #12 left open for `i18n`; both are proposed to the architect rather than edited into the spec.

**Fixed after the first push, which went red.** `build/` existed only on the machine where `composer api:docs` had first been run by hand, so on a clean checkout the export died with `file_put_contents(build/openapi.generated.json): No such file or directory` — taking down `test-sqlite`, `test-mysql`, `coverage` and `api-docs-drift` together, all from one missing directory. The local suite had been green throughout because the environment had quietly diverged from a fresh clone. The directory is now committed with a self-ignoring `.gitignore`, the pattern Laravel uses for `storage/`, so the documented one-command refresh path works from a clone; the test also ensures the directory rather than assuming it; and a new assertion fails if anything but that `.gitignore` is ever tracked under `build/`. `migrate-from-zero` passed on that same run, which is what confirmed the hand-edited schema snapshot from #12 was correct.


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
