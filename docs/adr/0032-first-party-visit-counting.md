# ADR-0032 — Counting visits, first-party and cookieless

- **Status:** Accepted
- **Date:** 2026-09-14
- **Decided by:** the product owner
- **Affects:** OPS-23 … OPS-26, GDR-12, WGT-11, WGT-12, WGT-7, SEC-10

## Context

OPS-23's statistics page answers *how did we do* entirely from rows the product
already writes — bookings, payments, departures. It cannot answer the question
the product owner asked next: **how many people looked, and where did they stop**.
A booking row exists only for the people who finished; everybody who opened a
trip page, picked a date and then closed the tab is invisible to it.

Three facts set the shape of the answer:

1. **The widget already emits the events.** WGT-11 fixed eight of them —
   `kaiki:ready`, `product-viewed`, `availability-loaded`, `booking-started`,
   `checkout-started`, `booking-confirmed`, `enquiry-submitted`, `error` — and
   `packages/widget/src/analytics.ts` scrubs every detail object against a
   ten-key allow-list, so a guest's name or token cannot appear in one. They are
   dispatched into the host page and **nothing records them**.
2. **GDR-12 promises no tracking cookie on a guest-facing page**, and WGT-12
   forbids the widget writing to `localStorage` for tracking. That is a promise
   the product makes to operators who put our widget on their own site, and it
   is worth more than the analytics.
3. **Hosted pages are ours and are server-rendered.** A page view there needs no
   JavaScript at all to count.

The alternative shape — an operator pastes their own GA4 or Meta measurement id —
was considered and rejected by the product owner in the same conversation: it
puts a third-party tracker on the page, makes a consent banner mandatory, and
widens SEC-10's Content-Security-Policy per operator.

## Decision

**Count first-party, cookieless, and store only aggregates.**

### `analytics_daily`, a rollup and never a log

One row per tenant, per local day, per metric, per dimension value, carrying a
count and an optional value in cents. Unique on all of those. There is **no**
visitor id, no session id, no IP address, no user agent and no row per person —
so:

- GDR-12 holds: nothing is written to the visitor's browser, so there is nothing
  to ask consent for;
- there is nothing to purge under GDR-3 or GDR-10, because there is no personal
  data to retain in the first place;
- growth is bounded by days × metrics × dimension values rather than by traffic,
  which is the difference between a table that can be kept for ever and one that
  needs a retention job nobody writes.

### Page views are counted on the server

The hosted pages already pass through `HostedPageHeaders` on every request. A
queued listener increments the rollup. No JavaScript, no cookie, no consent
question, and it works for a visitor with a blocker.

### Widget steps arrive by beacon

A throttled `POST /api/v1/events`, authenticated by the publishable key the
widget already carries, accepting **only** the eight event names and the ten
allow-listed keys the widget already enforces client-side — the server re-checks
rather than trusting, because a public endpoint is a public endpoint. The widget
sends it with `navigator.sendBeacon` when `data-analytics` is on, which is the
default (WGT-7); an operator who sets `data-analytics="false"` sends nothing, as
today.

### The funnel is counts, and says so

Cookieless means no visitor is followed from one page to the next. So the page
shows **counts per step and the ratio between consecutive steps**, labelled as
such. It does not say "conversion rate", because that would be a per-person
number and this is not one. A figure called something it is not is worse than no
figure.

## Consequences

- One new table, one new public route, one new queued listener, and roughly
  fifteen lines in the widget.
- The endpoint is a public write, so it is rate-limited per key, ignores
  anything not on the allow-list, and answers `204` whatever happens — an
  analytics beacon must never be able to slow down or break a booking.
- The rollup is **per local day in the tenant's timezone**, matching every other
  figure on the statistics page.
- An operator still gets no cross-site attribution, no returning-visitor count
  and no per-person funnel. Those need an identifier, and an identifier needs a
  consent banner. If that is ever wanted it is a new decision, not an extension
  of this one.
- Bot traffic is not filtered in the first version beyond obvious crawler user
  agents on the server side. A count that includes some crawlers is honest about
  what it is; a filter nobody can audit is not.
