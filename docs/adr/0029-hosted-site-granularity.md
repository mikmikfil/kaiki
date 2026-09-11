# ADR-0029 — The hosted site is three states, not a switch

- **Status:** Accepted
- **Date:** 2026-09-08
- **Decided by:** the product owner
- **Amends:** TEN-1 (FIXED), HOS-6
- **Affects:** TEN-1, HOS-1, HOS-6, HOS-10, SAA-3

## Context

`tenants.hosted_page_enabled` is a boolean. False means every hosted URL 404s —
the marketing home page, the trip pages, the search page and the legal pages
together — because the switch is read in `HostedSlugResolver`, which runs
**before routing** and therefore cannot know which page was asked for.

The product owner has said twice, unprompted, that this is the wrong shape:

> *"keep in mind that the website version should be optional for each operator,
> right? they may only use the single page and the search etc. like its on
> webhotelier"*

That is a real distinction and it is how the accommodation trade already works.
An operator who has a website they like — built by a nephew, or on WordPress,
or on Wix — does not want Kaiki to publish a second, competing home page under
their name. They want the **bookable** part: a page per trip that they can link
to from their own site, a search page, and the widget. What they do not want is
a marketing front page they did not write and will never maintain.

Today the product forces a choice between "all of it" and "none of it", and an
operator in that position picks none — losing the trip pages, which are the part
Kaiki is actually good at, and losing the SEO that #104 and #116 exist to
produce.

## The options

**A. Leave it binary and gate the whole site by plan.** One boolean, one plan
check. Cheapest, and it does not solve the problem: the operator above still has
to take a home page they do not want in order to get trip pages they do.

**B. Three states.** Off / bookings only / full site. "Bookings only" serves
product pages, the search page, the legal pages and the widget, and 404s the
marketing home page.

**C. A switch per page type** — home, search, product, FAQ, legal, each
independent. The most flexible, and the most states to test, explain and support.
Five booleans is thirty-two combinations, most of which are nonsense (search on,
products off) and all of which someone must reason about.

## Decision

**B.** Three states, as an enum on `tenants` replacing the boolean.

The middle state is the one that earns the change, and it is the one an operator
asks for by name. C's extra freedom buys combinations nobody has requested, at
the cost of a support conversation about each one.

## Consequences

**TEN-1 is FIXED and this amends it.** The column list in TEN-1 names
`hosted_page_enabled`; it becomes a state instead of a boolean. This is recorded
here rather than edited quietly, because a FIXED requirement changing is exactly
the kind of thing that should leave a trace.

**HOS-6 becomes two rules**, not one: a tenant in the *off* state 404s every
hosted URL, and a tenant in *bookings only* 404s the home page alone. The
resolver keeps the coarse question — is there a site at all — because it runs
before routing. The finer question moves to `HostedPageController::index`, and to
`RootController::__invoke`, which delegates to the same method for a custom
domain. Two entry points, one method.

**`HostedUrl::enabledFor()` splits.** It currently answers one question for two
callers that will diverge: the API's `booking_url` points at a **product** page,
while the panel's "view your page" link points at the **home** page. Under three
states those are different answers.

**`HostedEmbedToken` keeps asking the coarse question.** A token minted for a
product page must stay valid while any page is live; it should only die when the
site is switched off entirely.

**Plan gating is a separate decision and is not made here.** SAA-3 already gives
`Pro` custom domains and webhooks; whether the full site is a paid tier is a
pricing question the product owner has deferred (`app/Enums/Plan.php` exists and
none of its predicates has a caller). This ADR decides the *shape* of the switch,
so that the pricing decision, whenever it is made, has something to gate.

**Migration, not `ALTER`.** Per `docs/data-model.md` §0 the column changes in the
M0 migration with a `migrate:fresh`, which is how every other column on `tenants`
has changed.

## Amendment — 2026-09-11: two states, set by the platform

**Decided by:** the product owner.

**Off is retired.** Compared against WebHotelier and FareHarbor, neither offers
an operator "no pages": every property or company always has its hosted booking
pages, and a widget or a "Book now" link on the operator's own site leads into
them. Kaiki's *off* also did not hold together on its own terms — checkout is on
Kaiki in every mode, and it asks the guest to accept terms that live on a legal
page *off* 404'd. Two states remain: **bookings only** and **full**. Operators
who were *off* move to *bookings only* (migration
`2026_09_11_000002_retire_hosted_site_mode_off`), the nearest state that still
publishes no home page under their name.

**The platform chooses, not the operator.** The mode is set on `/admin` on the
operator's edit screen, beside the plan and QR boarding, and audited the same
way (SEC-16). The operator's «Η ιστοσελίδα σας» screen shows it read-only and
says who to ask.

**Consequences.** The coarse question — "is there a site at all?" — is gone:
`HostedSlugResolver` resolves every operator, and `HostedEmbedToken` no longer
checks the mode. The one remaining question, `servesHomePage()`, is asked by
`HostedPageController::index` and nobody else.

**Not decided here: a pop-up checkout.** FareHarbor keeps the guest on the
operator's site through payment, in an overlay. That is a property of the
widget, not a site mode — an operator on either mode could want it — and it is
on the roadmap as its own item. It needs Kaiki's pages to be framable by the
operator's registered origins (today every page sends `frame-ancestors 'none'`)
and an answer on whether Viva's payment step can run inside a frame.
