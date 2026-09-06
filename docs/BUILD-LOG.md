# Kaiki — build log

What has actually been built, and the evidence it works. One entry per issue, newest first.

Each entry records the **verification actually run** and its **real output** — not what was supposed to happen. Where something can only be checked in CI, it says so rather than claiming a local pass. Where an issue was delivered differently from how it was written, the deviation is stated with its reason.

`CHANGELOG.md` is the narrative for a reader; this file is the audit trail.

---

## Status

| | |
|---|---|
| Milestone | **M2 — Booking & payments, in progress.** M1 complete. |
| M0 | closed by #11 — #1 … #12, with #13 and #14 moved to `M8 — Launch & deployment` |
| M2 | #79, #80 |
| M1 | **Closed by #53.** #15, #16, #17, #47, #23, #18, #19, #20, #22, #21, #24, #33, #34, #25, #26, #27, #28, #29, #30, #31, #32, #35, #36, #37, #53 |
| Pulled forward | #44, a read-only slice of M7's `/admin` |
| Local stack | Laravel 12.68 · PHP 8.4.25 · SQLite · database/file drivers |
| Quality gate | Pint · PHPStan level 6 + Larastan · Pest (1552) · **cross-tenant isolation gate** · **ENV-8 JSON-path gate** · EL/EN parity · OpenAPI drift · coverage of `app/Domain` · dependency audits · schema drift — **all green** |
| Deployment | Deliberately last (#13, #14 moved to `M8 — Launch & deployment`) |

> **Entries missing for #24, #33, #34, #25, #26, #27, #28, #29, #30, #31 and #32.** All eleven are merged on `main`; none has an entry in this file or in `CHANGELOG.md`. They are not written here after the fact on purpose — this file's own rule is that *"inventing entries in this file's usual detail long afterwards would be reconstruction rather than an audit trail"*. The per-issue narrative is in each pull request until somebody who was there writes them.

**Open, not blocking, and not mine to close:**

| What | Who | Needed before |
|---|---|---|
| VAT **rates** (mechanism settled in ADR-0002; the numbers are not) | accountant | M6 |
| Invoice-numbering **gap policy** (ADR-0022) | accountant | M6 |
| Revisit ADR-0023 against the NFR-1 p95 benchmark | benchmark result | end of M2 |
| **ADR-0024** — two-factor authentication, the mechanism and its timing | product owner | SEC-15 (see #9) |
| ~~**ADR-0025** — the operator audit log~~ | ~~product owner~~ | ~~Decided 2026-09-04~~ — **built by #53.** |
| **Advisory `from_price_cents` per age band** (`docs/api.md` §9 item 6) | product owner | M3 — the widget's list mount |

---

## #80 — The booking aggregate, and the four seams M1 left open

**Files:** four migrations at §6 positions 30–33, `app/Models/{Booking,Voucher,BookingGuest,BookingExtra}.php`, `app/Enums/{BookingStatus,BookingSource,GuestDetailsStatus,GuestDocumentType,CancelledBy,CancelReason,VoucherStatus,VoucherReason}.php`, `app/Domain/Availability/{Actions/HoldSeats,Actions/ExtendHold,Actions/ReleaseHold,Support/HoldLock,Contracts/DepartureExpiredHolds}.php`, `app/Domain/Booking/{Actions/CreateBookingDraft,Actions/ExpireStaleHolds,Data/BookingDraftData,Support/BookingHoldSource,Support/BookingProductCount,Support/LeadGuest}.php`, `app/Support/Booking/BookingReference.php`, `app/Rules/BookingReferenceFormat.php`, `app/Exceptions/{HoldRefused,HoldLockUnavailable}.php`, `app/Events/BookingHoldExpired.php`, `app/Jobs/ExpireStaleHoldsJob.php`, `app/Policies/{Booking,Voucher,BookingGuest,BookingExtra}Policy.php`, `app/Providers/BookingServiceProvider.php`, `config/kaiki.php`, `routes/console.php`, `lang/{el,en}/{booking,enums}.php`, four factories, five test files

The largest issue in M2 and the one every other table in the milestone points at.

### The cycle that has to be broken, permanently

`bookings.voucher_id` is a real foreign key; `vouchers.issued_for_booking_id` points back. SQLite cannot add a foreign key to an existing table (§0), so one side has to give — and §6 settles which: `vouchers` is created first and its back-reference is a plain indexed `unsignedBigInteger` with **no FK, ever**. Integrity is the application's and the nightly reconciler's, the same permanent accommodation `vessel_blocks.booking_id` already carries.

`Voucher::issuedForBooking()` is therefore a method and not a relation, so the absence is visible at the call site rather than looking like an ordinary `belongsTo` that happens to skip referential integrity.

### The hold is data; the lock is a mutex

ADR-0005 opens by saying the two must not be conflated, and the reason is that the rejected design fails silently: a cache-resident hold evaporates on a Redis restart and every boat is sold twice with nothing in any log.

So `HoldDurabilityTest` **flushes the cache mid-hold** and asserts the seats are still held. On Option B that test passes by doing nothing; here it has to survive. A second test takes the lock immediately after a hold is created and asserts it is free — the hold has fifteen minutes to run and the mutex had five seconds, which is what lets the next guest book the remaining seats instead of queueing behind somebody's checkout.

`NoDirectRedisTest` scans `app/` for `Redis::` and `RedisStore`, because a direct call passes every test in CI — where Redis is real — and cannot run at all on the SQLite stack this project develops on. The failure is invisible to whoever writes it.

### Expiry twice over, and which half is the guarantee

AVL-38 asks for both and they fail in opposite directions. The **read side** is the authority: a hold is gone the instant `hold_expires_at` passes. The **sweeper** brings `departures.seats_held` back into line so an operator's dashboard is not showing seats held by nobody.

`HoldExpiryTest` asserts the read-side release **with the sweeper never run**, which is what proves the order of dependence rather than describing it in a comment. The mirror case is asserted too — a live hold is *not* released — because a fast sweeper must never release a hold a read still counts.

Both `HoldSeats` and `ReleaseHold` **recompute** the counter from live holds rather than incrementing or decrementing it. An increment is only correct if every previous one was; a recount is right whatever happened before it, which makes the Actions self-healing, makes a double release safe, and means a sweeper outage costs throughput rather than correctness.

### The four seams, filled together

M1 shipped four interfaces with no implementations, each with a docblock promising M2 would add one class and one `tag()` line. This is that line, four times:

| Seam | Answers | Silent failure without it |
|---|---|---|
| `DeparturePersonsAboard` | AVL-25's legal head-count | a boat sails full of infants |
| `VesselHoldSource` | AVL-3.4's expiring occupation | two guests check out for the same boat |
| `DepartureExpiredHolds` *(new)* | AVL-38's read-side release | a queue backlog costs bookings |
| `ProductBookingCount` | can a product's mode still change | a mode change reinterprets a live booking |

Three are one class. They are one question asked three ways — *what do the bookings on this boat currently mean?* — and three files that must agree about "an unexpired hold" are three chances to disagree.

They were wired as a group rather than one at a time because **every one of those failures is quiet**: nothing throws, nothing logs, and the numbers stay plausible.

### The query budget, and why one number moved and the other did not

Filling the seams cost queries, and the two costs were handled differently.

**The read-side correction did not raise the engine's budget.** NFR-7 gives the availability read five queries whatever the range, and asking "which held seats have lapsed" made it six on *every* request — including the overwhelming majority where nothing on any date in the range is held by anybody. It is now skipped when every departure has `seats_held = 0`. That is not a speed-for-correctness trade: an expired hold is a subtrahend and there is nothing to subtract from.

**The API ceiling did move, twelve to thirteen.** `VesselHoldSource` is a query that genuinely has to run — there is no cheap local signal for "is anybody holding this boat privately" the way `seats_held = 0` is one for seats. It is one query for the vessel across the whole range, and the test's own equality assertion (62 days costs what one day costs) is what proves it did not become one per date. Without it two guests can be at the checkout for the same boat on the same afternoon, which is worse than a thirteenth query.

### Two arithmetic slips in the spec, found by writing the code

**BKG-3 item 2 states the alphabet and then miscounts it.** *"The digits and uppercase letters minus `0 O I 1 L U`"* is eight digits plus twenty-two letters — **thirty** symbols, and 30^5 is **24.3 million**. The requirement said 31 and ~28.6 million, which are the figures for a 31-symbol alphabet. The rule is the specification and is unchanged; the two derived numbers are corrected, and `BookingReferenceTest` now asserts the count so they cannot drift apart again.

**ADR-0007's `char(9)` cannot hold what ADR-0007 produces.** Its own collision strategy widens the random part to six characters after five failed attempts, which is ten characters with the prefix. `docs/data-model.md` §2.5 already said `varchar(16)` and is authoritative on schema (`CLAUDE.md`); `docs/spec.md` BKG-3 item 1 is corrected to match.

### The bug only the real prefix could find

`BookingReference::normalise()` maps confusable characters onto what a guest meant — `O` to `0`, `I` and `L` to `1`. The default brand prefix is **`KAI`**, and it contains an `I`.

Mapping across the whole string therefore rewrote every reference's own prefix to `KA1` and then failed to recognise it, so `KAI-7F3K2` — a reference the class had generated a line earlier — did not validate. Every one of the six normalisation cases failed, including the one that was already in canonical form.

The map now applies to the random part only. The prefix is ours and is never ambiguous; only what follows it was ever read off a printed ticket in the wind.

### One column added, because the spec asked for a fact and the schema held its evidence

BKG-7 requires explicit consent to the operator's terms *"with a stored timestamp"* and GDR-9 says it lives on the booking. §2.5 listed `ip_address` and nothing else. `terms_accepted_at` is the fact; the address corroborates it. Added with §2.5 updated to match.

### BKG-8, and the exception that escaped the wrapper

The requirement is one sentence — *a malformed phone blocks SMS but MUST NOT block the booking* — and it is the entire design of `LeadGuest`: nothing throws, an unreadable number is stored as null, the booking proceeds.

**Which is exactly what the first version did not do.** `propaganistas/laravel-phone` wraps `giggsey/libphonenumber`, and catching only the wrapper's `NumberParseException` let the underlying library's own exception escape — turning "the guest typed their number oddly" into a 500, which is precisely the outcome the requirement forbids. The contract is "never throw", so it is now enforced as one.

The MX check has the same posture. A lookup that fails is *cannot tell*, and the address is accepted: DNS is a network call in the middle of a checkout, it fails in sandboxes and behind blocked egress, and a form that refuses an address because a resolver timed out refuses valid customers.

### A new package

`propaganistas/laravel-phone`, first use, from the ADR-0019 shortlist `CLAUDE.md` pre-approves. E.164 normalisation is BKG-8's explicit requirement and hand-rolling it for Greek mobiles alone would be wrong the first time a German guest books.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate:fresh` (SQLite) | four tables at positions 30–33 |
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — five real findings fixed at source, no baseline |
| `composer test` | **1552 passed**, 0 failed (after the snapshot refresh) |
| `tests/Feature/Booking` + the architecture test | 48 passed |

The snapshot was refreshed the documented way — it needs MySQL 8 and the local stack is SQLite (ADR-0015), so `docs/ci.md`'s procedure applies: push, take the `mysql-schema-snapshot` artifact, commit it.

Not run locally, by environment rather than by choice: the MySQL job. **The AVL-44 two-parallel-confirmations test is not here** — it belongs with confirmation in #81, because there is nothing to confirm yet.

### Things this touched that were not its own

1. **Five PHPStan findings, all real.** Two nullsafe accesses on the left of `??` that PHPStan correctly called unnecessary; a validation rule whose `$fail` signature did not match the interface it implements; and two Pest closures calling `$this->travel()` and `$this->fail()`, which are not statically visible because `$this` is a `TestCall` at analysis time. The last two are the same shape as #79's `withoutExceptionHandling()` — the explicit forms (`Carbon::setTestNow`, capturing the exception) are what both the analyser and a reader can follow, and capturing also fails with a useful sentence when nothing is thrown at all.

2. **The M1 availability scenario builder makes a rate plan but no per-band prices**, because the tests it was written for never ask what anything costs. A booking does, and PRC-5 refuses to price an unsellable product rather than charging zero — so the draft test adds the price row rather than loosening the assertion. A total of zero would have been a free trip passing every arithmetic check on its way to a gateway.

3. **`enums.booking_source.wordpress.label` joins the identical-translations allow-list.** A Greek operator whose site runs on WordPress calls it WordPress.

---

## #79 — `integration_credentials`, and the contradiction that had to be settled before the migration

**Files:** `database/migrations/2026_09_02_000029_create_integration_credentials_table.php`, `app/Models/IntegrationCredential.php`, `app/Enums/{IntegrationProvider,CredentialEnvironment}.php`, `app/Contracts/CredentialVerifier.php`, `app/Domain/Integrations/{Actions/SaveIntegrationCredential,Actions/VerifyIntegrationCredential,Actions/DeactivateIntegrationCredential,Data/IntegrationCredentialData,Data/VerificationResult,Support/CredentialRepository,Support/VerifierRegistry}.php`, `app/Exceptions/IntegrationCredentialIncomplete.php`, `app/Policies/IntegrationCredentialPolicy.php`, `app/Providers/IntegrationServiceProvider.php`, `app/Filament/App/Pages/Integrations.php`, `resources/views/filament/app/pages/integrations.blade.php`, `database/factories/IntegrationCredentialFactory.php`, `lang/{el,en}/{integrations,enums}.php`, `docs/{spec,data-model}.md`, four test files plus a scanner and its fixture

**M2 opens here.** §6 item **29**, and first in the milestone because `payments` (35) and the gateway contract both hold a foreign key to it, and §6's rule is absolute.

### The contradiction, and why the ADR is not overturned

`docs/spec.md` PAY-4 and ADR-0004 Option A named a table `payment_gateway_accounts` (`gateway`, `mode: live|sandbox`, `last_verified_error`). `docs/data-model.md` §2.7 named one `integration_credentials` (`provider`, `environment: live|test`, `last_error`, plus `public_config`, `is_active`, `webhook_secret`). One table, two names, and only one could be built.

The data-model table won on the merits: it is a **strict superset**. It does everything ADR-0004's does and also holds the myDATA, SMS and Postmark credentials, whose alternative is three more tables or a column group on `tenants` — four table rebuilds on SQLite (§0). ADR-0004's actual *decision* is untouched: encrypted cast columns, no external secret store, no per-tenant key separation, one row per tenant per provider per environment. Only its illustrative name is reconciled, the way ADR-0013's colon-form scope examples were corrected on 2026-08-28.

`sandbox` → `test` is the one substantive change, and it went to `docs/spec.md` PAY-4 with the reason in `CHANGELOG.md` per `docs/api.md` §10 item 5. `api_keys.environment` was already `live | test`, and PAY-11 pairs them directly. Two enums are now asserted to have identical case lists, and a second test asserts no case named `sandbox` exists — a vocabulary settled once in a document drifts the first time somebody adds a case to one enum and not the other.

### One column added to §2.7, because ENV-8 left nothing else

The issue's closing note asks for a lookup by provider plus external account id **with no tenant in context** — `gateway_webhook_events.tenant_id` is nullable precisely because a webhook arrives before the tenant is resolved, and this table is what resolves it.

The obvious home is a key in `public_config`. **ENV-8 forbids ordering or filtering on a JSON path**, so that is not available. `external_account_id` is therefore a plain indexed varchar with a deliberately not-tenant-first index, written from the matching `public_config` key on save. The same rule `ical_sources.url_hash` already illustrates.

Two tests keep the pair honest: every provider's `externalAccountField()` must be inside its own `publicFields()` — otherwise the data object reads a key the form never collects, the column stays null, and the resolver silently finds nothing — and an empty identifier must never match the first row with a null column, which is how a webhook gets filed against a stranger. The first of those **failed on its first run**: Stripe's `publicFields()` was `[]` while its external account field was `account_id`.

### The cast is the entire security story, so it is asserted from outside the model

ADR-0004 accepted the residual risk of `APP_KEY` plus a database dump explicitly. That makes "is the cast actually applied" a security assertion, and it is one the model cannot make — a silently dropped cast returns exactly the same string.

So the tests read the raw column through the query builder. They assert the ciphertext contains neither the plaintext nor the field names, **and** that it decodes to Laravel's `{iv, value, mac}` envelope, because an empty column also passes "does not contain the secret".

**The `APP_KEY` rotation test passed for the wrong reason on its first run.** `config()` plus `app()->forgetInstance('encrypter')` looks sufficient and is not: the `Crypt` facade caches its own resolved instance, so the cast kept using the old encrypter and the assertion proved nothing about MAC verification. `Crypt::clearResolvedInstances()` is what makes it real.

### Three layers past the cast, because a cast only protects the database

- `$hidden` — out of `toArray()` and `toJson()`, which is what a log context, a queue payload and a Sentry breadcrumb are built from.
- `__debugInfo()` — redacted for `dd()` and `var_dump()`, which ignore `$hidden` entirely and are what somebody reaches for while debugging a failing checkout.
- `NoCredentialLeakTest` — a scanner, because the first two are conventions.

### The scanner's first two findings were both in itself

1. **The `->revealable()` rule was per line.** Pint wraps a fluent chain the moment it passes the line limit, so the realistic three-line shape was invisible — the lesson `LiteralScanner` had already learned and written into its own docblock. It is now per statement, with a fixture written wrapped so the rule cannot silently regress to line-based.

2. **With that fixed, the first thing it flagged was the comment saying never to do it.** `Integrations::providerFields()` carries a note explaining why `->revealable()` must not be used on those fields. A lint that fires on the note explaining the lint is a lint somebody switches off, so comments are blanked before the statement pass — offsets preserved, so line numbers stay true.

The scanner has the third test the VAT scanner taught: that it does **not** fire on a cast list, a `$hidden` array, a redaction, a log line carrying `public_config`, or a credential being used for the thing it is for.

### Verification exists as a seam, and says so rather than lying

Five of the seven providers are not gateways, and `App\Contracts\PaymentGateway` is fixed at four methods by ADR-0004 — none of them this one. So `CredentialVerifier` hangs off the credential and resolves through `VerifierRegistry`.

**The registry is deliberately empty**, the same shape as `GuardVesselCapacity::TAG` and `SaveProduct::TAG`: the clients arrive with the issues that introduce them (Viva and Stripe with the gateway contract, Postmark and the SMS vendors with the notification issue, myDATA in M6), and each adds one `register()` line.

Pressing verify today therefore writes `last_error` with a plain Greek sentence and **not** `verified_at`. Reporting success would put a timestamp on credentials nothing has ever tried, and PAY-11's promise that sandbox mode *"MUST be impossible to enable accidentally"* leans directly on that timestamp meaning something. A test records which providers are live, so the gap stays visible in the suite.

**This is the issue's one delivery deviation.** Its acceptance criterion says "a test call runs"; with no clients in scope, what ships is the seam, the write on both outcomes, and the honest refusal — exercised in tests through registered fakes for the success, rejection and unavailable paths.

### The default flag, in an Action rather than in an index

Exactly one `is_default = true` payment gateway per tenant per environment. A partial unique index would let the database enforce it and partial indexes are not portable to MySQL 8; a plain unique index would forbid a second *non*-default row, which is the ordinary case.

The clearing update and the insert are one transaction with `lockForUpdate()`. The concurrency half is tagged `mysql` rather than skipped — the lock is a no-op on SQLite (ADR-0006), and a vacuous local pass is worse than an honest CI-only one.

Two behaviours that are not in the criterion and are wrong without: `is_default` is **forced off** for the five providers it means nothing for (a flag with no reader is one a later query trusts, handing an email provider to a checkout), and switching off the default **hands it to the surviving usable gateway** — otherwise the operator has a working Stripe row, a default flag on a disabled Viva one, and a checkout outage caused by a checkbox.

### The cached repository, and the cache it deliberately does not use

§2.7 asks for a cached repository so the encrypted column is not decrypted on every request. That is met by a per-request memo on a singleton, with a test asserting the second read runs **no query** and another asserting the binding is a singleton — a second `new` would leave every other assertion passing and the requirement silently false.

Nothing goes to the shared cache. That is Redis in production (ENV-1), and a decrypted gateway secret there moves every operator's live credential out of a column needing `APP_KEY` into a store needing only a connection — undoing PAY-3 to save one `SELECT` on a table with a few rows per tenant. §2.7's "config cache per tenant" is recorded as met by the memo, with the reasoning written into the class.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate` (SQLite) | table created at position 29 |
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — two real findings fixed at source, no baseline |
| `composer test` | **1472 passed**, 0 failed (after the snapshot refresh below) |
| `tests/Feature/Integrations` + `IntegrationsPageTest` | 51 passed |

**The snapshot was refreshed the documented way.** `CiGatesTest` compares a fingerprint of the migrations against the header of `database/schema/mysql-schema.snapshot.sql`, which needs a MySQL 8 connection to regenerate while the local stack is SQLite (ADR-0015). `docs/ci.md` §"Refreshing it after a migration change" gives the procedure: push, take the `mysql-schema-snapshot` artifact from that run, commit it. The regenerated file confirms the MySQL 8 shape matches §2.7 column for column — including `public_config` as a plain `json NOT NULL` with no default, which is why the default lives on the model.

Not run locally, by environment rather than by choice: the `mysql`-tagged concurrent-default test, and everything else in that job.

### Things this touched that were not its own

1. **The EL/EN parity gate reads a vendor wordmark as untranslated Greek.** Six of the seven provider labels are byte-identical in both files, which is the exact signal that gate exists to catch. Each is named in `tests/Support/I18n/allow-list.php` with its reason — an operator setting up Viva is looking for the word on their own Viva dashboard. `mydata` is **not** among them: it carries the issuing authority in brackets and that part is translated, AADE against ΑΑΔΕ.

2. **`Filament\Pages\BasePage` already has `configureAction()`.** Declaring one with a different signature is a fatal error at boot, not a subtle bug — the save action is named `saveCredentials`.

3. **`abort()` inside a Livewire action tears the component down**, so Filament's `assertNotFound()` macro then reads `mountedActions` on a null instance and reports "Attempt to read property on null" — which looks like a broken test rather than a passing guard. The cross-tenant test asserts the thrown `NotFoundHttpException` and, separately, that the row was not written to.

4. **`ModelIsolationTest` compared two different things, and the coverage job finally noticed.** The update test read `$before` from the freshly-created model's **in-memory** attributes and `$after` from the database. Several models are written a *second* time by a `saved` observer recomputing a derived column on a related row — `RecomputePriceFrom` reaching `products` from an age band or rate plan, `RecomputeDepartureBlockedFlags` reaching `departures` from a vessel block — so the in-memory timestamp is the one from the first insert and the stored one is a moment later. Normally the same second, so it held.

   It stopped holding on the **coverage job**, where PCOV is slow enough to cross a second boundary, and nowhere else: green locally, green on the SQLite job, green on MySQL, red on coverage with a one-second difference in a timestamp. That reads as a broken cross-tenant isolation guarantee, which is the most alarming thing this suite can say, and it was a measurement artefact. Both readings now come from the stored row, which compares like with like and is the stronger assertion anyway — the state immediately before the attempted write against the state immediately after it.

   Latent since #53 put the timestamp comparison in; this issue is only what tipped it over, by making the run a little longer.


---

## #53 — The operator audit trail, and the two ways a listener lies to you

**Files:** `database/migrations/2026_09_02_000009_create_audit_logs_table.php` plus **nineteen renamed M1 migrations**, `app/Models/AuditLog.php`, `app/Enums/AuditAction.php`, `app/Exceptions/AuditLogIsAppendOnly.php`, `app/Events/{Auditable,RecordSoftDeleted,DepartureCancelled,ApiKeyRevoked}.php`, `app/Listeners/RecordAuditLog.php`, `app/Jobs/RecordAuditLogJob.php`, `app/Domain/Audit/{Actions/RecordAuditEntry,Data/AuditEntryData,Data/AuditContextData}.php`, `app/Providers/AuditServiceProvider.php`, `app/Policies/AuditLogPolicy.php`, `app/Filament/App/Resources/AuditLogResource**`, `app/Console/Commands/PurgeAuditLogCommand.php`, `app/Support/Authorization/Capability.php`, `app/Enums/Concerns/HasTranslatedLabel.php`, `app/Domain/Tenancy/Actions/RevokeApiKey.php`, `config/kaiki.php`, `routes/console.php`, `docs/data-model.md`, `lang/{el,en}/{audit,enums}.php`, four test files

**M1 closes here.** This is ADR-0025 implemented, the gap #42 opened, and SEC-16 satisfied with a table instead of the `Log::info` #10 shipped and called temporary in its own review.

### The shape everybody would reach for first, and why it is wrong

A queued **listener** is the obvious answer and it silently misattributes every row.

Laravel constructs a queued listener **on the worker**. There, `Auth::id()` is null and `Tenancy::current()` holds whatever the previous job left behind — so the actor is lost and the tenant is *somebody else's*, which is the cross-tenant write ADR-0001 and #8's isolation gate exist to prevent. The failure is invisible: rows appear, they look plausible, and they are filed against the wrong operator.

So `RecordAuditLog` is **synchronous** and does two things that touch no database — capture the ambient context, and dispatch `RecordAuditLogJob`. The job is what is queued, and it carries plain data and **no models**: half these rows describe a deletion, so a `SerializesModels` payload would arrive as a `ModelNotFoundException` for exactly the events that matter most.

The issue's requirement — *"a vessel delete that succeeded must not appear to have failed because the trail was unavailable"* — is then covered twice, for two different failures. The dispatch returns before anything is written, which covers a database that was **not there**; `RecordAuditEntry` logs a refused insert rather than throwing, which covers one that **rejected** it.

### Two bugs found in the wiring, both invisible until the wrong moment

1. **Two identical rows for one delete.** Laravel 11+ discovers listeners in `app/Listeners` from their type hints, so `handle(Auditable $event)` was already bound to the interface — and the explicit `Event::listen(Auditable::class, …)` added a second registration. In an audit trail this is worse than a missing row: a trail that double-counts cannot be counted. The provider now registers nothing for it, and a test asserts the binding exists **exactly once**, so discovery being disabled would be a red test rather than a trail that quietly stops.

2. **A wildcard listener and a named one get different arguments.** A wildcard is handed `($eventName, array $payload)`; a named one is handed the dispatch arguments spread, which for a model event is the model itself. Copying the wildcard's signature failed twice over — `ArgumentCountError`, then `TypeError` — and only at the moment a departure is actually cancelled, which is to say in production during an incident.

### The queue lie in the tests, caught by CI

Every audit test passed locally and on the SQLite job, and **all of them failed on `Pest on MySQL 8 + Redis`** — while `writes nothing for an ordinary edit` kept passing, which is what made it look like a data problem rather than an environment one.

`phpunit.xml` sets `QUEUE_CONNECTION=sync`, so the job ran inline by accident. ENV-1 puts a real Redis in that one CI job, where it was enqueued and never executed. The driver is now set explicitly by the tests that assert a row, the queueing is still asserted separately with `Queue::fake()`, and the fix was proved by flipping `phpunit.xml` to the database driver and re-running.

This is the second issue running where the bug was an assumption inherited from the environment rather than anything in the feature.

### Append-only, three times over

ADR-0025: *"an audit row that can be edited is not an audit row."*

- The migration **omits `updated_at`**, which makes the accident impossible.
- The model **throws** on `updating` and `deleting`, which makes the deliberate attempt impossible.
- The policy refuses every write ability for **every role including the owner**, which stops the button ever rendering.

Three layers because a missing column is a convention the next reader adds back, and a policy alone is a rule the query builder walks around. The retention purge goes through the query builder as one named method with a docblock beside it — the difference between an exception and a loophole.

### Retention and erasure, which pull against each other

Seven years (`config('kaiki.audit.retention_days')`, 2555), against ADR-0012's ninety days for personal data. Greek bookkeeping wants records available for years and a trail that purges at ninety days cannot answer a dispute about last season — which is the dispute people actually have.

Reconciled by the actor being a **`user_id` and never a name**: a GDPR erasure anonymises the user row, and the trail keeps its timestamps and its causality while no longer identifying a person. There is a test that force-deletes the actor and asserts the row survives with its shape.

`context` is enforced to carry no personal data by a scanner, in the manner of `NoHardcodedVatRateTest` — including the other half, that it does **not** flag `seats_sold` or `environment`. A lint that cries wolf is a lint somebody switches off.

### Scope, which is a privacy decision rather than a convenience

SEC-16's five named actions **plus every soft delete and every reasoned override** — not every state change. ADR-0025 rejected complete history explicitly: *"every extra row is another row naming a person that the retention and erasure story has to account for."*

So there is a test that an ordinary field edit writes **nothing**, and it carries as much weight as the ones asserting a delete writes something. Soft deletes are caught by a wildcard on Eloquent's own event rather than an observer per model — a list of twenty models is a list that goes stale the first time M2 adds a table.

Two of the five actions are not buildable yet: `booking.refunded` and `gdpr.purged` need M2 and M6 subjects. They are enum cases anyway, so the milestone that builds them fires an existing action rather than inventing a spelling, and a test records which are live so the gap stays visible.

### The renumber

`audit_logs` is item **9** — first in M1, ahead of every table whose resource ships a destructive action. All nineteen M1 migration files were renamed so the numeric suffix means the §6 item number again, and §6's M2–M7 entries shifted with it. The file order now also matches §6 for the seasons/products pair, which the files had backwards. `docs/data-model.md` gains §2.8 with the schema, its three indexes and four notes. Nothing is deployed, so the cost is one `migrate:fresh`.

### Things this touched that were not its own

1. **`ModelIsolationTest`** probed cross-tenant updates by writing `updated_at`; `audit_logs` has no such column, and the failure read as a broken gate rather than as this model being different. It now writes the model's own timestamp column, which is stronger — the column being written was never the point.
2. **`RoleMatrixTest`** required a row for `ViewAuditLog` before it would go green. The gate working as designed.
3. **#10's revocation test** asserted the `Log::info`. It asserts the row now, which is the change ADR-0025 asked for, and the event moved onto `RevokeApiKey` so the API and the importer are audited too — a line in a Filament closure never would have been.

### Verification

`composer lint` (Pint, passed), `composer stan` (PHPStan level 6 + Larastan, **no errors**), `composer i18n:check` (154 passed), `./vendor/bin/pest` — **1412 passed, 1 skipped**. The MySQL schema snapshot was regenerated from this branch's own `migrate-from-zero` artifact (ENV-10, ADR-0015): the fingerprint moved for two reasons at once, a new table and nineteen renamed files. All **15 required checks** green before merge (PR #77).

---

## #37 — `GET /availability`, `POST /price-quote`, and the party rule that had to stop existing twice

**Files:** `routes/api.php`, `app/Http/Controllers/Api/V1/{AvailabilityController,PriceQuoteController}.php`, `app/Http/Requests/Api/V1/{AvailabilityRequest,PriceQuoteRequest}.php`, `app/Http/Resources/Api/V1/{AvailabilityDayResource,PriceQuoteResource}.php`, `app/Enums/{AvailabilityDayStatus,WindowUnavailableReason}.php`, `app/Domain/Availability/Support/PartyGuard.php`, `app/Domain/Availability/Actions/CheckSeatAvailability.php`, `app/Data/Availability/{DepartureAvailabilityData,VesselWindowData}.php`, `app/Domain/Catalog/Queries/PublicProductQuery.php`, `app/Http/Responses/ApiExceptionRenderer.php`, `app/Enums/Concerns/HasTranslatedLabel.php`, `app/Support/Locale/TranslationValue.php`, `app/Domain/Media/Support/ImagePayload.php`, `config/kaiki.php`, `docs/api.md`, `lang/{el,en}/{api,pricing}.php`, four test files

This closes the M1 public API. The engine behind both endpoints is #30, #31, #33 and #34; what landed here is the boundary.

### One rule, two callers, and the reason it could not stay where it was

`POST /price-quote` has to answer *"can this party board this departure"* — AVL-23's seat check, AVL-25's certificate check, AVL-26's counted-pax check. All three were private methods inside `CheckSeatAvailability`.

Copying them would have been the obvious move and it is the one that produces a site contradicting itself: a party the calendar offers and the quote refuses, or the reverse, in front of a guest. So they are extracted into `PartyGuard`, which both call.

**The extraction broke a test, and the test was right.** `CheckSeatAvailability` evaluates a *blanket* rejection once per request — the conditions that do not depend on a date — and a first pass had that call the whole guard. Legal capacity depends on who is already aboard a **specific** departure, so evaluating it blanket refused every date in the range and reported `legal_capacity_exceeded` where the honest answer was `not_enough_seats` on the one sailing the guest asked about. `LegalCapacityTest`'s "reports the seat shortage first" case exists precisely because those two codes send a guest to different remedies. `PartyGuard::blanket()` is now a separate, narrower entry point, and its docblock records why.

### Three vocabularies, and the API's is the smallest

The engine speaks `AvailabilityRejection` — fifteen precise reasons, one per rule. The contract fixes two much smaller sets, and the reduction is lossy on purpose.

- **`AvailabilityDayStatus`** (six cases) is what a calendar cell renders. WGT-16's whole point is that `not_operating`, `sold_out` and `past` are three different sentences to a guest, and collapsing them into "unavailable" produces the grey calendar nobody can act on. Note the ranking: **`sold_out` outranks `past`**, because a date with a full morning sailing and an afternoon one past its lead time is a full day, and "sold out" is the reading that sends a guest to another date rather than to another operator.
- **`WindowUnavailableReason`** (six cases) is a privacy rule wearing an enum. The contract: *"Never names the conflicting booking or guest — a competitor must not be able to read the operator's calendar in detail."* `VesselBusy` and `VesselHeld` are distinct to the engine and both collapse to `vessel_blocked` here, because the difference tells a caller somebody is at the checkout **right now** for a specific boat on a specific afternoon.

`maintenance` and `external_calendar` are in the enum and are not produced yet. They stay so that M5's iCal sync narrows an existing value rather than adding one — a client that has shipped a `switch` should not have to grow a new arm.

### The read path is advisory, and the controller is where somebody would fix that

ADR-0006, Option A. The seats reported may be stale by the time the guest posts; the authoritative check is M2's locked write path with its conditional counter update. Taking a lock here to make the number exact would serialise the hottest read in the product against every checkout, which the ADR names as explicitly the wrong trade. It is written into the controller docblock because that is the file where the temptation lives.

ADR-0005 is the other half and was already honoured inside the engine: expired holds are filtered lazily, so a stale sweeper can never make a seat look sold.

### PRC-1 is enforced by refusal, not by ignoring

The contract: *"**No price, no total and no discount may appear in this body** — anything money-shaped is rejected as an unknown field."* Ignoring such a field would be safe, since nothing reads one. Refusing is better, and the difference is the integrator who *thinks* they are setting a price: silently dropping the field means they ship a checkout that appears to apply their own discount and find out when a guest is charged the difference.

The check walks the whole body, not the top level — `window.price_cents` is the same assumption wearing a hat — and only money-shaped keys are refused. A `client_version` or a tracking id is harmless, and rejecting every unknown key would make the endpoint brittle for no safety gain.

**It is a `prohibited` rule rather than an `after()` error, and that is a §4.1 requirement.** A message added in `after()` is a finished string in whichever locale was active, and the renderer re-localises by re-running the rules — so a hand-added error has nothing to re-run and comes back with the same sentence in both language slots. `AvailabilityLocaleTest` asserts the two differ, which is what caught it.

### The validation envelope did not match its own contract

§4.2 fixes it as `details.fields` keyed by field path, each entry an object with `code`, `message` and `message_el`. #35 shipped a flat `details` map of message-string lists. The ENV-28 drift gate reads paths and security schemes, not error shapes, so nothing caught it until this issue needed the per-field Greek that AC6 asks for.

All three differences matter. `details` carries other keys on other codes — `max_days` on `invalid_date_range`, `retry_after_seconds` on `rate_limited` — so a client reading `details` as "the field errors" breaks on the first one. `code` is the failing rule, which is what a form branches on to highlight a field without parsing English. And a top-level language pair sitting over English-only field messages keeps §4.1's promise where nobody reads and breaks it where the guest does.

The Greek is obtained by **re-running the validator** under `el` rather than by translating the produced string: a message is assembled from a lang line and its `:attribute` substitutions, so there is nothing left to translate afterwards. `HasTranslatedLabel::labelIn()` is the same need for enum-backed refusals.

### `?pax=` is one integer and the engine wants a party

The contract's parameter is a single number — a calendar asks "can you seat four", not "two adults, a child and an infant". It is assigned to the product's **base** band: the one every other band's price is a multiple of, and by definition one that occupies a seat. That makes the calendar filter a *seat* question, which is the right reading; the legal-capacity question is answered properly at `POST /price-quote` and again, under lock, at checkout.

### The 62-day cap is enforced once and translated twice

`AvailabilityRequestData::forRange()` already refused an over-long range, and it is right that it does — it is the last line of defence for the panel and M3's hosted calendar too. `AvailabilityRequest` calls it and translates the refusal into `422 invalid_date_range` with `from`, `to` and `max_days`, which is what §4.2 names for exactly this. AVL-29 wants a refusal rather than a truncation: a silently trimmed range renders as empty days and the guest concludes the boat does not sail in September.

### NFR-7, asserted as a shape rather than a number

`CheckSeatAvailability` budgets five queries and spends all five, so `PublicProductQuery::findForAvailability()` is a **narrower** load than `find()` — the detail payload's cancellation policy and its tiers are two queries this endpoint never reads.

The test does not assert "five". A request also pays for the key, the tenant and the product, so an absolute number would count things NFR-7 is not about and would need editing whenever the auth path changed. What NFR-7 protects against is a count that **grows with the range**, and that is asserted directly: a 62-day request issues exactly as many queries as a one-day one, for both modes.

Two things had to be got right for that test to mean anything:

1. **The first request on a key writes `api_keys.last_used_at`**, which #6's throttle then suppresses. An unwarmed pair differs by one query for a reason unrelated to the range — and in the direction that makes the *short* request look more expensive. The key is warmed and the result discarded.
2. **A 62-day range crosses the October clock change.** Hand-written UTC instants made `local_time` and `starts_at_utc` disagree and CNV-3's guard refused the row, correctly. The fixtures use `DepartureFactory::at()`, which writes all three columns through `LocalDateTimeResolver`.

### One contract change, with its reason

`docs/api.md`'s `VatBreakdown` gained **`vat_category`**, per §10.5's procedure. The issue comment asks for it by name: a myDATA-aware client needs the AADE classification to reconcile a quote against the invoice that will be issued for it, and deriving it from `rate_bp` client-side would be a second mapping that can disagree with the `vat_rates` table. `vat_rate_id` is *not* exposed — the snapshot carries it, the wire never does (CNV-8).

### Deviations from the issue as written

1. **The machine codes are lower snake case** — `no_counted_pax`, not the issue's `NO_COUNTED_PAX`. They are `AvailabilityRejection`'s own values, which is what `GET /availability` already returns; two spellings of one code across two endpoints is worse than either spelling.
2. **`price_token` is not emitted.** The contract lists it as optional and describes it as a handle `POST /bookings` verifies. Nothing verifies it yet, and a token is only as good as the fields it binds — designing it without its verifier means guessing which inputs matter and shipping a signature format that has to change, on a value clients are told is opaque. `expires_at` is returned, because that is what PRC-15 is actually about.
3. **A `voucher_code` is refused rather than ignored.** PRC-18 is M2 and the contract says an invalid code is *"an error, not a silent no-op"*. A code nothing can redeem is invalid, and a guest who typed one would otherwise be charged full price with no explanation.
4. **A `mode: quote` product is refused a price.** BKG-24, and the contract's own `PriceQuote.mode` enum has no `quote` case.
5. **The validation-envelope fix is cross-cutting** and changes every endpoint's 422, including #35's and #36's. It is conformance to a contract already written, not a contract change.

### Verification

`composer lint` (Pint, passed), `composer stan` (PHPStan level 6 + Larastan, **no errors**), `composer i18n:check` (154 passed), `./vendor/bin/pest` — **1373 passed, 1 skipped** (the mysql-tagged concurrency test, ADR-0015). The ENV-28 drift gate reports **6 of 18 contract operations built**, up from 4, security schemes matching. The NFR-1 p95 benchmark is ADR-0023's, pulled forward to the close of M2; until it exists the query-count assertion is the standing evidence.

---

## #36 — `GET /products`, `GET /products/{uuid}`, and the `?locale=` no endpoint had

**Files:** `routes/api.php`, `app/Http/Controllers/Api/V1/ProductController.php`, `app/Http/Requests/Api/V1/ProductIndexRequest.php`, `app/Http/Resources/Api/V1/{ProductListResource,ProductDetailResource,AgeBandResource,ExtraResource,MeetingPointResource,VesselSummaryResource,CancellationPolicySummaryResource}.php`, `app/Domain/Catalog/Queries/PublicProductQuery.php`, `app/Domain/Media/Support/ImagePayload.php`, `app/Support/Locale/LocaleResolver.php`, `app/Http/Middleware/{SetLocale,ApiKeyCors}.php`, `app/Support/Format/MoneyFormatter.php`, `app/Models/{Product,Extra}.php`, `config/kaiki.php`, `lang/{el,en}/api.php`, `app/Filament/App/Resources/{PortResource,VesselResource}.php`, `database/factories/VesselFactory.php`, `tests/Support/Api/CatalogRequest.php`, four test files

### The contract promised a query parameter nothing implemented

`docs/api.md` §3.1 fixes the API's locale order as `?locale=` → `Accept-Language` → tenant default, and `LocaleQuery` is listed on **all sixteen operations**. The implementation's query key is `?lang=` — the panel's spelling, which appears in operators' own links and in the language switcher, and which no API client sends.

So step 1 of the chain was unreachable from the API. Every request fell through to `Accept-Language`, including the WordPress plugin passing the locale WPML had already decided, and **nothing failed** — the response was in *a* language, plausibly the right one, and the parameter was simply ignored. #35 shipped `/branding` against the same middleware without hitting it, because no test asked for a locale by the name the contract uses.

Both keys are now step 1 of `LocaleResolver::requested()`, which ADR-0008 requires be the only implementation of the order. `ProductLocaleTest` asserts each of the three steps separately: a chain tested only end to end passes in a world where two steps are wired to the same signal.

### The 400 is asymmetric on purpose, and the resolver still never throws

§3.1 requires `400 unsupported_locale` for an explicit `?locale=fr`. `LocaleResolver::resolve()` deliberately does not throw — a stray `?lang=fr` in a shared link must not break a public page — so the refusal is a separate question the resolver answers (`unsupportedApiLocale()`) and `SetLocale` acts on, for `api/v1` only.

The asymmetry is the requirement, not a compromise: §3.1's reason is *"a typo must not silently serve Greek to a French page"*, and it is about a machine client, which has no way to notice it got a different language than it asked for. A human on a hosted page does. An unmatched `Accept-Language` stays not-an-error on both surfaces, exactly as §3.1 says.

### `Vary` was being overwritten, and only a CDN would ever have shown it

§3.1 also requires `Content-Language` and `Vary: Accept-Language, Origin, X-Kaiki-Key` on every response. Neither existed. `Vary` is the load-bearing one — without it a shared cache serves one operator's Greek payload to another operator's English page.

`ApiKeyCors` is prepended globally, which makes it the **outermost** middleware and therefore the last to touch the response on the way out. It was calling `set('Vary', 'Origin')`, so anything added further in was already being discarded. Both sides now merge. The failure this would have produced appears only behind a CDN, which is to say only in production.

### A test that omits `Accept-Language` is not testing what it looks like

Asserting the tenant-default step needed an **empty** `Accept-Language` header, not an absent one. Symfony's `Request::create()` — which every Laravel test request goes through — defaults `HTTP_ACCEPT_LANGUAGE` to `en-us,en;q=0.5`. A test that simply leaves the header off is testing step 2 in English while appearing to test step 3, and passes without the tenant default ever being consulted.

### The filters do not validate, and that is WGT-6 rather than laxity

An unknown `category` returns an **empty list**. The `data-category` an operator wrote into their page outlives the products it named, and a red error where the trips used to be is worse than an empty widget.

The implementation detail that matters: the value reaches the `where` as a plain string. Mapping onto `ProductCategory` first and dropping what does not fit would silently **widen** the result to the whole catalogue — the one answer that is certainly wrong, and the one that looks like it works. `mode` and `vessel` follow the same rule; another operator's vessel uuid is an empty 200, because a 404 there is a cross-tenant probe (SEC-2).

### A malformed cursor is the only query-string refusal

`per_page` above the maximum is clamped and not rejected (§3.5), and clamped at the bottom too — `?per_page=0` has no useful reading and a paginator handed a zero throws. A cursor cannot be clamped: Laravel treats an undecodable one as *no cursor* and serves page one, so a sync job losing half a catalogue reports a clean run. `Cursor::fromEncoded()` returning null is exactly §3.5's "malformed or stale", and it is `400 invalid_cursor`.

`ProductIndexRequest::rules()` is therefore **empty**, which is worth stating because an empty `rules()` on a `FormRequest` reads like an oversight. The first draft validated `per_page` as an integer and `cursor` as `max:512`; both produced **`422 validation_failed`**, and the contract lists no 422 among this operation's responses at all — the only request failure it documents is the 400. They were also the wrong answers: `?per_page=abc` reads as "no preference" and takes the default, and a 600-character cursor is not a length violation but a value that cannot have come from `pagination.next_cursor`, which is what `invalid_cursor` means. Every parameter is read through an accessor that states what it does with a value it cannot use.

### Two `??` chains kept their single owner

`ExtraResource` wraps an `OfferedExtra`, never an `Extra`. The value object owns the per-product override resolution — including the tri-state `is_required`, where `false` must beat the extra's `true` — and a resource reading the model directly would publish the tenant-wide price on a product that charges something else. `Product::effectiveCancellationPolicy()` likewise stays the only place the tenant-default fallback is written.

### `booking_window` is a projection, and the eager load is narrowed for it

`min_lead_time_hours` and `max_advance_days` are per rate plan and vary by season, so one product-level pair is necessarily a projection. The contract records the rule in the absence of an ADR — the strictest value across **active** plans — so a client never offers a date the booking endpoint rejects. The relation is loaded through `->active()` rather than whole: an inactive plan tightening a window the operator switched off is the bug this shape prevents, and the test's inactive plan is 999 hours and one day so that counting it would be unmissable.

### Not decided here

`docs/api.md` §9 item 6 — the advisory `from_price_cents` per age band — is **open**, a product-owner choice due before M3. The field is optional in the schema, so omitting it is contract-legal, and `CLAUDE.md` makes an undecided question a hard stop. The substance of the decision is that an advisory band price is a **second pricing surface** and only `/price-quote` binds; two numbers that can disagree in front of a guest is a support incident.

### Two defects found on the way

1. **Panel image uploads were unreachable by URL.** `PortResource` and `VesselResource` upload through Filament's default disk, which follows `FILESYSTEM_DISK=local` — and the `local` disk cannot produce a URL at all. Every image the panel had accepted would have come back as nothing from this endpoint, and `Storage::url()` throws rather than returning null. Reader and writer now name one value, `kaiki.catalog.uploads.disk`, and `ImagePayload` degrades to no-image rather than 500ing the catalogue if a disk is ever misconfigured again.
2. **`VesselFactory` capped at six vessels per process.** `unique()->randomElement(self::NAMES)` over six Greek boat names; the seventh died with Faker's *"Maximum retries of 10000 reached"*, an error that names nothing and points at the wrong thing. The numeric suffix already carried the uniqueness, so it now carries all of it. #36 needed forty.

### Deviations from the issue as written

1. **The `{uuid}` segment also accepts a slug.** The issue's acceptance criteria name the uuid only; `ProductIdentifierPath` in the contract says *"the product `uuid`, or its tenant-unique `slug`"*, and the hosted page and WordPress permalink resolve with the slug. The contract is the authority (§10.5).
2. **`mode` and `vessel` filters, and cursor pagination, are implemented.** None are in the issue's criteria; all three are in the contract for this operation.
3. **The JSON field is `from_price_cents`, not `price_from_cents`.** The issue used the column name. `ProductSummary` uses `from_price_cents`, and the column stays `products.price_from_cents`.
4. **The locale, `Content-Language` and `Vary` work is cross-cutting** and changes `/branding` as well. It is conformance to a contract already written rather than a contract change, so `docs/api.md` is untouched — but it is larger than "this endpoint", and it is called out here for that reason.

### Verification

`composer lint` (Pint, passed), `composer stan` (PHPStan level 6 + Larastan, **no errors**), `composer i18n:check` (154 passed), `./vendor/bin/pest` — **1341 passed, 1 skipped** (the mysql-tagged concurrency test, ADR-0015). The ENV-28 drift gate reports **4 of 18 contract operations built**, up from 2, with the security schemes matching and no route resolving by database id. CI-only as always: the MySQL schema snapshot, `migrate-from-zero`, and `app/Domain` coverage.

---

## #21 — Rate plans, per-band prices and the deposit rule

`rate_plans` and `rate_plan_prices` per `docs/data-model.md` §2.3, and the four rules that decide what a guest is charged. All four are in `app/Domain/Pricing/Actions/SaveRatePlan.php` rather than in the form, because the importer and the public API will need the same four.

### The rule the database cannot hold

`rate_plans_tenant_prod_season_uq` is `(tenant_id, product_id, season_id)` and the product default is the row with `season_id` **null**. Both MySQL and SQLite treat NULLs as distinct in a unique index, so that constraint permits two default plans for one product and always will. A partial index or an index on an expression would express it and is unportable, which ENV-12 forbids.

`tests/Feature/Pricing/DuplicateDefaultPlanTest.php` therefore asserts **both** halves — that the index really does let a duplicate through (inserting twice through the query builder, bypassing the Action), and that the Action really does not. Without the first assertion, the next reader sees an index whose name promises the invariant, believes the database is holding the line, and deletes the check.

### The other three

- **CAT-5 consistency.** `per_vessel` requires `vessel_price_cents` and refuses band rows; `per_seat` is the reverse; `quote` is permitted for internal reference and never produces a guest-facing price.
- **Band coverage.** A `multiplier` band derives from the base band, so its row is optional; a `fixed` band and the base band itself have nothing to derive from, so theirs are required. Every missing band is named in one message rather than one per submit.
- **PRC-23 deposits.** `percent` needs 1–100, `fixed` a positive amount, `none` refuses both — *refused* rather than silently cleared, because an operator who typed a percentage and chose "pay in full" meant one of the two and only they know which. The column the chosen type does not use is cleared on save, so switching back cannot resurrect a stale figure.

### `MoneyInput`, and CNV-1 surviving contact with a form

`app/Filament/Forms/MoneyInput.php` is new and is the first money field in the panel. An operator types «150,50» and the column stores `15050`, with `brick/money` parsing the **string** — the obvious `(float) $state * 100` in between is exactly what CNV-1's *"not even transiently"* is about, since `1.15 * 100` is `114.99999999999999`. The comma is normalised before parsing, which is also why the field is not `numeric()`: a browser number input rejects the decimal comma a Greek keyboard produces.

The panel pages re-key the Action's errors onto `data.*`, so a refusal marks the field that caused it instead of floating above the form as an unattached banner.

### Deviations from the issue as written

1. **The resource lives at `app/Filament/App/Resources/RatePlanResource.php`**, not `app/Filament/Resources/` as the issue listed. The panel-scoped path is the convention every resource since #9 has used.
2. **No `app/Rules/*` classes.** The issue proposed `OneDefaultRatePlanPerProduct`, `RatePlanMatchesProductMode` and `DepositFieldsConsistent`. All three are cross-table and set-level; a Laravel rule object judges one value and would have had to reach for the product and the sibling rows anyway. They are methods on the Action instead, which is where the importer and the API will call them from.
3. **Four migration files renumbered.** #22 shipped `extras` and `product_extra` as 19 and 20, which §6 assigns to the rate plan pair. All four renamed so the numeric suffix keeps meaning the item number, as §6 item 9 promises. Neither pair holds an FK to the other, so nothing depends on the relative order — recorded in `docs/data-model.md`.

### Not lost, deferred

`docs/data-model.md` §2.3 calls for a **nightly integrity check reporting duplicate default plans**. It belongs with the other integrity checks in M5. Until then the Action is the only thing holding the line, which is why the test above exists.

### Verification

`composer lint` (Pint, passed), `composer stan` (PHPStan level 6, no errors), `composer i18n:check` (146 passed), `composer test` — **812 passed**, with `CiGatesTest > schema snapshot` red locally as designed, since ADR-0015 puts MySQL in CI only. The snapshot was then refreshed from the `mysql-schema-snapshot` artifact of the branch's own `migrate-from-zero` run, and all **14 required checks** went green before merge (PR #60).

---

## #47, #23, #18, #19, #20, #22 — the M1 catalogue chain

Six issues merged back to back, each gated on the one before it by §6's rule that **no migration ever adds a foreign key to an existing table**. Recorded together rather than as six entries written after the fact: the per-issue narrative is in each pull request, and inventing entries in this file's usual detail long afterwards would be reconstruction rather than an audit trail.

| # | What landed | The part worth remembering |
|---|---|---|
| #47 | `vat_rates`, platform-owned, no `tenant_id` | `rate_bp` has **no default** — a VAT column that defaults to anything is a statutory rate hardcoded in a migration (ADR-0002). `NoHardcodedVatRateTest` scans the source for a percentage beside a VAT word; the seeded sandbox rate is 1 basis point, marked «ΠΡΟΣΩΡΙΝΟΣ» |
| #23 | `cancellation_policies`, tiers, `RefundCalculator` | The calculator is static end to end and takes a `CancellationPolicyData`, never a model — so CXL-1's *"editing a policy must not affect an existing booking"* is a property of the type signature rather than a rule to remember |
| #18 | `products`, 44 columns, five FKs | Needed both #47 and #23 to exist first. `getTranslations()` returns the `_geo` key as though it were a locale, so the itinerary has its own accessors |
| #19 | `age_bands`, `AgeBandResolver` | Every CAT-8 rule is set-level, so all of them are in `SaveAgeBands` and reported at once. `countedSeats()` and `totalPersons()` are deliberately separate: an infant on a lap is not a seat and is still a person aboard |
| #20 | `seasons`, `season_date_ranges` | PRC-4 wants **both** save-time prevention of priority ties and read-time deterministic ordering. `docs/data-model.md` §2.3 said only the second; the note was amended |
| #22 | `extras`, `product_extra` | Tri-state overrides resolve with `??` and never `?:` — `0` and `false` are real override values, and `?:` would treat both as "inherit" |

Each merged with the same 14 required checks green and its own MySQL snapshot refresh commit.

---

## #17 — BrandProfile: the row that always exists, the sanitisers, and the one upload writer

**Files:** `database/migrations/2026_09_02_000009_create_brand_profiles_table.php`, `app/Models/BrandProfile.php`, `app/Observers/TenantObserver.php`, `app/Enums/{FontSource,WidgetTheme,BrandAsset}.php`, `app/Domain/Branding/Support/{CssSanitizer,SvgSanitizer,ContrastChecker,BrandPayload}.php`, `app/Domain/Branding/Actions/{UpdateBrandProfile,UploadBrandAsset,ResetBrandProfile}.php`, `app/Domain/Media/**`, `app/Exceptions/UploadRefused.php`, `app/Rules/{HexColor,SocialLinks}.php`, `app/Filament/App/Pages/Branding.php`, `app/Policies/BrandProfilePolicy.php`, `app/Console/Commands/MediaRebuildCommand.php`, `config/kaiki.php`, `lang/{el,en}/branding.php`, `database/factories/BrandProfileFactory.php`, six test files

Completes the work `13f2e01` landed as deliberate WIP. That commit shipped the table, the two enums, the config block and the three sanitisers and said in its own message that there was no model, no observer, no factory, no upload Action, no Filament page, no policy, no lang file and **no tests at all** — the sanitisers having been "exercised against a hostile-input table in a scratch script, which is not evidence and does not ship."

### The scratch script could not have caught the bug the first real test found

`SvgSanitizer::sanitize()` threw a `TypeError` on **every SVG it was given**.

`libxml_set_external_entity_loader()` returns `bool(true)`. It does *not* return the callable it replaced — that is `libxml_get_external_entity_loader()`, added in PHP 8.4. So `$previous = libxml_set_external_entity_loader(...)` captured `true`, and the `finally` block handed `true` back to a parameter typed `?callable`:

```
libxml_set_external_entity_loader(): Argument #1 ($resolver_function)
must be a valid callback or null, no array or string given
```

Thrown **after** the sanitised markup had been produced, from a `finally` on the way out. A scratch script that printed the return value saw correct output every time and never reached the exception, because the exception replaced the return rather than corrupting it. Sixteen of seventeen tests in the file failed on their first run.

The test that now covers it installs a sentinel loader, sanitises, and asserts the sentinel is still installed — the loader is disabled for the duration of the parse, and a parse that did not restore it would leave every later XML read in the process (an iCal import, a gateway response) inheriting the change.

### Two more found by tests, both silent in production

**`BrandAsset::column()` returned a column that does not exist.** Written as `$this->value . '_path'`, which is right for three of the four slots and wrong for `email_header_image_path`. `Model::getAttribute()` on a missing column returns null rather than throwing, so the failure mode was: the upload is accepted, the file is written to disk with its variants, `save()` succeeds, and the email header is never set. Nothing anywhere reports it. Caught by the assertion that every enum case names a real column — the only shape of test that could.

**Both JSON columns were being stored as `[]`.** §2.2 documents the default as `{}` and §3.10 documents a keyed object. Laravel's `array` cast encodes an empty PHP array as `[]`, and it round-trips correctly in PHP — `json_decode('[]', true)` and `json_decode('{}', true)` are the same value — so no PHP test can see it. What it means is a column whose JSON shape changes the first time an operator adds a social link. Replaced with explicit accessors using `JSON_FORCE_OBJECT`, pinned by a test that reads through the query builder rather than the model, because the cast is exactly what would hide it.

### The isolation gate's oldest assertion was wrong, not just inconvenient

`ModelIsolationTest` asserted `$class::query()->count() === 0` from the other tenant's context. That was true only while every tenant-owned model was one a test had to create. BRD-3 gives every tenant a brand profile the moment it exists, so tenant A legitimately sees one row — and the case failed.

The temptation is to exempt `BrandProfile`. The assertion is what is wrong: the invariant was never "A sees nothing", it is **"everything A sees belongs to A"**. That is identical for a model with no rows and strictly stronger for a model with some — a leak that returned B's row *alongside* A's would have passed the old count check on any model where A already owned one. Changed, with the reason in the test.

`TenantIsolationHarness` gained a documented `singletons()` map so the gate still builds B's row and still asserts it invisible; it takes the row the observer already made rather than inserting a second the unique index would refuse. **Not an exclusion** — the model stays in every case of the gate.

### Decisions recorded rather than left implicit

- **`docs/data-model.md` §3.15 was amended, and §3.10a added.** The sketched shape was one `primary_on_background` entry with a `passes_aa` flag. BRD-5 names two pairs at two thresholds, so it could not hold the answer, and a bare `passes_aa` cannot say which threshold it was measured against. The stored threshold is what lets the panel show "4.60:1, minimum 3.0:1".
- **`button_text` is `color_background` on `color_primary`.** §2.2 has no button-text column and this issue was not entitled to add one. Assuming white would quietly pass every dark palette and fail every light one regardless of what the widget draws.
- **`intervention/image` installed at first use** (ADR-0019 Option A, cited by BRD-7), bound explicitly to **GD**: Imagick is on neither the local stack nor the CI image, and a driver chosen by availability is a resize path tested on neither engine it runs on.
- **BRD-2 lives in `BrandPayload`, not at an endpoint.** #35, M3's embed bootstrap and the plugin's REST proxy are three separate chances to forget; the two payloads differ by exactly `custom_css` and the test does not care who is asking.
- **`social_links` survives a reset.** `platformDefaults()` is what a *new* profile starts as; a reset is something an existing operator asks for, and it would otherwise take their Instagram account with it.

### Verification

| Criterion | Result |
|---|---|
| `brand_profiles` matches §2.2, unique on `tenant_id` | `migrate:fresh` green on SQLite; **MySQL 8 in CI** |
| Profile created with platform defaults, **two creation paths** | factory, seeder, and with no tenant context resolved at all |
| Column defaults and config defaults agree | asserted by reading the schema, so a migration change with no config change fails |
| Non-`#RRGGBB` colour refused, localised | five spellings, and the row asserted unchanged after the refusal |
| `custom_css` sanitised on write **and** on read | hostile table; plus a row written around the model with a raw `update()` |
| Upload: SVG/PNG/WebP ≤ 2 MB, EXIF stripped, SVG sanitised, variants generated | 16 tests; content type from the **bytes**, refusals for size, type, unsafe SVG and undecodable body |
| Contrast stored, warns without blocking | low-contrast palette saves successfully and records `passes: false` |
| EL and EN labels, crew refused by policy | HTTP tests for the locales (`Livewire::test()` never runs `SetLocale`), role matrix for the policy |
| `custom_css` absent from every widget-facing path | `BrandPayload::forWidget()`, asserted on the array **and** on its JSON |
| Isolation gate discovers `BrandProfile` automatically | no hand-written test, which is the criterion |

`composer lint` green. `composer stan` — **`[OK] No errors`**, level 6, no baseline, no `@phpstan-ignore`. `composer i18n:check` — **140 passed**. `composer test` — **534 passed, 1 failed**.

Six PHPStan findings were fixed at the source rather than suppressed, and one is a house rule worth restating: **`$this` inside a Pest closure is typed `TestCall`**, so `$this->artisan()`, `$this->seed()` and `$this->tenant` are all undefined at level 6 — the same reason #2's smoke test imports the `Pest\Laravel\get` helper. The new tests use `Pest\Laravel\artisan()` / `seed()` and plain functions for fixtures. The other five were chained `->not`: it is a property on `Expectation` and not on the mixin a matcher returns, so a second `->not` in one chain is an undefined property. Split into separate statements.

### The one red test, again

`CiGatesTest` → "keeps the committed schema snapshot in step with the migrations". A new migration changed the fingerprint and `composer schema:snapshot` **requires a MySQL 8 connection**, which the local stack is not (ADR-0015). Identical to #16, and `docs/ci.md` §"Refreshing it after a migration change" is the path: open the PR, download the `mysql-schema-snapshot` artifact, **diff it before trusting it**, commit. The only lines that should move are the fingerprint and the `brand_profiles` block.

### Two i18n allow-list entries, with reasons

`Instagram`, `Facebook`, `TripAdvisor`, `WhatsApp` and `Google Fonts` are identical in `lang/el` and `lang/en`. They are company and product names — an operator looks for the wordmark they know, and translating "Google Fonts" would leave them searching for a product that is not called that anywhere they can look it up. Each is named in `tests/Support/I18n/allow-list.php` with that reason, per I18N-2.

---

## #16 — Vessels and ports: the first two catalogue tables

**Files:** `database/migrations/2026_09_02_0000{10,11}_create_{ports,vessels}_table.php`, `app/Models/{Port,Vessel}.php`, `app/Enums/Vessel{Type,Status,Amenity}.php`, `app/Domain/Catalog/**`, `app/Exceptions/CapacityLoweringRefused.php`, `app/Observers/VesselObserver.php`, `app/Rules/VesselCapacityNotLowered.php`, `app/Filament/App/Resources/{Vessel,Port}Resource**`, `app/Filament/Forms/TranslatableInput.php`, `app/Policies/{Vessel,Port}Policy.php`, `database/factories/{Port,Vessel}Factory.php`, `database/seeders/DemoCatalogSeeder.php`, `lang/{el,en}/catalog.php`, four test files

Items **10** and **11** of the §6 migration order. `products`, `schedule_rules`, `departures` and `vessel_blocks` all hold foreign keys into these two, and SQLite cannot add a foreign key after the fact, so every column either table will ever need is present today.

### Three things the tests found that reading would not have

**1. `deleted_at` in a unique index disables the index.** TEN-6 names `vessels.name` among the per-tenant uniques and §2.3's index table omitted it, so it is added here and the document amended in the same PR. The first version keyed it `(tenant_id, name, deleted_at)`, to stop a retired boat burning its name. `NULL` is distinct from `NULL` in a unique index on **both** engines, so every live row — all with a null `deleted_at` — stops colliding too and the constraint enforces nothing. Now `(tenant_id, name)`, matching `products_tenant_slug_unique`, which is also the safer half of the trade: a soft-deleted vessel can be restored, and restoring one into a collision is worse than refusing the duplicate.

**2. Laravel's `unique` rule ignores the tenant scope.** It runs through the `DatabasePresenceVerifier`, which builds a raw query, so `BelongsToTenant` does not apply and the form checked every operator on the platform — a second Greek operator adding their own Οδυσσέας was told the name was taken by a fleet they cannot see. Found by a test written to assert the *permissive* direction, which is the direction nobody writes a test for.

**3. `TranslatableRequired` had never fired on an empty field.** Laravel skips a non-implicit rule whenever the value is `null`, `''` or whitespace — every case the rule exists to catch. #15 did not see it because its tests validate whole translation *sets*, and an array is never "empty" by that check; it surfaces the moment a form binds one input per locale. The rule is now `implicit`. This is a latent #15 bug fixed here, not new work.

### `vessels.name` is not translatable and still needs folding

§1.6 says a boat's name is a proper noun, so a plain `orderBy`/`LIKE` looks correct and keeps the ENV-8 gate green. It is still wrong: the divergence ADR-0008 exists to prevent belongs to *Greek text*, not to JSON. MySQL folds tonos when comparing, SQLite does not, so `οδυσσευσ` finds `Οδυσσεύς` in production and misses it locally. `HasTranslatableSearch` grew `$foldedSearch` / `$foldedSort` for plain columns — one `{attribute}_sort` column rather than a per-locale pair, since a proper noun has one form in both languages.

### The capacity guard ships before the tables it guards

`capacity_max` is a **legal** ceiling, but the records that can claim a seat are in `products` (#18) and `departures` (#23). `GuardVesselCapacity` therefore ships against a `VesselCapacityClaims` interface with **zero implementations registered** — it refuses nothing today, correctly, because nothing can yet claim a seat — and a fake source proves the refusal, the localised offender list, the ordering and both enforcement points. #18 and #23 each add one class and one `tag()` line. Two enforcement points again, mirroring #15: `VesselObserver` throws for an import, `VesselCapacityNotLowered` puts the same sentence beside the field, and both delegate to the one Action.

### Two Filament testing traps

`Livewire::test()` never reaches the panel middleware, so `SetLocale` does not run and a component test asserting a Greek label renders in English and passes for the wrong reason — the locale assertions are HTTP tests. And Filament renders the empty state *instead of* the column headers when a table has no rows, so a label assertion against an empty table never sees the label. Both tests seed a row first.

### Verification

| Criterion | Result |
|---|---|
| `ports` then `vessels`, §2.3 columns, indexes, FKs | `migrate:fresh` green on SQLite; **MySQL 8 in CI** |
| `VesselType` — exactly five cases, string column, labels from `enums.php` | `EnumLabelCoverageTest` (reflective) |
| Buffer inheritance and override (AVL-7) | both cases, plus "tenant default changes → inheriting vessel follows" and resolution outside tenant context |
| `capacity_max` lowering refused, offenders listed, localised | via the fake claim source; message asserted in Greek and asserted *not* to be the dotted key |
| Filament CRUD + soft delete for owner/manager; crew refused | `VesselResourceTest`, `PortResourceTest`; `PolicyCoverageTest` proves the policies exist |
| Labels and messages from lang files, EL and EN | `composer i18n:check` — 138 passed |
| One-locale save refused per #15's rule | refused on `name.en`, the field that is empty |
| Isolation harness discovers `Vessel` and `Port` automatically | `ModelIsolationTest` — no hand-written test, which is the criterion |

`composer lint`, `composer stan`, `composer i18n:check` green. `composer test`: **425 passed, 1 failed** before the snapshot refresh below; **426 passed** after it.

### The one red test, and the CI round-trip that closed it

`CiGatesTest` → "keeps the committed schema snapshot in step with the migrations" was the only failure at commit time. Two new migrations changed the fingerprint, and `composer schema:snapshot` **requires a MySQL 8 connection** — the local stack is SQLite by ADR-0015 — so it could not be regenerated locally by any means.

`docs/ci.md` §"Refreshing it after a migration change" describes the path, and PR [#46](https://github.com/mikmikfil/kaiki/pull/46) walked it. Run [33727830006](https://github.com/mikmikfil/kaiki/actions/runs/33727830006) opened red on **four** jobs — `Pest on SQLite`, `Pest on MySQL 8 + Redis`, `Coverage gate on app/Domain` and `Migrate from zero and compare the schema` — all four tracing to that one assertion, not to four problems. The `mysql-schema-snapshot` artifact from that run was downloaded and committed.

The artifact was diffed against the committed file before it was trusted, rather than dropped in on the strength of the job name: **the only removed line is the fingerprint**, and the only additions are the `ports` and `vessels` table blocks. A snapshot refresh is the one commit in this repository that a reviewer cannot read line by line, so the check that nothing else moved has to be mechanical.

That diff is also the first MySQL 8 rendering of both tables, and it confirms on the real engine what SQLite could only imply: `vessels_tenant_name_unique` is `(tenant_id, name)` with no `deleted_at`, `search_index` is `text` with no index and the four `KEY`s are the intended ones, `name_sort` is `varchar(191)`, `description`/`specs`/`images` are `json`, and `vessels_home_port_id_foreign` is `ON DELETE SET NULL` against `ports`.

`vendor/bin/pest tests/Unit/CiGatesTest.php` — 11 passed. `composer test:fast` — **421 passed, 14.4s** against the 30s budget.

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

### Confirmed on MySQL 8, which is the whole point of ADR-0008

Three things could only be checked by the CI MySQL job. All three now are — [run 33641854446](https://github.com/mikmikfil/kaiki/actions/runs/33641854446) on PR [#45](https://github.com/mikmikfil/kaiki/pull/45), all 14 checks green:

1. **The fixture table on MySQL 8.** `json` columns and two `varchar(191)` indexes built without complaint; the 191 length exists precisely for the `utf8mb4` index-prefix limit SQLite does not have.
2. **The test-only migration path** registered from `TestCase::refreshApplication()` behaves identically under MySQL. This is why the fixture table is a migration rather than a `Schema::create()` in a `beforeEach`: DDL inside `RefreshDatabase`'s transaction is harmless on SQLite and an **implicit commit** on MySQL, so the wrong choice would have leaked in CI and nowhere else.
3. **`LIKE` against `search_index` returns the same rows on both engines.** `Pest on MySQL 8 + Redis` reports **351 passed (1121 assertions)** — the same count and the same assertions as SQLite locally, with `it matches καΐκι and καικι against the same records` and `it matches a final sigma against stored medial sigma and the reverse` both passing there.

Point 3 is worth stating plainly, because **it is the claim ADR-0008's Option A rests on.** Folding both sides in PHP was chosen over letting the database compare, precisely so that a Greek search cannot return one set of boats locally and a different set in production. Two engines producing identical results is the evidence for that decision rather than a restatement of it — and had the numbers diverged, what failed would have been the ADR, not this code.

The `mysql` group itself also executed for real (`1 passed (2 assertions)`), so the job is not passing on an empty filter.

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
