# Testing

What runs, where it runs, and what a red one means. `docs/ci.md` is the list of
required checks; this is how to run them yourself.

---

## The suites

| Command | What it is |
|---|---|
| `composer test` | Pest against SQLite. The one developers run. |
| `composer test:coverage` | The same, instrumented, with the `app/Domain` gate (TST-1). |
| `composer test:mysql` | The `mysql` group, which must **execute** rather than skip (ENV-11). Needs MySQL 8. |
| `composer test:tenancy` | The cross-tenant isolation gate (ADR-0001). |
| `composer test:api-docs` | The contract drift gate (ENV-28). |
| `composer test:widget-compat` | The widget's expectations of `/api/v1`, read out of its own TypeScript (ADR-0011). |
| `composer i18n:check` | EL/EN key parity and the hardcoded-string lint (I18N-2, I18N-3). |
| `npm run widget:test` | Vitest, in jsdom, with the transport mocked. |
| `npm run widget:guards` | No hardcoded colour, no price arithmetic, both locales in step. |
| `npm run e2e` | **The end-to-end run.** A real browser, a real server, a real booking. |
| `npm run e2e:smoke` | The subset CI runs on every push: four mounts, nothing slow. |
| `npm run plugin:lint` | phpcs over the WordPress plugin: the WordPress coding standard **and PHP 8.1 compatibility** (WPP-11). |

---

## The end-to-end run (TST-3)

```bash
npm run e2e:install   # once — downloads Chromium
npm run widget:build && php artisan widget:publish
npm run e2e
```

Everything else is automatic. `playwright.config.ts` recreates
`database/e2e.sqlite`, seeds the demo operator, mints a **test** publishable key,
and starts two servers: the application on `127.0.0.1:8123`, and a fixture host
on `127.0.0.1:8124` standing in for an operator's own website.

### What it proves that nothing else can

- **A person can buy a boat trip.** Date, party, contact details, consent, a
  gateway redirect, a payment, and a confirmation the widget polled for. Every
  other suite in this repository proves a rule about the code; this one proves
  the product.
- **The widget works in a browser.** Preflights, shadow boundaries, CSP,
  inherited CSS, `sessionStorage` across a page load. Every one of those is
  invisible to a suite that mocks the transport, and **issue 111 found six real
  defects in the first hour it ran** — a preflight that never allowed
  `Idempotency-Key`, a booking payload with two wrong field names, a checkout
  request missing `kind`, a guest token sent as a query parameter, a resume path
  reading query parameters nothing ever wrote, and a stylesheet that a strict CSP
  silently discarded.

### Why it is shaped the way it is

**A second origin, not an intercepted route.** The fixture host is a real HTTP
server on a second port, because Chrome's Local Network Access check refuses a
synthesised page's request to a loopback address, and turning that off with a
launch flag would be passing the suite by disabling a browser security feature.
A real origin also makes every request genuinely cross-origin, so SEC-7's
allow-list is exercised rather than stepped around.

**The payment is real, and costs nothing.** A `pk_test_` key marks the booking
`is_test`, which routes checkout to the fake gateway (PAY-11), which redirects to
the **sandbox checkout page** the platform serves itself. No third-party sandbox,
no card, no account — and no mock standing in for the payment either. The browser
actually leaves the operator's site and comes back.

**Retries are set to zero, deliberately.** Playwright retries on CI by default,
and an intermittent failure in a booking flow is a real bug — a race between the
hold, the availability cache and the redirect. A suite that retries until green
is a suite that lets exactly that through. Traces, screenshots and video are kept
on the first failure instead, so a red run can be read rather than re-run.

**One worker.** Every spec books against one seeded departure with a real
capacity. Parallel workers would compete for seats and manufacture the
intermittent failure the paragraph above exists to detect.

**Holds are one minute for the whole run.** `KAIKI_HOLD_MINUTES=1`, so
`hold-expiry.spec.ts` can watch a hold run out on a real clock rather than a
mocked one. It makes that spec about seventy seconds long, which is why it is
nightly and not a release gate.

### The files

| File | What it covers |
|---|---|
| `smoke.spec.ts` | All four mounts render and do their one job. The CI subset. |
| `booking.spec.ts` | The complete booking, end to end, to a confirmed booking. |
| `accessibility.spec.ts` | axe on each mount (A11Y-1) and a keyboard-only booking (WGT-21). |
| `environments.spec.ts` | A strict CSP with no `unsafe-inline`, and a host page with hostile global CSS (WGT-22). |
| `hold-expiry.spec.ts` | A hold running out, on a real clock (WGT-19). |
| `support/world.ts` | The seeded state, and how a spec embeds the widget. |
| `support/fixture-server.mjs` | The operator's website: three host pages, real headers. |

---

## The WordPress plugin

It cannot be exercised from the Pest suite: it needs WordPress, and WPP-15 puts
that in a Playwright run against **a real site** rather than a local harness
(ADR-0015, and `wp-env` is explicitly not used). So the plugin is checked in
three places, and the split is deliberate:

- **`npm run plugin:lint`** — phpcs, at PHP **8.1**, which is the version
  operator hosting actually runs. The platform is 8.4 everywhere else; the
  compatibility ruleset here is what catches an 8.4 habit before somebody's
  shared host fatals on it.
- **`tests/Feature/Plugin/PluginStandardsTest.php`** — everything true of the
  plugin *as files*: its header, its PHP constraint, its uninstall cleanup, its
  Greek translations, and the security rule of the whole milestone — **the
  secret key is named in three files and nowhere else**. That last one is
  enforced by reach rather than by value, because nobody types a key into a
  template; they pass the reader to one.
- **`npm run e2e:wp`** — the real-site run, which needs `KAIKI_WP_TEST_URL` and
  skips without it.

Translations are compiled by
`php packages/wordpress-plugin/tools/build-translations.php`, and the template
regenerated by `extract-strings.php` beside it. WordPress reads the `.mo`, not
the `.po`, so a commit that changed one and not the other would leave the plugin
silently in English — which is what the parity test in `PluginStandardsTest`
exists to catch.

---

## Where each kind of test belongs

- **Unit** — a rule with no I/O. `tests/Unit`, and Vitest for the widget.
- **Feature** — an HTTP boundary or a Filament page. `tests/Feature`.
- **Architecture** — a rule about the code itself: no JSON-path queries (ENV-8),
  no hardcoded strings (I18N-1), every model tenant-scoped (ADR-0001).
- **End-to-end** — a claim about the product that needs a browser to be true.

A test in the wrong layer is usually a slow test that proves less. The rule of
thumb: if it can be true without a browser, it should not need one.

---

## Two things the suite cannot tell you

**The MySQL schema snapshot.** `CiGatesTest` compares a fingerprint of the
migrations against the committed `mysql-schema.snapshot.sql`, and only CI has a
MySQL 8 connection to regenerate it with. While CI is blocked it fails locally
and there is nothing to fix in the code — see the CI row in `docs/BUILD-LOG.md`.

**A real gateway.** Viva and Stripe are driven against recorded fixtures, and no
test in this repository makes a network call. Confirming that a real sandbox
still behaves as the fixtures say is a manual verification on the roadmap, not a
CI job: a suite that depends on a third party's sandbox being up is a suite that
goes red for reasons nobody here can fix.
