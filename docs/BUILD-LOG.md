# Kaiki — build log

What has actually been built, and the evidence it works. One entry per issue, newest first.

Each entry records the **verification actually run** and its **real output** — not what was supposed to happen. Where something can only be checked in CI, it says so rather than claiming a local pass. Where an issue was delivered differently from how it was written, the deviation is stated with its reason.

`CHANGELOG.md` is the narrative for a reader; this file is the audit trail.

---

## Status

| | |
|---|---|
| Milestone | **M5 — Operations: #118 … #128, #130 and #131 built.** M4 #112 … #116 built (#117 needs a real WordPress site), M3 complete (#101 … #111), M2 complete, M1 complete. |
| M0 | closed by #11 — #1 … #12, with #13 and #14 moved to `M8 — Launch & deployment` |
| M3 | **Closed by #111** — the hosted pages, all four widget mounts, custom domains, the live preview, the widget's release gates, and the end-to-end run that proves a person can buy a trip. **Four additions after it closed:** the guest-page design pass and **#132** on 2026-09-08; on 2026-09-09 the trip header, the search results, and the meeting-point map — which had been serving a grey void since Google withdrew its keyless embed; and later the same day the **booking hand-off (ADR-0030)**, the month calendar, the contact page, the operator's deposit switch — and the fix for the defect all of that uncovered: **no booking could be started from a hosted trip page at all**, because the page's own transient credential carried no environment and the draft endpoint asked it for one. #132 is — a picture on every trip card, drawn rather than downloaded, together with the three home-page photographs whose seeder had promised committed files that were not committed. |
| M4 | **#112 … #116 built** — the plugin skeleton, its settings screen, the standards gate, the client and cache everything reads through, the four shortcodes that are the plugin's whole promise, the same four through Gutenberg and Elementor, and the SEO trip pages. **#116 had to build `GET /api/v1/sync/products` on the platform first**: specified in `docs/api.md` since M0 and never implemented, so the feature it exists for had nothing to read. It is the only endpoint in the API a publishable key cannot reach. **#117 the release remains; it needs a real WordPress site, which is the product owner's.** Six issues written (#112 … #117). |
| M5 | **#118 … #128, #130 and #131 built** — the dashboard, the vessel calendar, the cash that arrives after the booking, the weather-cancellation preview, the manifests, the bookings and guests CSV exports, and iCal in both directions. **Entries for #118 … #122 are missing from this file and from `CHANGELOG.md`**, by the same rule as #24 … #32: they are on `main` with their reasoning in each commit message, and writing them up long afterwards would be reconstruction rather than an audit trail. **#130 added the fleet strip and «Χρειάζονται προσοχή» to the dashboard; #131 added «Καιρός» (ADR-0027), which reports the days over each boat's own wind limit and what is booked on them, and cancels nothing.** Six smaller commits sit between them — the Stripe removal, the demo fleet, two panel fixes, the embedded map — written up together above. **#125 built the outbound webhooks (OPS-19, OPS-20)** — four events, HMAC-signed, eight attempts over a day, a delivery history with a resend button, and an SSRF guard that checks addresses rather than hostnames. **#126 put the vouchers on screen** — the engine had worked since M2 and nobody could see it; the expiry sweeper and the two reminders were the missing clock, and the sweep runs against each tenant's own day rather than UTC's. **#127 built the consolidated failure feed** — a view over six tables rather than a seventh, with a retry only where one would do something. It also moved four plumbing screens into a collapsed Ρυθμίσεις group and **switched SMS off for the first phase** (product owner). **#128 built the offline boarding page** — a second surface, because the Filament one is Livewire and does nothing without a signal. Remaining in M5: the 390x844 Playwright run (OPS-22). |
| M2 | **Complete — #79 … #89**, all eleven, none merged (see the CI row) |
| M1 | **Closed by #53.** #15, #16, #17, #47, #23, #18, #19, #20, #22, #21, #24, #33, #34, #25, #26, #27, #28, #29, #30, #31, #32, #35, #36, #37, #53 |
| Pulled forward | #44, a read-only slice of M7's `/admin` |
| Local stack | Laravel 12.68 · PHP 8.4.25 · SQLite · database/file drivers |
| Quality gate | Pint · PHPStan level 6 + Larastan · Pest (2546, one failing: the schema snapshot CI cannot regenerate — and #131's migration has now moved its hash) · **Vitest (76), the widget's four build gates and the 17-spec Playwright run**  · **the `chromium` PDF group, which until #88 no CI job ran** · **AVL-44 overselling gate, live at last** · **cross-tenant isolation gate** · **ENV-8 JSON-path gate** · **phpcs over the WordPress plugin, at PHP 8.1** · EL/EN parity · OpenAPI drift · coverage of `app/Domain` · dependency audits · schema drift — **green locally; see the CI row below** |
| Deployment | Deliberately last (#13, #14 moved to `M8 — Launch & deployment`) |
| **CI** | **Blocked since 2026-09-06.** GitHub Actions refuses to start any job on this repository: every job on run 34028822331 failed in two seconds with no steps and no log. The cause is an account-level setting rather than anything in this repository, and it is the owner's to clear. Until it clears, the ENV-10 MySQL schema snapshot cannot be regenerated, because CI is the only place with a MySQL 8 connection. **Correction, 2026-09-10: nothing is enforcing a merge block.** This row and the roadmap both said #83 … #89 *"cannot be merged (the required `CI passed` check cannot run)"*. `main` carries **no branch protection and no rulesets** — checked against the API — so the six open pull requests can be merged at any time. What is real is the *reason not to*: those branches add migrations that have never run against MySQL 8, and the committed snapshot predates them. The caution is sound; the mechanism was imagined. |

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
| ~~**A billing provider for M7**, after Cashier came out with Stripe~~ | ~~product owner~~ | ~~M7~~ — **Decided 2026-09-08: Viva Wallet, the same gateway operators use for guests (ADR-0028 as amended).** |
| **Whether the full hosted site is a paid tier**, and what each plan gets | product owner | before the hosted site is sold as a tier — ADR-0029 settles the *shape* of the switch, not the price. *(Update 2026-09-11: `Plan`'s three predicates now have their caller — `PlanLimits` enforces vessels, domains and webhooks at creation (SAA-8). The numbers are still M0's and still yours to change.)* |
| **Open-Meteo's commercial subscription**, or another provider — the free endpoint is non-commercial only (ADR-0027) | product owner | before a paying operator sees «Καιρός» |
| **OpenStreetMap's tile usage policy**, or a paid tile provider — the Foundation's tiles are a best-effort free service and the policy asks heavy users to go elsewhere. The meeting-point map moved onto them because Google's keyless embed stopped working (see below) | product owner | before the hosted pages carry real traffic |
| ~~**No screen for adding staff.**~~ | ~~product owner~~ | ~~before the first operator hires anybody~~ — **built 2026-09-08**, along with the password reset both panels also lacked. |
| **A date filter on the departures list.** #129 found it has none; the default ascending sort happens to put today first with the current seed, so an operator tapping the "sailing today and tomorrow" figure lands on the whole table | product owner | a screen change, not a bug — decide whether the figure should filter or the list should default to today |

---

## M7 — Plan limits, enforced at last (SAA-8)

`app/Enums/Plan.php` has carried three rules since M0 — `vesselLimit()`, `allowsCustomDomain()`, `allowsWebhooks()` — and **nothing read any of them**. A `Solo` operator could build ten boats, point a domain at the platform and register webhooks. A price list that is not enforced is a pricing page that is not true, and SAA-8 was the first M7 item that waited on no decision.

### One place asks, and it asks at the point of creation

`App\Domain\Tenancy\Support\PlanLimits` answers the three questions; the vessel form, the domain screen and the webhook screen all come to it rather than reading the enum their own way. **SAA-8's other half — "never silently truncated" — is why it is asked only when something is created.** An operator moved down a plan keeps every boat, domain and webhook: nothing is deleted, hidden or stopped, and a verified domain keeps serving, because a billing change must not take a business's website off the air.

**Not in a model observer.** The demo seeders, the importer and every factory create vessels directly; a limit in the model would fail each of them on a rule that is about what a person may do in the panel.

### What each screen does

- **Vessels.** The list's subheading says «Έχετε 3 από 5 σκάφη του πακέτου Fleet»; at the limit the "new" button becomes «Αναβάθμιση πακέτου» — changed rather than removed, because an operator who looks for "new" and finds nothing assumes the product is broken. `CreateVessel::beforeCreate()` refuses with a persistent notification carrying the reason and the upgrade link, **after** validation, so a form error is still reported first and somebody who reached the form by URL is told why on the button.
- **Domains.** Off Pro, the CNAME instructions and the form give way to «Διαθέσιμο στο πακέτο Pro» and the upgrade button; domains already there are still listed. `add()` refuses too, for a request that did not come from the screen.
- **Webhooks.** Off Pro, "new" becomes the upgrade link and the subheading says why; existing endpoints are listed and keep sending. The create action refuses server-side as well.

### Where "upgrade" goes

There is no billing screen until the Viva subscriptions exist, so `KAIKI_UPGRADE_URL` (a contact page or `mailto:`) with a fallback to `mailto:` the platform's from-address. When billing lands it becomes that screen's URL and nothing that links to it moves.

### The demo operator moved to Pro

Aegean Blue has ten boats — over Fleet's five — and is where the domain and webhook screens are shown, so `DemoTenantSeeder` now makes it Pro (and the local database was moved the same way). Ionian Sunset stays on Solo with ten boats: the demo of a limit reached.

### Tests changed, not deleted

Three `DomainsPageTest` cases ("adds a hostname as pending", "refuses one another operator registered", "refuses something that is not a hostname") ran on the factory's Trial plan. Two of them assert that *nothing was created* — and would have kept passing for the wrong reason, the plan refusing before the hostname was ever looked at. They now run on a Pro operator, so they test what they say.

### Verification

- `tests/Feature/Panel/PlanLimitsTest.php` — **8 tests**: Solo keeps one boat and is refused a second with the reason; Fleet adds a fifth and is refused a sixth (SAA-8's own example); Pro has no limit; a Solo operator with three boats keeps and sees all three with "upgrade" in place of "new"; the usage line; domains Pro-only while an existing verified domain survives; webhooks Pro-only; the upgrade URL with and without configuration.
- Panel, Operations, Webhooks, Tenancy and I18n together: **1044 passed**. PHPStan on every changed file: no errors. Pint: passed. The full suite's result is in the commit message.

### Still open, and not mine

- **The numbers** are the code's since M0 — Trial and Solo one boat, Fleet five, Pro unlimited plus domain and webhooks. Trial having one boat means an operator evaluating with two cannot; a product-owner call, and one line in `Plan.php`.
- **ADR-0029's three site states were already built** — `HostedSiteMode` and the operator's control on «Η ιστοσελίδα σας» landed with the booking hand-off on 2026-09-09. What ADR-0029 left open is whether the full site is a paid tier, which is `PlanLimits` plus one predicate the day it is decided.

---

## Boarding without a QR, finished — and two gaps the first version left

Walking the boarding flow step by step for the product owner, after the QR switch landed, found two things it had got wrong.

### The no-signal page was switched off with the QR, and it should not have been

The QR entry below made `/app/boarding`, its scan endpoint and its worker answer **404** with QR off. That took the only boarding that works with no signal away from exactly the operators least likely to have one — a small boat, a small quay. The page stays now, for everyone. Without QR it loses the scan box and ignores `?ticket=` (`QR_ENABLED` in its script), and every name that is not yet aboard carries a **«Επιβίβαση» button** — a button rather than a tappable row, so a thumb scrolling forty names cannot board somebody by brushing past. The tap goes through the page's existing `scan()`, so it is queued in IndexedDB and reconciled by `CheckInGuest` exactly as a scan is; no new endpoint and no new domain logic. A refused tap now shows the server's Greek reason rather than a tick silently disappearing. The check-in page links to it, because until now the only way in was a ticket's QR.

### Early boarding existed only beside a scanned ticket

BKG-22's override — board before the window with a reason — was rendered only in the scanned-guest panel. The list offered «Επιβίβαση» and «Δεν εμφανίστηκε»; tapping «Επιβίβαση» early was refused with *"a manager can check in early with a reason"*, and there was no button to do it. Every operator without QR, and anyone whose guest forgot the ticket, had no early boarding at all. Each row now offers «Πρόωρη επιβίβαση» instead of «Επιβίβαση» while the booking's window is still shut (`CheckIn::isEarly()`), for everyone.

### And the no-signal badge read «ΣΕ ΣΥΝΔΕΣΗ»

Uppercase Greek in a lang file, against I18N-2 and the settled design rule. «Σε σύνδεση» / «Χωρίς σήμα» now.

### Verification

`QrCheckInSwitchTest` — the 404 test replaced by three: the no-signal page without QR is the list with no scan form, `QR_ENABLED = false`, and a tapped code checks the guest in; the scan form is still there for an operator who scans; a booking two hours out shows «Πρόωρη επιβίβαση» in the list and the link to the no-signal page. Results below in the commit.

---

## «Ρυθμίσεις» became a page of cards (product owner, 2026-09-11)

The collapsed third sidebar group from #127 held fifteen screens with no word of explanation between them. It is now **one item** leading to `/app/settings`: fifteen cards in four sections — the business, what guests see, history and files, for the advanced — each with the screen's own icon and title and one plain line saying what it is for. The whole card is the link, and the cards are **squares** — icon at the top, title and line beneath — four to a row from a laptop up, three on a tablet, two on a phone. That is the third layout of the day: wide cards four to a row, then bigger ones three to a row, then squares four to a row, each the product owner's call after seeing the last. Measured against the content area rather than the viewport, so an open sidebar is accounted for; a square grows taller rather than clipping when a Greek description needs another line.

**The item sits at the very bottom of the sidebar**, also the product owner's placement. Filament puts every ungrouped item above the groups, so a registered menu item could only ever be at the top; `Settings` is therefore unregistered and drawn in `SIDEBAR_FOOTER` — outside the scrolling menu, pinned to the bottom — with Filament's own `sidebar.item` component, so its badge, active state and collapsed tooltip match every other entry.

### The hub keeps no role list of its own

A card appears only when its screen's own `canAccess()` says yes, so it cannot drift from TEN-8. A manager gets no «Συνδέσεις» and no «Πληρωμές»; crew get no cards, so no sidebar item, and `/app/settings` answers 403. `SettingsHubTest` compares the cards shown to each role against what each target screen answers for that role, rather than against a second hard-coded list.

### Not a Filament cluster, on purpose

A cluster would have moved every screen under `/app/settings/…` and broken the manual's links, the testing walkthrough's and people's bookmarks. The fifteen screens keep their addresses, leave only the sidebar (`$shouldRegisterNavigation = false`), and each gets a «Πίσω στις ρυθμίσεις» link through one render hook scoped to exactly those pages. The sidebar item stays active inside any of them.

### The failure badge moved with its screen

«Τι πήγε στραβά» carried the only red badge in the old group. It now sits on the «Ρυθμίσεις» item and on its card, so a bad morning is still visible from the sidebar — and it still answers null when no tenant is resolved, which `NavigationBadgeTest` asserts.

### Verification

- `tests/Feature/Panel/SettingsHubTest.php` — **11 tests, 84 assertions**.
- `tests/Feature/Panel`, `tests/Feature/Operations`, `tests/Feature/I18n` together: **578 passed** (2206 assertions). `composer stan`: no errors across 1129 files. `composer lint:test`: passed.
- Looked at in a browser at 1280, 1536 and 390 pixels, and one target screen with the back link.
- **Not done:** the operator manual has no `/app/settings` screenshot yet; `docs/manual/capture.mjs` needs a shot and the PDF a rebuild.

---

## QR boarding becomes the platform's switch (BKG-20, amended 2026-09-11)

Asked for by the product owner while deciding which functions a small operator — one boat, local trips — would never use. The QR was the first one taken out, and it is **his** switch rather than the operator's: it sits on the `/admin` edit screen beside the plan and the sandbox flag.

### "Off" has to mean it everywhere at once

Hiding the menu entry alone would leave a ticket whose QR scans to a page the operator was told they do not have. So one column, `tenants.qr_check_in_enabled`, is read in four places: the e-ticket (no square and no ticket code under it), the offline boarding page, its scan endpoint and its service worker (all **404**, not 403 — the page does not exist for them, and a crew member arriving from an old QR is not being refused anything), and the Filament check-in page, which loses the scan box and ignores `?ticket=` but **keeps the passenger list with a tap per name**. Check-in itself (BKG-21 … BKG-23) is not optional; the scan is.

### On by default, and for everyone who exists today

Tickets already in guests' bags carry a QR. A default of off would have made every one of them stop scanning the morning the migration ran. Nullable with a constant default so it could be added to an existing table (data-model §6), exactly as `deposits_enabled` was; null reads as on through `Tenant::usesQrCheckIn()`, and the admin toggle formats a null as on so saving an unrelated field cannot switch it off.

### Audited like the plan

It joins `EditTenant::AUDITED`, so switching it writes `qr_check_in_enabled_from` / `_to` into the operator's own trail with the platform owner's reason — SEC-16's three parts, unchanged. A hidden-by-default ✓/✗ column on the merchant list answers "who boards from the list".

### Verification

- `tests/Feature/Operations/QrCheckInSwitchTest.php` — **6 tests**: null reads as on; the boarding page, the scan endpoint and the worker 404 when off and nobody is checked in; the check-in page keeps the list and drops the scan box, ignoring `?ticket=`; the scan box is still there for an operator who scans; the ticket markup has no `<svg>` and no ticket code when off; the admin switch saves and writes exactly the two context keys.
- With `OfflineBoardingTest`, `ETicketTest`, `TenantResourceTest`, `CheckInAccessTest`, `RoleMatrixTest`, `CheckInWindowTest`: **93 passed** after one fixture fix (the ticket test read guests outside the tenant). i18n gates: **87 passed**, including key resolution. PHPStan on every changed file: **no errors**. Pint: passed.
- The e-ticket **PDF** itself was not rendered: the decision is one line in `GenerateETicket` and the template is asserted as markup, the same split `ETicketTest` already makes for everything that does not need Chromium.
- The MySQL schema snapshot hash moves with this migration, like #131's did; it can only be regenerated in CI.

---

## #51 — the setup guide, and the column it should have had since M1

> 2026-09-10. Asked for out loud — *«πάμε να φτιάξουμε τον οδηγό πρώτης
> ρύθμισης?»* — and pulled forward out of M7, where the roadmap has it at two
> hours.

### `tenants.default_vat_rate_id` was never added, and #51 said it had to be

#51 was written on 2026-09-04 with one instruction marked as unable to wait:
the column *"has to land in M1"*, because §6 forbids a migration that adds a
**foreign key** to an existing table — SQLite cannot, and local development runs
on SQLite. It never landed. Nothing failed, because nothing used it; the
per-product `vat_rate_id` has been nullable since #18 and an operator has simply
been picking a rate from nothing on every trip they create.

By the letter of that rule the `tenants` table would now need rebuilding. It
does not, because **the rule is about foreign keys and this is a plain indexed
column without one**. `VatRate` rows are platform reference data withdrawn by
`is_selectable` rather than deleted (#47), so there is no cascade for a
constraint to enforce; the relation resolves in PHP and answers null for an id
that no longer does — the same answer the column's own null gives. What is given
up is the database refusing a dangling id, and what is bought is a migration
that runs on both engines.

### The checklist is derived, and only two things are stored

There is no "current step" column. An operator who adds their first boat from
the Σκάφη screen without ever opening the guide **has done that step**, and a
stored pointer would still be telling them to do it. `SetupChecklist` asks each
of the six SAA-9 steps of the data it is about, which makes SAA-10's
*"resumable"* free and, more to the point, single-valued: one answer to "is this
done", the same one whichever screen asks.

Two things genuinely cannot be read back, and are the whole of the migration:
`onboarding_skipped_steps`, because a skipped step and an untouched step are
identical in the data and must not be identical on screen; and
`onboarding_completed_at`, because SAA-9 ends by handing over an embed snippet
and no row records whether anybody read one.

### Three of the six steps do not have a form, and should not

SAA-9 lists six things and three already have a screen that does the job
properly — branding has #17's logo processing, sanitisers and contrast check; a
boat and a trip have their resources, with CAT-5's validation and the publish
checklist inside them. A wizard step reimplementing any of those is a second,
worse copy of a form that already exists, and the day one of them gains a field
is the day the wizard starts writing an incomplete record. So those three
**send the operator to the screen that owns the job** and tick themselves from
the data when it comes back. The business details and the VAT default are asked
inline, because there is nowhere else in the product that asks them at all.

### It is not `FirstSteps`, and merging them would have broken the dashboard

`FirstSteps` (#118, OPS-1) is also a derived checklist on the same dashboard,
and folding SAA-9's steps into it was the obvious move. It would have been
wrong: while `FirstSteps::applies()` is true it **suppresses the figures
widgets**, and its `applies()` is `next() !== null`. Adding the business,
branding and VAT steps to it would mean an operator with a full season's
bookings and an unfilled ΑΦΜ has their dashboard replaced by a checklist for
ever. So `SetupProgress` is an addition and never a replacement, and the two
lists overlap on the boat and the trip **by delegation** — `FirstSteps::state()`
owns those two answers and is asked for them.

### The redirect was too broad, and about a hundred tests said so

#51's first criterion is that the wizard *opens* when an owner signs in.
`OfferSetupOnce` first fired on the first page view of the session, whatever it
was, guarded by a session key so it could not nag. The suite went from one
failure to about a hundred: every panel test that signs an owner in and asks for
a page got a 302.

That was the feature being wrong, not the tests. An operator following a
bookmark to their bookings, or a link in a notification email, **is not
arriving** — they are going somewhere, and a setup guide instead loses what they
came for. Signing in lands on the dashboard, so the panel home is the only path
it fires on now. Nine failures remained, and six of those were the same
misjudgement one level down: `TenantFactory` has always built an operator
already trading — it fills in a legal name and an ΑΦΜ — so it now builds one
already set up, with `unconfigured()` for the other state.

### Top of the menu, and gone when it is done

Asked for while it was being built, and both halves were wrong first time. It
had gone into the **collapsed Ρυθμίσεις group** — which is the one place a new
operator will not look, and that group is closed by default precisely because it
holds the screens somebody goes *looking* for. This is the one screen that has
to find them. Ungrouped now, sort `-100`, which puts it above `Dashboard` and
above all three groups.

And it leaves the navigation entirely once finished, rather than merely dropping
its badge — a first-run guide in the menu for the rest of an operator's life is
a permanent reminder of something already done.

**That stranded a setting, and it had to be un-stranded rather than accepted.**
The VAT default was asked for only inside the guide, so a guide that disappears
made it unreachable for ever. It has a permanent home on **Ρυθμίσεις →
Πληρωμές** now, beside the deposit switch — which is where a tax setting
belonged anyway, on the owner-only screen about what a guest is charged, reading
the same `ProductResource::vatRateOptions()` and refusing a withdrawn rate the
same way.

The page's **URL keeps working** after completion, and `canAccess()` deliberately
does not close: the guide is still the only screen that asks for the legal name
and the ΑΦΜ. What disappears is the invitation, not the page. **Those business
details remain without a permanent home of their own** — named here rather than
left to be discovered.

### Verification

| Gate | Result |
|---|---|
| `vendor/bin/pint` | clean |
| `vendor/bin/phpstan` (level 6) | **No errors** |
| `vendor/bin/pest` | **2750 passed, 4 skipped, 1 failed** — `CiGatesTest`'s schema fingerprint, the pre-existing one only CI can regenerate, moved again by the two migrations here |
| `SetupWizardTest` | 18 passed — one per acceptance criterion, plus the derivation and the two gates |
| EL/EN parity, no-hardcoded-strings | passed |
| `npm run e2e:mobile` | 14 passed — the back office at 390×844, unchanged by this |
| Browser | The guide and the dashboard checklist read at 390×844 by screenshot; the demo operator correctly reads 3 of 6, with branding false because it has no logo and platform-default colours |

**Not covered by a test, and said so rather than claimed:** how the wizard looks
to an operator who skips every step, which is a judgement rather than an
assertion.

---

## The booking hand-off, the calendar, the contact page and the deposit switch

> 2026-09-09, unplanned like the three entries below it. Asked for out loud, one
> request at a time — and one of them turned up a defect that had made the whole
> feature it was about impossible.

### Every booking from a hosted trip page answered `500`

Found while walking the new flow in a browser, which is the only way it could
have been found. `HostedEmbedToken::transientKey()` builds an `ApiKey` in memory
for the page's own widget and never saves it — and never set `environment`.
`BookingCreateRequest::isTestKey()` reads `$key->environment->isTest()` to decide
PAY-11's `is_test` flag, so that was a method call on null:

```
Call to a member function isTest() on null
app/Http/Requests/Api/V1/BookingCreateRequest.php:270
```

Every attempt to book from `/{operator}/{trip}` — the pages M3 exists to
produce — died there, and the widget rendered «κάτι πήγε στραβά» with nothing to
say what. **Nothing caught it.** Every test of that endpoint authenticates with a
real `api_keys` row, which has the column; the one credential in the system that
is constructed rather than stored was the one nothing exercised end to end.

Fixed in two places, deliberately. `transientKey()` now sets the environment from
`tenants.is_sandbox`, which is the *correct* value rather than merely a non-null
one — an operator still in sandbox must not produce live bookings from their own
page (§3.9). And `isTestKey()` became `?->`, because the next transient
credential will be written by somebody who has not read that file.
`HostedWidgetEmbedTest` asserts both halves.

### ADR-0030 — the lead guest moved to checkout

The product owner described the flow twice, the second time in the plainest
terms: *"στο single page απλά να υπάρχει ημερομηνία, μετά άτομα … και μετά πάμε
για checkout. εκεί θα συμπληρώνουν στοιχεία για πρώτη φορά."*

WGT-18 had the widget walking five steps inside a 380-pixel embed, and BKG-7 made
the lead guest and the consent **required at draft creation** — which is what
forced a name and an email out of a guest before any price had been shown. The
review step that was supposed to show the price never did: it took a `quote` prop
nothing supplied, so it rendered «Υπολογίζουμε την τιμή σας…» permanently.

So `bookings.guest_name` and `guest_email` are nullable, and the invariant moved
rather than disappearing — from *"no draft without a lead guest"*, which a name
typed into an abandoned draft satisfied, to **"no payment without a lead guest
and a recorded consent"**, asserted in `StartCheckout`. That is the one line the
API and the hosted checkout page both cross, and it answers
`422 lead_guest_required`.

BKG-5 is FIXED and is unchanged: availability → draft with a hold → checkout →
gateway → webhook → confirmed. Only the step at which a person types their name
moved, from before the first arrow to between the second and the third.

| Changed | Why |
|---|---|
| `2026_09_02_000031_create_bookings_table.php` | Two columns nullable — a migration edit plus `migrate:fresh`, per data-model §0 |
| `BookingCreateRequest` | `guest` and `terms_accepted` optional; `accepted` still enforced **when sent**, because an unticked box is not consent |
| `BookingDraftData`, `CreateBookingDraft` | Nullable, and whitespace normalised to null: `''` is a name, null is a fact |
| `StartCheckout`, `CheckoutRefused` | The new guard and its refusal |
| `packages/widget` | `ContactStep` and `ReviewStep` deleted; the walk ends by redirecting to `checkout_url` from the `201` |
| `docs/spec.md`, `docs/api.md` | BKG-7, WGT-18, WGT-20 amended; the schema's `required` list and the error table |

The widget **never assembles the checkout address**. It reads `checkout_url` off
the `201`, because a URL built in a browser from a config value is a guess about
somebody else's deployment, and a custom domain makes it a wrong one.

### The date field became a calendar

`<input type="date">` was chosen for the right reasons — keyboard-operable,
screen-reader-labelled, free against the 80 KB budget — and had one fatal
property: **it cannot say which days are sold out**. A guest picked a full
Saturday and was refused at the next step, on a page that was rendering exactly
that availability in the calendar mount a few centimetres away. It also read
«mm/dd/yyyy» on a Greek page, which is what "localised by the device" means when
the device is set to English.

`MonthGrid.tsx` is now shared by the calendar mount (read-only) and the booking
walk's date step (the days with room are buttons). Seven columns, Monday first,
every day of the month drawn — a day the endpoint said nothing about is «not
sailing» rather than a hole. Green and red, plus a strike-through on sold-out
days and the status in each cell's accessible name, because roughly one man in
twelve cannot tell the two hues apart.

The colours are CSS named colours mixed into the operator's own background rather
than hex — not to slip past WGT-9's guard, which greps the built bundle, but
because that rule is about *brand* colours and these are the meaning of the cell.

### The contact page

`/{operator}/contact`, `GET` and `POST` on the same path so a browser with
scripts blocked submits to the page's own address. It writes an **enquiry**
through `SubmitEnquiry` — the same Action the widget's enquiry mount and the API
call — rather than sending an email, because `enquiries` already has a status, a
panel screen and BKG-29's immediate notification, and an inbox has none of those.

It reuses `EnquiryCreateRequest` unchanged, so BKG-29's honeypot and timing check
are the ones the API already applies, with the same limits. `toData()` gained a
`$source` parameter: the API cannot tell a widget from a WordPress shortcode and
guesses nothing, while this page knows it is `hosted`.

Linked from the header, the footer, the home page's contact banner and each trip
page's «Έχετε απορίες;» card — which passes `?product={uuid}`, so the enquiry
arrives attached to the trip it is about.

### The deposit is the operator's decision now

`rate_plans.deposit_type` has existed since #21 and there was no way for an
operator to say "not on my boat" other than setting `none` on every rate plan one
at a time — a business decision expressed as a chore. `tenants.deposits_enabled`
(nullable, constant default, no FK — portable under §6) and a **Πληρωμές** screen
in the panel, owner-gated like the gateway credentials, with the balance-due days
beside it because that is the other half of the same decision.

**Off for everyone.** The product owner's instruction, and the safer default: a
deposit is not one setting but a chain — a balance falls due (PRC-27), a reminder
goes out, a guest comes back, somebody chases whoever does not.

`DepositCalculator` was briefly made to read `Tenancy::current()`, and that was
wrong: it turned three lines of arithmetic into ambient state, so the same €150
at 30% answered 4500 or 15000 depending on what happened to be resolved, and
three tests that had never needed a database started failing. The flag is a
**required parameter** now — no default, because a caller that has not decided
has not decided — and `ComputePrice` asks the tenant once and passes it down.
Switched off, the answer is what `DepositType::None` has always meant: the whole
total, now.

### Two smaller things, both found by looking

**The passenger form counted seats, not people.** It iterated
`pax_capacity_total`, and an infant occupies no seat — so a family of two adults
and a baby filed a manifest with the baby missing from it, which is the one
document the coastguard reads. It reads `pax_breakdown` now, so the list is as
long as the party and each row says which band it is: «Επιβάτης 3 · Παιδί».

**`npm run typecheck` was already failing** on `Api` missing `setLocale`, a
method that exists on the class and is called through the interface. Fixed rather
than worked around, with the preview client's stub beside it.

### Verification

| Gate | Result |
|---|---|
| `vendor/bin/pint` | clean |
| `vendor/bin/phpstan` (level 6) | **No errors** |
| `vendor/bin/pest` | **2727 passed, 1 failed** — `CiGatesTest`'s schema fingerprint, the pre-existing one only CI can regenerate |
| `npm -w packages/widget run typecheck` | clean, for the first time in a while |
| `npm -w packages/widget run test` | 81 passed |
| `npm -w packages/widget run guards` | passed — no hex in the bundle, 63 keys in both locales |
| `npm -w packages/widget run size` | 22.0 KB gzipped, **27.5% of the 80 KB budget** |
| Browser | The whole path walked by hand on `:8001` — calendar → party → `/c/{token}` with the real price, the manifest and the Viva block |

**Not covered by a test, and said so rather than claimed:** the gateway redirect
itself, which needs Viva credentials nobody has yet.

---

## The guest pages, after looking at them — the map, the trip header, the search results

> Unplanned, like #130, #131 and #132. Two of the three were asked for out loud;
> the first was found by scrolling a page nobody had scrolled in a while.

### The meeting-point map had been a grey void

`https://www.google.com/maps?q=…&output=embed` — keyless, and what HOS-2 has
used since M3 — now `301`s to `/maps/embed?origin=mfe&pb=…`, which answers
**404** and sends `X-Frame-Options: SAMEORIGIN` with it. Every trip page with a
meeting point had a full-width grey slab in the middle of it.

Nothing here failed. `MeetingPointMapTest`'s six assertions were green the whole
time, the CSP permitted the origin it framed, and the browser logged nothing: a
frame that 404s is silent. **No test suite notices a third party changing its
mind**, and that is now written at the top of that file rather than learned
twice.

| Candidate | Result |
|---|---|
| `www.google.com/maps?q=…&output=embed` | 404, `X-Frame-Options: SAMEORIGIN` |
| `maps.google.com/maps?q=…&output=embed` | 404, same |
| `www.google.com/maps/embed/v1/place?q=…` | 404 — the keyed Embed API, and the key is the point |
| `openstreetmap.org/export/embed.html?bbox=…` | **200**, framed by design |

Google's supported replacement is the keyed Embed API. That would put a platform
credential in the markup of every operator's page and hand the platform a
per-render bill for a static picture of a marina. So the map is OpenStreetMap's
embed, with `HostedPageCsp::MAPS_ORIGIN` moved to match — one constant, so the
header and the markup cannot drift apart.

Two consequences, both deliberate:

- **An address with no coordinates gets no map.** The embed takes a bounding box
  rather than a search term, so there is nothing to geocode with. It keeps its
  link. Same subtraction the method already made for a pasted `goo.gl`, same
  reason: nothing beats a broken box on the section that tells a guest where to
  stand at nine in the morning.
- **No attribution line of ours.** The ODbL does require credit and the embed
  already carries it inside the frame. A first draft added a second one below
  and it was removed on sight — the same sentence twice, forty pixels apart.

The bounding box is ±0.004° of longitude and ±0.002° of latitude: about 350m by
220m, a marina and the streets that reach it. Coordinates are formatted to six
decimals in plain notation, because `(string) 1.0E-5` is a corner a bbox parser
reads as zero.

**Open, and not mine to close:** the OSM Foundation's tiles are a free
best-effort service whose usage policy asks heavy users to move to a provider
that sells them. That is fine for a demo and a question before real traffic, so
it is in the table above next to Open-Meteo's.

### The trip page's header, from the mockup

The five facts under the title were one run-on line with dots between them — a
sentence to read through to find the one thing you came for. Each sits under its
own icon now, and the row moved **above** the title by `order` rather than by
moving the `<ul>`: a page whose source begins "480 minutes, day charter, Zea
Marina" announces four details to a crawler before it says what the trip is
called. Nothing in the row is focusable, so the usual objection to a visual
reorder does not apply.

The icons were redrawn on a 24 grid. The old "people" mark was one figure with a
plus beside it — which everywhere else on the internet means *add a user*,
sitting next to the words "up to twelve people" — and the old "kind of trip" was
a wireframe cube, which is a package, a database or a 3-D model depending on
where you last saw one.

The cancellation policy stopped being a section of its own, open, printed at a
visitor who had not asked, and became the last FAQ entry. It is deliberately
**outside** the `FAQPage` schema: that markup claims questions this operator
answered, and this answer is generated from a policy row. A rich result quoting a
sentence nobody wrote is right until the policy changes and Google is still
showing last season's.

And the widget's «powered by Kaiki» is a flag now — `data-credit`, on by default
everywhere including custom domains, off only on Kaiki's own hosted pages whose
footer already says it.

### The search results were a card only in the stylesheet's opinion

Asked for directly: «εδω μεσα φτιαξε το πως φαινονται οι εκδρομες».

`search.blade.php` drew its own `<li class="trip">` — the same class name as
every other trip card and none of the structure. No `.trip-body`, so no padding
and the text began flush against the card's edge; no photograph, on a site whose
every other trip card leads with one; no button. A guest who searched landed on a
plainer version of the catalogue they had just left.

It is `hosted.partials.trip-card` now, extended with the two things a search
result knows and a listing does not: **the price for this party** rather than a
from-price, and the hour the boat leaves on the day they asked about. A quote
trip still shows no price (BKG-24) and its foot reserves the same height, so its
button lands on its neighbours' line.

The grid is capped rather than stretched — `ul.trips` maxes its tracks at `1fr`,
which is right where a last row of two would otherwise leave a hole, and wrong
where two results become 33rem cards with hero-sized images on a page meant for
comparing them. `.results` puts a ceiling on the track and packs from the left.

### Verification

| | |
|---|---|
| `vendor/bin/pest` | 2706 passed, 1 failed — the ENV-10 schema fingerprint only CI can regenerate, unchanged by this work |
| `tests/Feature/Hosted` | 130 passed, including three new ones |
| `vendor/bin/phpstan` | no errors, level 6 |
| Vitest | 79 passed, including the `data-credit` default |
| Looked at | the trip page, the map, and the search results at 1440px, EL and EN |

The three new tests assert **structure**, not appearance: `.trip-image`,
`.trip-body`, `.trip-foot is-party-price`, the bounding box's exact corners and
that no coordinate is printed in exponential notation. Appearance is what nobody
noticed for two milestones.

---

## #132 — A picture on every trip, drawn rather than downloaded

> Unplanned, like #130 and #131. Asked for directly: «βάλε σε όλες τις εκδρομές
> εικόνα ή χρώμα. προτιμώ εικόνα».

### What was actually on the page

Twenty-one demo trips, `products.images` empty on all twenty-one, so every card
on the hosted home page rendered `.trip-image.is-empty` — the tinted panel in
the operator's own colour. That state is correct and deliberate for a real
operator who has not uploaded anything yet, and it is the wrong thing for a demo
to be made of.

Two files did exist — `demo-trip-1.jpg` and `demo-trip-2.jpg` — on one
development machine and in no repository. Opened, they are flat teal gradients:
images in name only. The same gap explains why `DemoHomePageSeeder` shipped a
docblock saying "the demo images are committed for tenant 1" when `git
ls-files` listed no such file. A `migrate:fresh --seed` on a clean checkout got
a home page with no photographs and a catalogue of tinted panels.

### Why they are generated

The obvious way to get sixteen pictures is to download sixteen photographs of
somebody else's boat. That is the one option not open to seed data: it ships in
the repository and gets deployed to a demo people are shown, and a stock
photograph with no licence attached is a liability sitting in `database/`.

So `database/seeders/assets/generate.mjs` draws them — five times of day, five
boat silhouettes, islands, chop, sun-glitter, an arch for the cave trip — and
Chromium prints each to an 1800×1000 JPEG. They read as illustration rather than
as photography, which is the honest thing for demo data to look like. A real
operator replaces them on their first afternoon.

**JPEG rather than the SVG source**, because the panel accepts `image/jpeg`,
`image/png` and `image/webp` and deliberately not SVG — an uploaded SVG is a
script. Seeding a format no operator could upload would put demo data outside
the rules the product enforces.

**Deterministic**, so editing one scene does not rewrite the other fifteen:
every random-looking number comes from an FNV-1a hash of the scene's own name.

### Three things the drawing got wrong, found by looking at the output

1. **Seven full-width sine curves rendered as scan lines.** At 1800 pixels wide,
   an amplitude of a few pixels over a wavelength of three hundred is a straight
   line; the sea looked like a gradient somebody had ruled. Replaced with broken
   dashes, which carry the same information about distance and never form a line
   across the frame.
2. **Every headland was a sheer vertical slab.** The shape ran down to the
   bottom of the picture, and its outer boundary was a straight line from its
   foot to the frame edge — a wall rising out of the sea, not a coast. Land that
   meets the water where the water starts needs no such edge.
3. **The arch arrived on the home page as a black blob.** The cards crop 3:2
   from the middle of a 9:5 picture, and the hole was in the first 150 pixels.

### Matching a picture to a trip

By slug, so the scene drawn for the sunset trip lands on the sunset trip. A
product with no picture of its own — `DemoFleetSeeder` tops each catalogue up
with `trip-<random>` slugs — draws from the same pool by hashing its slug, so
re-seeding does not reshuffle the demo. `DemoImageSeeder` will not overwrite
anything a person chose: it writes `images` only when the column is empty or
still holds one of the two gradient placeholders it replaces.

### Verification

```
$ php artisan db:seed --class=DemoImageSeeder
INFO  Seeding database.

$ php artisan tinker --execute="… Product::withoutGlobalScopes() …"
1 | iliovasilema-aigina     | "products/1/iliovasilema-aigina.jpg"
… twenty-one rows, every one with a path …
files: 27

$ curl -s -o /dev/null -w '%{http_code} %{size_download}' \
    http://127.0.0.1:8001/storage/products/1/iliovasilema-aigina.jpg
200 58399
```

In the browser at 1280, on `/aegean-blue`:

| | |
|---|---|
| `.trip-image` elements carrying an `<img>` | 10 of 10 |
| `is-empty` occurrences in the document | 1 — the stylesheet rule |
| distinct `.trip-foot` tops among the ten cards | three — 1230 (six cards), 1843 (three), 2373 (one) |

```
$ vendor/bin/pint --test        # passed
$ vendor/bin/phpstan analyse    # [OK] No errors
$ vendor/bin/pest --parallel --processes=12
Tests: 1 failed, 4 skipped, 2703 passed (7780 assertions)  213.25s
```

The one failure is `CiGatesTest > it keeps the committed schema snapshot in step
with the migrations`, unchanged and not caused here: M6's migrations moved the
fingerprint this morning and CI is the only place with a MySQL 8 connection to
regenerate it. Blocked on GitHub billing since 2026-09-06.

### Two tests, because the mechanism is one lookup

`TripCardImageTest` asserts both card states. The whole thing is
`$product->images[0]['path']` in a partial, over a JSON column whose shape
nothing enforces — a rename of that key takes every photograph off every card
and leaves a page that still returns 200 and still passes every other test in
that directory.

---

## The invoice PDF, and the QR that is absent rather than invented

> **MYD-12**.

### "Once issued" is the operative clause

A PDF of a `pending` document would look official and carry neither a number nor
a MARK — handed to a guest, then contradicted by the real one a minute later. A
PDF of a `failed` one is worse: it says a sale was filed that was not. Anything
that is not `sent` is refused.

### The QR is AADE's URL or nothing

`qr_url` comes back **with** the MARK and points at the tax authority's own
verification page. It is issued, not derived, so this code cannot construct one.

A document that somehow reached `sent` without one therefore prints without a
square. A QR pointing somewhere plausible is worse than no QR, because somebody
will scan it — possibly a tax inspector — and believe the answer.

### Rendered once, and the bytes do not move

A guest opening their booking four times does not start four headless browsers.
More importantly, an invoice regenerated on each request is one whose printed
copy and screen copy can disagree, and that is a conversation nobody wants with
an accountant.

### The locale bug, for the third time in three places

The first render printed **«Retail receipt» directly under «ΑΛΠ Α/2026/1»**. The
type label inherited whatever locale was current — for a queue worker, whatever
the last job set — while every other word on the page is Greek by law rather than
by preference.

Pinned around the render and restored in a `finally`, so a worker is never left
in Greek for the next tenant's English guest.

That is the same defect `GuestMail` was written to avoid and that
`AadeErrors::inGreek()` was written to avoid earlier today. Three occurrences in
one codebase says it is not a slip but a **shape**: anything rendered off the
request path inherits a locale nobody set, and every such place needs the
question asked deliberately.

### Everything comes off the invoice row

Not the booking, not `vat_rates`. The row was frozen at issuance from a snapshot
frozen at pricing time, and reaching past it would let a vessel renamed in March
or a statutory rate changed in April rewrite a document AADE registered in
February.

Private disk, uuid filename — «ΑΛΠ Α/2026/41» has slashes and Greek in it, and a
filename is not where to discover how a storage driver feels about either.

### Verified

```
vendor/bin/pest tests/Feature/Compliance/InvoicePdfTest.php   7 passed
vendor/bin/pest --parallel --processes=12                     2695 passed
vendor/bin/pint --test                                        passed
vendor/bin/phpstan analyse                                    [OK] No errors
```

The seven render real PDFs. And the document was looked at: reference, legal
identity, VAT split, MARK, QR, and the test-environment line at the foot.

### This closes M6's buildable half

What remains needs a person, not an afternoon:

- **VAT rates** (MYD-6a) — an accountant.
- **The gap policy** (MYD-4.6) — an accountant.
- **The ναυλοσύμφωνο's ΚΥΑ text** (CMP-6) — a lawyer.
- **AADE credentials**, for the client itself.

---

## The invoices screen, and a warning that only appears when it is true

> **MYD-5**, **MYD-10**, **MYD-11**, **TEN-8**.

### Read-only, and more strictly than the vouchers screen

`VoucherResource` has no edit form because a voucher's terms were given to
somebody in writing. An invoice is stronger: a document in a state tax register,
among the rows §1.4 says nothing removes, with `InvoicePolicy::delete()`
returning false for **everyone including the owner**. No form, no create page, no
delete. Two things can be done — read one, and ask a failed one to try again.

### The warning is the reason this screen needed a test

MYD-11: *"…the current endpoint is displayed in the panel so an operator can see
they are in test mode."* An operator who believes they are filing documents and
is not finds out from an accountant months later, and the cost of that discovery
is measured in years of trading.

So the line appears **only when a document on their own account actually went to
the test endpoint**. A permanent banner is furniture; one that appears is a fact,
because its presence is itself the information.

It reads the rows rather than the config, deliberately. `config` says what the
*next* document will do; the rows say what the ones already issued did — and an
operator whose credentials changed last month needs to know that September's went
to a sandbox, whatever October's will do.

### `getHeader()` was the obvious place and the wrong one

Filament's page header is the **whole** header — title and actions — so returning
a banner from it replaced «Παραστατικά» rather than sitting under it. It is a
table header now, which renders between the page title and the rows.

Caught by looking at the screen. There is no other way that particular mistake is
found: nothing throws, nothing logs, and the page looks deliberate.

### Three smaller decisions

**A credit note's total renders negative.** A column of unsigned numbers makes a
series of sales and corrections add up to nonsense at a glance.

**A pending document says «Χωρίς αριθμό ακόμη».** An empty cell in a numbered
series reads as data loss rather than as a document waiting its turn.

**The failure column shows the stored Greek sentence** rather than re-deriving it
from the code. The dictionary will grow, and the same document explained two ways
on two afternoons is exactly what an operator quotes to their accountant and then
has to un-quote.

### Retry, and what it is careful about

Offered only on a document that gave up — a `pending` one is coming back on its
own, and a second job for it would take a second number. It sets the row back to
`pending` before dispatching, because `SubmitInvoiceToMyData` ignores anything
that is not, which is what stops a registered document being sent twice.

The navigation badge counts what gave up: an invoice AADE refused is money the
operator's books do not yet reflect, and it is the one thing here that needs
noticing without opening the screen.

### Verified

```
vendor/bin/pest tests/Feature/Panel/InvoiceScreenTest.php    9 passed
vendor/bin/pest --parallel --processes=12                    2688 passed
vendor/bin/pint --test                                       passed
vendor/bin/phpstan analyse                                   [OK] No errors
```

And in the browser: breadcrumbs, the heading, the warning, and four demo
documents — one registered, one pending with no number, one refused showing the
Greek explanation of code 243 with «Νέα προσπάθεια» beside it.

---

## Credit notes, and the two listeners BKG-13 and CXL-11 were waiting for

> **MYD-13**, **CXL-11**, **BKG-13.4**, **ADR-0003**, **ADR-0025**.

### A refund answers an invoice; it does not delete one

§1.4 puts invoices among the rows nothing removes, and a document registered with
AADE cannot be withdrawn by deleting a row here — the register holds it. What a
refund produces is a **second document** pointing at the first.

The **partial** refund is the ordinary case, not the edge. A weather cancellation
under a policy returning 60% is the most common refund this product will ever
issue, which is why the amount is a parameter, why several credit notes may point
at one invoice, and why `IssueInvoice` exempts credit notes from its
one-per-booking rule.

### The bug, and why the fix moved rather than got a condition

The first version marked the original `cancelled` when the credit note was
**written**. Then a test refused one, and:

- the sale looked cancelled in the operator's books while the tax register still
  held it live; and
- the original became **uncreditable**, because a cancelled invoice is not a
  creditable one — so the refund the operator still owed had no route to a
  document at all.

The second consequence is the worse one and was not obvious from the first.

The close moved into `SubmitInvoiceToMyData`, where the MARK arrives. The status
now says what the register says, which is the only thing it can usefully mean —
and a refused credit note leaves the sale standing and creditable, which is what
an operator needs it to do.

### VAT at the original's rate, and the over-credit refusal

€60 of a €100 sale at 13% is €53.10 and €6.90, derived from **the invoice's**
snapshotted `vat_rate_bp`. Reading `vat_rates` at credit time would apply a rate
that may have changed since, and a credit note that does not mirror its original
is one an accountant reconciles by hand. Taken as `total − net`, so MYD-7's
invariant holds without being enforced twice.

Two partials totalling more than the sale are refused: the register would
otherwise show a negative amount of trade. A **refused** credit note does not
count against what remains — it took nothing off the sale, and counting it would
stop the operator re-issuing the refund they still owe.

### Two listeners, in a provider of their own

`NotificationServiceProvider`'s docblock has carried BKG-13's nine side effects
as a table since #88, with *"myDATA invoice — M6"* against step 4. That file is
about **messages** — its own docblock explains at length why the email and the
SMS are one listener — and putting a tax authority into it would make it about
two unrelated things.

**`IssueInvoiceOnConfirmation`** is queued and delays the *submission* by
`invoice_auto_issue_delay_minutes`, fifteen by default (ADR-0003). The window is
the point: issue instantly and every guest who asks for a company invoice on the
confirmation page costs a credit note plus a re-issue. The row is written at
once, so an operator sees a pending document rather than nothing.

**`IssueCreditNoteOnRefund`** is deliberately **not** queued. `BookingRefunded`
carries the `Booking` model and ADR-0025 §2 keeps it inside the dispatching
process; a `ShouldQueue` listener would serialise the model with the event. It
writes two rows and dispatches the submission, so it returns long before anything
reaches AADE — and it catches everything, because an exception in a synchronous
listener would propagate into the refund path and could unwind money that has
already moved.

Both refuse quietly rather than restating conditions that live elsewhere. Two
copies of "may this be invoiced" is one too many, and the copy that goes stale is
always the one in the listener.

### A fixture that had to take the real path

`registeredInvoice()` originally wrote `number = 1` by hand. That leaves the
counter at zero, the next document allocates 1 again, and the unique index
refuses it — the index doing exactly its job, and a good argument for a fixture
that goes through `AllocateInvoiceNumber` like production does.

### Verified

```
vendor/bin/pest tests/Feature/Compliance/    90 passed
vendor/bin/pest --parallel --processes=12    2679 passed
vendor/bin/pint --test                       passed
vendor/bin/phpstan analyse                   [OK] No errors
```

---

## The ναυλοσύμφωνο, built around a legal answer nobody has yet

> **CMP-6**, **CMP-7**, **CMP-8**, **CMP-9**.

### The mechanism is buildable; the text is not

The wording is prescribed by ΚΥΑ Α.Π. 3133.1/47821 and **nobody here has read
it**. `CharterAgreement` has said since #88 that M6 is blocked on a legal
question rather than on code, and that remains true of the *wording*.

It is not true of anything else. A versioned template, a snapshot frozen at
generation, a hash, a stored file, evidence that cannot be overwritten — all
ours. So the machinery is built and the template announces itself as provisional
in its own first paragraph, in Greek, on the page.

Two worse options were available. Building nothing leaves the whole of CMP-6
waiting on an answer that may take weeks. Inventing plausible ΚΥΑ wording
produces a document an operator might hand to a harbour master, which is far
worse than an obviously unfinished one.

### `provisional-` in the version is load-bearing

CMP-8 keys immutability to the template version. When a lawyer's text arrives it
becomes `v1`, and every agreement produced under `provisional-2026-09` stays
permanently distinguishable from a real one — in the row, in the snapshot and in
the filename. A version called `v1` today would make that impossible to tell
apart later, which is the sort of thing discovered during an inspection.

### The snapshot, stated as the thing an operator actually does

CMP-7 asks that a regenerated PDF be byte-comparable in content to the one the
guest accepted. A template reading `$booking->vessel->name` renders today's name
every time it runs.

So the test is not "the snapshot has keys". It is: generate an agreement, rename
the boat in March, and assert February's document still says «Αμφιτρίτη».

### Regeneration, and the row that must not move

In place while nothing has been accepted — an operator correcting a passenger
count before sending should get one agreement, not two. A **new row** once
something has, because the evidence a guest's acceptance created (the timestamp,
the IP, the hash of what they saw) has to survive somebody pressing
"regenerate". `CharterAgreement::openVersionFor()` was written for exactly this
and existed before the action did.

The version is in the filename for the same reason: a regeneration under a new
template must not overwrite the bytes an accepted row's hash points at.

### It gates nothing

CMP-9 requires acceptance for a per-vessel charter and says it **must not block
the booking**. A guest who cannot pay because a document is unsigned is a guest
who books elsewhere. Outstanding agreements are chased by reminders and shown in
the dashboard — a nudge rather than a wall.

### Verified

```
vendor/bin/pest tests/Feature/Compliance/CharterAgreementTest.php   8 passed
vendor/bin/pest --parallel --processes=12                          2666 passed
vendor/bin/pint --test                                             passed
vendor/bin/phpstan analyse                                         [OK] No errors
```

The eight do render real PDFs through Browsershot rather than mocking it.

### Still owed by a person

The ΚΥΑ text itself, from a lawyer or a shipping accountant. Everything else in
CMP-6 is done and waiting for it.

---

## GDPR: the job whose success is that data is gone, and the two requests with a deadline

> **GDR-2**, **GDR-3**, **GDR-4**, **GDR-5**, **GDR-6**, **GDR-10**, **GDR-11**,
> **ADR-0012**.

### Passport numbers now stop existing on time

`PurgeGuestDocumentsJob`, daily at 05:05, per tenant. GDR-2 is a promise made to
guests in the privacy notice and to operators in the DPA: an identity document
number is kept for a stated number of days after the departure and then
destroyed. **A promise nothing enforces is one the operator is breaking without
knowing**, and unlike most broken promises this one accumulates silently and is
discovered by a regulator.

GDR-3.3 draws the line precisely and half the tests are about the half that does
*not* get deleted: **`document_number` and `document_type`, and nothing else.**
The name, the date of birth and the nationality stay, because a manifest a
coastguard asked for last August has to remain explicable and a chargeback six
months later is argued with a passenger list. Purging a name in service of a
promise nobody made would leave an operator unable to answer either.

`document_purged_at` is why this is not "set it to null". A null number means
*purged* or *never given*, which are opposite answers to "did this guest provide
a document" — and the second one gets the guest chased for missing details months
after the trip.

Chunked rather than a mass `update`, because `document_number` is an `encrypted`
cast: a bulk update writes the literal string past the cast and the row reads
back as a decryption failure rather than as empty.

The clock runs from the **departure**, not the booking — a trip booked in January
for August is retained from August — and the window is clamped to GDR-3.2's 30
and 365. Clamped rather than trusted: the column is validated at save, so a value
outside the bounds arrived by a seeder, an import or a hand-edited row, and the
floor is what stops an operator promising thirty days and keeping the number for
one.

The audit line carries a tenant and a count. Putting a name in the record of
having deleted a name is the joke that writes itself, and this is where it would
have been written.

### And a bug that would have made erasure a lie

`EraseGuestData` first erased the booking and then looked passengers up through
`bookings.guest_email` — **which the first step had just overwritten.** The
passenger query matched nothing, and every passenger name and passport number
would have survived an erasure the operator had been told succeeded.

Nothing in the code would have shown it. The action returned counts, the
transaction committed, the booking's own name was gone, and the operator's
confirmation said the request was honoured.

The fix is not to reorder the two calls. The booking ids are resolved **once,
first**, and both steps work from them — so the ordering dependency is gone
rather than correct and fragile behind a comment saying which line has to come
second.

### Erasure is anonymisation, and that is not a shortcut

GDR-10 says a deletion *"purges or anonymises … retaining only what accounting
and tax law require (invoices, payment records)"*. A booking that is truly
deleted takes its invoice's foreign key with it, and an invoice is a document in
a state tax register the operator must keep for years. Article 17(3)(b) does not
ask them to break tax law.

So the **person** goes and the **transaction** stays: a pseudonym, an amount, a
date, and no route back to a human being. Fields are overwritten rather than
nulled, because a null column reads as *never collected* and this has to read as
*erased on request* — the difference between an operator answering an audit and
shrugging at one. The pseudonym carries the date for the same reason.

The email becomes `erased-{id}@erased.invalid`. RFC 2606 reserves `.invalid`
precisely so nothing sent there can reach a person by accident, and null would
break every screen that renders a booking.

The docblock says outright that this is **not a cryptographic guarantee** — an
amount, a date and a trip are still a row, and somebody holding an external
record could match them. Claiming otherwise is the kind of sentence that ends up
quoted in a DPA.

### The export answers "what do you hold" without printing a passport number

GDR-6 keeps document numbers out of every export, and this file is emailed to
whoever asked. So it reports one of three states — never given, held, or
**destroyed on this date** — which is the honest answer, and the third is what
turns a privacy notice from a promise into evidence.

Asserted the way `ExportRows` asserts it: a real number in the database, the
finished document, and the number nowhere in its bytes. Checking the array's
shape would pass a version that added the field back under a different key.

Two things worth recording about the data model:

**`booking_guests` holds no contact details at all** — no email, no telephone. A
passenger's address is simply never collected, which is a better privacy answer
than anything this code could do, and it means the party is reached through the
booking. An export returning only the lead's own row would be an incomplete
answer to a request that covers everyone who sailed.

**The notification log's column is `to`, not `recipient`.** Quoted in the raw
comparison, because it is a reserved word in more than one dialect and an
unquoted one is a syntax error on the day this runs against MySQL rather than
SQLite.

The log row itself survives an erasure with its address replaced: it is an
operational record — a reminder went out at this hour and was delivered — and
deleting it would lose the operator's answer to "did you tell them".

### Verified

```
vendor/bin/pest tests/Feature/Compliance/    69 passed
vendor/bin/pest --parallel --processes=12    2658 passed
vendor/bin/pint --test                       passed
vendor/bin/phpstan analyse                   [OK] No errors
```

### Not built here

No screen yet. Both actions are callable and tested; putting them behind a panel
button is a separate piece of work, and it needs a confirmation flow worth
designing carefully — an erasure is the one action in this product that cannot
be undone by anybody.

---

## The AADE client, built around not having one

> **MYD-5**, **MYD-9**, **MYD-10**, **MYD-14**, **MYD-15**, **MYD-4.4**.

### The interface exists because the credentials do not

`MyDataGateway` is a seam for three reasons, and the third is what decided it.
MYD-11 selects an endpoint per environment, which is wiring. MYD-1 issues under
the *operator's* credentials, so the object is resolved per tenant rather than
injected at boot. And the platform has no AADE account, which would have idled
the milestone if the client were welded into the issuing path.

Behind the interface, `NullMyDataGateway` is bound today and every other part of
M6 is built and proved against `FakeMyDataGateway`. A credential arriving in
November is one line in `AppServiceProvider`.

### The null gateway refuses, the SMS one swallows, and both are right

`NullSmsGateway` reports success: an unsent text is a small loss and a broken
reminder sweep is a large one. This is the opposite case and takes the opposite
answer.

An invoice marked `sent` claims a document exists in a state tax register. A null
gateway reporting success would give an operator a shelf of invoices their books
say were filed and AADE has never heard of — discovered during an audit, by the
worst possible person, at the worst possible moment. So it refuses, and it is not
retryable either: retrying will not conjure a credential, and eight attempts
would bury the one line they need to read.

The invoice lands in the failure feed saying «Δεν έχει συνδεθεί το myDATA». An
operator finds out on day one that issuing is off, which is what silence would
have prevented.

### Refused, unreachable, and why collapsing them breaks a queue both ways

`MyDataResult` has three outcomes, and the middle one is the one people forget.

**Refused** — AADE answered, and the answer is no. A malformed ΑΦΜ will be
malformed in six hours. Retrying is eight attempts at something that was never
going to work, filling the failure feed with noise.

**Unreachable** — nobody answered. The payload may be perfect.

A single "failed" state retries what cannot succeed and, once somebody notices
and turns retries down, stops retrying what would have.

### The error dictionary's honest state

MYD-9 asks for plain-Greek explanations in `docs/compliance/mydata-errors.md`,
loaded from lang at runtime. The mechanism is complete; **the table has six
entries and only one is an AADE code.**

That is deliberate. Without an AADE account there is no way to verify a mapping,
and a confidently wrong Greek explanation is worse than none — an operator will
act on it. So `243` (the one the spec names) is mapped, the transport outcomes
are mapped, and everything else falls back to an entry that shows **AADE's own
message verbatim** beside "show this to your accountant".

The file marks every row by provenance and says outright that somebody with a
developer account must walk it against the official list before go-live. A short
honest table beats a long plausible one.

### `last_error_message_el` was not Greek, and a test found it

The column is named `_el` and is written by a **queue worker**, which holds
whatever locale the last job on it set. The first version called `__()` and the
test — running in English — got English into a column whose name promises Greek.
An operator's failure feed would have carried English on a busy morning and Greek
on a quiet one, from the same error, with nobody able to work out why.

`AadeErrors::inGreek()` pins it. The same defect `GuestMail` was written to
avoid, in the same place: a worker with no request behind it has no locale worth
trusting.

### And a guard that had never fired

BKG-34 says an imported booking never produces an invoice. The check read
`$booking->source === 'import'` — but `source` is cast to `BookingSource`, so the
comparison was never true and the guard did nothing at all. An imported booking
would have been invoiced a second time in the operator's own series. Exactly the
shape a requirement acquires when nothing exercises it.

### The number is taken in the job, immediately before the call

MYD-4.2. Not at dispatch: a job sitting in a queue for an hour must not be
holding a number. Not at row creation: a document that is never submitted must
not burn one. `AllocateInvoiceNumber` is idempotent, so a job that crashed after
allocating does not take a second on its next run.

When a send then fails permanently, `invoice_number_gaps` records the number, the
reason and the moment — MYD-4.4, and the answer to an accountant's question about
a hole in the series.

**⚠ If the gap policy is ruled out** (spec §16.3), the change is one line in this
job: allocate after a MARK comes back rather than before the call. Nothing in the
allocator assumes which side it is on.

### Verified

```
vendor/bin/pest tests/Feature/Compliance/    48 passed
vendor/bin/pest --parallel --processes=12    2637 passed
vendor/bin/pint --test                       passed
vendor/bin/phpstan analyse                   [OK] No errors
```

---

## M6 opens — the invoice foundation, without the credentials

> **MYD-2…MYD-5**, **MYD-8**, **ADR-0003**, **ADR-0022** — the tables, the two
> pure decisions, and the number allocator.

### What this is, and what it deliberately is not

M6 is myDATA, and the AADE client needs credentials the product owner does not
have yet. Waiting for them would idle the milestone, so this is **everything in
M6 that a test can prove without a tax authority on the other end**: the schema,
ΑΦΜ validation, the ΑΛΠ/ΤΠΥ decision, and invoice numbering.

The client itself is next and will be built behind a gateway interface with a
null implementation, the same shape `app/Domain/Notifications/Gateways` already
uses for SMS — so a credential arriving later is a config change rather than a
rewrite.

### `number` is nullable, and `docs/data-model.md` disagreed with itself

§4.6's column grid said `number` is `no` null. Its own **[LOCK]** note, two
paragraphs down, said *"`number` is nullable until allocated"* and spent a
paragraph explaining why. The note wins and the grid is corrected in this
commit, because the rule it protects is the design: **MYD-4.2 allocates at the
send attempt, never at row creation**, so a document written and never submitted
does not burn a number. A non-null column forces a number onto every `pending`
row and defeats that entirely.

Worth recording as a class of problem rather than a typo: a spec that states a
fact twice will eventually state it two ways, and the second statement is
usually the considered one.

### The numbering guarantee is three layers, and the middle one is the real one

1. **[LOCK]** `lockForUpdate()` on the `series_counters` row. Makes a collision
   rare.
2. `unique(tenant_id, series, year, number)`. Makes it **impossible**.
3. A retry when (2) fires anyway. Makes it invisible.

`MAX(number) + 1` was never an option: two requests reading it at the same
instant see the same maximum, and the window is small and the consequence is a
duplicated invoice number in a Greek series — not a bug an operator reports, a
question they answer to their accountant.

**On SQLite the lock is a no-op** (§0), so on every developer's machine layers 2
and 3 are the only ones running. That is a good argument for the layer that has
to hold being the one that holds everywhere, and it is why the duplicate test
writes the collision by hand rather than racing two processes: a race that
passes once has proved nothing.

The counter row is created on first use, so nothing is seeded at the turn of the
year and an operator who starts trading in July does not begin at a number their
books cannot explain.

### The year is the tenant's, and a test caught me getting the clock wrong

`AllocateInvoiceNumber` reads the year in the **operator's** timezone. 23:30 on
31 December in Athens is already 1 January in UTC, and an operator whose last
document of the year landed in next year's series would be explaining that to an
accountant.

The reset test originally set the clock to 22:00 UTC on 31 December to mean
"late on the last day". In Athens that is already 1 January, so both allocations
landed in the same year and the test failed — correctly. The clock was wrong, not
the allocator, and the fixed test now says so in a comment so the next person
does not reach for the same obvious hour.

### ΑΦΜ validation is arithmetic, on purpose

MYD-3.5 makes validation a **precondition** of issuing a ΤΠΥ, and a precondition
that needs a network call is a precondition that fails when the network does — on
a Saturday in August, on the confirmation page, in front of a guest. The Greek
modulus-11 checksum catches the overwhelmingly common error, which is a typo, and
needs nothing. Whether the number belongs to a trading company is a question only
AADE can answer and is not asked here.

Two details that are each one test:

- **`% 11 % 10`.** A remainder of 10 is a check digit of 0. Leaving the second
  modulus out rejects one valid number in eleven.
- **Nine zeros fail.** They satisfy the arithmetic and are not an ΑΦΜ — and they
  are exactly what an empty padded field becomes.

Foreign numbers get a shape check, not a checksum. Every member state has its own
scheme and reimplementing twenty-six from memory is how a valid Italian VAT
number gets refused at a Greek checkout. Any leading pair of letters is stripped
so «IT12345678901» and «DE123456789» work alongside «EL094014201» — general
rather than three special cases.

### A bad ΑΦΜ never stops a sale

MYD-3.5, and the rule the whole resolver is shaped around. An invalid number
produces an **ΑΛΠ plus an operator-visible warning**, not an error. The guest is
not held up, the operator is told, and a customer who really needed the invoice
is fixed with a credit note and a re-issue. The alternative is a ΤΠΥ that AADE
refuses days later, discovered by the wrong person at the worst time.

`InvoiceTypeResolver::explain()` returns the type and the reason in one pass
rather than offering a second `reasonFor()`, so the two can never disagree — the
copy is always the one that goes stale.

A name with no number is *not* a warning: somebody typed their employer into the
wrong box, and putting the ordinary case in a warning list buries the two rows a
month that are real.

### Three gates in this repository caught things before I did

- `ModelIsolationTest` refused three tenant-owned models with no factory, with a
  message that says why removing them from the suite is not the fix.
- `PolicyCoverageTest` refused them with no policy.
- `EnumLabelCoverageTest` refused two enums with no Greek labels.

All three are the kind of gate that is worth more than the test it replaced.

### Two policies say `false` out loud

`InvoicePolicy::delete()` and `forceDelete()` return false for **everyone,
including the owner**: §1.4 puts invoices among the rows no code path removes,
and a soft delete is a delete a person can perform. `SeriesCounterPolicy` and
`InvoiceNumberGapPolicy` refuse every write for the same reason — a counter
edited by hand is how a series gains a duplicate, and a gap that can be tidied
away is a gap that will be, on the one afternoon the record matters.

### `auto_issue_invoice` was already there, defaulting the wrong way

`tenants.auto_issue_invoice` exists and defaults **false**; MYD-3.2 and ADR-0003
both say auto-issue defaults **on**. Rather than flip a default that already sits
in every seeded tenant inside an additive migration — which is how a test that
has passed for months starts failing for a reason nobody can find — the pair the
requirement actually names was added (`invoice_auto_issue`,
`invoice_auto_issue_delay_minutes`) and the old column is marked superseded in
`docs/data-model.md`. It should be dropped in a migration of its own.

### Verified

```
vendor/bin/pest tests/Feature/Compliance/     32 passed
vendor/bin/pest --parallel --processes=12     2621 passed
vendor/bin/pint --test                        passed
vendor/bin/phpstan analyse                    [OK] No errors
```

The one failure is the MySQL schema snapshot — and this time with a real cause
rather than only the billing block: **four new migrations move its hash.** CI is
the only place with MySQL 8 and has been unable to start a job since 2026-09-06.

### Still owed by a person, not by code

- **The VAT rates themselves** (MYD-6a). The mechanism is complete and the
  numbers are an accountant's answer. No code, seeder or fixture presents a
  percentage as authoritative, and `NoHardcodedVatRateTest` enforces that.
- **The gap policy** (MYD-4.6). ADR-0022 was accepted on engineering grounds;
  whether *any* gap is acceptable in a Greek series is not an engineering
  question. If gaps are ruled out, **only the allocation instant moves** —
  after AADE returns a MARK rather than before the attempt — and nothing in
  `AllocateInvoiceNumber` assumes which side of that it is on.
- **AADE test credentials**, for the client itself.

---

## Giving a colleague a login — the screen that was never written

> **TEN-8** *"`owner` — everything including billing, API keys, gateway
> credentials, tenant deletion."* And `Capability::ManageStaff`, which has
> existed since M0 with no way to exercise it.

### The gap was invisible because a missing screen looks like one you have not needed

`UserPolicy`, `RoleAssignmentPolicy`, `Capability::ManageStaff`, the last-owner
guard on `RoleAssignment::deleting` and the Greek strings «Ομάδα», «Ανάθεση
ρόλου» and «Αφαίρεση ρόλου` were all written when the roles were, in M0. The
screen was not, and neither was a command.

So **from M0 until today an owner could not hand their skipper a login**. Every
role `docs/spec.md` describes was reachable only by editing the database. Nothing
failed, no test went red, and no requirement was violated — because no
requirement says a staff screen exists. The spec defines the roles and never
says how somebody gets one.

Found while writing the manual's chapter on the three roles: a chapter that
explains what each role may do has to begin by explaining how a person acquires
one, and there was no answer to write down.

### Password reset did not exist either, on either panel

`filament.app.auth` had exactly two routes: login and logout. An operator who
forgot their password had **no way back in** — the only recovery was somebody
editing the database, which is the same answer as the staff gap and for the same
reason: the flow was never asked for out loud.

`->passwordReset()` on both panels. It is a prerequisite here rather than a
bonus: the invitation is a reset link, and a link that 404s is worse than no
invitation because the owner believes they sent one.

### The inviter never chooses a password, and that is the design

The account is created with a 64-character random string nobody ever sees, and
the colleague sets their own through the reset flow. The alternative — an owner
typing a password into a form and reading it down the telephone — puts a working
credential into a chat window, a notebook and the memory of somebody who will
not always work here, and leaves it usable by them afterwards.

**The link is a password-reset token, deliberately, rather than a bespoke
invitation token.** Laravel's tokens are already hashed at rest, single-use,
expiring on their own schedule and invalidated the moment they are spent. A
second token type would be a second chance to get all four wrong, on the one
link that opens an operator's whole business.

`InviteStaffMember` commits the user and their roles in one transaction — a
colleague with a login and no role signs in and sees nothing, which reads as a
broken product rather than an incomplete invitation — and sends the mail
**after** the commit, because a mail server that is briefly down must not roll
back an account that was created correctly. A colleague who never received the
message can be sent it again; one whose account vanished cannot.

### `User` has no `BelongsToTenant`, so this screen scopes itself

A person can hold roles at two operators — the same skipper working for two
companies is ordinary here — so `User` is deliberately outside the global scope
that protects every other resource in this panel (data-model §2.1). The
`where('tenant_id', …)` on `StaffResource::getEloquentQuery()` is therefore not
belt-and-braces: without it this screen lists every account on the platform. It
is asserted from both sides.

### Four decisions worth stating

**The email address cannot be edited.** It is the login and it is where the
reset link goes, so changing somebody else's is not an edit — it is a handover of
their account to a different inbox.

**The status column reads `email_verified_at`.** An invitation that was never
opened looks identical to a working account on every other column, and the
operator finds out on the morning the person cannot sign in.

**"Send again" is offered only to somebody who has not been in.** A "choose your
password" link arriving at a colleague who already has one reads as a security
incident.

**Nobody gets a button to delete themselves.** `UserPolicy::delete()` refuses it
and Filament therefore does not render the action; deleting your own account is
a support conversation, not a control beside your own name in a list.

### The last-owner guard is reached rather than reimplemented

Editing roles deletes and re-inserts rather than diffing, because the guard lives
on `RoleAssignment::deleting`. A diff that removed the final owner role would
have to know it was about to — and two implementations of "is this the last
owner" is one too many. The swap is one transaction, so a refusal leaves the
person with the roles they had rather than with none, and the thrown
`LastOwnerException` becomes a sentence rather than a five hundred.

### Not built, and named

No two-factor (ADR-0024 is still Proposed, SEC-15 wants it for operators and
requires it for super-admins). No self-service profile page — a colleague can
change their own password through the reset flow and nothing else. Both are
larger than this gap and neither is what stopped an owner hiring anybody.

### Verified

```
vendor/bin/pest tests/Feature/Panel/StaffInvitationTest.php    9 passed
vendor/bin/pest --parallel --processes=12                      2575 passed
vendor/bin/pint --test                                         passed
vendor/bin/phpstan analyse                                     [OK] No errors
```

And in the browser at `/app/staff`: the three demo staff with their roles as
badges, «Διαγραφή» correctly absent on the signed-in owner's own row, and the
invitation modal showing each role with its description.

---

## TEN-8, on every screen rather than one — and a name leaving the building

> **TEN-8** *"`crew` — read-only access to **departures within a configurable
> window** (default today and tomorrow), the pax list, check-in actions and
> manifest view; **no pricing, no financials**, no guest documents beyond what
> the manifest shows."*

### The manual is what found all four

The operator manual needed a chapter called «Ποιος βλέπει τι» with a table: one
row per screen, one column per role, one answer per cell. Writing that table
against the code rather than against TEN-8 is what surfaced these, because the
table could not be filled in — three screens gave two different answers to the
same question, and a fourth answered one nobody had asked.

That is worth recording on its own. A requirement written once and implemented
per-screen has no single place to be wrong, so nothing is ever *found* wrong; it
takes an artefact that has to state the rule in one place to expose that the code
never did.

### 1. The dashboard showed a skipper the operator's takings

`OperationsOverview::canView()` gated on a resolved tenant and on
`FirstSteps::applies()` — **no capability check of any kind**. Two of its six
figures are money: outstanding balances and the week's revenue. The dashboard is
the panel's landing page and crew reach it, so a crew member signing in to see
today's sailings was shown the operator's finances first, every time.

The four operational figures stay — departures, at risk, pending guest details,
unanswered quotes. None is pricing and all four are things the person on the boat
has reason to know. The two money figures are **appended** after a
`ViewFinancials` check rather than filtered out of a list of six, so a figure that
needs financial access cannot be added later without landing behind the gate.

### 2. The bookings list was the whole history

`DepartureResource::getEloquentQuery()` applied the window correctly. Nothing
else did. `BookingResource::getEloquentQuery()` was
`parent::getEloquentQuery()->with(['product'])`, and `BookingPolicy` opens the
screen on `ViewPaxList`, which crew hold — so one click from a correctly-narrowed
departures list sat **every booking the tenant had ever taken**, each with a
name, an email address and a telephone number. Money columns were correctly
absent, which is exactly what makes this the kind of hole nobody notices: the
screen looks restricted.

### 3. The calendar paged back a season

`Calendar::canAccess()` requires `ViewDepartures` and nothing gated the dates.
`shiftDays()` moved freely, and each day carries a pax action. Clamped rather
than refused — an arrow that does nothing at the edge is how every date control
behaves, and an error would suggest the crew member had done something wrong.

### The fix is one class, and that is the actual repair

`app/Support/Authorization/CrewWindow.php` holds the dates, the config key, the
tenant-timezone "today" and the two query scopes. `DepartureResource` now calls
it rather than owning it.

The bug was never the missing `whereDate` on two screens. It was that a
cross-cutting rule lived inside whichever screen was written first, so each
later screen had to remember it — and remembering is not a mechanism.

`scopeBookings` uses `whereHas` rather than a join, because a booking whose
departure has been deleted must not pass the filter by having no date to test,
and a `leftJoin` would let precisely that through.

### 4. Every panel page sent a staff member's name to ui-avatars.com

Found while collecting the manual's screenshots: a broken image in the top-right
corner of all thirty-eight of them. Filament's default avatar provider is
`UiAvatarsProvider`, which builds `https://ui-avatars.com/api/?name=…`, and no
panel had replaced it. So both panels were sending the signed-in person's name to
a third party on every page load, with a `Referer`, under no agreement.

The request is already refused here, which is why it rendered broken — and that
was the tell. It is not refused everywhere.

`InitialsAvatarProvider` draws two letters on a hull-teal disc as an inline SVG
`data:` URI. No route, no storage, no `storage:link`, no network. The initials
are decomposed and stripped of combining marks, because «Άννα» upper-cases to «Ά»
and a standing Greek initial drops its tone mark — the same pass gives «É» as
«E», which is what a set of initials wants for a Latin name too.

### One test had to be corrected rather than kept

`RecordPaymentActionTest > it gives crew no way to record money` began failing
with a 404: its booking sat on a departure outside the window, so a crew member
could no longer resolve the record at all. The test would have passed while
proving nothing about the button. Its booking now sits on **today's** departure —
one a crew member can open, with a balance owing, and no way to settle it. The
row filter and the action gate are two guarantees and that file owns the second.

### Still open, and named rather than quietly fixed

- **`ManageStaff` has no surface.** `UserPolicy`, `RoleAssignmentPolicy` and the
  Greek strings (`Ομάδα`, `Ανάθεση ρόλου`, `Αφαίρεση ρόλου`) all exist; there is
  no screen and no command. **An owner cannot give their skipper a login through
  the product.** Raised with the product owner rather than built inside a
  security fix.
- **Webhook endpoints sit behind `ManageApiKeys`**, so a manager cannot reach
  them. TEN-8 withholds billing, API keys and gateway credentials from a manager;
  a webhook endpoint is none of those three. Defensible by association, narrower
  than the requirement. Left as it is and recorded.
- **Guest email and telephone are visible to crew** on a booking they can open.
  Inside TEN-8's wording, and now inside the window as well, but worth an
  operator knowing — so it is in the manual rather than only here.

### Verified

```
vendor/bin/pest tests/Feature/Panel/CrewWindowTest.php        8 passed
vendor/bin/pest tests/Feature/Panel/InitialsAvatarTest.php    6 passed
vendor/bin/pest --parallel --processes=12                     2565+ passed
vendor/bin/pint --test                                        passed
vendor/bin/phpstan analyse                                    [OK] No errors
```

The one failure is the known MySQL schema snapshot.

---

## #129 — The back office on a phone, proven rather than asserted

> **OPS-22** *"The operator panel is usable on a phone: the dashboard, today's
> departures, check-in and manual booking all work at a phone viewport."*

### Nothing had ever loaded the panel in a browser

`playwright.config.ts` had two projects, `smoke` and `full`, both `Desktop
Chrome`, no viewport override anywhere, and **not one spec authenticated**. Every
existing spec drives the widget on a fixture host. So OPS-22 was an assertion in
a document, and the four surfaces it names had been looked at on a phone by
nobody.

A third project `mobile` at 390×844 with `isMobile`, `hasTouch` and
`deviceScaleFactor: 3`, selecting `backoffice.*.spec.ts`. The viewport is spelled
out on top of `Desktop Chrome` rather than taken from `devices['iPhone 12']`,
which pins WebKit — `npm run e2e:install` installs chromium only, so that
descriptor would fail on a missing browser instead of on a layout bug. `full`
gained the same pattern in `testIgnore`, or every one of these runs a second time
at desktop width where the assertions are trivially true.

Credentials come from `E2eSeedCommand`, which now writes a `panel` block into
`.state.json` looked up **by role** rather than by email. `User` deliberately has
no `BelongsToTenant` (a person can hold roles in two tenants), so the lookup
filters `tenant_id` explicitly — without it, `forTenant` alone would happily
return Ionian Sunset's owner.

### The suite went from thirteen minutes to twenty seconds, by not signing in thirteen times

Filament rate-limits the login form to five attempts a minute, correctly, and
fourteen specs each filling the form trip it on the sixth. The failure reads as
*"the panel would not let me in"* — alarming and untrue. Nobody signs in fourteen
times before breakfast; the repetition is an artefact of test isolation, so that
is what was removed rather than the protection. `signIn` mints one session per
role and lends the cookie to each fresh context. Every test still gets its own
storage and its own viewport.

### Two real defects, both invisible from a desktop

**The drawer opened over the page on a phone's first visit.** Filament seeds its
sidebar store `$persist(!0)` and never consults the viewport — verifiable in
`vendor/filament/filament/dist/index.js`. Above `lg` that is right: the sidebar
*is* the left column. Below it, the same `true` means 320px of drawer and a dark
backdrop over a 390px screen, with nothing tappable underneath. It then persists
`false` and never appears again, which is exactly why a developer's second look
never sees it and why a first-visit spec is what found it.

Fixed in `resources/views/filament/sidebar-first-visit.blade.php` on `HEAD_END`,
before `@filamentScripts`, seeding the same key `false` when the screen is narrow
**and nothing has been stored yet**. Only the first visit — an operator who
opened the drawer keeps it open. Safe at desktop width because Filament's closed
state still carries `lg:translate-x-0`.

**Row actions measured 20px.** «Κατάσταση επιβατών» and «Επεξεργασία» are links,
so their height is their line box. WCAG 2.5.8 (AA) asks 24×24; Apple asks 44pt
and Android 48dp, and those are the numbers written by people watching somebody
use a phone one-handed on a moving deck. 44px, below `lg` only —
`resources/views/filament/touch-targets.blade.php`. The same rule at desktop
width would push thirty departure rows apart for nobody's benefit.

Injected through render hooks rather than a compiled Filament theme: a theme is a
second Vite entry point and its own build step for the panel, which is a lot of
pipeline to carry seven declarations. If the panel ever grows one, both move into
it unchanged.

### And a third, of the same shape, found while checking the first in a browser

**«Ρυθμίσεις» was configured collapsed and was open.** `AppPanelProvider` has
carried `->collapsed()` on the settings group since the navigation was
reorganised; `components/sidebar/index.blade.php` writes the panel's collapsed
groups into `localStorage` **only when the key is null**. Every browser that had
opened the panel before that line existed already held `[]`, so the setting was
read once, found to be a decision already made, and the group stayed open for
ever — including in the product owner's own browser, where it was asked for.

Cleared **once**, against a `kaiki:nav-groups` marker, on the same load that
Filament reseeds from the panel config. Clearing on every load would be worse
than the bug: it would throw away an operator's own collapse each time they
opened a page. A later change to which groups start collapsed takes a new marker.

Three defects, one shape: a navigation preference persisted to `localStorage` and
thereafter trusted over the server's own default. Worth knowing before the next
one is added.

### The assertion states the standard, not what currently passes

`expectTappable` was briefly written to assert only *a box*, with the 20px
recorded in a comment, on the reasoning that a failing suite should not litigate
a design decision. That is the wrong trade when the fix is seven lines of CSS: a
floor lowered to fit looks like a checked guarantee and is none. It asserts
24×24, and the CSS clears it.

### What passes cleanly, and one thing worth knowing

The dashboard, the departures list and the manual booking form **all** pass the
horizontal-scroll assertion at 390px. The fleet strip (429px of content in a
310px box) and the departures table (1478px in 358px) both correctly hand
overflow to their own scrollers rather than to the document — which is the
failure that makes a back office unusable rather than merely tight.

Not a defect but not obvious: the departures list has **no date filter**. Its
default `starts_at_utc` ascending sort happens to put today first with the
current seed, so an operator arriving from the "sailing today and tomorrow"
figure gets the whole table, not today's. Recorded in the BUILD-LOG's open table
rather than fixed here — it is a change to a screen, not to this issue.

### Verified

```
npx playwright test --project=mobile     14 passed (20.7s)
vendor/bin/pint --test                   passed
vendor/bin/phpstan analyse               [OK] No errors
```

The 14th is the first-visit spec, which is red without the fix.

---

## #128 — Boarding on a quay with no signal

> **OPS-12** *"The check-in page tolerates an intermittent connection: scans
> queue locally and sync when connectivity returns, and scanning an
> already-checked-in ticket reports that clearly instead of failing."*

### Half of it was already true, and finding that out was most of the work

`CheckInGuest` is **already idempotent**: both writes are conditional updates
(`whereNull('checked_in_at')`, `where('status', confirmed)`) and it returns
`false` when another scan won. It was written that way in #88 for two crew on
two phones, and a sync-on-reconnect queue needs exactly the same property — a
scan that reaches the server twice must be safe.

So OPS-12's second clause was already satisfied, and the server side needed **no
new domain logic at all**. What was missing was entirely client-side, and one
endpoint to talk to.

### The Filament page cannot do this, and that is not a defect in it

`CheckIn` is Livewire. Its scan box is `wire:model.live.debounce.300ms` and
every action is a round trip, so with no signal it does not even echo the code
somebody just scanned. Livewire is a server-rendered model; this is the one
requirement in the product where the server may be unreachable.

OOS-6 forbids a native app, so "works without a signal" has to mean **a web page
that has already been loaded**. Hence a second surface: plain Blade, its own
JavaScript, a service worker scoped to its own path, an IndexedDB queue. The
Filament page stays for everything else — the searchable list, the BKG-22
override with a reason, the no-show marking — and both write through the same
Action.

### Four decisions that are the whole design

**The manifest is embedded, not fetched.** Everything needed to recognise a
ticket and show a name is in the bytes that loaded the page. A page that fetched
its manifest on load would be a page that works only when it does not need to.

**The server decides, not the phone.** A queued scan is a *claim*. The phone
shows an optimistic tick from its cached manifest; the server's answer replaces
it when the batch syncs. Two crew on two phones is the case that matters, and
only one of them boarded anybody.

**The service worker is served from `/app/boarding/sw.js`**, because a worker's
scope is the directory it comes from. There it can only ever control
`/app/boarding/…`. At the origin root it would cache authenticated panel HTML,
and a phone handed on after somebody signed out would still render the last
operator's screens. It is network-first, not cache-first: the page carries the
day's manifest, so a cached copy from yesterday is a boarding list for
yesterday's boat.

**Scans sync as a batch, and the batch never fails as a unit.** A phone that
regains signal after twenty minutes has a queue; twenty racing requests would be
twenty chances to hit a rate limit at the moment the crew most need it. Each
scan is answered on its own — one unknown ticket among nineteen good ones must
not lose the nineteen.

### The QR moved, and every printed ticket still works

`TicketQr` now points at `/app/boarding`. The old `/app/check-in?ticket=…` is
untouched and still checks people in, because **a QR on a sheet of paper in
somebody's bag cannot be reissued**. `ETicketTest`'s assertion was updated
rather than deleted, and `OfflineBoardingTest` asserts the old URL still answers.

### The manifest carries a name and a seat and nothing else

TEN-8 gives crew `ViewPaxList` and `CheckInGuests` and explicitly not pricing,
financials or documents — and this payload sits in a phone's cache on a boat,
which is the worst place in the product for a passport number to be. There is no
case for one in the payload, so there is no mechanism that could include one,
and the test asserts it against the response bytes the way `ExportRows` does.

### Five things the browser found that the suite could not

1. **`refused: this trip has already finished`** — the fixture moved
   `starts_at_utc` and not `ends_at_utc`. The check-in window closes on the
   latter, so a booking starting in fifteen minutes and finishing in July is
   correctly refused. A test bug, and the window working.
2. **The manifest escaped Greek to `Μα…`** — `@json()` does by
   default, which doubled the size of the one payload downloaded on one bar of
   signal. `JSON_UNESCAPED_UNICODE`.
3. **Every operator role has `CheckInGuests`** — a manager boards people too. The
   negative case had to become somebody with no role in the tenant at all.
4. **The queue counter kept a stale number** behind `display: none`. Emptied now
   rather than merely hidden: a count behind a hidden element is a lie waiting
   for the next CSS change.
5. **«1 σαρώσεις»** — no singular form. Two strings now, chosen in the page.

### Verified

```
vendor/bin/pest tests/Feature/Operations/OfflineBoardingTest.php   10 passed
vendor/bin/pest --parallel --processes=12    2546 passed, 4 skipped, 1 failed
vendor/bin/pint --test                       passed
vendor/bin/phpstan analyse                   [OK] No errors
```

The one failure is the known MySQL schema snapshot.

And in the browser, end to end: the page listing three passengers with their
trip, time and reference; a scan showing «Επιβιβάστηκε · Ελένη Νικολάου» and
flipping her row; the queue reaching one and clearing; and **the database
confirming she was checked in server-side**, through the same Action the panel
uses.

The client half — the service worker, the offline queue surviving a lost
connection — is #129's Playwright run to prove, because a PHP test cannot turn
the network off.

---

## #127 — One feed for every failure, and a navigation that stopped burying the work

OPS-21: *"Every operator-visible failure (payment, myDATA, SMS, iCal, webhook,
PDF) appears in one consolidated error feed with a plain-Greek explanation, the
affected booking, and a retry action."* NFR-8 says the same thing from the other
end: every external call is *"retried, and failures are visible in the operator
panel with human-readable Greek messages."*

This is the issue the milestone plan said could only be built last, because it
consolidates failures the earlier issues create — and #125's webhook deliveries
are one of the six sources.

### It is a view over six tables, and deliberately not a seventh

The tempting build is an `operator_failures` table that everything writes into.
One query, one model, a normal Filament Resource. It was rejected.

Each source already records its own failure, with its own retry semantics and
its own idea of what "resolved" means. A copy in a seventh table drifts the
first time one of them is updated and the other is not — and then an operator
has **two records of the same event disagreeing about whether it is still a
problem**, which is worse than not having the feed.

So `FailureFeed` merges: notification logs, failed payments, unprocessable
gateway webhooks, broken iCal sources, failed exports, and undelivered outbound
webhooks. Six queries and a sort in PHP, bounded at fifty rows per source over
thirty days.

**The cap is per source rather than overall**, which matters more than it looks:
a hundred failed webhooks must not push the one refused payment off the screen.

### Three of the six have a retry, and each absence is a decision

`FailureSource::isRetryable()` is where this lives, and the three that cannot be
retried are the interesting half:

- a **payment** was refused by a bank, and the retry belongs to the guest and
  their own card — a button here would promise the operator something they
  cannot do on somebody else's behalf, and they would believe the guest had been
  charged again;
- a **gateway webhook** we could not process needs a person to read it. PAY-7
  deliberately keeps an orphan visible rather than retried, because somebody has
  been charged and the money cannot be matched;
- an **iCal source** is already retried every fifteen minutes by the poller, so
  a button would promise what the schedule is doing anyway.

A single generic "retry" would also have got the webhook one wrong in the way
that costs money: a resend **reuses the same `event_id`**, so a receiver that
already had the event ignores the repeat. A fresh id would defeat the consumer's
own deduplication, which is the mechanism `docs/api.md` §8.4 tells them to rely
on.

### The boundary with «Χρειάζονται προσοχή», settled

`AttentionItems` has said since #130 that it is a **decision** list and that
OPS-21's feed is for *"things the system tried and could not do"*. The overlap
the plan flagged was iCal failures, which appeared in both.

Resolved by **keeping both, deliberately**. The dashboard row says a boat may
look free while somebody else has sold it — the most expensive silence in the
product, and a decision. The feed row says *why*, with the provider's own error
and the failure count. Two different reactions to the same fact, one click
apart.

### Small things that are the whole difference between a feed and a wall

- **A broken calendar has no date filter**, unlike the other five. It is a
  *state*, not an event — it has been failing every quarter of an hour since it
  broke — and one dead for six weeks is more urgent than one that broke this
  morning, not less. A thirty-day cut would hide exactly the worst case.
- **Recipients are shortened.** `maria.papadopoulou@example.gr` becomes
  `mar…@example.gr`; a telephone number becomes its last four. Enough to
  recognise which guest, and not a screen somebody can photograph to collect
  addresses. The whole value stays on the notification log, behind its own
  permission.
- **The explanation is never the code.** The provider's own string sits
  underneath in monospace for whoever is about to paste it into a support
  ticket; the sentence beside it says what it means. Asserted for a code nobody
  has written a sentence for yet, because that is the case a fallback exists for.
- **The navigation badge is cached for a minute.** A badge runs on *every* page
  of the panel, and uncached this would have put six queries behind every click
  an operator makes — against NFR-5's 1.5 seconds for the dashboard. The page
  itself is never cached; it polls, and it is the authority.

### Two absences, named rather than left as a gap

OPS-21 lists six kinds and four exist. **myDATA is M6** and has no model yet.
**PDF** failures — a Browsershot timeout generating an e-ticket — land in
`failed_jobs` with no operator-facing row, and giving them one is a change to
`GenerateETicket` rather than to this feed. Both are in `FailureSource`'s
docblock so the thin-looking list is explained where somebody will find it.

---

## Two changes the product owner asked for while this was being built

### No SMS in the first phase

*"δεν θα εχουμε sms σε πρωτη φαση".*

The guard is in `SendNotification::sms()` — the one door all three senders go
through — rather than at the three call sites, so a fourth cannot be written
without it. Nothing is logged when it declines, for the same reason a missing
telephone number is not logged: a row per booking saying we did not send a text
nobody was expecting would fill OPS-21's brand-new feed with a decision rather
than a fault.

**Everything underneath stays built and stays tested** — the templates, the
segment counting, NTF-5's warning, the gateway resolver, the per-tenant account.
The two tests that exercise the SMS path now switch it on explicitly rather than
being deleted, and a new test asserts the shipped default sends nothing. Turning
it on is `KAIKI_SMS_ENABLED=true`, not a rebuild.

### The navigation stopped putting plumbing first

*"ολα αυτα τα μηνηματα, εξαγωγες κλπ δεν θελω να φαινονται πρωτα… και το
dropdown των ρυθμισεων να ειναι by default κλειστό".*

Four screens moved from Λειτουργία to Ρυθμίσεις: the notification log, the
exports, this new failure feed, and the calendar sync. All four are things you
go *looking for* when something has happened or an accountant has asked.

Λειτουργία is now the six an operator opens every morning — boarding, the
calendar, bookings, quotes, vouchers, enquiries — and Ρυθμίσεις is collapsed by
default. Eleven items you rarely need were burying six you always do, and the
clicks to reach a rare screen cost far less than scanning past it daily.

**One thing worth recording about the collapse:** Filament keeps group state in
the browser's `localStorage`, so `->collapsed()` is the default for somebody who
has never opened the panel and is overridden for everybody who has. Verified by
clearing the key and reloading; a fresh operator gets it closed.

### Verified

```
vendor/bin/pest tests/Feature/Operations/FailureFeedTest.php   11 passed
vendor/bin/pest tests/Feature/Notifications/                   58 passed
vendor/bin/pest                          2535 passed, 4 skipped, 1 failed
vendor/bin/pint --test                   passed
vendor/bin/phpstan analyse                [OK] No errors
```

The one failure is the known MySQL schema snapshot.

And in the browser, against real broken things — a hard-bounced email, an SMS
with an untranslated provider code, an iCal source on seven consecutive
failures, an export that hit a disk error, and #125's own failed delivery: five
rows, each with its badge, its Greek explanation and the provider's words
underneath; the retry offered on three of them and absent from the calendar; and
pressing one produced «Μπήκε στην ουρά» and took the count from five to four.

---

## #126 — Vouchers in the panel, and the clock nobody had wound

OPS-16: *"Vouchers: issue, list, redeem (the widget accepts codes), expiry
reminders."* Three of those four already worked.

**What existed since M2:** the `vouchers` and `voucher_redemptions` tables,
`IssueVoucher`, `ApplyVoucher` and `RestoreVoucher` with PRC-18…22's arithmetic,
the pro-rata restoration, the guest page at `/v/{code}`, and the widget
accepting a code at checkout. A weather cancellation has been producing
vouchers, correctly, for two milestones.

**What did not exist was any way to look at one.** No list, no search by code,
no way to write one out by hand. `VoucherReason::Goodwill` and `::Manual` have
been in the enum since M2 **with no caller at all** — an operator who wanted to
apologise for a bad afternoon had no way to do it.

And nothing was time-aware. A voucher stayed `active` in the database for ever
after it expired, and the two reminder templates had Greek copy, an English
copy, a `expiry_reminder_sent_at` column, and no code.

### Neither gap could sell a boat trip wrongly, and that is the interesting part

`Voucher::isSpendable()` has always checked all three conditions, so an expired
voucher could never be redeemed. The cost of the missing sweeper is not a bad
booking — it is an operator reading `active` off a screen and telling somebody
on the telephone that their credit is still good, when the checkout will refuse
it. **The status column has to agree with the answer the guest gets**, and it
did not.

### The timezone is the whole of the sweeper

PRC-21: *"Voucher expiry is evaluated at end of day in the tenant timezone."*

One cross-tenant `where('expires_at', '<', now())` would have been a line, and
would expire an Aegean operator's vouchers **three hours before their own day
ended** — a guest told their credit expired on a date their calendar says has
not arrived. That is the kind of thing that reaches a consumer protection body
rather than a support inbox.

So the sweep is per tenant, and the boundary is the start of *that tenant's*
today. A voucher expiring today survives all of today, wherever the server is.
The test that proves it puts one tenant in Athens and one in London, sets the
clock to 22:30 UTC — already tomorrow in Greece, still today in England — and
asserts that only the Greek one expires.

It touches `active` rows only. `redeemed` is spent and `cancelled` was
withdrawn; writing `expired` over either would erase what actually happened.

### One column, two reminders

`expiry_reminder_sent_at` is a single timestamp, and it is enough. The seven-day
reminder is due when the last one was sent **before the seven-day window
opened** — so a voucher issued ten days before it expires gets the seven-day
message and never the thirty-day one, which is right: a warning about a month
that has already passed is noise.

**A voucher with no booking cannot be reminded**, and that is a silence with a
reason rather than a gap. A voucher has no email column; the address comes from
the booking it was issued against. A goodwill voucher handed over the counter
has no booking, so there is nowhere to send anything — and inventing a contact
field so the platform could email a stranger would be collecting personal data
for a message nobody asked for. The operator who handed it over is the one who
knows how to reach them.

Two more silences: nothing is sent about a voucher with a zero balance (it makes
somebody check, find nothing, and trust the next message less), and nothing
about a test booking's voucher (SAA-12).

One thing that had to be got right and is easy to miss: the send uses
`once: false`. `NotificationLog::alreadySent()` dedupes per **booking**, and a
guest whose trip was cancelled twice has two vouchers — the second would never
be mentioned. The dedupe that matters here is per voucher, and it is the column.

### `goodwill()` is a separate method, not a nullable argument

`IssueVoucher::__invoke()` reads a voucher's validity from the **booking's
frozen policy snapshot** — the terms the guest accepted when they paid, which is
the only defensible source for a credit issued because that booking was
cancelled. Its docblock says so, and CXL-8 is why.

A goodwill voucher has no such booking and therefore no such promise. Passing a
nullable booking into that method would have made its one important sentence
untrue half the time. So `goodwill()` sits beside it, takes its default from
configuration, and **allows a null expiry** — "whenever you like" is a real
answer an operator gives, and `hasExpired()` is false for a null.

### The screen is read-mostly, deliberately

There is no edit form. A voucher's amount, code and expiry are terms somebody
was given, often in writing, in an email they still have; a screen that let an
operator quietly change the number would make every one of those emails a
liability. Two things can be done: read it, and cancel it — which is a status
rather than a deletion, because the record of a promise has to outlive the
operator changing their mind.

The remaining balance is not editable either. It is `Σ(amount − reversed)` over
the ledger, and the ledger is shown directly beneath it so an operator asked
*"why does it say forty euros when I gave them a hundred"* can read the answer
rather than be told it.

The filter that earns its place is «Μπορεί να χρησιμοποιηθεί τώρα» — three
conditions the status alone does not answer, and the exact question somebody on
the telephone is asking.

### Deviations

- **Only two reasons are offered on the form.** The other three are written by
  the cancellation machinery and mean something specific about how a booking
  ended; offering them here would let a goodwill credit claim to be a weather
  cancellation in every report that groups by reason.
- **Euros in, cents stored, and rounded rather than cast.** `(int) (12.10 * 100)`
  is 1209 on a binary float, and a voucher one cent short of what the operator
  typed is a discrepancy nobody can explain.
- **The chosen expiry date is stored as end of day.** Midnight would expire the
  voucher a day before the date printed on the guest's email.
- **The view page is titled with the code**, not Filament's
  «Προεπισκόπηση Κουπόνι» — awkward Greek, and the code is what the operator is
  about to read down a telephone.

### Verified

```
vendor/bin/pest tests/Feature/Vouchers/   15 passed (32 assertions)
vendor/bin/pest                           2523 passed, 4 skipped, 1 failed
vendor/bin/pint --test                    passed
vendor/bin/phpstan analyse                [OK] No errors
```

All fifteen passed on the first run, which is worth recording because it is
unusual in this log — the arithmetic they sit on was already right and already
tested.

The one failure is the known MySQL schema snapshot.

And in the browser: «Κουπόνια» under Λειτουργία with three seeded vouchers —
a goodwill one with «Δεν λήγει», a manual one, and a weather cancellation
showing «Για την κράτηση KAI-TSXXB» through the foreign-key-less lookup — and
the ledger panel below reading «Δεν έχει χρησιμοποιηθεί ακόμη».

---

## #125 — Outbound webhooks, signed, retried, and visible

OPS-19 names four events. OPS-20 is the hard half: HMAC signatures, a timestamp
and an event id, exponential backoff for twenty-four hours, a delivery history
in the panel, and no guest document number ever.

**Nothing existed.** No `app/Domain/Webhooks/`, no models, no migrations, no
panel screen — the inbound Viva receiver was the only webhook machinery in the
product, and it solves the opposite problem. What *did* exist was a complete
specification: `docs/data-model.md` §3.13 gives both tables column by column as
items 41 and 42, and `docs/api.md` §8 gives the envelope, the signature scheme
and the retry schedule. So this was build-to-spec rather than design, and the
interesting decisions are the ones the spec left to the implementation.

### The unique index is the delivery guarantee, not an optimisation

`(webhook_endpoint_id, event_id)`. A queued job that runs twice — which happens
whenever a worker dies between the HTTP call and the ack — must not fire a
second POST at somebody's accounting system. The database refuses the row, the
duplicate job finds the delivery already recorded, and stops.

That is the same construction the booking API's idempotency key uses, for the
same reason: at-least-once is what a queue offers, and exactly-once is something
you build on top of it.

### The payload is written once and never rebuilt

A retry six hours later re-sends the stored bytes. Rebuilding would let a
booking cancelled in the meantime turn a `booking.confirmed` into a POST
describing a cancelled booking — the retries would disagree with each other and
with the event they claim to be. An event says what was true when it happened;
the next event says the rest.

### Three rules that are each a real leak, and none of them is obvious

**BKG-34 — an imported booking fires nothing.** Importing four seasons of
WooCommerce history must not post four seasons of `booking.confirmed` at
somebody's accounting system.

**SAA-12 — a test booking *is* sent, flagged.** This is the one place a test
booking is allowed out, and the asymmetry is deliberate: SAA-12 keeps them out
of every figure and every export, but an operator wiring up their integration
needs the event to arrive. `is_test` rides along and §8.2 tells consumers to
branch on it.

**No document numbers, ever.** `guest_details.completed` reports *that* the
manifest is complete and how many rows. The payload has no case for a document
number, so there is no mechanism that could include one — and it is asserted
from outside, the way `ExportRows` is: a real number in the database and a
search of the finished JSON bytes for it.

The other two removals are `manage_token` and `links`. Both are the **guest's**
— the URLs that let whoever holds them cancel the booking — and neither is the
integrator's to hold.

### The retry schedule is ours, not the queue's

Laravel's `$tries` and `backoff()` would work and are wrong here twice over. The
schedule is **published** — an integrator reads §8.4's eight intervals and sizes
their own retention against them — so it has to live somewhere a person can
read, which is `WebhookDelivery::BACKOFF_SECONDS`. And the panel has to *show*
where a delivery has got to: which attempt, what the receiver said, when the
next one is due. A queue's internal retry state answers none of that.

So the job records the attempt, computes `next_attempt_at`, and re-dispatches
itself with a delay. The cost is that a pending attempt then lives in two places
— the row and a delayed job — and only one of those survives a flushed queue or
a deploy in the middle of the twelve-hour gap. **`SweepWebhookRetriesJob` is
what makes the row the source of truth**, every minute, and a duplicate job it
causes is harmless because `DeliverWebhook` returns immediately for a delivery
that is no longer pending.

### `failed` and `abandoned` are different states

`failed` is *the attempts ran out* — re-sendable by hand, and OPS-21's feed will
want it. `abandoned` is *the endpoint was switched off or deleted while this was
queued* — nothing failed, there is nowhere to send it, and a retry button on it
would be a button that cannot work. Collapsing them would put an outage in the
failure feed every time an operator turned an integration off.

### The SSRF guard, and why it runs twice

An operator types a URL and the platform fetches it, from inside its own
network. `https://169.254.169.254/` is the cloud metadata service and returns
the instance's credentials; `127.0.0.1` and `10.0.0.0/8` are everything else. A
webhook feature is the friendliest possible way to hand somebody a server-side
request forgery, because the product asks for a URL and promises to call it.

Addresses are checked, not strings — `localtest.me` is a public DNS name that
resolves to `127.0.0.1`, so a guard that read the hostname would refuse the
honest spelling and admit the dishonest one. The form check skips DNS because a
form must answer while somebody is typing; **the check that matters runs inside
the delivery job, immediately before the socket**, where a hostname repointed
since save time is caught.

### Three bugs found while building it, two of them by the same mistake

**The payload was built outside tenancy.** `BookingResource` reads tenant-owned
relations — the voucher ledger among them — so building the payload before
entering the tenant threw `TenantContextMissingException` from a listener. That
is TEN-4 working exactly as designed and this code getting it wrong. Fixed in
`DispatchWebhookEvent::forBooking()`, and then **found again** in the listener's
`guest_details` branch, which does not go through that method because its
payload carries a count the booking does not know.

**An IPv6 literal kept its brackets.** `parse_url` returns the host of
`https://[::1]/x` as `[::1]`, which `filter_var` does not recognise as an
address — so loopback fell through to the hostname branch and was treated as a
name that merely failed to resolve. Caught by the test, not by review.

**The panel rendered raw lang keys**, and only the browser showed it. Every
event name contains a dot, and Laravel reads a dot in a translation key as a
path separator: `__('webhooks.events.booking.confirmed')` searches for
`webhooks → events → booking → confirmed`, finds nothing, and returns the key.
The form showed `webhooks.events.booking.confirmed` under each checkbox.
`HasTranslatedLabel::line()` carries a note about this exact trap because it
shipped once before in #6 — **this is the second time it has been walked into**,
and the fix is the same: fetch the block and index it.

### Deviations and deliberate omissions

- **`PublishDomainEvent` is not queued**, and it is the only listener in the
  application that is not. Its whole job is local — resolve, build, write a row,
  dispatch a job — and the HTTP call is already somebody else's job. Queueing it
  would buy nothing and cost correctness: `DepartureCancelled` carries a
  **model** rather than ids, unlike every other event in that directory, and a
  queued listener is constructed on a worker where a serialised model is
  re-fetched under whatever tenant the previous job left behind.
- **One listener, not four.** These four do the same thing four times; four
  classes would put the rule about which events are published in four places.
- **`guest_details.completed` fires on the transition, not the state.**
  `SaveGuestDetails::syncStatus()` runs on every save of the guest form, so a
  party of six filled in over three sittings would otherwise announce a finished
  manifest three times.
- **Plan gating is not wired**, per the product owner's decision today.
  SAA-3 gives webhooks to `Pro` and `Plan::allowsWebhooks()` already exists with
  no callers; the note naming it as the place is in the code.
- **Secret rotation publishes one signature, not two.** §8.3's comma-separated
  `v1=<new>,v1=<old>` for twenty-four hours is implemented in
  `WebhookSignature::header()` and `verify()`, and the panel's rotate action
  replaces the secret outright rather than keeping the old one alive. The
  machinery is there; the twenty-four-hour overlap needs a second column and is
  not built. **Recorded rather than silently skipped.**

### Verified

```
vendor/bin/pest tests/Feature/Webhooks/   35 passed (77 assertions)
vendor/bin/pest                           2508 passed, 4 skipped, 1 failed
vendor/bin/pint --test                    passed
vendor/bin/phpstan analyse                [OK] No errors
php artisan migrate                       both tables DONE
```

The one failure is `CiGatesTest > it keeps the committed schema snapshot in step
with the migrations`, and **this issue moved its hash again** — two new
migrations. It has been failing since 2026-09-06 for the reason in the Status
table: CI is the only place with a MySQL 8 connection, and CI is blocked on
GitHub billing. Not a new break.

And in the browser, because a green suite did not catch the lang-key bug: the
endpoint list under Ρυθμίσεις → Webhooks with its four event badges, the edit
screen with «Αλλαγή μυστικού κλειδιού», and «Ιστορικό αποστολών» showing a
delivered row (1/8, 200, no button) beside a failed one (8/8, 500, «Νέα
αποστολή») — the resend offered on exactly the row that can use it.

---

## Sixty bookings, so the panel has something to be about

Asked for directly: *"βαλε μου μεσα και δοκιμαστικες κρατησεις να δω τι παιζει.
βαλε μπολικες"*.

Every operations screen built in M5 reads **bookings**, not products — the
dashboard figures, the fleet strip, «Χρειάζονται προσοχή», the manifests, the
exports, the reconciliation, and «Καιρός»'s "5 αναχωρήσεις · 0 επιβάτες". With
an empty table all of them render their empty state, which is the one state
nobody needs to look at, and the arithmetic on two rows reads as arithmetic
while sixty rows read as a business.

### Through the real Action, never a factory

`CreateManualBooking`, which means `CreateBookingDraft`, which means
`ComputePrice` and `HoldSeats`. Every row has a real reference, a real VAT
split, a frozen cancellation policy and a `seats_sold` on its departure that
adds up. A factory would have been three lines and would have produced bookings
whose totals disagree with their own line items — worse than no demo data,
because the first thing anybody does with a figure on a dashboard is check it
against the row underneath.

The engine refusing a booking is not an error and is swallowed: a party that
does not fit what is left on the boat is the availability engine working, and a
full boat should be one fewer booking rather than a broken `db:seed`.

### Four payment states, because each one is a different screen

| State | What it makes visible |
|---|---|
| cash, settled | the takings figure |
| bank transfer, settled | the same figure through the other tender |
| deposit only | «Οφείλονται σε εσάς» and the balance-due reminders |
| nothing | an unpaid hold, which is what the expiring-holds row is about |

The deposit case needed one thing the engine deliberately does not do: **a part
payment does not confirm a booking**, and should not — `RecordManualPayment`
leaves that decision to whoever took the money. An operator with a deposit in
hand confirms the seat, so the seeder does the same. Without it these sat as
drafts and «Οφείλονται σε εσάς» stayed at zero however many deposits were on
the table, which is exactly what the first run of this seeder produced.

Roughly one in nine is cancelled, and not for realism: the refund figures, the
cancellation reasons on the reconciliation screen, and the "excluded from the
count" rules in the exports and in the weather panel are all invisible without
them.

### Deterministic, and spread across the season

No faker and no `rand()` — fifteen named guests in the mix a Greek day-boat
operator actually sees, and eight party shapes cycled, so the same run produces
the same numbers and a screenshot means something a week later. Names matter
more than they look: a manifest, an e-ticket and a passenger CSV all render
them, and a screen of "Test User 1" proves nothing about how the real thing
reads.

A fortnight back and a fortnight forward. The past half is what the
reconciliation screen and the completed-trip figures read; the future half is
what the calendar, the manifests and «Καιρός» read. Booking only future trips
would have left half the product looking broken.

### What it produced, and one thing it did not

**60 bookings for Aegean Blue: 37 confirmed, 16 unpaid holds, 7 cancelled, 42
payments, and 11 confirmed bookings carrying a balance of 1.866,90 €.** Ionian
Sunset stays empty on purpose — a trial account with nothing configured is a
real state the panel has to render, and if both demo operators were complete
nobody would ever see it.

**No `booking_guests` rows**, and that is correct rather than missing. Only one
demo product sets `guest_details_required`, and nothing in the ordinary booking
flow creates passenger rows for a product that does not ask for them — a day
trip takes a lead name and sails. This was checked rather than assumed, because
"the manifest is empty" would be a serious bug: `Manifest` emits **placeholder
rows, one per head**, and counts them as missing, which is what a quayside list
for an unfilled booking should look like.

Departures beyond about 2026-09-20 are skipped with *"there is no rate plan for
that date"*, which is the pricing engine correctly refusing to invent a price
outside the seeded season rather than a fault in this seeder.

### Verified

```
php artisan db:seed --class=DemoBookingSeeder
  aegean-blue: 60 bookings (7 of them cancelled).
  ionian-sunset: 0 bookings (0 of them cancelled).

vendor/bin/pest        2457 passed, 4 skipped, 1 failed (the schema snapshot)
vendor/bin/pint --test passed
vendor/bin/phpstan     [OK] No errors
```

And on `/app`: **18 αναχωρήσεις σήμερα και αύριο, 28 επιβάτες κλεισμένοι, 16 σε
κίνδυνο, 7.292,10 € εισπράξεις αυτής της εβδομάδας**, with the fleet strip
showing real load per boat (6/40, 5/12, 4/10) instead of a row of zeros.

---

## The widget on the hosted pages, which had never been loaded

Not an issue from the roadmap. Found by opening a hosted product page and
trying to book a trip on it, which is a thing nobody had done.

### What was wrong

`resources/views/hosted/product.blade.php` rendered the widget's mount point,
its data attributes and the no-JavaScript fallback inside it — and **no script
tag anywhere on the page**. The bundle was built, published and served; nothing
ever asked for it. Every guest who has ever reached a hosted product page got
"email us or ring us", and the hosted pages, which are the platform's own shop
window, could not sell anything.

`#111` did not catch it, and the reason matters more than the bug: its
seventeen-spec Playwright run drives a **fixture host page** that the test
writes itself. It proves the widget works. It proves nothing whatever about the
pages the platform serves, and that gap is exactly the size of this failure.

### Why it had been left, which was a real reason

The widget will not start without a `data-key` and authenticates every call
with it. An operator embedding on their own site pastes a publishable key they
copied once. A hosted page cannot: SEC-5 stores only a key's hash, prefix and
last four, so there was **nothing to render into the page**. #110's live preview
sidesteps the same problem with a fake `pk_preview` and a preview transport that
answers before a request is made, which works for showing somebody a colour and
cannot sell a boat trip.

Put to the product owner as two options — an ephemeral per-render token, or a
durable publishable key per operator with its plaintext stored. **The ephemeral
token was chosen.**

### `HostedEmbedToken`

A signed assertion, minted per response and written nowhere: the tenant's uuid
and an expiry, HMAC-SHA256 with `APP_KEY`, prefixed `hpk_` so the key parser can
never confuse it for a stored key and an operator cannot paste it into the
WordPress plugin and have it work.

It resolves to a **transient `ApiKey`** — a model that was never saved — which
is what made the integration a few lines rather than a second authentication
path. Scope checks, the secret-key-in-a-browser guard and the capability
middleware all go on reading an `ApiKey`. The one thing they must not do is
write, so the middleware skips `touchLastUsed()` for a key that does not exist.

Two hours. Long enough to leave a trip open in a tab over lunch, short enough
that a token lifted from a page source is worth almost nothing — and what it is
worth is precisely what a publishable key is worth, because it carries
`ApiKeyType::Publishable->allowedScopes()` and nothing else. It also dies the
moment `hosted_page_enabled` goes false, so switching a site off does not leave
working keys in every browser that had the page open.

### The bug that made it look broken anyway

Wiring the token into `AuthenticateApiKey` was not enough. **`ResolveTenant`
runs afterwards and re-derives the tenant from the raw header itself**, with its
own `pk_`/`sk_` three-part parse — so the request authenticated, then fell
through every resolver and died on `abort(404)` at the bottom of the chain.

The symptom is worth recording because it is so misleading: the widget loaded,
the key was accepted, and every call came back *"That endpoint does not exist.
Check the path and the API version."* — a message that sends you to look at
routes, which were fine. A credential is not wired in until **every** thing that
reads credentials knows about it.

### A second bug, found by looking at the result

With the widget finally drawing, it rendered its own Greek chrome around
**English** trip titles and port names. `ApiClient` never sent `Accept-Language`
— the bundle knew its locale, resolved it properly through all three of WGT-15's
steps, and never told the server, so every response came back in the operator's
default language whatever the page was in.

This is not a hosted-page bug. It has been wrong in every embed since the widget
existed, including the WordPress plugin, and it is invisible to an operator whose
default language is the one they are looking at. Fixed in the client, with the
declared locale now part of the client's cache key so two embeds on one page in
different languages cannot overwrite each other's header.

### Two tests changed rather than deleted

`ProductPageNoJsTest` and `ProductJsonLdTest` both asserted that *every*
`<script` on a product page is `application/ld+json`. The first had said in a
comment since M3 that "the widget's own tag arrives in #106 and mounts into the
node below rather than replacing this claim" — so this is the anticipated
update, not a weakened assertion. Both now count ld+json **plus the widget
bundle**, and both still prove what they were written for: no framework runtime,
no CSRF token, no hydration, and an operator's pasted `</script><script>` still
reaching the page as text.

### Verified

```
vendor/bin/pest tests/Feature/Hosted/HostedWidgetEmbedTest.php   9 passed
vendor/bin/pest tests/Feature/Hosted/                          108 passed
vendor/bin/pest                     2457 passed, 4 skipped, 1 failed (the schema snapshot)
npm -w packages/widget run test      78 passed (76 before)
vendor/bin/pint --test               passed
vendor/bin/phpstan analyse           [OK] No errors
```

And in the browser, which is the only verification that would have caught the
original bug: `/aegean-blue/olimeri-tria-nisia?lang=el` now renders the booking
widget — «Διαλέξτε ημερομηνία», a date field and «Συνέχεια» — with the trip
title, port and vessel in Greek, the no-JavaScript fallback correctly hidden,
and the meeting-point map beside it.

### Left open

The widget is on the **product** page only. The home page's trips block and the
search results are still server-rendered links, which is the right default —
but if a list or availability mount is wanted on either, the token is now there
for it.

---

## The "about us" block, which was built and invisible, and the control on it that did nothing

Asked for directly: *"on frontend, i need an about us section. title and text
left, image to the right. all these should be editable from inside dashboard"*.

It already existed. `HomeBlockType::Story` is documented in its own enum as
*"Heading and prose, with an optional image beside it. The 'about us'"*, and has
had a template, a CSS grid, an `image_side` control and a panel form with an
image upload since #102. Nothing needed building.

Two things were wrong with it, and both are the same kind of wrong: the feature
worked and nobody could see it.

### The demo home page had never been seeded

`grep` for `HomePageBlock` across `database/seeders/` returned nothing. Tenant
1's four blocks — hero, trips, faq, contact — were arranged by hand during #102
and existed **only in one development database**. A fresh `migrate:fresh --seed`
served `defaultLayout()` instead, and no story block appeared on any screen
anybody looked at, which is why the block read as unbuilt.

`DemoHomePageSeeder` now writes the demo page, story block included, and is
registered last in `DatabaseSeeder` because it references the products and FAQ
entries above it. It skips a tenant that already has blocks — overwriting a page
somebody arranged by hand is not a seeder's job — so the existing development
database was left alone and the block added to it separately.

`HomeBlockType::defaultLayout()` deliberately still does **not** include the
story. The default is what an operator gets before they have written anything,
and an empty "about us" heading on an untouched page is worse than no block.

### `image_side` had no effect, and shipped that way

The screenshot is what caught it: the request was image on the right, the
setting said `right`, and the image rendered on the **left**.

The `<img>` was first in the DOM. So the default put it on the left, and
`side-left`'s `order: -1` moved it to where it already was — both settings
rendered the identical page. A form field, a lang key in two locales, a stored
setting and a normaliser, all wired to nothing.

It survived two milestones because every other assertion about the block passes
either way: heading present, prose present, image present, class on the section.
Nobody had asserted the one thing the setting exists to change.

The fix puts the copy first in the DOM, which is also the better order — the
`<h2>` reaches a screen reader and a crawler before its own illustration, and a
narrow screen reads heading, prose, photograph. Image-right is then the default
and `side-left` is the rule that moves something.

`StoryBlockSideTest` asserts **order rather than presence**, against the markup
rather than the CSS, because DOM order is the thing a template can get wrong.

### Verified

```
vendor/bin/pest tests/Feature/Hosted/    108 passed
vendor/bin/pest                          2448 passed, 4 skipped, 1 failed (the schema snapshot)
vendor/bin/pint --test                   passed
vendor/bin/phpstan analyse               [OK] No errors
```

And in the browser at `/aegean-blue?lang=el`: «Ποιοι είμαστε» with its two
paragraphs on the left and the photograph on the right, above «Οι εκδρομές μας».

### Still open, and larger than this issue

The product owner has twice said the hosted **website** should be optional per
operator — some will want only the product page and the search, as WebHotelier
does — and that it likely belongs to a more expensive plan. Neither exists.
`hosted_page_enabled` (TEN-1, HOS-6) is all or nothing: there is no state where
the product pages are on and the marketing home page is off, and nothing
anywhere gates the hosted site by plan. Both need writing down before they are
built.

---

## #131 — The weather, joined to the sailings it threatens

OPS-6 asks for a wind forecast on the dashboard. Taken literally that is a
widget nobody needs: every operator on this coast already has three weather apps
and trusts the one their father used. The forecast is a commodity and Kaiki will
never be the best source of it.

What they do **not** have is the sentence *"Thursday and Friday are over
Νεφέλη's limit — three departures, twenty-seven passengers"*, and a link to the
screen that shows what each of those guests is owed. That join is the whole
feature. The numbers are the cheap half.

### It never cancels anything, and that is a decision, not an omission

ADR-0027. A product that cancelled a charter because an API said 7 Bft would
eventually cancel one on a day that turned out fine, and the operator would lose
the money *and* the customer *and* their trust in the panel. Worse, the failure
would be invisible: nobody files a complaint about the trip that did not happen.

So this produces a list and a button to #121's weather-cancellation preview,
where a person decides. The panel's own text says so in Greek —
«Τίποτε δεν ακυρώνεται αυτόματα» — because an operator seeing a red row on a
booking system has every reason to assume software already acted.

### Two silences, both deliberate

**A vessel with no `max_wind_bft` is absent entirely.** The column is nullable
with no default, and null means *do not tell me*. A default of 6 would have been
one line and would put a warning on somebody's dashboard about a boat they have
skippered for thirty years. `WindForecast::exceeds(null)` is false, always, so
the silence is enforced at the bottom rather than remembered at each call site.

**A vessel whose next four days are inside its limit is absent too.** "The
weather is fine" is not news, and a panel permanently on screen is one people
stop reading before the week it matters. On a calm week the widget does not
render at all.

### Not knowing is a different answer from calm

The one thing this feature must never do is report 0 Bft because a request timed
out, on the screen an operator uses to decide whether to sail. So a provider
failure returns `null` and the vessel is skipped — not a row of zeros, not a
"—", not a stale cache. `WeatherOutlookTest` asserts it directly: with the
provider returning null the outlook is `[]`.

For the same reason **a failure is not cached**. Caching it would let one bad
minute suppress Thursday's warning for three hours. The cost is a retry on the
next render, which is exactly what should happen.

### Units are pinned in the URL, not assumed in the parser

`wind_speed_unit=ms` is in the query string. Open-Meteo's default is km/h, and a
parser assuming m/s against that default reads 25 km/h as 25 m/s — force 4
reported as force 10, which cancels a season. The conversion is the WMO table of
upper bounds in `Beaufort`, tested at every boundary in both units.

Force is the **max of mean and gust**, not the mean. Gusts are what capsize a
tender and what a harbourmaster closes a port for; `drivenByGust()` exists so
the panel can say which of the two decided, because "5 gusting 8" and "8 all
day" are different days.

### Cached per port, never per vessel

A fleet in one marina shares a sky. The key is the port's coordinates rounded to
three decimals — about a hundred metres — so ten boats in Zea are one request,
and a coordinate edited by a metre does not orphan the cache. The dashboard
renders on every page load; this is the difference between one call every three
hours and one per widget per operator per minute.

### Deviations from the issue as written

- **Four days, not ten.** A meltemi is forecast reliably about that far out, and
  the decision — cancel now and give people notice, or wait — is made inside
  that window. Ten rows nobody trusts is worse than four they do.
- **`seats_sold`, not a guest-row count.** This is a headline figure. OPS-9's
  manifest head count, which counts infants, is the number that matters on a
  quay; the two disagreeing by one on a dashboard would be noise.
- **Cancelled departures are excluded** from the counts. They are already
  cancelled; counting them inflates the number an operator reads before
  deciding and keeps showing work that is done.

### Verified

```
vendor/bin/pest tests/Feature/Operations/WeatherForecastTest.php   8 passed
vendor/bin/pest tests/Feature/Operations/WeatherOutlookTest.php    7 passed
vendor/bin/pest        2445 passed, 4 skipped, 1 failed (23 assertions in the two files above)
vendor/bin/pint --test {"tool":"pint","result":"passed"}
vendor/bin/phpstan analyse   [OK] No errors
```

And in the browser, against the **live Open-Meteo API** rather than a fake —
the «Καιρός» panel on `/app` rendering Οδυσσέας, «Σταματά στα 4 μποφόρ»,
`Τρι 5 · Τετ 4 · Πεμ 5 · Παρ 5` with three days flagged, and
«5 αναχωρήσεις · 0 επιβάτες κλεισμένοι».

**The one failing test is `CiGatesTest > it keeps the committed schema snapshot
in step with the migrations`, and this issue is why it moved.** It was already
failing before this work for the reason in the Status table — CI is the only
place with a MySQL 8 connection and CI has been blocked on billing since
2026-09-06 — but the *hash* it reports is now different, because
`add_max_wind_bft_to_vessels` changes the migrations fingerprint. Expected
`8651a0be…`, actual `b419ba16…`. It cannot be regenerated locally. Recorded here
so that whoever unblocks CI knows the snapshot is legitimately stale rather than
the migration being wrong.

### Left open

`config('kaiki.weather.base_uri')` points at Open-Meteo's free endpoint, which
is **licensed for non-commercial use only**. That is correct for a dev database
and wrong the day a paying operator sees this panel. ADR-0027 records it;
somebody has to buy the commercial subscription or swap the provider before
launch, and the `WeatherProvider` contract is one method precisely so that swap
is a binding change.

---

## The six commits between #124 and #131

Written the same day they were made, not reconstructed. Each is smaller than an
issue and none of them is one, so they share an entry rather than inventing
issue numbers that do not exist.

### `1fa6807` — Stripe removed, as a gateway and as the platform's billing

Asked for directly by the product owner; the scope question *"the operator's
gateway, the platform's own billing, or both"* was answered **both**. 51 files.

Two separate removals that happened to share a vendor. The **gateway** was one
of the payment methods an operator could offer a guest, and Viva Wallet is what
Greek operators actually use — `PaymentGatewayName` is now `Viva`, `Cash`,
`BankTransfer`. The **billing** was Laravel Cashier, which was how Kaiki would
have charged operators for Kaiki; its columns came out of the users migration
with a comment saying why.

`ADR-0028` records the consequence honestly: **M7 is now blocked.** There is no
billing provider, and choosing one is a business decision. Removing Cashier did
not remove the requirement to take money from operators; it removed the only
implementation of it that existed.

`WebhookScenario` was rewritten rather than deleted — Viva's shape, with the
`X-Viva-Verification` header and `EventTypeId` 1796/1798, so the webhook tests
still assert replay protection and signature failure against a real provider's
semantics instead of a removed one's.

### `c3c2fe5` — Ten boats and ten trips per operator, and the empty dashboard they exposed

Demo data, asked for directly. `DemoFleetSeeder` tops up to ten of each with
Greek names, varied categories, times and prices.

It exposed a real bug rather than needing one. With all four first steps done
and no bookings yet, `FirstSteps::applies()` was true, so the checklist rendered
**empty** *and* suppressed the figures behind it — a dashboard showing nothing
at all. `applies()` now also requires an outstanding step. The seeder is how it
was found; the fix is not demo-only.

### `65163f8` and `92f1f00` — A navigation predicate must answer without a tenant, not throw

`GET /app/login` returned **500**, reported by the product owner with the
exception: `TenantContextMissingException` on `Enquiry`.

Filament builds navigation badges while rendering the login page, and
`BelongsToTenant` throws rather than scoping to nobody (TEN-4, and it is the
right design — silently returning every tenant's rows is the failure that design
prevents). So a badge query on a page with no tenant is a 500.

Two things worth recording. My original sweep drove 39 authenticated routes and
**missed this**, because authenticated routes redirect away from `/app/login`;
the test added is `get('/app/login')->assertOk()`, which is the assertion that
would have caught it. And guarding `FirstSteps::applies()` alone **inverted**
`canView()` — `! false` shows the widget — so each predicate carries its own
`Tenancy::check()` rather than relying on one upstream. My own new test caught
that second mistake before it was committed.

### `c0cda7a` — The meeting point, drawn

Asked for directly: embedded Google Maps wherever the single product page has a
map link. `Port::mapsEmbedUrl()` builds the embed from **lat/lng or address**,
never from the stored `maps_url` — a `goo.gl` short link cannot be framed, and
an operator who pasted one would get a blank box with no error. `frame-src` was
opened to exactly `https://www.google.com` in `HostedPageCsp` and nothing else.

### `1315ef1` — Today's fleet, and the decisions waiting on a person

The two dashboard panels the product owner picked out of the mockup: the
per-boat strip and «Χρειάζονται προσοχή».

`AttentionItems` is ordered by **deadline, not severity** — `PHP_INT_MAX` for
items with no deadline, so they sink. A list sorted by how bad each thing is
puts a serious problem with a week left above a small one that expires in an
hour, which is the wrong instruction to give somebody at 07:00. Later moved
above the calendar and given the now-marker, both on request.

---

## #124 — iCal out and iCal in, and two bugs that had been waiting since M1

OPS-13 asks for a feed per vessel and a poll every fifteen minutes. OPS-14 is
about what the feed must **not** say. OPS-15 is about what happens when
somebody else's server is having a bad week.

### The export is a subtraction

The URL is unauthenticated by necessity — Google Calendar will not send a
header, hold a session or complete an OAuth flow — so the token in the path is
the entire authentication, and in practice everything in the file is public to
anybody who ever sees the link. Links get pasted into third-party services and
forwarded between colleagues.

So the feed publishes busy periods and nothing else: no guest name, no
reference, no email, no party size, no price, and **no `ATTENDEE` property**,
which is the one an implementer adds without thinking because that is what an
attendee field is for. The trip's own title is out too — a product name is not
personal data, but «Ηλιοβασίλεμα, 4 άτομα» sitting in a competitor's calendar is
an operator's whole schedule and load factor.

`seats_sold`, never `seats_sold + seats_held`: publishing a hold makes the boat
busy for a quarter of an hour and then not, which is the flapping that teaches a
subscriber to ignore the feed. Cancelled sailings are excluded, because a
cancelled trip is not an occupation.

### `ical_feeds.include_guest_names` is deliberately not read

§2.7 gave the table that column, defaulting to false, on the reasoning that an
operator could opt in. **OPS-14 offers no such choice** — *"expose no guest
personal data"* has no exception clause — and `CLAUDE.md` makes the spec the
contract. So nothing consults the flag. **Open for the product owner:** either
the column goes, or OPS-14 gains an exception. It is not a decision to take
inside a rendering class.

### The import resolves every ambiguity toward keeping the boat blocked

The two failure modes are not symmetrical. A boat left blocked for a cancelled
charter is annoying and fixed in ten seconds. A boat freed while it is actually
out is a double booking discovered on a quay.

- **A feed that will not parse changes nothing.** An empty calendar and a broken
  one are the same bytes to a naive reader; treating a fetch failure as "no
  events" would delete every block the source ever created.
- **An empty feed deletes nothing**, for the same reason — a legitimately empty
  Airbnb calendar and a subtly broken publisher are indistinguishable from here.
- **A block converted into a booking is never removed** (OPS-15, word for word).
- **A 304 is a success that touches nothing.**

`DTEND` on an all-day event is **exclusive**: Airbnb's `20260704`–`20260706` is
two nights, not three days, and reading it as inclusive blocks a boat on a day
it is free. That is the single most common iCal bug in the accommodation trade
and it has its own test.

**The deletion guard is one-sided, and the first version was not.** Bounding
deletions by the latest live event as well as the earliest looks symmetrical and
is wrong — the window shrinks as events vanish, so anything disappearing from
the *end* of a feed falls outside its own range and survives for ever. A
cancelled charter would have stayed blocking the boat. A test caught it; no
operator would have. The future needs no guard, because time only moves events
closer to a horizon and never back out of one.

### Two pre-existing bugs, both found by being the first consumer

**`ical_sources.url` was stored in plaintext.** The model had `'url' =>
'encrypted'` in `casts()` **and** a custom `Attribute` mutator maintaining
`url_hash`. A custom mutator replaces the cast's setter, so the row was written
in the clear; the cast's *getter* survived, so reading it back tried to decrypt
plaintext and threw `DecryptException`. Both halves were invisible for two
milestones because **nothing had ever read the column** — the table was pulled
forward into M1 with `vessel_blocks`, and the sync that fetches the URL is M5.
An operator's private Airbnb feed URL is a credential that exposes their whole
calendar, and §1.7 names this column as encrypted.

Fixed by doing both directions explicitly in the accessor and removing the cast,
so there is one place that decides how the value is stored and no second
mechanism that can quietly disagree with it. `IcalSourceUrlTest` asserts against
the **raw row** rather than through the model — reading through the model is
what hid the problem, because the round trip works whether or not anything was
encrypted. No data migration: no seeder creates these rows and there is no
production, so none exist anywhere.

**The VAT lint was matching `vat` inside the word `private`.** `private const
FUTURE_DAYS = 400;` was reported as a Greek reduced rate, and any `private`
declaration whose value is 24, 13, 9, 6, 17, 400, 600, 900, 1300, 1700 or 2400
would have been. Fixed in the scanner by stripping PHP modifier keywords before
the word test — rather than by requiring a word boundary, which would also stop
`vatrate` matching and weaken the gate this project relies on. The scanner's own
self-tests, which assert it still detects every shape it claims to, pass
unchanged.

### Smaller decisions

- **`sabre/vobject` only**, though ADR-0019 approves `spatie/icalendar-generator`
  as well. One dependency covers both directions; the generator cannot parse,
  and the hard part here is surviving other people's feeds.
- **The schedule is the retry.** `SyncIcalSourceJob` has one attempt. A queue
  retry three minutes later against a server that is down is three failures
  against one outage, which would trip OPS-15's threshold on a blip; the next
  fifteen-minute poll is a longer and gentler backoff.
- **Three and ten are separate numbers.** Three is when the operator is told
  (OPS-15); ten is when the platform stops asking. Warning on the first failure
  trains somebody to ignore the warning.
- **The poll dispatches rather than loops.** A loop stops at the first
  fifteen-second timeout and delivers every operator behind it a stale calendar,
  every quarter of an hour, invisibly.
- **A PHPStan stub for `sabre/vobject`**, which implements `IteratorAggregate`
  without declaring what it iterates. A stub answers the finding; a baseline
  entry would hide it. It omits `Sabre\Xml\XmlSerializable` deliberately — a
  stub replaces the class it describes, and naming an interface resolved from
  another package makes the stub itself the error.

### Verification

| What | Result |
|---|---|
| `tests/Feature/Availability/IcalExportTest.php` | 8 passed — the guest's name, email, phone and `ATTENDEE` all absent from a real booking's feed |
| `tests/Feature/Availability/IcalImportTest.php` | 13 passed — exclusive `DTEND`, idempotency, the booked block that survives, unparseable and empty feeds, 304, and the three/ten thresholds |
| `tests/Feature/Availability/IcalSourceUrlTest.php` | 4 passed — asserted against the raw database row |
| `vendor/bin/pint` | clean |
| `vendor/bin/phpstan` (level 6) | **No errors** — no baseline, no ignore |
| `vendor/bin/pest` (full) | green but for the pre-existing schema snapshot; see the CI row |

### Found on the way, and not fixed here

**The hosted pages never load the widget.** `hosted/product.blade.php` renders
the mount point, and `hosted/layout.blade.php` still says *"the widget arrives
in M3's later issues"* — it never did. There is no `<script src>` for the bundle
anywhere in the hosted views, so a guest on `book.kaiki.app/{operator}/{trip}`
gets the no-JavaScript fallback and **cannot book**. #111's end-to-end run
drives the widget on a *fixture* host page, which is exactly why this was not
caught — the same shape as the CORS defect that run did find. It needs its own
issue rather than being smuggled into this one.

---

## #123 — The bookings and guests CSVs, and the two halves of an expiring link

OPS-17 asks for two files and stops there. OPS-18 adds the four properties that
make them usable — queued, streamed, a link that expires after twenty-four
hours, logged — and OPS-10's second half is one sentence about what must not be
in them.

### The table the data model did not have

`docs/data-model.md` §6 listed `import_jobs` and `manifest_exports` and no
counterpart for the ordinary exports. Three of OPS-18's four properties need a
row: a queued job needs somewhere to report to, an expiring link needs a
recorded expiry, and *logged* **is** the table. `export_jobs` is documented in
§2.6 and lettered `43a` in §6 rather than renumbering M6 onward.

### Four decisions, each with an obvious wrong version

**The date basis is a field, not an assumption.** A booking made in June for a
trip in August and paid in July belongs to three different months. Choosing one
silently is OPS-2's failure applied to a file rather than a dashboard: the
accountant reconciles once, disagrees with the bank, concludes the product is
wrong about money, and does not tell anybody. So it is on the form above the
dates, stored on the row, and in the filename — a CSV cannot carry a comment
line without breaking its parsers, and the filename is the only part that
survives being forwarded as an attachment.

**Document numbers are absent rather than removed.** The tempting shape is a
filter that strips the column; a filter has to be remembered by every future
caller, and forgetting it produces a spreadsheet of passport numbers that looks
correct. `ExportType::columns()` is an allow-list with no case for one. The test
asserts the property from outside — a real number in the database, and its
absence from the finished file's bytes.

**The link is authenticated, not signed.** `URL::temporarySignedRoute` was the
obvious build. It is a bearer credential over every guest's name, email and
phone number: it survives a group chat, a shared office browser, and the person
who generated it leaving the operator's staff. The route lives in the panel's
`authenticatedRoutes`, so it inherits the session guard and `ResolveTenant` —
which is what makes another operator's uuid resolve to nothing and answer 404
rather than 403.

**Expiry has two halves and only one of them is visible.** A check on the way in
satisfies the requirement and leaves the file on disk for ever (GDR-2).
`ExportJob::isDownloadable()` closes the link on time with the scheduler
stopped — the same asymmetry `holdsSeats()` has — and an hourly sweep deletes
the bytes while keeping the row, so *"it expired on Tuesday"* is an answer the
screen can give.

### Smaller things that would have been wrong

- **`expires_at` is stamped on completion**, not on request: an export waiting
  behind a catalogue import would otherwise silently get twenty-one hours.
- **`EXISTS` over `payments`, never a join.** A booking with a deposit and a
  balance has two succeeded payments, and a join bills the boat trip twice.
- **The window's inclusive end is `< to + 1 day`.** On a timestamp column,
  `<= '2026-09-30'` means midnight and drops the last day of every window. It
  does not look like a bug; it looks like a quiet Tuesday.
- **The panel's global `DatePicker` timezone had to be overridden.** It converts
  a picked date to UTC, which is right for a departure time and wrong for a
  calendar window: "1 June" arrived as `2026-05-31 21:00` and the export covered
  a different month than the form said. Found by a test, not by reading. The
  timezone is applied once, in the query, where the tenant is known.
- **Money is a plain dot decimal with its own currency column.** Reusing
  `MoneyFormatter` looks obviously right and produces `1.234,50 €`, which every
  spreadsheet reads as text. The trade-off is taken knowingly: Greek Excel wants
  a comma, so a dot decimal may need the import dialog — machine-readable
  everywhere beats human-readable in one tool and text everywhere else.
- **`CsvWriter` is now the one CSV implementation.** `GenerateManifest` had its
  own; a manifest and an accounting export are opened on the same Greek Windows
  machine, and two implementations of "what Excel needs" is two chances to fix
  only one of them.

### Verification

| What | Result |
|---|---|
| `tests/Feature/Operations/ExportTest.php` | 13 passed — including the document-number absence, the last-day boundary, the two-payments count, and expiry with the sweeper stopped |
| `tests/Feature/Panel/ExportDownloadTest.php` | 10 passed — owner, manager, crew refused, signed out, another tenant's uuid, three 410 cases |
| `tests/Feature/Panel/ExportResourceTest.php` | 6 passed |
| `vendor/bin/pint` | clean |
| `vendor/bin/phpstan` (level 6) | **No errors** — the six `nullsafe.neverNull` findings were fixed at the source, no baseline, no ignore |
| `vendor/bin/pest` (full) | **2387 passed, 4 skipped, 1 failed** |

The one failure is `CiGatesTest > keeps the committed schema snapshot in step
with the migrations`. It was **already failing on clean `main` before this
work** — verified by stashing the branch and re-running it — because the MySQL 8
snapshot can only be regenerated in CI (ENV-10) and CI has been blocked since
2026-09-06. A new migration changes the fingerprint, so it stays red until a CI
run refreshes the snapshot. No new failure was introduced.

### Open, and not mine to close

**Should read-only mode (TEN-9, SAA-7) refuse a data export?** It does today,
because TEN-9 says *"blocks all writes in `/app`"* and creating an export is a
write. That is the spec as written and it is worth flagging rather than
burying: an operator whose subscription has lapsed has the strongest claim of
anyone to a copy of their own books, and a product that holds them shut is one
they leave angry. Downloading an export that already exists is unaffected — it
is a read. **This is a product decision, not one to take inside a policy class.**

### Not in this issue

OPS-21's consolidated error feed. A failed export writes a translated sentence
onto its own row and the panel shows it there (NFR-8); folding it in with
payment, myDATA, SMS, iCal and webhook failures is the feed's own issue.

---
## #115 — Gutenberg blocks and Elementor widgets, both rendering through the shortcode

The same four embeds, chosen with a mouse. WPP-5 fixes them as **server-rendered
wrappers around the shortcodes**, and that phrase is the whole design.

### The tempting alternative, and why it is wrong

A JavaScript block that mounts the widget in the editor demonstrates better. It
also gives you **two rendering paths to keep in step for ever** — and the editor
one puts a working booking form inside a page editor, which is how somebody
accidentally makes a real booking while laying out a page.

So every block's `render_callback` and every Elementor widget's `render()` call
the shortcode's own callback. There is one implementation of the markup in this
plugin, and `EditorParityTest` is what keeps it that way: the two editors offer
the same four embeds, ask for the same two things, and name callbacks that exist.
The failure it prevents is undramatic — somebody adds an attribute to the block
because that is where they were working, and the Elementor version quietly does
not have it, discovered six months later by an operator who uses the other one.

### No build step, deliberately

The editor script is plain JavaScript against the `wp.*` globals WordPress
already ships. `@wordpress/scripts` would give JSX and a bundler and would add a
Node build to a PHP plugin that has none — for four blocks whose entire interface
is a select and a text field. **A build nobody can run is a block nobody can
fix**, and the person who will need to fix it is the operator's web person.

### The trip picker is why this issue cost more than the shortcodes did

`GET /kaiki/v1/trips`, capability-gated, so an operator picks "the sunset one"
instead of pasting a uuid. **Nobody knows a uuid.**

Two locks, and the second is the one that matters: the route returns only what a
publishable key could already read, because it proxies `GET /products` with the
`pk_`. A route whose safety depended solely on a capability check is a route one
plugin conflict away from being public.

When Kaiki cannot be reached the picker degrades to a text field holding whatever
the block already had, rather than disappearing and taking the value with it —
an operator must be able to save the page they are working on (WPP-14).

### Elementor's class is declared inside a hook

`Widget_Base` does not exist on a site without Elementor, and a class extending a
missing class is a fatal error the moment the autoloader is asked for it. So the
widget is in a file that is `require`d from inside `elementor/widgets/register`,
which only fires when Elementor is running, and is deliberately not reachable
through PSR-4.

### The translation was half-done and looked finished

`wp.i18n.__` in the block editor reads a **JSON** file, never the `.mo`. A plugin
shipping only a `.mo` has a Greek settings page and an English block panel on the
same site — which reads as a sloppy translation rather than as a missing file,
and nobody reports it.

Two fixes, both small and both the sort of thing that never gets done later:
`extract-strings.php` now reads JavaScript as well as PHP, so the editor's own
labels are in the template at all; and `build-translations.php` writes the JSON
beside the `.mo` from the same `.po`. That is normally `wp i18n make-json`, which
would mean WP-CLI on every machine that builds; the format is a dictionary and it
is nine lines.

Sixty-nine strings, both files, with a test asserting the JSON exists and carries
a string only the editor uses.

### Verification

| Command | Result |
|---|---|
| `npm run plugin:lint` | phpcs, WordPress ruleset and PHP 8.1 compatibility, clean |
| `npm run plugin:test` | **40 passed** (5 new) |
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer test` | **2267 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |

One earlier test had to be narrowed rather than exempted. `Blocks.php` genuinely
calls `wp_enqueue_script` — for the **editor** script, which is admin-only and is
not the widget bundle — so the assertion became "no file both knows the bundle's
URL and enqueues a script" instead of banning the function. An exempted guard
soon enforces nothing.

---

## #114 — The four shortcodes, and a bundle that loads only where it is needed

The plugin's whole promise: an operator pastes `[kaiki_booking product="…"]`
into a page and takes a booking. Everything after this — blocks, Elementor — is a
nicer way of producing the same four strings.

### Written into the output, not enqueued, and the reason is WGT-7

`wp_enqueue_script` puts a tag in the head or the footer. The widget mounts
**where its script tag is** — so an enqueued bundle would render the booking form
at the bottom of the page instead of where the operator put the shortcode, which
is the one thing the one-line embed promises.

So the tag is written into the shortcode's own output, and a static flag stops a
second shortcode fetching the bundle twice. The second tag still exists, because
the widget mounts one instance per `data-key` tag (WGT-8); only the `src` is
dropped.

**A page with no shortcode gets nothing at all.** That is the difference between
a plugin an agency recommends and one they rip out, and WordPress makes the lazy
way easy — hook `wp_enqueue_scripts` and be done. The test asserts it against the
source rather than a hook registry: a stub answering "no hook fired" would be
agreeing with itself.

### The messages are for two different people

A **visitor** who meets a misconfigured shortcode sees a short neutral line and
nothing about us. An **editor** sees which attribute is missing and what a
correct shortcode looks like — because the person who pasted it is the operator
or their nephew, at night, once, with nobody to ask, and a shortcode that
silently rendered nothing would be an afternoon of their life.

The split is `current_user_can( 'edit_posts' )` rather than "is somebody signed
in": a subscriber with an account is still a visitor.

### The `product` attribute is checked, not trusted

It is a string a page editor typed, and page editors paste strange things. A
value that is not a uuid is refused rather than escaped and passed on — the
difference between an attribute and an injection — and the test feeds it
`"><script>alert(1)</script>` to prove it.

An **unknown category** is deliberately not an error (WGT-6): an operator writes
one into a page once, and the page outlives the trips it was written for.

### Verification

| Command | Result |
|---|---|
| `npm run plugin:lint` | phpcs, WordPress ruleset and PHP 8.1 compatibility, clean |
| `npm run plugin:test` | **35 passed** (12 new) |
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer i18n:check` | 161 passed |
| `composer test` | **2266 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |

`docs/wordpress.md` is the operator's guide: the five minutes from installing to
a booking form on a page, the four shortcodes, and — in the plainest words the
subject allows — what the secret key is for and why it must never touch a page.

Two false positives were fixed on the way, both the same shape and both worth
naming: a guard that reads **docblocks** flags the sentence explaining why the
code does the right thing. `SecretKeyScanner` learnt to strip comments in #113;
the enqueue check learnt it here. A guard that fires on innocent code earns an
exemption, and after a dozen exemptions it enforces nothing.

---

## #113 — The API client, the transient cache, and the webhook that busts it

How the plugin talks to Kaiki: one client, one cache, one failure path.
Everything the plugin renders after this goes through it, which is the point —
three call sites with three opinions about caching and failure is how a plugin
comes to show a stale price on one page and a fatal error on another.

### A failure is a value, not an exception

WPP-14: *"A platform outage MUST NOT produce a PHP fatal error or a blank
page."* Returning `ApiResult` rather than throwing is what makes that easy to
obey — an exception in a shortcode callback **is** a fatal error on an
operator's page, WordPress does not catch it for you, and every call site would
otherwise have to remember a `try`. A caller that ignores the failure renders an
empty list instead of a white screen, which is the right way round for the
mistake to go.

Three named failures, because they need three different sentences and, for an
editor, three different actions: `unreachable`, `refused`, `unexpected`. An
operator told only "it did not work" changes the wrong one.

### The stale rule is narrow, and the narrowness is the decision

A catalogue read falls back to the **last good answer** when Kaiki cannot be
reached — a stale trip list is better than a hole in an operator's page, and the
entry lives a week, which covers an outage nobody is awake for.

**Availability never does.** A guest shown seats that are gone books a boat that
is full, and WGT-17's sixty seconds is already the compromise. `get_fresh()`
exists so a caller says which kind of read it is, rather than the cache guessing.

A **refused key** does not fall back either. It is a configuration problem, not
an outage, and serving yesterday's catalogue would hide it until the operator
noticed bookings had stopped.

### The cache key is a promise about who may see the entry

It carries the path, the query, the locale and a hash of the publishable key. A
site serving two languages, or reconfigured with another operator's key, cannot
serve one's catalogue on the other's page — and it would have looked completely
normal. The key is hashed rather than readable, because a transient name lands
in `wp_options`, which is in every database backup an agency emails around.

The flush is coarse on purpose. The platform's message says *something* changed;
working out which of a hundred cached reads it touched would be a second
implementation of the catalogue's shape, wrong the first time a field moves.
Re-reading a trip list costs one request. Serving a wrong one costs a wrong price
on a page. The **last-good** entries survive a flush: they are the outage net,
and an operator editing a title should not remove it.

### The webhook, verified before it is parsed

A payload parsed before it is verified is a payload an attacker chose. It is one
line in the wrong order and no test catches it by accident, so this follows the
platform's own `GatewayWebhookController` rather than inventing a second opinion.

Three refusals, all needed. A **wrong signature**, obviously. A **stale
timestamp**, because a signature over a body stays valid for ever otherwise and a
captured request replays next year. A **seen event id**, because a sender that
retries is not an attack but ordinary behaviour, and the second delivery must be
a no-op rather than a second flush.

The reply to a replay is **200**, not 4xx: from the sender's side it succeeded,
and an error would make it retry for ever.

`permission_callback` returns `'__return_true'`, which reads as a missing check
and is not one — the HMAC **is** the authorisation, and a server-to-server call
has no user, no cookie and no nonce to check instead. The docblock says so beside
the line, because that is where somebody reviewing it will be looking.

### The plugin got a test suite, and the reason is uncomfortable

WPP-15 puts the plugin's real testing in a Playwright run against a real
WordPress site, and that run needs a site the product owner has to provide.
Shipping **unverified HMAC verification** while waiting for it is not a thing to
do — so the plugin has about forty lines of WordPress stubs and PHPUnit on its
own PHP version, covering exactly the decisions that must not be wrong and do not
need WordPress to check: the signature, the replay window, the cache key, and
WPP-13's locale order.

It is not a WordPress test harness and does not pretend to be one. Anything that
needs WordPress to *behave* like WordPress is still the Playwright run's, which
is what ADR-0015 decided.

`SecretKeyScanner` learnt to strip comments on the way: the `Client`'s docblock
explains why it does **not** call `Settings::secret_key()`, and a guard that
flagged that would have earned an exemption — after a dozen of those a guard
enforces nothing.

### Verification

| Command | Result |
|---|---|
| `npm run plugin:lint` | phpcs, WordPress ruleset and PHP 8.1 compatibility, clean |
| `npm run plugin:test` | **23 passed** |
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer i18n:check` | 161 passed |
| `composer test` | **2266 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |

The Greek translation moved with the code: 46 strings, compiled, with the parity
test as the thing that notices when it does not.

---

## #112 — The plugin skeleton, and the key that must never reach a browser

M4 opens with the WordPress plugin's first files: the header WordPress reads,
the settings screen an operator fills in once, the standards gate, and the rule
that the rest of the milestone depends on being right.

### PHP 8.1, and it is a market fact rather than a preference

The platform is 8.4 everywhere (ADR-0014). This is not. Greek shared hosting is
where these sites live and a good half of it is on 8.1 — a plugin that needs 8.3
is a plugin those operators cannot install and will not understand why.

So the plugin has **its own everything**: its own `composer.json` at `^8.1`, its
own phpcs ruleset carrying `PHPCompatibilityWP` at `testVersion 8.1-`, and its
own CI job on an 8.1 runner. `phpstan.neon` already excluded the directory; the
compatibility ruleset is the half that was missing, and it is the one that
matters, because a developer writing this plugin has 8.4 habits and an enum in a
property type would pass every other check here and fatal on the operator's host.

`CiGatesTest` was asserting *"every workflow runs the one PHP version
composer.json requires"*, and it passed only because its scanner could not see a
version written into a step's `with:`. It can now, and the test names the
exception explicitly: the platform's version and the plugin's, and nothing else
— so a **second** job drifting onto its own version fails, and this one cannot
drift silently either.

### Two options, and the split is the security model

`kaiki_settings` holds everything an operator configured and everything the front
end may see. `kaiki_secret_key` holds the one thing it may not, **in an option of
its own**, so that no code path can hand the secret to a template by passing "the
settings". That is not hypothetical: a helper returning the whole settings array
to a view is the obvious convenience, and it is exactly how a secret ends up in
page source.

ADR-0013 Option A: a standard installation stores a `pk_` and nothing else. The
secret field is **not rendered at all** until the SEO toggle is on — the best way
to stop somebody pasting a secret into a page is for them never to have been
shown a box asking for one. `Settings::secret_key()` returns nothing when the
feature is off, whatever is still in the database from before.

**The enforcement is by reach, not by value.** `SecretKeyScanner` allows three
files to name the secret — where it is defined, where it is typed in, where it is
deleted — and fails on a fourth. Nobody writes a key into a template; they pass
the reader to one, and a grep for `sk_` cannot see that. The allow-list is short,
each entry says why it is there, and adding a template to it is a decision
somebody has to defend in a commit.

Beside it, SEC-9's actual grep, which did not exist anywhere: no built artefact a
browser downloads may carry something shaped like a live key.

### The connection test says which of three things is wrong

An operator who has just pasted a key wants one of four sentences, and they are
genuinely different problems with different fixes: it works and here is whose
account it is; the key is wrong; **this site is not on the key's allowed
origins**; the platform is unreachable. An operator told only "it did not work"
changes the wrong one, and the origin case is the one nobody guesses.

### Greek, compiled, because WPP-3 asks for both locales

The strings about the secret key are why that is a requirement rather than a
courtesy: an operator who cannot read the warning is the operator who pastes the
key into a page.

WordPress reads a `.mo`, not a `.po`, and `msgfmt` is on neither a Windows
machine nor a bare GitHub runner — a build step only some people can run is a
translation that goes stale in silence. So `tools/build-translations.php` writes
the `.mo` itself: the format is a header, two offset tables and the strings, and
eighty lines of PHP is cheaper than a dependency. `extract-strings.php` beside it
regenerates the template, and two tests hold them together — every template
string has a Greek translation, and the compiled file is not stale.

### Verification

| Command | Result |
|---|---|
| `npm run plugin:lint` | **phpcs, WordPress ruleset, 6 files, clean** — the stub that printed a sentence is gone |
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer i18n:check` | 161 passed |
| `composer test` | **2266 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |

10 new tests in `PluginStandardsTest`, plus `SecretKeyScanner`. The secret-reach
test was checked against a deliberately planted offender rather than only against
a clean tree: a guard that has never failed is a guard nobody has tested.

`plugin-lint` split out of `node-checks`, which is now empty and gone — the last
of #90's four merged stubs, and the one that never belonged in a Node job at all,
since it is phpcs over PHP.

---

## #111 — The first test that proves a person can buy a boat trip

M3's closing issue, and the moment `npm run e2e` stopped printing a sentence.
Playwright drives a real Chromium through the real widget, on a real second
origin, against a real server, to a real confirmed booking.

**It found nine defects in its first hour, most of them fatal to the product and
every one of them invisible to every other gate in this repository.** That is the
entry.

### What it found

| What was wrong | Why nothing caught it |
|---|---|
| **The CORS preflight never allowed `Idempotency-Key`** | It is required on every booking POST (§3.4), it makes the request non-simple, and a preflight that does not name it fails. **No widget on any operator's site could ever create a booking.** A test client does not preflight. |
| `guest.full_name` where the contract says `guest.name` | A mock transport reads no field name. |
| `pax[].band_code` where the contract says `age_band_uuid` | The same. Two tenants may both call a band `adult`; the uuid is what the server matches. |
| The checkout request carried no `kind` | Required, so **every** checkout the widget started was refused as invalid. |
| The guest token was sent as `?token=` | `AuthenticateGuestToken` reads `X-Kaiki-Guest-Token`, so the confirmation poll got a 403 — and a credential in a query string lands in access logs and `Referer` headers anyway. |
| The return-from-gateway path read `?kaiki_booking=` and `?kaiki_token=` | **Nothing anywhere ever wrote them.** A guest who paid came back to an empty booking form. |
| A strict CSP silently discarded the widget's stylesheet | `shadow.ts` used to claim a script-created `<style>` was not an inline style for CSP purposes. It is. The widget mounted, worked, and was completely unstyled, with five violations in a console it cannot see. |
| A host page's `!important` reached inside the shadow root | Inherited properties are decided on the *host element*, which lives in the operator's document. The whole booking form rendered in Comic Sans. |
| A page-builder reset hid the widget entirely | It inserted an anchor `div` around its host, contributing a `div > div` to a page whose theme it does not control. |
| The hold countdown was dead code | WGT-19 shows it *"once a draft exists"*, and the draft was created in the same breath as the redirect. It was never on screen for a single frame. |
| The footer failed WCAG AA at 3.75:1 | Nothing had ever measured it. |

### The sandbox checkout page, which had to exist first

The issue's own criterion — *"it uses the sandbox path, so the suite needs no
third-party account and no card"* — presumes the sandbox path can be walked in a
browser. It could not. `FakeGateway` redirected to `gateway.kaiki.test`, chosen
because it cannot resolve, which was right while nothing was ever going to follow
it. **SAA-9's "a test booking in sandbox mode" was unbuildable for the same
reason**: an operator finishing onboarding met a browser error where a payment
should be.

So `/sandbox/checkout/{reference}` exists now, and its design is four refusals.
The load-bearing one is the booking's own `is_test`, written at creation from the
key that created it and never changed — never a config switch, a header or an
environment check, because PAY-11 says sandbox mode must be impossible to enable
accidentally on a live tenant and this page's URL is guessable by construction.
It settles through `ConfirmFromWebhook`, the same action a real webhook uses,
because BKG-11 makes the webhook the only authority for a successful payment and
the seat arithmetic on both the success and failure paths is the one place a
mistake oversells a boat.

`return_url` had been accepted and dropped on the floor since the contract was
written. It now reaches the payment row — and is **validated against the key's
allowed origins** on the way, which was a precondition of storing it rather than
an improvement on it: an unchecked value is an open redirect wearing a payment
flow's clothes.

### The shape of the run, and three decisions inside it

**A real second origin, not an intercepted route.** The first version fulfilled
`operator.example` with `page.route()` and could not load the bundle at all —
Chrome's Local Network Access check refuses a synthesised page's request to a
loopback address. A launch flag would have fixed it by switching off a browser
security feature, which is how a suite comes to hide a real cross-origin bug. A
genuine server on a second port needs no flag and makes every request actually
cross-origin, so SEC-7 is exercised rather than stepped around — which is exactly
how the `Idempotency-Key` defect surfaced.

**Retries are zero, and the issue says why**: an intermittent failure in a booking
flow is a race between the hold, the availability cache and the redirect, and a
suite that retries until green is a suite that lets it through. Traces,
screenshots and video are kept on the first failure instead.

**One worker**, because every spec books against one seeded departure with a real
capacity, and parallel workers would manufacture the very flake the line above
exists to detect.

### The behaviour changes, and why none was optional

- **The draft is created on arrival at the review step**, not on "pay". WGT-19's
  countdown is shown "once a draft exists"; review is the earliest point one can
  exist, because the contract requires a lead guest and the contact step is where
  one is entered. BKG-9 still keeps the seats merely *held* until the redirect
  commits them, so nothing is taken from anybody else's boat before this guest
  goes to pay.
- **"Start again" after an expiry returns the guest to the date**, not to a review
  of a booking whose seats went back to the boat. Everything they typed survives
  (WGT-18).
- **Styles are adopted, not injected.** A constructed `CSSStyleSheet` is not
  markup, and `style-src` does not govern it. The `<style>` element stays as the
  fallback for Safari before 16.4.
- **Every inherited property is restated inside the shadow root**, where the
  operator's stylesheet cannot match anything. That is the fix rather than an
  escalation: there is no war of important flags to lose.
- **The host element defends five properties inline** — display, visibility,
  opacity, position, max-height — the one thing an author stylesheet cannot
  outrank. Anything cosmetic is left alone: a widget that fought the operator's
  page over a margin would be a widget that never fits into it.
- **The manage token lives in `sessionStorage` beside the uuid.** WGT-12 names the
  uuid; reading the booking back needs both. The alternative was a credential in
  a URL, in the operator's logs and in a `Referer`.
- **The outcome heading is an `h3`.** A heading that is a styled paragraph is
  invisible to somebody navigating by headings (A11Y-1).
- **Secondary text is one value at 70%**, not five percentages. axe found the
  footer at 3.75:1 against white and the other four could not be checked without
  reading every rule.

### Verification

| Command | Result |
|---|---|
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer i18n:check` | 159 passed |
| `composer test` | **2252 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |
| `npm run widget:test` | 76 passed |
| `npm run widget:build` | 61.6 KB raw, **20.7 KB gzipped — 25.9% of the 80 KB budget** |
| `npm run widget:guards` | no hardcoded colour, no price arithmetic, 71 keys in both locales |
| **`npm run e2e`** | **17 passed** in 1.3 minutes, including a seventy-second hold expiry on a real clock |

32 new tests: 17 Playwright specs, `SandboxCheckoutTest` (9), `CheckoutReturnUrlTest` (5),
`ViewFrontendLinkTest` (2), two more in `WidgetCompatibilityTest` — which now
reads the payloads the widget **sends** as well as the fields it reads, closing
the exact hole three of the defects above came through — one in `CorsTest` and
two in `CiGatesTest`.

`widget-e2e` split back out of `node-checks`, which is what #90's comment there
promised would happen the day it stopped being a stub. The pull request gets the
smoke subset; the full suite runs nightly.

### Two smaller things, asked for while this was being built

A **"View your page"** link at the top of the `/app` sidebar, absent rather than
dead when HOS-6's switch is off — a button leading to a 404 teaches an operator
the feature is broken rather than switched off. And `HostedUrl` now recognises a
loopback host **with a port**, because a developer running the panel and the
hosted pages on two ports is the ordinary local setup and `https://127.0.0.1` is
a link that cannot work.

---

## #110 — The live preview is the real widget, and three gates on the alias

Two loose ends that belong together: **BRD-4's live preview**, deferred from #17
because there was no widget to preview, and **ADR-0011's distribution** (WGT-4),
which is how the widget reaches a page at all.

### The preview embeds the bundle, with no key to embed it with

The issue is explicit that the preview must be the real widget, and the reason is
worth restating: a hand-drawn preview is a second implementation of the widget's
appearance and it is wrong the first time either changes. So the branding screen
carries an actual `<script src="/widget/kaiki-widget.js">`.

Which immediately runs into SEC-3. A publishable key is stored as a hash, a prefix
and its last four characters — **there is no plaintext key on the platform to put
in that tag**, and minting a real credential in order to look at a colour would be
absurd. The answer is a **preview transport** in the bundle itself: the panel puts
the operator's own branding and two of their own trips on the page as
`__kaikiPreview`, and `previewClient()` answers from that. Same bundle, same
components, same shadow root; only the transport differs, and the transport is the
one part of the widget a preview could never exercise anyway. `post()` throws
rather than pretending, so nothing in a panel can hold a seat nobody sold.

Their **own** trips, not invented ones. The first thing an operator checks is
whether their longest title fits, and "Sample trip &euro;99" answers a question
nobody asked.

### Live is six custom properties, not a re-fetch

`GET /api/v1/branding` returns what is **saved**, and an operator dragging a colour
picker has not saved anything — so a preview that re-fetched would lag by a round
trip and a save. Instead the unsaved values are written onto the host element as
`--kaiki-*` properties, which inherit through the shadow boundary; that is what
custom properties do and it is why WGT-9 uses them.

Two consequences fell out of that and both are load-bearing:

- The embed sits behind **`wire:ignore`**. Livewire re-rendering the page around a
  mounted shadow root would tear it down on every keystroke, which is the opposite
  of live. So a colour change travels as a dispatched event carrying only the six
  properties.
- The preview payload's `colors` is **deliberately empty**. The widget writes what
  `/branding` returns into `:host`, and the panel writes the unsaved values onto
  the host element where an inline declaration outranks a `:host` rule. Sending
  both would make the preview race itself, and which colour won would depend on
  which finished first.

Colour fields are `->live(debounce: 400)`. A picker being dragged emits a value per
frame, and a round trip per frame would make the preview slower than saving.

### The email preview is the real template too

`mail.booking.html` rendered with an **unsaved** `Booking` — a reference, a name, a
date and a balance, and no row written to look at a picture of one. Same argument
as the widget: a second template built to look like the first is a promise that
they stay in step, and they will not. It goes into an `srcdoc` iframe because an
email is a whole document, doctype and table layout and inline styles, all of which
would fight the panel's stylesheet if they were inlined into the page.

### Hull teal, at last

The third acceptance criterion asked for the reset defaults to be *"the hull teal
settled on 2026-09-04, not the old placeholder"*, and they still were the
placeholder. `config('kaiki.branding.defaults.colors')` and the `brand_profiles`
column defaults moved **together** — primary `#0B4F4A`, secondary `#063733`, accent
`#B5511F`, text `#16211F` — with `docs/api.md`, `docs/data-model.md` and the mail
and PDF fallbacks following. `font_family` did not change: Inter was chosen, not
inherited.

### The three gates, and what the compatibility one found

`.github/workflows/widget-release.yml`. The bundle is built **once** and downloaded
by each gate, because three jobs each running their own build would weigh, exercise
and ship three different bundles. The alias job `needs: [size, smoke,
compatibility]`, and that dependency **is** the gate: a workflow that skipped a
check would still pass its own steps and report green, which is why `CiGatesTest`
asserts the shape rather than the steps.

`smoke` runs the still-stubbed `npm run e2e` and is wired by name, the same
reasoning `node-checks` in `ci.yml` already carries: the gate starts being honest
the day #111 fills the script in, with no change to the workflow.

**The compatibility gate reads the widget's own TypeScript interfaces** rather than
a hand-written list of fields, which would be a third opinion agreeing with neither
side the day somebody changed one. It found a real drift on its first run: the
widget's `BrandPayload` declared a `locale` the API has never sent, and
`index.tsx` used it as the fallback in WGT-15's locale chain — a branch that could
not be taken. Both are gone. That is exactly the failure this gate exists for: a
missing field is `undefined`, and `undefined` renders as nothing.

### Verification

| Command | Result |
|---|---|
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer i18n:check` | 159 passed |
| `composer test` | **2233 passed, 4 skipped, 1 failed** — the inherited ENV-10 snapshot |
| `npm run widget:build` | 57.6 KB raw, **19.3 KB gzipped** |
| `npm run widget:size` | **24.1% of the 80 KB budget**, 60.7 KB left |
| `npm run widget:guards` | no hardcoded colour, no price arithmetic, 71 keys in both locales |
| `npm run widget:test` | 75 passed |
| `php artisan widget:publish` | `v0.1.0`, alias repointed, manifest written |

16 new tests: `BrandingPreviewTest` (11) and `WidgetCompatibilityTest` (4), plus
two shape assertions in `CiGatesTest`.

Two notes for whoever reads this next. The **ENV-10 schema snapshot still fails**,
as it has since CI was blocked — and the `brand_profiles` default change is now one
more thing the regeneration has to pick up when a MySQL 8 connection exists again.
And `branding.preview.widget` had to stop being the word "Widget" in both locales:
the I18N-3 gate reads an identical Greek string as an untranslated one, which is
the right default even when the word really is the same.

---

## #109 — Custom domains, and the endpoint that has to say no

An operator points `book.theirdomain.gr` at Kaiki with a CNAME and it works, with
a certificate, without anybody touching a server.

### The ask endpoint is the whole security story

On-demand TLS means the server obtains a certificate for **whatever hostname
arrives**, provided `GET /tls/ask` approves it. An endpoint that answered broadly
would let a stranger point any DNS record at the platform and burn through Let's
Encrypt's rate limit — for every operator at once, with a DNS record and a
browser.

So: **200 only for a hostname with a `verified` row.** Not pending, not failed,
not disabled, not "belongs to a tenant". The refusals are asserted first and in
every shape, because an approval test passing says nothing about the property
that matters.

The body is empty and the status identical for a known and an unknown hostname. A
`404` saying *no such domain* beside a `403` saying *not verified* is a probe
oracle: ask, and learn which operators exist.

### A domain that stops resolving keeps serving

The acceptance criterion asks for it and it is the humane reading: *"A registrar
glitch must not take an operator's site down."* From the platform's side a
resolver hiccup, a maintenance window and a deleted record are the same silence,
and only one of the three is worth an outage. A failed check on a **verified** row
is recorded, logged and left verified; a `pending` row that fails becomes
`failed`, because it never worked.

### Two routing lessons, both expensive

**`/` cannot be registered twice.** Laravel keys its route collection by method +
domain + URI, so a second `/` with no domain constraint does not compete with the
first — it **replaces** it. Registering a custom-domain root turned the platform's
own front page into a 404, and `SmokeTest` was the only thing that noticed. `/` is
now one route through `RootController`, which asks `CustomDomainResolver` and
serves the operator's page or the platform's.

**And `/` cannot carry the hosted middleware.** `ResolveTenant` 404s when nothing
resolves, which is right everywhere except the platform's own root. So
`HostedRootPipeline` applies the hosted stack **conditionally, inside the
pipeline** — the resolver decides, and the platform's host passes straight
through.

The other three custom-domain paths — `/legal`, `/search`, `/{product}` — collide
with nothing and are ordinary routes behind `CustomDomainOnly`, registered last.
That middleware is the guard #101's lesson demands: a `/{product}` route at the
root of every host matches `/app`, `/admin` and every probe route, and the domain
constraint that fixed it there is unavailable when the hostname is the operator's.

### Deviations and additions

- **The platform's front page is served on the platform's hosts and nowhere
  else.** An unverified hostname pointed at us used to get the marketing page,
  which is an impersonation surface for free and a duplicate of our own page in
  search. It is a 404 now.
- **`DnsLookup` is a port with a fake**, because a test cannot make a registrar
  answer "not yet", and one calling `dns_get_record()` would pass on a laptop and
  fail on a runner behind a proxy.
- **An A record verifies as well as a CNAME.** A registrar that refuses a CNAME on
  an apex leaves an operator with an A record at the platform's address, and
  refusing that would be refusing a domain that works.
- **`kaiki.tenancy.custom_domain_target`** is separate from `hosted_host` although
  they are the same string today: a platform behind a CDN points customer domains
  at the CDN while serving its own pages from the origin. Empty means **nothing
  verifies**, because an unconfigured platform must not approve certificate
  requests.
- **`docs/deployment/caddy.md`** carries the Caddyfile fragment, the `interval`
  and `burst` reasoning, and what M8 still owes — including a persistent volume
  for certificate storage, without which every restart re-issues and meets the
  rate limit the ask endpoint exists to protect.

### Verification

| Command | Result |
|---|---|
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer test` | **2211 passed, 1 failed** — the inherited ENV-10 snapshot |

27 new tests across five files: `CustomDomainTest` (7), `TlsAskEndpointTest` (6),
`CanonicalRedirectTest` (5), `DomainCheckSweepTest` (3) and `DomainsPageTest` (6),
plus `FakeDns` in `tests/Support/Tenancy`.

Two PHPStan lessons re-learnt from #89: `$this->dns` inside a Pest closure is a
`TestCall` at analysis time, so the fake is reached through a file-scoped
function; and a `TestResponse` return type needs its generic.

---

## #108 — The other three mounts, and the price that must not reach the DOM

`list`, `calendar` and `enquiry`. With them the widget has all four mounts
WGT-5 fixes, at **19.1 KB gzipped — 23.9% of the 80 KB budget**.

### The budget did not need splitting, and WGT-1 forbids it anyway

The issue expected code-splitting to be the answer here: *"Three more mounts is
where that budget gets spent."* It was not. Four mounts, seven locales' worth of
strings and the whole booking machine come to under a quarter of the allowance,
and **WGT-1 is FIXED on a single IIFE** — so splitting would have traded a
requirement for headroom that was not needed. If a later mount changes the
arithmetic, the trade is an ADR rather than a build-config edit.

### `list`: the price a quote product must not have

BKG-24 seen from the widget's side, and the issue is right about how to test it:
*"A `quote` product whose price is hidden by CSS still has the number in the
DOM."* So the element is **not rendered at all**, and the test scans the markup
for a currency symbol and for the digits of the price. Hiding it would pass a
visibility check and fail this one.

The category tabs are built from **what came back**, not from the enum: a fleet
selling two kinds of day gets two tabs and not six.

### `calendar`: availability, and nothing that competes with the page it is on

No price and no booking button. This mount goes where the operator's own write-up
has already sold the trip, and all that is left to say is which days sail. Paging
forward and back costs **one request**, because WGT-17's cache is keyed on the
query string — asserted by counting requests across two clicks.

Every day says its status **in words as well as in colour**: a calendar that only
shades cannot be read by a colour-blind guest (A11Y-1).

### `enquiry`: the honeypot that has to be visible to a robot

`company_website` and `form_rendered_at` are #85's two cheap filters, and the
honeypot is hidden **off-screen rather than with `display: none`** — a form
filler that skips hidden inputs would skip the trap, which is the whole point of
the field. A rejection renders **the API's own sentence** (§4.1) rather than "your
message failed": the envelope already carries a localised explanation, and
substituting ours would swap a specific answer for a vague one and drift the day
the server's changed.

### Two defects the tests found

- **A payload that is not an array threw into the host page's console.** The
  three-instance test of #106 answers every URL with the branding fixture, so the
  list mount received an object where the contract promises a list and
  `.map` exploded. Both mounts now render empty rather than throwing — a widget
  inside somebody else's page must never put a stack trace in their console.
- **A Preact state update is scheduled, not immediate.** The enquiry test filled
  the form and submitted in the same tick, which posted the *initial* state — an
  empty message. The helper now lets Preact flush, and says why.

### Verification

| Command | Result |
|---|---|
| `npm run widget:build` | one IIFE, all four mounts registered |
| `npm run widget:size` | **19.1 KB gzipped, 23.9% of budget**, 60.9 KB left |
| `npm run widget:guards` | clean — 71 keys in both locales |
| `npm run widget:test` | **75 passed** across eight files |
| `composer test` | 2184 passed, 1 failed — the inherited ENV-10 snapshot |

---

## #107 — The booking mount, and the key that must not be fresh

The walk that takes the money: date, party by age band, extras, contact and
consent, review with the server's own breakdown, and the gateway. Plus the hold
countdown while the guest decides and the confirmation state when they come
back.

| | |
|---|---|
| Bundle | 16.2 KB gzipped — **20.2% of WGT-2's 80 KB**, 63.8 KB left for #108's three mounts |
| Tests | 27 new (`booking-machine` 12, `booking-flow` 15), **64 in the widget suite** |

### Back navigation is a property of the design, not a thing to remember

WGT-18's *"back navigation never loses entered data"* is the most commonly
broken thing in a multi-step form, and it breaks the same way every time: each
step owns its state, going back unmounts the component, the answers go with it.

So **the machine owns one state object and the steps are views onto it**. There
is no code path in `booking/machine.ts` that clears an answer — `back()` moves a
cursor. The test walks to the end, walks back to the beginning and asserts every
field individually, because `toEqual` on the whole object would pass on a machine
that reset everything to the same defaults it started from.

The extras step is **skipped symmetrically** for a product with none, so a guest
going back from contact lands on party rather than on a step they have never
seen.

### The idempotency key is per intention, which is the requirement most likely to be built backwards

The issue says so and it is right. The instinct is a key per request; §3.4's
mechanism depends on it being per **intention**, so that a retry replays the
first answer rather than creating a second booking. A widget minting a fresh key
per attempt double-books on a flaky connection and **every server-side test of
the middleware still passes** — from the server's side two keys are two
intentions, which is a true statement about a false situation.

`IdempotencyKeys.keyFor('draft', fingerprint)` is bound to what makes this draft
this draft — the date, the party, the extras, the voucher. Changing the guest's
telephone number does not mint a new key; changing the party does. And a new
draft key never invalidates the checkout key of a booking already on its way to
a gateway, which is its own test.

### Three states after "pay", and only one of them is a claim

- **A gateway redirect**, the ordinary path.
- **A direct confirmation** when a voucher covered the total: BKG-19 and PRC-22,
  detected by the **absence of `redirect_url`** rather than by a status code,
  because 200-versus-201 is a fact about HTTP and not about the booking.
- **The WGT-20 poll** on the way back: sixty seconds of `GET /bookings/{uuid}`,
  and when the webhook has not landed the answer is *pending* — an email will
  follow — never *confirmed*. The issue's note is the reasoning: a redirect is a
  guest pressing a button; the webhook is the money moving.

The poll reads with `cache: false`, which the client gained for it. WGT-17's
cache window is sixty seconds and the poll is sixty seconds long, so a cached
read would have re-read its own first response and concluded nothing ever
happened.

### Deviations and additions

- **A native `<input type="date">`** rather than a calendar. The budget meets
  A11Y-1: the native control is keyboard-operable, labelled and localised by the
  guest's own device, and costs nothing. #108's calendar mount is where a month
  grid with availability shading belongs.
- **The mount is loaded by a wrapper that fetches the product**, so
  `BookingMount` renders what it is given and the machine and the flow are
  testable with no network at all.
- **`MountProps` grew** the shared client, translator, emitter and locale. A
  mount building its own client would be a second branding fetch and a second
  cache, which is what WGT-8 exists to prevent.
- **`ApiClient` gained per-request headers and a cache opt-out**, both for this
  issue and both narrow: the header carries the idempotency key, and the opt-out
  exists for the one read whose purpose is that the answer changes while you ask.

### The guard caught the issue number

`#107` in a CSS comment is a valid three-digit hex colour, and the widget's
stylesheet is a template literal, so the comment reached the bundle and WGT-9's
grep failed the build. The comment now reads `issue 107` and the guard's docblock
says why — teaching the regex about comments would be teaching it to ignore a
place a colour can hide.

### Verification

| Command | Result |
|---|---|
| `npm run widget:build` | 29 modules, one IIFE |
| `npm run widget:size` | **16.2 KB gzipped, 20.2% of budget** |
| `npm run widget:guards` | clean — 45 keys in both locales |
| `npm run widget:test` | **64 passed** across seven files |
| `npm -w packages/widget run typecheck` | clean |
| `composer test` | 2184 passed, 1 failed — the inherited ENV-10 snapshot |

---

## #106 — The widget shell, and three gates built before there was anything to gate

`packages/widget`: Preact and TypeScript, one IIFE, a shadow root, the loader,
the API client, branding, translations and the analytics events. Everything the
four mounts stand on and nothing a guest can see yet.

### The budget was built first, on purpose

The issue's own note: *"Build the budget check first, before there is anything to
measure — a gate added after the fact is a gate that gets raised instead of
enforced."* `scripts/size.mjs` existed before the first component did.

| | |
|---|---|
| Bundle | **21.7 KB raw, 9.0 KB gzipped** |
| WGT-2 budget | 80 KB gzipped — **11.3% used, 71 KB left** for the four mounts |

Preact rather than React and compiled-in locale bundles rather than fetched ones
are both consequences of that number, and the headroom is what makes #107's
booking walk affordable.

### Three rules about absences, checked by reading the build

`scripts/guards.mjs`, because the issue is right that a behavioural test cannot
see the difference between a colour that came from the API and one that happened
to match:

1. **No hex colour in the bundle** (WGT-9). Every neutral the widget draws is
   `color-mix()` of the operator's own `--kaiki-text` or `--kaiki-background`, so
   there is nothing legitimate for the grep to find — and an operator changing
   one colour changes the rules, the hovers and the error panel with it, which is
   what BRD-8 promises them.
2. **No arithmetic on a `*_cents` value in the source** (WGT-13). The widget
   never computes a price.
3. **Both locale bundles carry the same keys** (WGT-14, whose *"fails the build
   in CI"* is this line).

### What the client does and does not retry

WGT-16, and the two failure modes point in opposite directions. Reads retry twice
with exponential backoff; **writes never retry**, because a failed `POST
/bookings` may have created the booking and lost only the response — retrying
that is how a guest ends up holding two boats. The classification is by **HTTP
method**, not by an endpoint list, because a list is a thing the next endpoint
falls off.

Every request has a ten-second deadline: without one the failure is a spinner
that never resolves, which is the blank widget WGT-16 forbids.

### Deviations and additions

- **The mounts are a registry, not four imports.** #106 builds none of them, and
  a shell that imported four empty components would leave #107 unpicking which.
  `registerMount()` is one line for the next issue and lets this one test the
  state that actually exists: a bundle asked for a mount it does not carry.
- **`data-api` is deliberately not an attribute.** The API origin comes from the
  origin the bundle was served from. A configurable one would let a compromised
  page point a live publishable key at somebody else's server.
- **`widget_theme` moved from `auto` to `light`** in `config/kaiki.php` **and**
  in the `brand_profiles` migration, together — `BrandProfileDefaultsTest` reads
  both and fails when they disagree. `auto` handed the decision to the visitor's
  operating system, so an operator's colours, chosen against white, rendered on a
  dark ground for anybody whose phone was in night mode.
- **`widget-build` left `node-checks`** and took its own required check, which is
  the split the old comment in `ci.yml` promised for M3. `docs/ci.md` and
  `CiGatesTest` moved with it, and the stub test became a test that the script is
  **not** an echo.
- **npm workspaces**, so `npm -w packages/widget` is the command CLAUDE.md
  already documents.
- **jsdom** added as a dev dependency: Vitest needs a DOM to assert a shadow root
  into, and three widgets on one page is the acceptance criterion that cannot be
  checked any other way.

### Verification

| Command | Result |
|---|---|
| `npm run widget:build` | 13 modules, one IIFE, 108 ms |
| `npm run widget:size` | 9.0 KB gzipped, 11.3% of budget |
| `npm run widget:guards` | no hardcoded colour, no price arithmetic, 8 keys in both locales |
| `npm run widget:test` | **37 passed** across five files |
| `npm -w packages/widget run typecheck` | `tsc --noEmit`, clean |
| `composer lint` / `composer stan` | Pint clean; PHPStan level 6, no errors |
| `composer test` | **2184 passed, 1 failed** — the ENV-10 schema snapshot |

The snapshot failure is now **also** this issue's: `widget_theme`'s column default
changed, so the fingerprint has genuinely moved again. It still needs the MySQL 8
connection only CI has.

One test-fixture lesson worth recording: `mockResolvedValue(new Response(...))`
hands back the **same** response object on every call, and a body can be read
once — so the second read threw, the client correctly reported a network error,
and three cache tests failed for a reason that had nothing to do with caching.
The helper now builds a fresh response per call and says why.

---

## #105 — Catalogue search, the party price, and the filter that must actually be off

`GET /api/v1/search` and `book.{platform-domain}/{operator-slug}/search`. The
answer to *"what can I do on Saturday, for four people, leaving from Piraeus"*,
which `GET /availability` cannot give: it answers for one product at a time.

### The contract went first (ENV-28)

`docs/api.md` gained the path, its seven parameters, the `SearchResult` and
`SearchDeparture` schemas, the class **B** rate-limit row and the `max-age=30`
sentence **before** any code existed, and the drift gate stayed green through
both halves — first as a documented-but-unbuilt operation, then as a built one.

### The party price is the feature

A grid showing *"από 65 €"* that charges 162,50 € at checkout is the search
experience guests telephone to avoid, so `party_price_cents` is `pax` guests
priced against the product's base band on the plan resolved for that date —
`PaxLineBuilder`, so PRC-6's rounding order is the arithmetic the checkout will
do. A `quote` product carries **no price at all** (BKG-24) and sorts last;
`price_from_cents` is still in the nested summary, because both numbers are true
and a card can show them together.

### Bounded, and asserted as a shape rather than a number

`CheckSeatAvailability` costs five queries **per product**. Looping it over a
catalogue is the obvious build and would be a hundred queries for a fleet of
twenty. `SearchCatalogue` inverts it: one date, five loads, everything else
decided in PHP.

`SearchQueryCountTest` asserts **a search across twelve trips issues exactly as
many queries as one across two**, which is the claim the criterion actually
makes — an absolute number would be counting the auth path and would need
editing whenever that changed.

Two things had to move for that to be true:

- **`PaxLineBuilder` now prefers the loaded `prices` relation.** It called
  `$plan->prices()`, which queries every time — one per product, invisible from
  the call site. Behaviour is identical; what changed is that a caller is
  allowed to have paid for the rows already.
- **`VesselCalendar::occupiedVesselIds()`** answers "which of these boats are
  busy" for a fleet in two queries instead of two per boat. It went **into** the
  port rather than around it: ADR-0023 makes this class the only one permitted
  to query occupancy, and a bulk read is still a read.

### The disabled filter, which is the issue's own note

*"Hiding it in the template and honouring it in the controller is the version
that passes a visual review."* So `SearchFilters` is read on the way **in** —
by `SearchRequest` for the API and by `SearchPageController` for the page — and a
switched-off filter arrives at the Action as null. `SearchFilterTest` compares
the **result set** of a crafted request against the unfiltered one and asserts
they are identical; a page that merely stopped drawing the control passes a
screenshot review and fails that.

Defaults are the design review's: date, port, party and type on; duration, price
and vessel off. Date and party are **fixed on** — a search with neither is a
catalogue listing, which the home page already is — and the settings screen shows
them disabled rather than hiding them, so an operator does not hunt for a switch
that is not there.

### Deviations and additions

- **The search is a shortlist, and says so.** It applies the conditions a guest
  chooses between and leaves the full AVL-22 ladder to `GET /availability`,
  which the guest reaches next. Re-implementing all seven would be a second copy
  of the engine, and the second copy drifts. The cost — a trip can appear here
  and be refused a minute later — is ADR-0006's existing bargain, stated again.
- **`pax` is one integer, not a band breakdown.** A search box that asked for
  ages would be a booking form; `POST /price-quote` prices a family exactly.
- **A new Filament page** rather than a section on the branding screen, gated on
  the brand profile because the choice is the same job as the logo and the
  colours. No new capability, and crew reach none of the three.
- **`/{operator}/search` is registered before `/{operator}/{product}`**, so a
  trip slugged `search` is shadowed — the same trade `legal` already made, and
  for the same reason.

### Verification

| Command | Result |
|---|---|
| `composer lint` | Pint, clean |
| `composer stan` | PHPStan level 6, **880 files, no errors** |
| `composer test` | **2183 passed, 1 failed** — the inherited ENV-10 schema snapshot |
| Drift gate | green before the route existed and after it landed |

31 new tests across five files: `SearchEndpointTest` (9), `SearchFilterTest` (7),
`SearchQueryCountTest` (3), `SearchPageTest` (6), `SearchSettingsTest` (6), plus
`SearchScenario` in `tests/Support/Api`.

One harness fact worth writing down: **the tenancy package keeps the initialised
tenant for the life of the process**, so a settings change made between two test
requests is not seen by the second. `SearchPageTest` uses a second operator
rather than asserting around it — a property of the test harness, not of the
page.

---

## #104 — The product page, and the graph that has to be two types

The page a search engine lands on and the page an operator sends a link to:
`book.{platform-domain}/{operator-slug}/{product-slug}`, server-rendered, with
HOS-2's structured data.

### `Product` **and** `Event`, which is the issue's own note and the reason for the shape

A boat trip is a product with a price and a series of dated occurrences, and
search engines use the two differently — `Product` earns the price, `Event`
earns the date and the place. So the page carries one `@graph` with one
`Product` and one `Event` per upcoming departure, the `Event`s pointing back at
the `Product`'s `@id` rather than repeating it.

Three decisions inside that are worth more than the shape:

- **`AggregateOffer` with `lowPrice`, not `Offer` with `price`.**
  `price_from_cents` is the cheapest band on the cheapest active plan (#33).
  Stating it as *the* price puts a number in a search result that a family of
  four will never be charged.
- **A quote product has no `offers` key at all.** Not zero, not null — a
  `Product` with `price: 0` is a free boat trip as far as Google is concerned,
  and that listing is not something an operator can undo (BKG-24).
- **Cancelled and blocked departures never reach the graph.** An `Event` for a
  sailing that is not running is worse than no `Event`, because a search engine
  will show it, with a date and a place.

`ProductJsonLdTest` parses every assertion out of the actual script element.
A malformed document is discarded by Google silently, so a test matching the
string `"Event"` would pass on markup achieving nothing.

### The page has two addresses, and one of them is canonical

`PublicProductQuery::find()` answers a uuid as well as a slug — the widget embed
carries the uuid and the two are the same resource. That is the duplicate-content
problem a canonical exists for, so the canonical and both `hreflang` alternates
are always built from the slug, and `ProductPageLocaleTest` requests the uuid URL
and asserts it advertises the slug.

### Deviations and additions

- **`HostedController` was extracted.** #101's controller held the tenant, the
  locale, the alternates and the five shared view variables; #104 was the second
  page that needed all four. The alternative was a copy, and the failure mode of
  a copy here is a page that quietly stops carrying the read-only notice or the
  nonce.
- **`HostedUrl`** replaces four hand-built strings with one. The panel built the
  operator URL by hand because `route()` on the panel's host produces a link
  that 404s; this issue needed the same URL in `booking_url`, `canonical_url`,
  the page's canonical and its alternates.
- **`booking_url` and `seo.canonical_url` are filled.** Both were `null` in
  `docs/api.md` with a comment saying M3 would fill them, and this is the issue
  that built the page they address. `ProductShowTest` asserted `canonical_url`
  was null; that assertion encoded the not-yet-built state and now asserts the
  URL.
- **`JsonLd` was extracted from #103's `FaqSchema`**, so the escaping — the four
  `JSON_HEX_*` flags that stop an operator's `</script>` closing the element — is
  decided once for both blocks rather than twice.
- **The home page's trip cards became links.** They had nowhere to go until this
  issue. The whole heading is the link rather than a "read more" underneath it,
  because a row of identical "read more" links is what a screen-reader user
  hears when they list the links on a page.
- **A trip whose slug is `legal` is shadowed** by HOS-9's page: both routes match
  two segments and Laravel takes the first registered. Reversing the order would
  make the legal page unreachable for every operator, which is the worse of the
  two, so the trade is asserted in `ProductPageTest` rather than left to be
  found.
- **No per-locale slug.** The acceptance criterion asks for "the product slug for
  that locale" and `products.slug` is a single column with a
  `(tenant_id, slug)` unique — per-locale slugs would be a schema change and a
  URL-structure decision, which is an ADR rather than a line in this issue. Both
  locales therefore share the slug and differ by `?lang=`, the convention #101
  settled.

### The flaky test this issue found in #101's file

`HostedPageLocaleTest > it renders every word of content without a single script
tag` failed once in a full run and passed twice after, with nothing changed
between. The cause is not the page: `TenantFactory` uses `faker->company()`,
whose `en_US` last names include `O'Conner` and `O'Hara`, Blade escapes the
apostrophe to `&#039;`, and the assertion compared the **raw** name against the
**escaped** body.

Measured rather than guessed: **44 of 2000 generated company names contain an
apostrophe**, so each such assertion failed about one run in forty-five, and
there were four of them. All four now compare `e($tenant->name)`.

### Verification

| Command | Result |
|---|---|
| `composer lint` | Pint, clean |
| `composer stan` | PHPStan level 6, **866 files, no errors** |
| `composer test` | **2152 passed, 1 failed** — the inherited ENV-10 schema snapshot |
| Route check | `/{operator}/legal` still wins over `/{operator}/{product}`, asserted |

23 new tests across four files: `ProductPageTest` (8), `ProductJsonLdTest` (7),
`ProductPageLocaleTest` (5), `ProductPageNoJsTest` (3), plus `TripPage` in
`tests/Support/Hosted`.

**The one failure is still the ENV-10 schema snapshot**, inherited from #102 and
#103 and unchanged by this issue, which adds no migration. It needs the MySQL 8
connection only CI has.

One fixture mistake worth recording: `TripPage` first set `price_from_cents` on
the factory, and the page rendered no price. The column is **derived** — the
age-band and rate-plan observers rewrite it on every write (#33) — so the value
was null by the time the page ran. The fixture now builds a real rate plan and
band price, which is the fixture being honest about where a price comes from.

---

## #103 — Per-operator FAQ, and the first `<script>` a hosted page has ever carried

A `faqs` table, a panel resource, a sixth home-page block type and a `FAQPage` JSON-LD document. A scope addition from the design review of 4 September, like #102, rather than a §7 requirement.

### The decision the issue is about

`product_id` is **nullable**, and the issue's own note says why the other shape is tempting: a `faq_product` pivot sounds more flexible and forces every general answer to be attached to every trip the operator owns. Tenant-wide by default, product-specific by exception.

Every rule that follows from it is in one place, `BuildFaqList`, rather than in the two templates that need it — the product page and the home page would otherwise be two implementations of "published", and only one of them would get fixed.

### Delivered against the Action for one acceptance criterion

*"Given the hosted product page, when it renders, then it shows the entries for that product plus the tenant-wide ones."* **The hosted product page is #104 and does not exist.** The criterion is therefore met at the seam rather than end to end:

- `BuildFaqList` answers a product with that product's entries **plus** the tenant-wide ones, its own first, and excludes another product's — asserted directly in `FaqRenderingTest`.
- The rendering — the section, the markup, the JSON-LD — is asserted on the home page, through `resources/views/hosted/partials/faq.blade.php`, which is the partial #104 includes.

Stated here rather than left to be discovered: #104 has to include that partial and pass it `BuildFaqList($product)`. It does not re-derive the rule.

### The FAQPage block, and what it put at risk

This is the first `<script>` element a hosted page has ever contained, and #101 asserted there were none. Both halves of that are now asserted deliberately:

| Risk | What was done | Where it is proved |
|---|---|---|
| CSP drops the block silently — `script-src` applies whatever the `type`, and there is no `unsafe-inline` | the element carries the per-response nonce | `FaqSeoTest`: the nonce is read **out of the element** and looked for in the header |
| An operator pastes `</script>` into an answer | `JSON_HEX_TAG\|AMP\|APOS\|QUOT` — the JSON still parses, and the text is returned verbatim | `FaqSeoTest`, by parsing the block, not by matching a string |
| HOS-4's "no script tag" claim | restated as *exactly one*, and that one is `application/ld+json` | `FaqRenderingTest` |

**Every JSON-LD assertion parses.** A malformed block is discarded by Google without a word, so a test that greps for `FAQPage` passes on markup that achieves nothing.

### Deviations and additions

- **`Faq` is added to `HomeBlockType::defaultLayout()`.** Without it, an operator who writes entries and has never opened the page editor sees them nowhere, and the remedy is a step nobody would guess at. Safe because the block renders **nothing** — heading included — when nothing is published, so a page belonging to an operator with no entries is unchanged.
- **`DemoFaqSeeder`**, registered after `DemoBookableSeeder` because one of its entries belongs to a product. Without seeded rows the feature reads as an absence on the demo page. Keyed on (`product_id`, `sort_order`) rather than on the question, which is translatable JSON — matching on it would be the JSON-path query `NoJsonPathQueryTest` scans `database/` for.
- **No ADR-0008 companion columns.** Nothing sorts or filters these; the table orders by `sort_order`, a plain integer. The both-locales rule therefore comes from `TranslatableRequired` at the form rather than from `SearchIndexObserver`, which only watches `TranslatableSearchable` models. The cost is that an import could store a half-translated row; it renders through the I18N-5 fallback, which is better for a guest than a missing section.
- **`FaqPolicy::reorder()`** exists because Filament calls `can('reorder')` before rendering the drag handles, and a policy without the method denies — the feature would vanish with nothing anywhere saying why.
- **`ManageBranding`, not `ManageCatalogue`**, on the #102 argument. The two draw the identical line in the TEN-8 matrix, so this is about which sentence the next reader gets; crew reach neither, which is the criterion.
- **`docs/data-model.md` said M3 adds no tables.** It now documents `faqs` **and** `home_page_blocks`, which #102 added without recording. §2.2 and the M3 migration ordering.

### Verification

| Command | Result |
|---|---|
| `composer lint` | Pint, clean |
| `composer stan` | PHPStan level 6, **854 files, no errors** |
| `composer test` | **2129 passed, 1 failed** — `CiGatesTest > it keeps the committed schema snapshot in step with the migrations` |
| `php artisan migrate` | `2026_09_07_000001_create_faqs_table` … DONE (SQLite) |
| `php artisan db:seed --class=DemoFaqSeeder` | four entries on Aegean Blue: three tenant-wide, one on the sunset cruise |

19 new tests across three files: `FaqRenderingTest` (7), `FaqSeoTest` (5), `FaqAccessTest` (7). `HomePageBlockTest`'s "renders each of the block types" now seeds one question, because the FAQ block is a mount and renders nothing without one — the same exception the empty gallery already had.

**The one failure is the inherited ENV-10 schema snapshot.** #102 made it genuine by adding a table; this issue adds another. The fingerprint can only be regenerated against a MySQL 8 connection, which exists only in CI, which is still blocked on the account's billing. The first green run needs `composer schema:snapshot`, not an investigation.

Two mistakes worth recording rather than tidying away. The CSS comment for the FAQ toggle contained the words `text-transform: uppercase` while explaining why they are not used — and `HomePageBlockTest` and `HostedPageLocaleTest` both assert the string does not appear in the body, which a Blade `/* */` comment does. And a first attempt at "renders no section" asserted the absence of `faq-list`, which is in the stylesheet on every page whether or not a section uses it; it asserts the absence of the `<section>` element instead.

---

## #102 — The editable home page, and the field that is deliberately not there

Five block types, an editor that arranges them, and a renderer that turns none of the operator's text into markup. A scope addition from the design review of 4 September, not a §7 requirement.

### The decision the issue is actually about

An editable page needs a text field, and a text field is where a rich-text editor goes. It did not go there, and the reasoning is in `HomeBlockType`'s docblock rather than in a commit message because it is the thing a future issue is most likely to undo by accident.

The short form: HOS-8 removed `unsafe-inline` from the hosted page's policy, so a pasted `<script>` would not run **there**. It would still be stored, and the same string is the `meta description`, the WordPress SEO post (WPP-6) and an email — three destinations with no CSP between them. Structured blocks mean the markup is ours in all four places.

The accepted cost is stated rather than hidden: an operator cannot centre a word or link a phrase mid-sentence. The `body` help text says so, in both locales, in the operator's own terms.

### `BlockText`, and why the order of two operations is the class

Escape, then add structure. The other order escapes the tags the class just added, and the fix somebody reaches for at that point is to stop escaping.

It is also not `nl2br(e($text))` — that renders a paragraph break and a wrapped line identically, so the operator types paragraphs and gets none. A blank line is a paragraph; a single newline inside one is a break. Markdown was refused for the same reason as raw HTML: every renderer has a passthrough and most have it on.

### The test that matters

`it('escapes an operator who pastes markup, and the page still has no script tag')` asserts `&lt;script&gt;` is present **and** that `<script` is not — the second half being #101's HOS-4 claim, restated because #102 is the issue that could have broken it.

### Settings are normalised on read, not only on write

`settings` is the one JSON column an operator writes into, so it is where an undocumented setting could arrive by accident. `BlockSettings` names every key per type, coerces to the declared type — a form posts `"1"` and `"3"`, JSON hands them back as strings, and `strict_comparison` then silently never matches — and drops the rest.

Dropping rather than refusing is deliberate, because this runs on **read**: a row written against an older shape must render on a page a guest is looking at. The same pass reconciles `source = category` with no category down to `source = all`, since an empty grid on a home page is worse than showing everything.

### The save replaces rather than diffs

One submission can create, update, delete and reorder. A diff needs an id in the form, and a mismatched id writes one block's text over another's — silently, with the operator's public page as the evidence. Replacement is one delete and one insert in a transaction. The cost is that `uuid` changes on every save; nothing points at a block by uuid today, and the docblock says what changes the day something does.

### Deviations and additions

- **The default layout is not saved.** `BuildHomePage` synthesises hero + trips + contact for an operator with no rows — the page #101 already served — so nothing regressed when the editor shipped. Writing them on first render would make a read request write, on the busiest page in the product, in a possibly read-only tenant, on a crawler.
- **`meta description` now comes from the operator's first paragraph** when one exists. The generic line became the fallback rather than the default.
- **Alt text is per image and per locale.** An image with no alt in the current locale falls back to the other rather than to `alt=""`; an image whose description is not written yet is kept rather than costing the operator the other eight photographs.
- **`ManageBranding`, not `ManageCatalogue`.** A block changes what the business says about itself, not what is for sale. Same job as the logo and the colours.
- **No live preview.** The preview an operator wants is the page, which is one link away and cannot disagree with itself.

### Things this touched that were not its own

`hosted/index.blade.php` (rewritten around the block loop), `hosted/layout.blade.php` (block styles), `HostedPageController::index()`, `lang/{el,en}/hosted.php`, `lang/{el,en}/enums.php`, and one allow-list entry for `hosted.blocks.contact.email` — the word Greek operators use, alongside the two already there.

### A trap, for the second time

`asOperator()` as a file-local Pest helper collided with the identical helper in `AuditTrailTest`. That is a **fatal error**, not a failed assertion: the suite stops, in a file unrelated to either. `hosted()` did the same in #101. Helpers now live in `Tests\Support\Hosted\OperatorPage`, and twice is a convention rather than an accident.

### Verification

```
$ vendor/bin/pint --test
$ vendor/bin/phpstan analyse
[OK] No errors

$ php artisan test --exclude-group=mysql --exclude-group=chromium --exclude-group=external
Tests:  1 failed, 2102 passed (6030 assertions)

FAILED  Tests\Unit\CiGatesTest > it keeps the committed schema snapshot in step with the migrations
```

31 new tests across two files: `HomePageBlockTest` (13), `SaveHomePageTest` (9), plus the nine of `HostedPageAccessTest` re-verified against the block renderer.

**The schema-snapshot failure is now caused by this issue as well as inherited.** #101 added no migration and inherited it; #102 adds `home_page_blocks`, so the fingerprint has genuinely changed and the snapshot is genuinely stale. It still cannot be regenerated here — ENV-10's snapshot comes from a MySQL 8 connection that only CI has, and CI is blocked on the billing issue. This is the first issue where that distinction matters, and it is recorded so the first green run is known to need `schema:snapshot` rather than an investigation.

---

## #101 — The hosted page shell, and the route that ate `/app`

HOS-1's `book.{platform-domain}/{operator-slug}`, rendered server-side, in two locales, under a policy with no `unsafe-inline`, with HOS-6's 404, HOS-9's legal footer and HOS-10's read-only page served in full. The first M3 issue, and the first guest surface the platform hosts itself rather than reaching through a token.

### A route that broke eight tests belonging to somebody else

`/{operator}` is one path segment at the root. Registered on every host it matches `/app`, `/admin`, `/up` and any probe route a test file declares — and it did: eight of #7's `TenantResolutionOrderTest` and `ReadOnlyModeTest` cases went red on a change that had nothing to do with tenant resolution.

The first fix attempted was registration order, which is wrong for a reason worth writing down: order is a property of a file, and the next person to tidy `routes/web.php` re-breaks it silently. The fix that holds is `Route::domain((string) config('kaiki.tenancy.hosted_host'))` around the group and `->where('operator', '[a-z0-9][a-z0-9-]*')` on the parameter, and a test that asks for `/aegean-blue-test` on the **default** host, asserts a 404, and then asserts `/app/login` and `/up` still answer. The host is the guard, so the guard is what is asserted rather than the symptom.

Three probe routes in #7's own files had claimed the bare slug this issue now owns. They move to `/{slug}/_probe`, which is not a slug any operator can hold, so they test the resolver rather than the route shadowing it.

### Livewire on a cacheable public page

`HostedPageLocaleTest`'s HOS-4 assertion — `not->toContain('<script')` — failed only when the whole file ran, and passed test by test. Livewire injects its script **and a CSRF token** into every HTML response from the web group once it has booted, so a hosted page served by a worker that had previously served a panel page carried both. On a page a CDN may cache.

`HostedPageHeaders` sets `livewire.inject_assets` to false. The assertion stays in the full-page test rather than moving to one of its own, because a test of its own would have passed.

### The policy, written as three absences

A Content-Security-Policy that is too permissive passes every test asserting the page renders. The three that matter:

- **no `unsafe-inline`** — the brand colours arrive through a `<style>` carrying the per-response nonce. The test extracts the nonce from the **header** and looks for that exact value in the body, so a header and an element that disagreed fail rather than silently drop the operator's colours.
- **no Google Fonts unless the operator chose one** — WGT-10's rule. A policy that always named `fonts.gstatic.com` would make that requirement decorative and would be wrong for every operator who never picks a font, which is most of them.
- **no gateway the operator has not connected** — Stripe is configured platform-side, so a `form-action` naming both would pass a test that only asserted Viva's presence. The test asserts Stripe's absence on a Viva-only operator.

`X-Robots-Tag` is deliberately **empty** here, unlike #86's token pages: a hosted page is exactly what an operator wants indexed, and a copied `noindex` would quietly undo HOS-2.

### Deviations and additions

- **`ResolveHostedTenant` renamed to `HostedPageHeaders`** (alias `hosted.page`). It resolved nothing — the `tenant` middleware already had — and a name describing work it does not do is how the next person adds a second resolution path.
- **`config('kaiki.hosted')` is new**: `powered_by`, `api_origin`, `widget_origin`, `gateway_origins`. Brand decision 6 of 2026-09-04 renders «powered by Kaiki» from the flag rather than the template, so a white-label tier is a config change; the test flips the flag and asserts both directions.
- **`Tests\Support\Hosted\HostedRequest`** holds `url()` and `headers()`. Two Pest files declaring the same helper `function` is a fatal redeclaration, and a helper taking `$this` is a `TestCall` at analysis time, not a `TestCase` — twenty-nine PHPStan errors, the same shape as `$this->fail()` in #89.
- The **editable home page is not here.** `hosted.index` lists active products, which is the default the block system of #102 falls back to anyway.

### Things this touched that were not its own

`bootstrap/app.php` (the alias), `routes/web.php`, and the three probe routes above.

### Verification

```
$ vendor/bin/pint --test
$ vendor/bin/phpstan analyse
[OK] No errors

$ php artisan test --exclude-group=mysql --exclude-group=chromium --exclude-group=external
Tests:  1 failed, 2071 passed (5944 assertions)

FAILED  Tests\Unit\CiGatesTest > it keeps the committed schema snapshot in step with the migrations

$ vendor/bin/pest tests/Feature/Tenancy tests/Feature/Hosted
Tests:  361 passed (709 assertions)
```

25 new tests across three files: `HostedPageAccessTest` (9), `HostedPageHeadersTest` (7), `HostedPageLocaleTest` (9).

The single failure is **ENV-10's schema-snapshot fingerprint**, unchanged since #83 and blocked on the same CI billing issue. #101 adds **no migration**, so it inherits the failure rather than causing one. The MySQL and chromium groups have not run, for the same reason.

---

## #89 — The booking API, the first refused `pk_`, and the booking a phone call makes

**Files:** `app/Http/Controllers/Api/V1/BookingController.php`, `app/Http/Requests/Api/V1/{BookingCreateRequest,CheckoutRequest,CancelBookingRequest}.php`, `app/Http/Resources/Api/V1/{BookingResource,CheckoutSessionResource,CancellationResultResource}.php`, `app/Http/Middleware/{EnforceIdempotencyKey,AuthenticateGuestToken}.php`, `app/Domain/Api/Support/{IdempotencyStore,IdempotencyRecord}.php`, `app/Domain/Booking/Actions/{CreateManualBooking,ImportBooking,MintCheckoutSession}.php`, `app/Domain/Booking/Data/ManualBookingAdjustment.php`, `app/Events/CapacityOverridden.php`, `app/Exceptions/CheckoutRefused.php`, `app/Filament/App/Resources/BookingResource.php` (+ three pages), `app/Http/Controllers/Guest/ManageBookingController.php`, `routes/{api,web}.php`, `bootstrap/app.php`, `config/kaiki.php`, `lang/{el,en}/{api,booking,bookings}.php`, five test files and a scenario class

### The first `pk_` refusal in the product

Every SEC-5 test so far has proved a publishable key *can* do something. §2.1's table is the first place one cannot, and the sentence above it is the reason:

> A `uuid` alone never authorises anything.

A `pk_` is in the source of somebody's home page. A publishable key that could read a booking by uuid would turn every uuid that ever appeared in a redirect URL, an analytics payload or a browser history into a guest's name, phone number and itinerary.

Checkout is the exception, and footnote 1 puts a **state** in it: a `pk_` is accepted *"only while the booking is still `draft` with an unexpired hold"*. Both conditions are checked, and neither alone is enough — a `draft` whose hold ran out is a session the widget no longer owns, and "unexpired hold" without the status would also match `pending_payment`, where a second session started by a publishable key is a second charge waiting to happen.

The check lives in **middleware rather than the controller** because it is an authentication question — whether this credential may act at all. A controller that ran first would already have loaded the booking a `pk_` is not entitled to see.

### The guest-token lookup is scoped here and unscoped on the pages

`GuestTokenResolver::booking()` looks a `manage_token` up `withoutTenancy()` because `/b/{token}` has **no tenant** — the token is what resolves one. This request already has one, resolved by the API key before the middleware ran.

So the API's lookup is tenant-scoped, which is both stricter and more correct. A token from another operator's fleet would otherwise render *inside the wrong tenant*, where #8's global scope hides its own product, vessel and meeting point — and the response is a payload of nulls that reads like a bug in the resource rather than a credential used in the wrong place.

### One idempotency middleware, and the case that gets skipped

§3.4 names five. The fourth — *"replay while the first request is still in flight"* — is the one hand-rolled guards miss, and the obvious check-then-write implementation of it has a race exactly the width of the request it is protecting. `Cache::add()` is atomic on every store the platform uses, so the second caller loses and gets `409 idempotency_in_progress`.

The second-most-skipped: a replayed **create returns `201`, not `200`**. The status is stored rather than inferred, because a client that branches on it — and the widget will — otherwise gets a different answer to the same request.

**The record lives in the cache, not a table.** §3.4 fixes the semantics and the 24-hour retention and says nothing about storage. A table would be a migration, a model, a policy, a purge job and a schedule entry for something worthless after a day that nobody queries by anything but its exact key; the cache expires its own rows, which is the whole of the retention requirement. The honest cost is that a flush forgets in-flight keys — survivable **because the Actions behind these endpoints are each idempotent in their own right**, and a design where the middleware were the only guarantee would be the wrong design.

The body hash sorts object keys recursively before hashing, so a client library that reorders JSON on a retry is replaying rather than being told it has a bug. Values are untouched: `{"qty": 2}` and `{"qty": "2"}` are different requests, and a normaliser that made them equal would hide a real one.

### BKG-32 contradicts itself, and this is the reconciliation

> A manual booking may exceed `min_lead_time_hours` and `max_advance_days` restrictions but **MUST NOT exceed capacity or the legal `capacity_max`** (AVL-25). **Capacity override requires an explicit confirmation and is logged.**

Two sentences that cannot both be true of one number. They are both true of **two**:

- the **legal `capacity_max`** is a certificate rather than a commercial decision, and has no override at all;
- the **departure's own `capacity`** is a number the operator chose, and may be exceeded with an explicit reason, written to the trail as an `override.applied` row carrying the seats requested and the capacity.

An operator may squeeze one more person onto a boat they under-sold. They may not sail illegally full. AVL-25 sharpens it further: it counts *every* person including non-capacity-counting bands, so the infants a commercial capacity ignores are exactly the ones the legal ceiling does not — which is precisely where the two diverge.

`docs/spec.md` marks BKG-32 **RESOLVED by #89** with that reasoning, the third specification contradiction settled this session after CXL-3.3 against ADR-0017 (#84) and BKG-26 against §2.5 (#85).

The lead-time and advance halves needed **no code**: those rules are enforced by the availability *calendar*, which decides what a guest is shown, and never by the draft Action. A manual booking does not go through the calendar, and the panel's departure select says so in its help text rather than leaving an operator to wonder why the list is longer than the website's.

### A CNV-8 breach that a scanner found and review had not

`bookings.pax_breakdown` has carried `age_band_id` since #80. It is rendered straight into `GET /bookings/{uuid}`, so it was an **integer primary key in a public payload** from the moment that endpoint existed — CNV-8's exact prohibition.

`docs/data-model.md` §3.1 has documented the correct shape since M1: `age_band_uuid`, a frozen per-locale `label`, `min_age`, `max_age`, `unit_price_cents` and `total_cents`. The code wrote none of the six. All six now land, the id is gone, and nothing read it back — `SaveAgeBands`'s own docblock says the snapshot exists *instead of* a live join.

Found by walking the response recursively for any key called `id` or ending `_id`, which is the only way a rule about what leaves a payload is kept at all. `BookingResource` still strips `age_band_id` defensively, because bookings created before this issue have one and a frozen snapshot is not rewritten.

### Deviations and additions

**`/b/{token}/ticket` lands here, and belongs to #88.** That issue put the e-ticket on the private disk and said it would be streamed through a controller; the controller was not written. It is now, because `BookingResource.links.ticket_pdf_url` needed something to point at. Noted rather than folded in silently.

**`HoldSeats` gained an `$allowOvercapacity` parameter** rather than a second Action. The lock, the seat arithmetic and the counter write are identical and only the refusal differs; a duplicate hold path would be a **second writer of `seats_held`**, which `NoDirectRedisTest` and `CLAUDE.md`'s first invariant exist to prevent. The AVL-25 check that no override reaches sits beside it.

**`BookingDraftData` gained `skipHold`**, set by exactly one caller. `CreateBookingDraft` takes the hold *without* an override, so a party that does not fit would be refused before the override could apply — skipping it there and taking it in the manual Action is the only ordering in which BKG-32's override exists at all.

**`CreateBookingDraft::insertWithReference()` became public.** `ImportBooking` writes a `bookings` row too and needs the same collision retry (BKG-3, ADR-0007). A second copy of that loop is the copy that swallows a foreign-key violation.

### Things this touched that were not its own

1. **`tests/Unit/Architecture/NoDirectRedisTest.php`** — the hold-column scanner flagged two API resources that **read** `hold_expires_at` into a payload, which the contract requires (§5's countdown). Filtered **by shape rather than by file**, like the existing `casts()` rule: a line is exempt only when its right-hand side reads the very column its key names, so `'hold_expires_at' => $expiresAt` from a resource would still fail. Written as two literal patterns rather than one with a backreference — a bare `\1` inside a PHP double-quoted string is `chr(1)`, and the pattern silently matches nothing, which for a lint means it silently stops linting.
2. **`tests/Support/I18n/allow-list.php`** gained the two `email` labels, which are the word Greek operators actually use — «ηλεκτρονικό ταχυδρομείο» appears on no real form.
3. **`app/Models/Booking.php`** gained `@property` lines for `checked_in_at`, `completed_at` and #88's three e-ticket columns; PHPStan could not otherwise see them as dates.
4. **`tests/Support/Api/OpenApiContract.php`** learned about `api.guest`, which is the first middleware that can *remove* `PublishableKey` from an endpoint's accepted schemes. Without it the ENV-28 drift gate would have reported a security mismatch on three of the four new endpoints.
5. **`app/Filament/App/Resources/BookingResource.php` is new**, because there was no booking resource in the panel at all. It is a list, a view and BKG-30's create form — deliberately **not** an edit form: changing a confirmed booking's party size, date or total moves seats, invalidates a price snapshot and contradicts a policy snapshot the guest was shown, and each of those has an Action that does it properly.

### Verification

```
$ vendor/bin/pint --test
$ vendor/bin/phpstan analyse
[OK] No errors

$ php artisan test --exclude-group=mysql --exclude-group=chromium --exclude-group=external
Tests:  1 failed, 2046 passed (5866 assertions)

FAILED  Tests\Unit\CiGatesTest > it keeps the committed schema snapshot in step with the migrations
```

55 new tests across five files: `BookingEndpointTest` (12), `IdempotencyTest` (10), `BookingCredentialTest` (10), `ManualBookingTest` (13), `ImportedBookingTest` (10).

```
$ php artisan test tests/Feature/Api/OpenApiDriftTest.php
contract surface: 11 of 18 operations built
Tests:  7 passed
```

The single failure is **ENV-10's schema-snapshot fingerprint**, unchanged since #83 and blocked on the same CI billing issue. #89 adds **no migration**, so it inherits the failure rather than causing one.

The MySQL and chromium groups have not run, for the same reason.

---

## #88 — The e-ticket, the check-in on a pier, and an M6 table landing early

**Files:** `database/migrations/2026_09_02_000041_create_charter_agreements_table.php`, `database/migrations/2026_09_06_000003_add_ticket_and_check_in_columns.php`, `app/Models/CharterAgreement.php`, `app/Enums/AgreementStatus.php`, `app/Exceptions/{AgreementEvidenceLocked,CheckInRefused}.php`, `app/Domain/Booking/Support/{CheckInWindow,TicketQr}.php`, `app/Domain/Booking/Data/CheckInOverride.php`, `app/Domain/Booking/Actions/{GenerateETicket,CheckInGuest,MarkNoShow,CompleteDepartures}.php`, `app/Events/{BookingCheckedIn,BookingCompleted,CheckInOverridden}.php`, `app/Listeners/Booking/GenerateETicketOnConfirmation.php`, `app/Jobs/CompleteDeparturesJob.php`, `app/Filament/App/Pages/CheckIn.php` (+ its view), `app/Policies/CharterAgreementPolicy.php`, `resources/views/pdf/e-ticket.blade.php`, `lang/{el,en}/{ticket,checkin}.php`, `routes/console.php`, `config/kaiki.php`, `.github/workflows/ci.yml`, `composer.json`, `package.json`, five test files and two support classes

### A contradiction with three sides, and it is a security decision

The issue's acceptance criterion says the QR carries the booking's **`manage_token`**. `docs/data-model.md` §2.5 says two other things in the same table: `uuid` is *"encoded in the QR ticket"*, and `ticket_code` is *"globally unique; the QR payload"*.

Three answers to one question. The issue's *reasoning* is right — a reference is guessable, and BKG-3's alphabet is about 28.6 million per tenant, which is a printer and a morning. Its *prescription* is wrong, twice:

1. **`manage_token` is per booking.** A family of four would carry four identical QR codes, and the per-guest check-in BKG-21 (*"at least one guest"*) and BKG-23 (*"per guest"*) both require would be impossible to build.
2. **`manage_token` is the credential for `/b/{manage_token}`**, where a guest can **cancel the booking and take a refund** (TOK-6). Printing it on a sheet of paper that gets handed round, photographed and left on a seat puts the cancel button in anybody's hands.

`uuid` is out for a third reason: CNV-8 makes it the *public* identifier that appears in API responses and widget payloads, and a value that legitimately travels is not a credential.

**`ticket_code` wins, and the schema already knew.** `booking_guests_ticket_code_unique`'s own rationale in the index table reads *"QR scan at check-in resolves with one indexed read and no tenant context"* — which is precisely a crew member standing on a pier with no session and no subdomain. It is per guest, it is forty bits of CSPRNG output past what a printer can brute-force, and it can do exactly one thing, for somebody already signed into the operator's panel.

`docs/data-model.md` §2.5 now carries the three-way comparison and the `uuid` note is corrected. The issue's criterion is knowingly not met as written, and this is the record of why.

### BKG-22 offers an override for one edge and not the other

- **Early** is an operator decision. `CheckInOverride` refuses to be constructed without a reason (CXL-5's pattern, because a Filament validation rule binds one screen and the API and the console command skip it), and `CheckInOverridden` writes an `override.applied` row beside the refund overrides.
- **Late** has no way through. A check-in recorded after `ends_at_utc` is not an early judgement call; it is a false manifest. There is no parameter on `CheckInGuest` that permits it, and a test passes an override at the late edge to prove it is ignored.

*"Which is logged"* is the clause that gets skipped, and a log line does not satisfy it: it rotates away, and no operator can read one. The row carries the **minutes early** alongside the reason — "four minutes" and "four hours" are different decisions, and neither is legible from a timestamp pair somebody has to subtract by hand a year later.

### The DST trap, in its purest form

BKG-21: *"at `ends_at_utc` plus 3 hours"*. That is an instant plus a duration, and it is the same instant on either side of a clock change. Computing it as *"local finish time plus three hours, converted back"* looks equivalent, reads more naturally, and is wrong by an hour on the last Sunday in October — inside the Greek season, on a day boats are sailing.

`CompleteDepartures` contains no timezone at all, and `CompletionSweepTest` brackets the change with a pair on 24 and 25 October 2026. Neither test alone proves anything: an implementation that used local arithmetic passes the first and fails the second.

### `confirmed` completes as well as `checked_in`

BKG-21 names both, and it is easy to read as a slip. It is not. Small operators do not scan tickets on a six-person day boat, and leaving those bookings `confirmed` forever gives an operator a growing list of trips that apparently never ended — and makes *"how many trips did we run"* a question the platform cannot answer.

Absence of a scan is not evidence of a no-show. BKG-23 makes that an explicit, separate, reversible mark that **does nothing else**: no refund, no cancellation, no seat released, no event, no audit row. That reads like an omission and is the requirement's own last clause. The money question is settled from the policy snapshot frozen at booking (CXL-1) at the moment somebody actually refunds; an action that also moved money would apply today's policy to a booking made under last season's.

### The one Filament surface with a real phone requirement

BKG-20 says *"works on a phone"*, which everywhere else in `/app` would be a courtesy. Here the user is standing on a pier in the sun, holding a phone in one hand, with somebody waiting in front of them — and three things follow, which is why this is a Page and not a Resource:

- the scan box is **first and focused**, so a hardware scanner's carriage return lands in it rather than nowhere;
- the QR encodes a **URL**, so a phone camera opens `?ticket=…` and resolves the guest with no form submission at all;
- check-in is **one tap with no confirmation modal**, because the confirmation is the person standing there.

TEN-8's half is structural rather than conditional: there is no price, no payment status and no document number **in the template**, absent rather than hidden behind a role check, because a condition is one edit away from being wrong.

### The `chromium` group had never run anywhere

ENV-20 says *"CI always runs them"*. The group has existed since #2, `composer test` excludes it, and **no CI job referenced it** — so a requirement about PDF rendering was being enforced by nothing at all, and would have stayed that way until somebody opened a blank ticket.

`pdf-chromium` now installs Puppeteer with `PUPPETEER_SKIP_DOWNLOAD`, points it at the runner's own Chrome, runs `--group=chromium`, and is in `ci-passed`'s `needs:`. Locally the four render tests skip through a `->skip()` modifier carrying a sentence that names `KAIKI_CHROME_PATH` — never a silent pass, for the reason `requiresMysql()` gives about SQLite.

### Things this touched that were not its own

1. **`composer.json`** gained `spatie/browsershot` and `bacon/bacon-qr-code`. Both are pre-approved — Browsershot by ARC-20 and ARC-10 (*"dompdf is forbidden"*), the QR library by ADR-0019's named shortlist, whose rule is that each is *"installed only when first used and cited in the pull request that adds it"*. This is that citation.
2. **`package.json`** gained `puppeteer`. Browsershot drives Chromium through Puppeteer's Node bridge, so it is Browsershot's runtime rather than a new decision; the bundled 150 MB Chromium is skipped in both CI and local install.
3. **`.github/workflows/ci.yml`** gained `pdf-chromium`, and `docs/ci.md`'s required-checks table gained its row — `CiGatesTest` asserts the two agree, and caught the missing row on the first run.
4. **`lang/{el,en}/enums.php`** gained the `agreement_status` block; `EnumLabelCoverageTest` requires every backed enum to have one in both locales.
5. **`app/Models/Product.php`** gained `DEFAULT_CHECK_IN_OFFSET_MINUTES`, so `CheckInWindow` has something to fall back on when the product is not loaded without a bare `30` drifting from the migration.
6. **`resources/views/pdf/e-ticket.blade.php` renders times with a `null` timezone**, not `config('app.timezone')`. `DateTimeFormatter` resolves the **tenant's** zone when handed none, and the application default is UTC — the first render printed a Greek departure three hours early, on the one document a guest reads standing at a quay. Caught by a test asserting the check-in time, which is why that assertion is against the markup rather than the PDF.

### Verification

```
$ vendor/bin/pint --test
$ vendor/bin/phpstan analyse
[OK] No errors

$ php artisan test --exclude-group=mysql --exclude-group=chromium --exclude-group=external
Tests:  1 failed, 1991 passed (5699 assertions)

FAILED  Tests\Unit\CiGatesTest > it keeps the committed schema snapshot in step with the migrations
```

The single failure is **ENV-10's schema-snapshot fingerprint**, which needs the MySQL 8 snapshot only CI can generate — the CI billing block in the Status table above. The same failure stands on #83, #85 and #87.

**The `chromium` group was run for real**, against the Chrome installed on this machine, rather than left to a CI job that cannot start:

```
$ php artisan test --group=chromium
✓ it generates a PDF and stores it on the private disk
✓ it never writes a ticket to the public disk
✓ it regenerates in place rather than versioning
✓ it is generated by a queued listener on confirmation
Tests:  4 passed (9 assertions)
```

A ticket was also rendered from the seeded demo tenant and looked at: two pages, one per guest, Greek labels, both QR codes scannable, the meeting-point and vessel rows correctly absent where the demo booking has none.

```
$ composer i18n:check
Tests:  159 passed (503 assertions)
```

The MySQL group has not run, for the same billing reason.

---

## #87 — Notifications, and the reminder that must not arrive after the boat has left

**Files:** `database/migrations/2026_09_02_000040_create_notification_logs_table.php`, `app/Models/NotificationLog.php`, `app/Enums/{NotificationChannel,NotificationStatus,NotificationProvider,NotificationTemplate}.php`, `app/Contracts/SmsGateway.php`, `app/Domain/Notifications/Gateways/{ApifonSmsGateway,TwilioSmsGateway,NullSmsGateway}.php`, `app/Domain/Notifications/Support/{SmsComposer,SmsGatewayResolver,QuietHours,QuietHoursDecision}.php`, `app/Domain/Notifications/Actions/{SendNotification,SendDueReminders,RetryNotification}.php`, `app/Domain/Notifications/Data/SmsResult.php`, `app/Mail/GuestMail.php`, `resources/views/mail/booking/{html,text}.blade.php`, `app/Listeners/Booking/SendBookingConfirmation.php`, `app/Jobs/Reminders/SendDueRemindersJob.php`, `app/Providers/NotificationServiceProvider.php`, `app/Filament/App/Resources/NotificationLogResource.php` (+ its list page), `app/Policies/NotificationLogPolicy.php`, `lang/{el,en}/{mail,notifications}.php`, `routes/console.php`, `config/kaiki.php`, six test files and a factory

### The requirement whose second half is the whole point

BKG-18 reads:

> *No SMS between 21:00 and 08:00 local time. A reminder that would fall in the window is sent at 08:00, or **dropped and logged** if 08:00 is after the event.*

Deferring to 08:00 is four lines. The clause after the comma is the one that gets skipped, and its failure mode is a *"your trip is tomorrow"* text arriving while the guest is already standing on the boat.

`QuietHours::decide()` therefore returns **three** outcomes rather than a nullable time — send now, send at 08:00, drop — because a null cannot carry a reason, and the requirement says *dropped **and logged***. A reminder that simply vanished is unanswerable when an operator asks *"why did my guest not get the text"*. The dropped row is written with `quiet_hours_would_deliver_after_the_event`, which `NotificationLogResource::explain()` turns into a sentence naming 08:00.

The window is **tenant-local wall-clock**, and two of the nine tests bracket a clock change to prove it: Athens is UTC+3 in July and UTC+2 in January, so 05:00 UTC is 08:00 local for half the year and 07:00 for the other half. A comparison written against UTC hours passes in summer and silently misfires all winter.

### A sweep, not one delayed job per reminder

BKG-13.7 says *"schedule the reminder jobs"*, and the obvious reading is `dispatch()->delay()` once per reminder at confirmation time.

That is the reading that fires anyway. Between confirmation and departure the departure can move, the balance can be paid, the guest details can be completed and the booking can be cancelled — and there is no handle on a queued job to cancel it.

So `SendDueReminders` runs every fifteen minutes, derives BKG-16's schedule from each booking **on the pass**, and evaluates every one of BKG-16's own "suppressed when" conditions at send time. A guest who finishes their passport details an hour before the 48-hour reminder does not get one. Nothing is scheduled ahead, so nothing has to be unscheduled.

### The idempotency key needed a third column, and the bug it prevents is silent

BKG-16 asks for *"idempotent per booking per reminder type"*, which is one indexed read on `notif_logs_tenant_tmpl_idx`.

Written that way it is wrong. BKG-13's confirmation goes out as an **email and a text**, both under `booking_confirmed` — so a key of (booking, template) lets whichever went first suppress the other. It was written that way first, and the SMS test caught it: the log looked entirely healthy and the guest never got the message. `alreadySent()` takes the channel.

Two status decisions inside the same method:

- A `failed` row is **not** counted as sent. Otherwise one provider outage becomes a message the guest never receives and which no later pass ever retries.
- A `bounced` row **is**. Re-sending to a mailbox that rejected us produces a second bounce and damages the sending domain; NTF-8 flags the booking so a person telephones instead.

### The log row is written before the send

A log written on success is a log of the sends that worked, which is the log nobody needs. The row goes in as `queued` and is updated with whatever came back, so a crash between the two leaves evidence of exactly what was about to happen — and BKG-14's retry button has a record to act on, which a listener that only threw would not have left.

### NTF-5 is an arithmetic requirement, and Greek is where it bites

GSM-7 fits 160 characters in a segment and does **not** contain the Greek lowercase alphabet. An ordinary Greek sentence forces UCS-2 and the segment becomes **70**. `SmsComposer` counts what the operator is billed for rather than what `strlen` returns:

- the GSM-7 alphabet and its escape table, where `€ { } [ ] ~ ^ |` cost **two** septets each;
- the six-character concatenation header, which drops a multi-segment message to **153** per part in GSM-7 and **67** in UCS-2 — dividing by 160 under-counts exactly the messages that matter;
- a budget in segments (`kaiki.notifications.sms_max_segments`, default 2), with the **lead trimmed** and the meeting point, the time and the link never touched, because NTF-5 fixes those three as mandatory and a text without a meeting point is a phone call.

Ten tests, including the mixed Greek/Latin string that is one character over the UCS-2 boundary.

### `NullGateway` is a provider, not an absence

NTF-2 names it as the fallback. It records `null_gateway` in the log rather than skipping the row, so a tenant with no SMS account still gets every message composed, counted and logged — and an operator can *see* that their reminders are being written and dropped, which is a five-minute fix. A silent skip is indistinguishable from a broken platform.

### Deviations from the issue as written

**MJML is not used.** The issue names it as an npm dev dependency with compiled HTML committed. Two templates do not justify a Node toolchain, a build step and a second source of truth for every wording change; hand-authored table-based HTML with inline styles satisfies NTF-6, and `TemplateSnapshotTest` asserts the properties MJML would have been bought for — no `<img>`, a plain-text part on every message, no `text-transform`.

**BKG-13's confirmation email and SMS are one listener, not two.** They share the template, the NTF-4 locale resolution and the "did the guest give us a phone number" decision. They stay independently visible because the log has a row per channel, which is what BKG-14 asks the panel to show. The other six of BKG-13's nine listeners are named in `NotificationServiceProvider`'s docblock with the issue or milestone that owns each, rather than left to be rediscovered.

**Listeners are registered explicitly, not by discovery.** `Event::listen` in a dedicated provider, so the nine-row table above it is checkable against the code beside it. Auto-discovery makes "which listeners fire on `BookingConfirmed`" a question answerable only by running the application.

### Things this touched that were not its own

1. **`lang/{el,en}/enums.php`** gained label blocks for the four new enums — `EnumLabelCoverageTest` requires every backed enum to have one in both locales.
2. **`tests/Support/I18n/allow-list.php`** gained `Apifon`, `Twilio` and `Postmark` as vendor wordmarks identical in both locales — the treatment Viva Wallet and Stripe already had — and `webhook`, which has no Greek word this audience would recognise.
3. **`config/kaiki.php`** gained the `notifications` block: the two providers' hosts (CLAUDE.md's rule that no endpoint is ever written into a class), a ten-second timeout, the gateway map NTF-2's fallback is an entry in, and `sms_max_segments`. The quiet-hours boundaries are **not** here: 21:00 and 08:00 come from BKG-18 rather than from a deployment, and a configurable value invites somebody to widen the window instead of arguing with the requirement.
4. **`routes/console.php`** gained the fifteen-minute sweep and a daily `model:prune` for `NotificationLog` — §2.7's twelve months, which is a retention rule and therefore has to be executed by something, not merely documented.
5. **`bootstrap/providers.php`** registers `NotificationServiceProvider`.

### Verification

```
$ php artisan test --exclude-group=mysql --exclude-group=chromium --exclude-group=external
Tests:    1 failed, 1930 passed (5552 assertions)

FAILED  Tests\Unit\CiGatesTest > it keeps the committed schema snapshot in step with the migrations
```

```
$ vendor/bin/pint --test
$ vendor/bin/phpstan analyse
[OK] No errors
```

The single failure is **ENV-10's schema-snapshot fingerprint**, inherited by every issue since #83 that adds a migration. The snapshot is generated against MySQL 8, and CI is the only place with a MySQL 8 connection — which is the CI billing block in the Status table above, not a defect in this issue. The same failure stands on #83, #85 and now #87.

The MySQL and chromium groups have not run for this issue for the same reason.

---

## #86 — The four tokenised guest pages, where a URL is a credential

**Files:** `app/Domain/Booking/Support/GuestTokenResolver.php`, `app/Domain/Booking/Actions/SaveGuestDetails.php`, `app/Http/Middleware/{GuestTokenPage,ThrottleTokenLookups}.php`, `app/Http/Controllers/Guest/{GuestPageController,ManageBookingController,GuestDetailsController,QuoteController,VoucherController}.php`, `resources/views/guest/**` (layout plus five pages), `routes/web.php`, `bootstrap/app.php`, `lang/{el,en}/guest.php`, `tests/Support/Secrets/CredentialLeakScanner.php`, five test files and a shared scenario class

### The requirement that is not satisfied by doing what it says

TOK-4 asks for *"a generic branded 'link not valid' page … never a distinction between 'not found' and 'expired'."* Write that page and the requirement looks met.

The issue's own note is the half that matters:

> A found-but-expired token that hits the database and a not-found token that short-circuits are distinguishable by response time, and that is the same oracle in a slower form. Look the token up the same way in both cases.

So `GuestTokenResolver` has **no shape check**. A forty-character token, a three-character one and an empty string all reach the same query. The length guard that would obviously belong at the top of `booking()` is deliberately absent and says so in a comment, and `isWellFormed()` exists only for tests to assert the *generator* with — never to gate a lookup.

The test asserts the two bodies are **byte-identical**, not equivalent. The distinction TOK-4 forbids is exactly the kind that creeps back as one helpful extra sentence.

### Two rate limits, and the split is the design

Thirty lookups a minute is **usability**; ten failures a minute is **security**.

One limit of thirty would give an attacker thirty guesses a minute. One limit of ten would throttle a guest who opened their booking in three tabs waiting for a bank app. The two questions are different and so are the numbers.

Two orderings inside that:

- The **failure** counter is incremented *after* the response and only on a 404. Counting on the way in would lock a guest out of their own booking for refreshing it.
- The failure limit is checked *before* the lookup. Somebody who has spent ten guesses this minute does not get an eleventh however good the token they finally arrive at — which is what stops a guesser confirming a hit.

### `Referrer-Policy` is the header that gets forgotten

`noindex` stops a search engine and `no-store` stops a cache; both are the obvious two. `no-referrer` is the one that matters most here, because `/b/{manage_token}` carries a **map link** (TOK-6) — and a guest who taps it sends the whole URL, token and all, to a map provider in the `Referer` header. So does the font stylesheet, and every image on a page that has one.

All three are middleware. A header set in a controller is a header the fifth token page forgets, and the test asserts them on the **failure page** too — the one an implementation is most likely to build outside the group, because it is "just an error".

### A tenancy bug the scope caught, and the fix that is not eager loading

`response()->view()` defers rendering until the response is **sent**, which is long after `Tenancy::forTenant()` has handed the context back. The first lazy `$booking->product` in the template then throws `TenantContextMissingException`.

That is #8's global scope working exactly as intended. The mistake was rendering outside the tenant, not the scope catching it.

Eager-loading everything the template touches was the other available fix and is the fragile one: it works until somebody adds `$booking->vessel` to the view. `GuestPageController::renderInTenant()` renders **inside** the tenant and returns finished HTML, which is true for whatever the template reaches for.

### TOK-7's nil refund is shown

Where the policy yields nothing, the cancel action still appears, says plainly that no refund is due, and still releases the seat. The reasoning is commercial: an operator would far rather have the place back to resell than have a guest conclude the button is broken and not turn up.

### TOK-13 is asserted, not implemented

Every Action behind these forms was already idempotent, each in its own way — `CancelBooking` returns early on a booking already cancelled, `MintBalanceSession` reuses an open payment row, `ApplyGuestChoice` uses a conditional update a second click loses. Implementing it at the controller would have protected this page and left the API and the panel exposed to the same double click.

The tests submit twice, which is how the requirement words it.

### TOK-10, tested here so it is not discovered in M6

`/g/{guest_details_token}` stays reachable after the deadline and after departure — **read-only, not gone**, because a 404 on a link in a guest's own inbox is indistinguishable from the security failure TOK-4 is about.

After the document purge it still renders: a missing document number is a rendering branch, exactly as the issue asked. A purged row also counts as **complete** rather than flipping the booking back to `pending`, which would put a departure that already sailed into the reminder scheduler.

### Verification

| Check | Result |
|---|---|
| `composer lint` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **1864 passed**, 1 failed: the ENV-10 schema-snapshot fingerprint |
| `tests/Feature/Guest` | 42 passed |

**No migration**, so the snapshot failure here is inherited from #83's two and #85's three rather than added by this issue. CI is still blocked on account billing.

### Things this touched that were not its own

1. **No migration for the ναυλοσύμφωνο evidence.** TOK-8 asks for a checkbox capturing timestamp and IP; `bookings.terms_accepted_at` and `bookings.ip_address` already exist, and §2.5 describes the second as *"also the ναυλοσύμφωνο acceptance evidence"*. #88's issue title says the `charter_agreements` columns must land early — the *acceptance* evidence already did, in #80.

2. **`CredentialLeakScanner` gained `document_number`.** SEC-14 and GDR-4 make the same demand about a different secret — never a log line, never an exception context — and a second scanner for it would be a second scanner to keep in step. The **Blade-echo** rule deliberately does not cover it: TOK-8 requires the form to render a guest their own passport number so they can correct it, on a page whose URL is already the credential, and what SEC-14 forbids is logs and exceptions, which the sink rules do cover.

3. **Two Pest sharp edges.** `toContain()` is variadic, so a failure message passed as a second argument silently becomes a second needle — a test asserting something nobody wrote. And chaining `->not` after `->toContain()` on a `string|false` expectation is not something PHPStan can follow; both were rewritten as plain `str_contains` with an explicit message.

4. **`Product` has `title`, not `name`.** Two views reached for `->name`, which `$guarded = []` cheerfully accepted into an insert and SQLite refused. Caught by a test rather than by a page.

---

## #85 — Quotes and enquiries, and the boat a quote does not hold

**Files:** three migrations (§6 items 37–39), `app/Enums/{QuoteStatus,QuoteLineKind,EnquiryStatus}.php` plus a case on `CancelReason`, `app/Models/{Quote,QuoteLineItem,Enquiry}.php`, `app/Domain/Booking/Actions/{BuildQuote,SendQuote,AcceptQuote,DeclineQuote,ExpireQuotes,SubmitEnquiry}.php`, `app/Domain/Booking/Support/QuoteSnapshot.php`, `app/Domain/Booking/Data/EnquiryData.php`, `app/Jobs/ExpireQuotesJob.php`, `app/Events/{QuoteSent,QuoteAccepted,QuoteDeclined,EnquiryReceived}.php`, `app/Http/{Controllers/Api/V1/EnquiryController,Requests/Api/V1/EnquiryCreateRequest,Resources/Api/V1/EnquiryResource}.php`, `app/Policies/{Quote,QuoteLineItem,Enquiry}Policy.php`, `app/Filament/App/Resources/{Quote,Enquiry}Resource*`, `routes/{api,console}.php`, `config/kaiki.php`, `lang/{el,en}/{quotes,enums,api}.php`, five test files and a shared scenario class

### The requirement whose reason is in the requirement

BKG-25 is marked RESOLVED and carries its own justification: *"otherwise a quote request would block a vessel indefinitely."*

That is the whole shape of the feature. A `quote_requested` booking holds **nothing** — no `hold_expires_at`, no `vessel_blocks` row, no counter — and an operator with a dozen open enquiries would otherwise have a boat nobody can buy with nothing in the panel saying why.

The hold is opt-in at the moment of **sending**, it is an ordinary block an operator can see in their own calendar, and its expiry is **equal** to `valid_until`. Not close to it. Two dates that are supposed to match and are entered separately are two dates that will not match.

In the panel the toggle defaults to **off**, which is the requirement rather than a preference, and a test asserts the default rather than the option.

### The re-check §4.4 calls the most important behaviour in quote mode

> *"the boat is not reserved while the guest thinks about it. Acceptance re-checks availability and can fail."*

Read again under a lock, at the instant of acceptance, through `OccupationCollector` — the same reader the calendar and the availability endpoint use, because ADR-0023 hides that union behind one port so a second opinion about "is this boat busy" cannot exist.

On a refusal the guest is told the date has gone, and **the quote stays `sent`**. Marking it `declined` would be tidier and would lose the operator's work over a race the guest did not cause.

### Two orderings that are not obvious and are both load-bearing

1. **The quote's own hold is released before the read, not after.** A quote that held the boat and then refused to let the guest accept *because the boat was held* is a perfect little deadlock, and it is the implementation anybody writes first.

2. **That release happens inside the transaction.** So a refusal rolls it back with everything else, and an operator's hold survives a failed acceptance. Releasing it outside would give the boat away on the one path where the guest did not get it.

### `quote_declined` was in the spec and not in the data model

BKG-26: *"Declining transitions to `cancelled` with reason `quote_declined`."* §2.5's `bookings.cancel_reason` enumerated seven reasons and that was not among them.

Added, and §2.5 reconciled. Folding it into `guest_request` would have been invisible and would have destroyed the one number a quote-mode operator most wants — how many of my offers get turned down. That is a pricing signal, and a guest cancelling a confirmed booking is not.

### An operator's free text, in §3.4's shape, and refunds are the reason

BKG-27 permits free-text lines and constrains everything else: integer cents, and *"still produces a `price_snapshot`."*

`RefundCalculator` takes a frozen policy and a `paid_cents`; CXL-1 makes that the only route to a refund. A quoted booking carrying its totals in some other shape would be a booking the cancellation path could not price. §3.4's `source` field has `quote` in its enumeration for exactly this, and the last test in `QuoteSnapshotTest` feeds an accepted quote's snapshot to the same calculator every other booking uses, unmodified.

Two mappings inside it:

- **`charter` becomes §3.4's `fee`.** It is money the guest pays per booking rather than per person, which is what `fee` means there. Widening §3.4's line kinds would have been the other option and would have touched every existing reader of a snapshot.
- **No `vat_category`.** ADR-0002 and CAT-11a put the AADE category on the `vat_rates` row and state that the myDATA client *"contains no percent→category mapping"*. A quote carries a rate in basis points and no category; inventing one here would put that mapping back, in the one place nobody would look.

`rate_plan_id` and `season` come back **null**, and honestly so: there was no rate plan and no season, and the provenance for "why this price" is `quote_line:{id}` and the quote row.

### The spam filters are cheap and the code says so

BKG-29 asks for a honeypot and a timing check with no third-party CAPTCHA. Neither stops anybody who is trying — a honeypot is visible in the DOM, and a client-supplied `form_rendered_at` is a number the client chose. What they stop is the scripts that POST to every form they find, which is most of what this endpoint gets. The defence is §3.6's class F: **5 per IP**, four times tighter than anything else in the contract.

Writing that down is the point. A filter whose limits are stated is a filter nobody mistakes for security.

**The timing check is bounded at one end only**, which the issue's own note asked for: too fast refuses, too slow does not. A guest interrupted by a phone call for forty minutes is not a bot, and an "implausibly slow" rejection would refuse exactly the careful enquiries an operator most wants. A missing or unparseable timestamp is accepted — an older client is not an attacker — and so is a future one, which is a clock-skewed browser rather than an attack.

### A contract change, made deliberately

`docs/api.md`'s `EnquiryCreateRequest` gains `form_rendered_at`. §10.5's rule is that the direction of authority runs contract → code, and when the two disagree the fix is *"to change the code, or to change the contract **and say why in `CHANGELOG.md`**"*. There was no field for a timing check and BKG-29 requires one; the reason is in the changelog and in the schema's own description.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate` (SQLite) | three migrations clean |
| `composer lint` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **1822 passed**, 1 failed: the ENV-10 schema-snapshot fingerprint |
| `tests/Feature/Quote` + `tests/Feature/Api/EnquiryEndpointTest.php` | 42 passed |

**The snapshot could not be refreshed** — CI is still blocked on account billing. See the CI row at the head of this file.

### Things this touched that were not its own

1. **`CancelReason::QuoteDeclined`**, and §2.5's list with it. See above.

2. **Three policies, because `PolicyCoverageTest` insisted.** Filament *allows* an action when no policy is registered, so a model reachable through a relation manager is writable by every role with nothing in the code saying so. `QuoteLineItemPolicy` exists for that reason and shares `QuotePolicy`'s capabilities exactly — an authorisation that let somebody edit the lines but not the quote would be a way round the policy that governs the total.

3. **Crew read neither screen**, and the quote one changed during the work. `ViewPaxList` was the convenient reading — crew meet the chartered party, after all — and `PaymentPolicy` already had the argument written down: what a guest paid *"is not their business and is the kind of thing that ends up discussed on a quay."* A quote is that sentence in advance. `ViewFinancials`.

4. **`QuoteFactory` tripped `NoHardcodedVatRateTest`** with a `vat_rate_bp` of 1300 — a statutory percentage in the source, which ADR-0002 forbids anywhere. Zero, as `BookingFactory` already does; the rates stay unseeded until an accountant supplies them (CAT-11b).

5. **The Greek `email` label**, caught by the I18N-3 parity gate: identical strings in both files read as untranslated, which is exactly the check working.

---

## #84 — Cancellation and refunds, and the arithmetic two documents disagreed about

**Files:** one migration (six columns, no item number), `app/Enums/{WeatherChoice,RefundMethod}.php`, `app/Domain/Booking/Data/RefundOverride.php`, `app/Domain/Booking/Support/RefundEntitlement.php`, `app/Domain/Booking/Actions/{CancelBooking,RefundBooking,CancelDeparture,ApplyGuestChoice}.php`, `app/Domain/Pricing/Actions/{IssueVoucher,RestoreVoucher}.php`, `app/Jobs/{ExecuteGatewayRefund,ApplyWeatherChoiceDefaults}.php`, `app/Events/{BookingCancelled,BookingRefunded,RefundOverridden,WeatherChoiceRequested,WeatherChoiceReminderDue,WeatherChoiceApplied}.php`, `app/Models/{Booking,Tenant,Payment,VoucherRedemption}.php`, `app/Enums/AuditAction.php`, `config/kaiki.php`, `routes/console.php`, `lang/{el,en}/enums.php`, five test files and a shared scenario class

### Two documents disagreed about a number, and the number is money

The finding of this issue, and it was not in the acceptance criteria.

CXL-3.3: *"`refund_cents = round_half_up(paid_cents * refund_percent / 100)`. The base is the amount actually paid in cash, not the booking total."*

ADR-0017, and the issue's own acceptance criterion restating it: €200 paid with a €120 voucher and €80 cash, cancelled under a 50% policy, gives **€60 to the voucher and €40 in cash**. That is a €100 entitlement — half of €200, not half of the €80 of cash.

Both cannot be implemented. A literal CXL-3.3 returns €40 in total and the ADR's example fails; the ADR's example returns €100 and CXL-3.3's sentence is wrong as written.

Resolved for the ADR. The reasoning, now in the spec: what CXL-3.3 is contrasting `paid_cents` **with** is the *price*, and it exists to stop a guest who paid a 30% deposit being refunded half of a trip they have not paid for. A voucher is not an unpaid balance — it is consideration the guest handed over. The base is therefore `cash + voucher redeemed`, which satisfies the clause's actual concern and reproduces the ADR exactly.

**For a booking with no voucher the two readings are identical**, which is every booking CXL-3.3 was written about. That is why nothing has caught it for four issues.

### `discount_cents` was the wrong source, and #81 was using it

§2.5 defines it as *"voucher + manual discount"*. A manual discount is a **price reduction**, not money anybody paid — split against it and the operator refunds cash they never received, on exactly the bookings they are most likely to have discounted by hand.

`RestoreVoucher::voucherShareOf()` read that column since #81. It reads `voucher_redemptions` now, through a new `usedByBooking()` on the ledger, which is what the issue asked for in its own notes: *"reconstructible, rather than from a denormalised column."* A test sets `discount_cents = 5000` on a booking with an empty ledger and asserts the entitlement does not move.

### The cash half is the remainder, and that is not a style choice

Compute two proportions of one entitlement and they round independently. The guest is then owed a euro more or less than the operator gave back — every time, silently, on the bookings where somebody is already unhappy.

So `RefundEntitlement` computes the voucher share and subtracts. The two sum to the total by construction. The cash half is then clamped at `paid_cents`, which is the guarantee that actually matters: a booking almost entirely covered by credit refunds the €10 of cash and not a cent more.

### A default that disagreed with itself in one of four places

CXL-8's `force_majeure_voucher_months` is 18 — in the column default, in the factory, and in `CancellationPolicyData::fromSnapshot()`. In `RestoreVoucher::issueReplacement()` it was `?? 12`.

A guest whose voucher went through PRC-19.3's expired-voucher branch got twelve months instead of eighteen. Nothing would have reported it; the two paths are otherwise identical and no test compared them.

`IssueVoucher` is the single writer of that date now, reading it off the booking's own frozen snapshot.

### CXL-10's teeth are in its last clause

> *…without silently marking the booking refunded.*

That is the natural shape of the bug, because the status is written by the code that **asked** for the refund rather than by the code that got an answer. A booking that says `refunded` while the money is in the operator's account is a dispute the operator loses without knowing why.

The row is written `pending`; the gateway call is a queued job; only a settlement writes `paid_cents`, `refunded_cents` and the status. A refusal is not an exception — `RefundResult` carries `succeeded: false` for the charge being too old, the balance short, the refund already made — and lands as `failed` with the gateway's own code.

`Payment::scopeNeedingAttention()` is failed **refunds** only. A failed charge is a declined card; a failed refund is money promised and not sent. A feed showing both shows mostly declined cards.

### A weather cancellation moves no money, which needed a new parameter

CXL-6 ends the trip; CXL-7 opens a question. Until the guest answers — or the deadline answers for them — nothing is refunded and no voucher is issued.

`CancelBooking` grew `settle: false` for that one path. It is **not** an override: nobody is overruling the policy, and CXL-5's mandatory reason would be a lie. The entitlement is real and waiting, recorded as `weather_choice_due_at` rather than as a payment row.

### `rebook` issues the same voucher as `voucher`

A decision, stated in the enum. There is no seat-transfer flow: a guest rebooking makes a *new* booking, and a voucher is the only mechanism that carries their money to it. What `rebook` adds is intent — the operator's list can tell "coming back" from "took the credit", and the email carries a link to the product rather than a balance.

Collapsing the cases loses that. Issuing nothing for a rebook strands the money, which is what CXL-7 exists to prevent.

### The choice is recorded by a conditional update, not by a check

`where weather_choice is null` **is** the idempotency guarantee. Two clicks from a guest on a slow connection, or a click racing the deadline sweeper, both arrive; only the call that wrote the row moves money. A `SELECT` first leaves a window two requests drive straight through, and the consequence here is a guest refunded twice — the same reasoning `gateway_webhook_events` and `bookings.reference` follow.

It is also what makes recomputing the entitlement safe: `forWeather()` reads `paid_cents`, and honouring the choice changes `paid_cents`. A second call never gets past the write, so the figure is never recomputed against a booking that has already been refunded. Storing it in a column would be the other way to make it safe, and would put a derived number in the schema for a race that is already closed.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate` (SQLite) | clean |
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — two real findings fixed at source |
| `composer test` | **1741 passed**, 1 failed: the ENV-10 schema-snapshot fingerprint |
| `tests/Feature/Booking` | 50 passed |

**The snapshot could not be refreshed.** It is generated by CI against MySQL 8, and GitHub Actions is refusing to start any job — for an account-level reason rather than anything in this repository. See the note at the head of this file.

### Things this touched that were not its own

1. **`CancelDeparture`, not `WeatherCancelDeparture`.** The issue named the latter. Cancelling a departure for `min_pax` or `vessel_booked_privately` also has to cancel its bookings, and a weather-only Action would have left that path unbuilt with no issue owning it. One Action, with weather as the branch that holds the money back.

2. **No Filament `BookingResource`.** The issue's file list names one and **no issue in the milestone builds it** — the operator booking screen is not in M2's queue. Every acceptance criterion here is a Given/When/Then about behaviour, so the override is a domain Action with a mandatory reason and an `override.applied` audit row, which is what the existing `AuditLogResource` renders as a booking's timeline today. The screen that puts a button on it needs an issue.

3. **`ConfirmFromWebhook` added to `LockDisciplineTest`.** #83's payment-failure path takes all three locks and was never on that list — an ordering obeyed by three files and merely intended by a fourth is the state the test exists to prevent. It passes.

4. **`CancelBooking` added to the hold-column writer list** in `NoDirectRedisTest`. It writes `hold_expires_at => null`, which is AVL-38's release rather than AVL-37.5's creation — the same exemption the four existing enders have, and it can only ever null the column.

5. **`AuditAction::BookingRefunded` flipped to live**, and `NoPersonalDataInAuditContextTest`'s recorded gap narrowed to `['gdpr.purged']`. That test was written in #53 to make this happen.

---

## #83 — Gateway webhooks, and three columns the data model never had

**Files:** two migrations (§6 item 36, plus a column-addition with no item number), `app/Models/GatewayWebhookEvent.php`, `app/Enums/WebhookEventStatus.php`, `app/Http/Controllers/Webhooks/GatewayWebhookController.php`, `app/Jobs/ProcessGatewayWebhook.php`, `app/Domain/Booking/Actions/{ConfirmFromWebhook,MintBalanceSession,ComputeBalanceDueAt}.php`, `app/Providers/ApiRateLimitServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`, `config/{kaiki,tenancy}.php`, `lang/{el,en}/enums.php`, six test files and a shared scenario class

### The endpoint does four things and then stops

PAY-6 gives it five seconds; both gateways retry anything slower, so a slow endpoint **manufactures** the duplicate deliveries it then has to deduplicate.

1. Verify the signature, **before any parsing**.
2. Write the row, in its own transaction, with the raw payload.
3. Answer 2xx.
4. Queue the work.

No money logic runs in the request. `ProcessGatewayWebhook` does all of it, keyed on the row's id.

### Idempotency is an index, and there are two windows

`gw_events_provider_event_uq` on (provider, event_id) — a duplicate delivery violates it, and **that violation is the answer**: respond 200, do nothing. A prior `SELECT` would leave a window that two concurrent retries drive straight through, the same reasoning §2.5 gives for `bookings.reference`.

The queued job adds `ShouldBeUnique` on the row id, which closes the second window: the index stops a duplicate *row*, that stops a duplicate *job* for one row.

### "Verify before parsing" has a cost, and the cost is the finding

An unverified webhook is still recorded — PAY-7 wants the source IP, because a forged stream from one address is otherwise invisible. But the endpoint never decoded the body, so **it never saw the event id inside it either**, and those rows carry a synthetic `unidentified:` id.

The test asserts exactly that: the row is not findable by the id in the payload it refused, and its stored payload is empty. An unverified webhook contributes nothing at all — including the key we would file it under. That is what the rule costs and precisely what it buys.

### A fifth status, because four could not say what PAY-7 needs

§2.7 lists `received`, `processed`, `ignored`, `failed`. PAY-7 requires a verified webhook for an unknown booking to be *stored and surfaced in the super-admin gateway error feed*.

Under those four it is either **`failed`** — wrong, nothing failed and the signature was good — or **`ignored`**, which is worse: `ignored` means "we looked and decided it did not concern us", and it hides the row from the feed it is supposed to appear in.

`orphaned` is a real payment nobody can match. Somebody has been charged. It needs a person, not a backoff and not a discard, and it needs to be visibly distinct from the event types we simply do not act on — which is a status the feed's query can select on.

### The third case the isolation harness has met

`Tenant` and `User` are platform-owned because they *are* the platform. `VatRate` because Greek tax law is not an operator's to maintain. All three, forever.

`GatewayWebhookEvent` is written **before the tenant is known** and *acquires* one when the payment is matched. It is listed as platform-owned rather than given `BelongsToTenant` because a global scope would hide the row from the very job whose task is to work out whose it is — and `tenant_id` is nullable for exactly that window, the only such column in the schema.

### Three columns PRC-27 needs that §2 never defined

ADR-0018 settled the balance-due policy after `docs/data-model.md` §2 was written, and the columns it names were simply absent: `tenants.balance_due_days_before_departure`, `rate_plans.balance_due_days_before_departure`, `bookings.balance_due_at`.

All three are additions **§6's own rule permits** — nullable, no foreign key, constant default where there is one — so nothing is rebuilt on SQLite and no `ALTER` locks anything on MySQL. They are alterations rather than a new table, so the migration carries no item number and a different date prefix says so.

The rate plan's column has **no default**, and that is the decision worth naming: null means "use the tenant's", and defaulting it to 14 would make every rate plan silently shadow the tenant setting. Identical behaviour until an operator changes the tenant one and nothing happens.

### The 09:00 floor, and the hour that is invisible in one season

`starts_at_utc->subDays(14)->setTime(9, 0)` sets nine o'clock **UTC** — midday in Athens in summer, eleven in winter, moving an hour across each DST boundary. The issue said so outright: *"use `LocalDateTimeResolver`; do not compute `subDays()` on a UTC instant and hope."*

So the subtraction is in local calendar days and the nine o'clock is set in the tenant's timezone. Two tests bracket the clock change:

| Departure | Due date | Local | UTC |
|---|---|---|---|
| 20 Aug | 6 Aug | 09:00 | **06:00** |
| 10 Nov | 27 Oct | 09:00 | **07:00** |

A single-season fixture passes against the broken version.

### A late confirmation is not born overdue

PRC-27.3: confirmed ten days out on a fourteen-day policy, the ordinary due date is already behind us and the guest would receive an overdue notice for money they have had no chance to pay. So it becomes confirmation plus 24 hours — capped at two hours before departure, because a balance due after the boat has sailed is not a due date.

### The balance session is priced when the guest opens the page

ADR-0004 Option D's reason, in its own clause: *"so a legitimately changed balance is charged correctly."* Weeks pass between the confirmation email and the click, and the balance moves — extras added, pax changed, an operator discount applied.

Emailed links point at `/b/{manage_token}`, **never** at a gateway URL. Stripe's expires in 24 hours and Viva's on the order timeout, so a link emailed today is dead by the time a guest opens it in three weeks, and they would have no way back.

### BKG-12's ordering, and a test that needed a real competitor

Seats are in `seats_sold` because BKG-9 committed them at redirect. So on a failed payment they come **out** first, the booking goes back to `draft`, and a fresh hold is attempted — in that order, because the seats being re-taken are the ones just released and a hold attempted first would compete with itself.

Proving the *other* branch — the boat filled while the guest was failing to pay — needed a genuine competing booking rather than a counter set by hand. `HoldSeats` recounts live holds from `bookings` rather than trusting `departures.seats_held`, so a bare counter would have been corrected away and the re-hold would have succeeded. **The test would have passed the wrong branch**, which is the most expensive kind of green.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate` (SQLite) | both migrations clean |
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — three real findings fixed at source |
| `composer test` | **1682 passed**, 1 failed: the ENV-10 schema-snapshot fingerprint |
| `tests/Feature/Payments` | 75 passed |

The snapshot was refreshed the documented way.

### Things this touched that were not its own

1. **The webhook route is in `routes/web.php`, not under `/api/v1`.** Every route under that prefix is compared against `docs/api.md` §5 by the drift gate, and a gateway callback is not an operation an integrator calls — putting it there would either break the gate or force an endpoint into a contract that does not describe it. CSRF is excluded by path in `bootstrap/app.php`, narrowly, so a second `/webhooks/*` route later is a deliberate act.

2. **A rate limiter kept deliberately out of the §3.6 table.** That table is the public API's contract and every class in it is documented for integrators. A webhook limiter keys on the IP alone — a gateway presents no API key, which is the whole reason it cannot reuse one — and its number is generous, because throttling a real webhook means refusing to hear that somebody paid.

3. **`Log::shouldHaveReceived()` is not statically visible inside a Pest closure**, so the spy returned by `Log::spy()` is asserted on directly. The third instance of this shape after `travel()`, `fail()` and `withoutExceptionHandling()`.

4. **Four test files needed one scenario**, and a Pest `function` is scoped to its file — the second file to call it fails with "undefined function", which reads as a broken test rather than a missing import. `Tests\Support\Payments\WebhookScenario` is a class, and it carries the recorded gateway responses too: a file that forgets those does not fail loudly, it makes a network call.

5. **The suite ran the queued job by accident, and only one CI job noticed.** `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so `ProcessGatewayWebhook` executed inline everywhere except the `Pest on MySQL 8 + Redis` job, whose own environment sets `redis` and wins — PHPUnit only applies an `<env>` entry when the variable is not already set. There, the job was enqueued and never run: nine tests failed, and precisely the nine that assert an *outcome*, while every test that only inspects the recorded row stayed green. It reads as a data problem and is an environment one. The two processing files now set the driver themselves, and `WebhookQueueingTest` asserts the other half with `Queue::fake()` — that the endpoint *queues* rather than doing the work in the request, which a sync driver can no longer distinguish. The same shape as #53, on a different table.

---

## #82 — The gateway contract, and the two implementations that disagree about everything

**Files:** `app/Contracts/PaymentGateway.php`, `app/Domain/Payments/Data/{RedirectTarget,RefundResult,TranslatableMessage}.php`, `app/Domain/Payments/Gateways/{VivaSmartCheckoutGateway,StripeCheckoutGateway,FakeGateway,GatewayCallFailed}.php`, `app/Domain/Payments/Support/{GatewayResolver,GatewayErrorDictionary}.php`, `app/Providers/IntegrationServiceProvider.php`, `config/kaiki.php`, `lang/{el,en}/payments.php`, `phpunit.xml`, `phpunit.coverage.xml`, three test files

No migration in this one — the first M2 issue without one.

### Four methods, and why the number is the requirement

ADR-0004 item 2 fixes it, and the reason is not minimalism. Stripe supports card-on-file, stored mandates and off-session charging; **Viva Smart Checkout supports none of them.** A contract including any of the three would have one implementation throwing on half its own surface, which is not an abstraction but a Stripe client with a Viva-shaped hole.

The consequence is ADR-0004 Option D and it reaches the whole product: a deposit and a balance are **two independent checkout sessions**, months apart if need be, because "create a checkout session" is the only primitive both gateways genuinely share. Two gateway fees instead of one is the accepted cost, and PRC-27's balance reminders are mandatory rather than optional because of it.

A test asserts the interface has exactly those four method names. A fifth is an ADR, not a commit.

### Everything the two disagree about, absorbed

Three real differences, all handled inside `VivaSmartCheckoutGateway`:

1. **An order code, not a URL.** Stripe returns a hosted link. Viva returns a number, and the redirect is built from it against a **different host from the API** — the sort of thing that reads as a typo until it is written down, which is why the config has three Viva hosts per environment.

2. **OAuth2, not a bearer key.** Every Viva call needs a token minted from client credentials, so there is a round trip Stripe does not have. Cached per tenant and environment, for a minute less than its own expiry — minting one per checkout would double the latency of the slowest step in a booking. Through `Cache`, never `Redis::` (ENV-7).

3. **No webhook signature at all.** Stripe signs `{timestamp}.{payload}` with HMAC-SHA256; Viva authenticates the *receiver* with a shared verification key. Different mechanism, same guarantee, and the difference stops at the class.

`RedirectTarget` carries a URL **and** a reference for exactly this reason: without the reference a webhook cannot find its payment, and Viva's reference is all it gives you.

### PAY-12, and why one message would have been wrong

The requirement asks for four sentences and the asymmetry is the point:

- A **guest** whose card was declined needs to know to try another card. They do not need `card_declined: insufficient_funds` — a stranger telling them about their own bank balance, in English, with nothing to act on.
- An **operator** looking at the same failure needs precisely that, because they decide whether to chase it.

So `TranslatableMessage` holds guest and operator lines in both languages, resolved at description time rather than at render time — a guest can hit a refusal mid-locale-switch, and `payments.failure_message_el`/`_en` are columns because what the operator was told at the time is part of the record.

Three tests keep it honest: every code resolves in all four slots (a missing key renders *as the key*, which is technically a string and useless), the guest half never contains the code, and the two audiences never get the same sentence. The code check is **word-bounded** — Viva's codes are single digits, and a naive `str_contains` matches any sentence containing "2".

**The dictionary entries were chosen for what the audience can do**, which is why `api_key_expired` maps to the guest's *temporary* message rather than the declined one. An operator's expired key is not the guest's card, and saying it was is a lie about their bank.

### The unmapped path is a designed outcome

Neither gateway publishes a complete, stable list and both add codes without announcing them, so the question was never whether an unknown code arrives. The guest gets the ordinary sentence — never nothing, never the code — and the operator gets the raw code **marked unrecognised**, because they are the only person who can report the gap.

### The fake is a deliverable, and the contract test is why

One test file runs the same assertions against Viva, Stripe **and** the fake. #81 confirms bookings through the fake — that is what lets AVL-44 be tested without a network — so a fake that drifted from the real gateways would quietly turn the overselling guarantee into a test of nothing. M3's Playwright run and SAA-9's onboarding test booking depend on it too.

### No network call, asserted rather than assumed

Both real gateways run against recorded fixtures through `Http::fake()`. Seven env values in `phpunit.xml` (and `phpunit.coverage.xml`, which `CiGatesTest` keeps in step) pin every gateway host to a `.test` domain — reserved by RFC 6761, cannot resolve, so a request that escapes the fake fails immediately rather than reaching a real payment provider from a CI runner. A test asserts every implementation's redirect URL contains `.test`.

Seven values because Viva splits accounts, api and checkout across two environments while Stripe has one host for both. The asymmetry is real and flattening it would hide it.

### PAY-11 is structural, not procedural

*"Sandbox mode MUST be impossible to enable accidentally on a live tenant."* The mechanism is that live and test are **separate rows** — #79's unique index on (tenant, provider, environment) — so switching is not a toggle at all. It is entering the other environment's credentials, which nobody does by accident.

The environment comes from `bookings.is_test`, and `GatewayResolver::environmentFor()` takes no parameter: a caller that could ask for `test` could ask for it on a live booking, and the guest would be confirmed while nobody was charged. Two tests pin the fallbacks in both directions — a test booking never reaches live credentials, and a **live booking with no gateway resolves to null rather than the fake**, because a fake that quietly succeeded would confirm a trip nobody paid for.

### PHP coerces numeric array keys, and Viva's codes are numbers

`'2' => [...]` is stored as `2`, so `array_keys()` returned integers that failed the dictionary's own `string` parameter. Cast at the boundary rather than widening four signatures to `string|int` — an error code is an identifier that happens to look like a number, and the widening would have spread from the dictionary through the message and the exception.

### Verification

| Check | Result |
|---|---|
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — five real findings fixed at source |
| `composer test` | **1642 passed, 0 failed** |
| `tests/Feature/Payments` | 37 passed |

No migration, so no schema snapshot to refresh — the first M2 issue where the local run is the whole story.

### Things this touched that were not its own

1. **#79's credential scanner now runs over `app/Domain/Payments`.** That is where a `Log::debug($credential->credentials)` would be written while somebody chased a failing checkout at eleven at night — the exact moment SEC-9 and MYD-15 are most likely to be forgotten, and a directory the scanner did not previously cover because it did not exist.

2. **The Stripe webhook verifier checks the timestamp as well as the HMAC.** A valid signature over an old payload is a replay, and a verifier that only compares the signature accepts one forever. The tolerance is a config value at five minutes, which is Stripe's own recommendation and generous for clock skew.

---

## #81 — Confirmation, and the test that has been a required check with nothing to run

**Files:** two migrations at §6 positions 34–35, `app/Models/{Payment,VoucherRedemption}.php`, `app/Enums/{PaymentStatus,PaymentKind,PaymentGatewayName}.php`, `app/Domain/Booking/{Actions/ConfirmBooking,Actions/StartCheckout,Actions/ExpireAbandonedCheckouts,Support/SeatCommitment,Support/LockOrder}.php`, `app/Domain/Pricing/Actions/{ApplyVoucher,RestoreVoucher}.php`, `app/Exceptions/{CapacityExceeded,IllegalStateTransition}.php`, `app/Events/{BookingConfirmed,DepartureGuaranteed}.php`, `app/Jobs/ExpireAbandonedCheckoutsJob.php`, `app/Policies/{Payment,VoucherRedemption}Policy.php`, `config/{kaiki,database}.php`, `phpunit.xml`, `phpunit.coverage.xml`, `tests/Pest.php`, `routes/console.php`, `docs/{spec,data-model}.md`, two factories, five test files

**AVL-44 has been a required status check since #4 and had nothing to run for two milestones.** A required check that silently executes nothing has been protecting nothing.

### The two mechanisms, and why neither is enough alone

**`lockForUpdate()`, unconditionally** (AVL-43.1). It is never skipped or branched "for SQLite compatibility" — the spec calls that a review blocker, and a conditional lock is invisible in a passing suite, so `LockDisciplineTest` reads the source rather than the behaviour.

**A conditional counter update** (AVL-43.2). `WHERE capacity - seats_sold - seats_held >= :n`, evaluated against the row **as it is at write time**, with zero affected rows aborting the transaction. This runs on every driver, which is the point: the lock proves nothing on SQLite, and this is what makes the capacity invariant testable on the stack the code is actually written on.

`ConditionalCounterTest` arranges the stale read by hand — write the row from underneath a model that still remembers the old numbers — so the refusal is exercised locally with no parallelism at all.

### The clamp the tests found, which was an oversell vector

Seats moving from held to sold get a credit for the booking's own hold, so a guest is not made to compete for seats they already hold.

Unclamped, that credit is subtracted whether or not the counter contains it. A booking whose `hold_expires_at` was still set but whose seats had already been released — the sweeper got there first, or a hold was released by hand — subtracted a hold that was not there and **manufactured capacity out of arithmetic**. A departure with one seat, one seat sold, and a stale claim was allowed to confirm.

The credit is now `CASE WHEN seats_held < n THEN seats_held ELSE n END`. `LEAST()` is MySQL's and a two-argument `MIN()` is SQLite's; neither has the other, and this is the one form both understand.

### A real deadlock, found by an architecture test

`ExpireAbandonedCheckouts` locked the booking first. That is the obvious way to write it — the row being expired is the one carrying the `departure_id` the next lock needs — and it is a deadlock: this job and a confirmation racing over the same sailing take the departure and the booking in **opposite orders**. MySQL detects it and kills one after a lock-wait timeout, so the symptom is not a hang but a confirmation that randomly fails under load, which is the condition nobody can reproduce.

The ids now come from the unlocked outer read, which is safe because neither ever changes on a booking; the *status*, which does, is re-checked under the lock.

`LockDisciplineTest` catches this class of thing because AVL-45 is a rule no green test run can demonstrate. It also asserts the lock is never driver-branched, that no external call sits where a lock may be held (AVL-46), and that `BookingConfirmed::dispatch` appears **after** the transaction closes.

### The idempotency bug, and a test that was green for the wrong reason

`ApplyVoucher` runs twice on every booking by design: checkout applies the voucher, confirmation re-validates it under a row lock (PRC-20).

It refused any voucher whose status was `Redeemed`. But **`Redeemed` is derived from the ledger** — applying a voucher marks it so — which meant that on the second call a booking was refused *its own* discount, the discount was cleared, and the total went back up. The BKG-19 zero-total path therefore arrived at a gateway with fifty euros to charge.

The existing idempotency test asserted the redemption count and the voucher's balance. Both stayed correct throughout, because `clearDiscount()` leaves the ledger alone. It never looked at the booking, so it passed. **It does now**, and the assertion is written with the reason attached.

`Cancelled` and expiry remain refusals: those are decisions rather than arithmetic. `$availableNow` already excludes this booking's own share and is the honest answer to "how much can this booking spend".

### The concurrency suite, and why it could not live in `tests/Feature`

Two things had to be true and neither was:

1. **Two connections.** PDO serialises statements on one link, so a "concurrent" pair driven through a single connection is a sequential pair — and a sequential pair passes against an implementation with no locking whatsoever. `mysql_concurrent` is a clone of the default connection onto the same database.

2. **Committed fixtures.** `RefreshDatabase` wraps each test in a transaction that is never committed, so the second connection sees an empty database and the test fails on missing rows rather than on capacity. `tests/Concurrency/` uses `DatabaseMigrations`, which commits.

Pest applies `RefreshDatabase` to everything in `Feature` and the two traits collide outright — a fatal error, not a subtle one. Hence a third test suite, in `phpunit.xml` and `phpunit.coverage.xml` both, and one entry in `tests/Pest.php`.

Everything in it is `@group mysql` and skips with an explicit reason elsewhere (AVL-43.3), through Pest's `->skip()` rather than `markTestSkipped()` — `$this` is a `TestCall` at analysis time, the same thing `travel()` and `fail()` ran into in #80.

### PRC-19.4 asks for a row the schema forbids

The requirement says cancellation *"writes a reversal row"*. `voucher_redemptions_v_b_uq` is unique on (`tenant_id`, `voucher_id`, `booking_id`), so a second opposite row for the same pair cannot exist.

The index wins: it is what stops one voucher being applied twice to one booking, which is a real double-spend, and that is worth more than the symmetry of two rows. A reversal is `reversed_at` and `reversed_amount_cents` **on the row being reversed**; the ledger's arithmetic is `Σ(amount_cents − reversed_amount_cents)`; the movement, its amount, its direction and its time are all still recorded and never deleted, which is what PRC-19.4 actually protects. `docs/spec.md` was reconciled to the data model, which is authoritative on schema, and `reason` was added to §2.5 because the requirement lists it and the table omitted it.

### Four numbers, all recomputed and none incremented

`paid_cents` from `Payment` rows (PAY-10), `remaining_cents` from the ledger (PRC-19.4), `seats_sold` and `seats_held` from live holds. The same reasoning every time: an increment is only correct if every previous one was, and drift in any of these is money or seats somebody reconciles by hand. PRC-26 — `paid_cents + balance_cents = total_cents` — is asserted after every path.

### Verification

| Check | Result |
|---|---|
| `php artisan migrate` (SQLite) | both tables at 34–35 |
| `composer lint` | clean after fixes |
| `composer stan` | `[OK] No errors` — seven real findings fixed at source, no baseline |
| `composer test` | **1605 passed**, 0 failed (after the snapshot refresh) |
| `tests/Feature/Booking` + the two architecture files | 71 passed |
| `tests/Concurrency` | skips locally with its reason; **runs in CI** |

The snapshot was refreshed the documented way. **AVL-44 itself is verified only in CI** — that is the whole design, and a local pass would be the dangerous outcome rather than the reassuring one.

### Things this touched that were not its own

1. **`phpunit.coverage.xml` had to gain the new suite**, because `CiGatesTest` compares the two skeletons. A coverage config running a different set of tests would report a percentage for a suite nobody runs — the gate working exactly as intended.

2. **`Payment` and `VoucherRedemption` policies refuse deletion for everybody, including the owner.** The base class hands `forceDelete` to the owner, which is right for a vessel and wrong here: a payment that can disappear cannot be reconciled against a bank statement, and a deleted ledger row does not merely lose a record — it silently changes a voucher's balance, in the direction that gives an operator's money away.

3. **The enum is `PaymentGatewayName`, not `PaymentGateway`.** PAY-2 fixes `App\Contracts\PaymentGateway` as the interface the two implementations sit behind, and a collision between an interface and an enum of the same name is waiting for the first person who imports the wrong one — in a file about money.

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
