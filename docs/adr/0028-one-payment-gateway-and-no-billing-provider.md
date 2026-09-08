# ADR-0028 — Viva is the only payment gateway, and the platform has no billing provider

- **Status:** Accepted
- **Date:** 2026-09-08
- **Decided by:** the product owner
- **Supersedes in part:** [ADR-0004](0004-payment-credentials-and-deposit-model.md)
- **Affects:** PAY-1, PAY-2, VIS-4, SCP-5, ARC-13, ARC-14, ARC-20, EXT-4, MIL-3, MIL-8

> **Amended 2026-09-08, later the same day — the open question is answered.**
> This ADR was accepted with the platform's own billing left undecided, which
> blocked M7. The product owner has since chosen **Viva Wallet for the operator
> subscriptions too**, so Kaiki charges its operators through the same gateway
> its operators charge their guests through.
>
> That is one integration rather than two, one set of credentials to hold, and
> one webhook shape already built and tested (`WebhookScenario`). The cost is
> that Viva's recurring-payment support is narrower than a subscription
> platform's — dunning, proration and plan changes are ours to write rather than
> a provider's to supply — and M7 should expect that, not discover it.
>
> **M7 is no longer blocked on a decision.** It is blocked only on being built.
> The body below is left as it was written: an accepted ADR records what was
> decided at the time, and editing the "no provider" reasoning out of it would
> hide that the platform spent a day without one.

## Context

Two unrelated things in this codebase were called Stripe, and it is worth
separating them before anything else, because conflating them is how the wrong
one gets deleted:

1. **An operator payment gateway.** `StripeCheckoutGateway`, one of the two
   implementations behind `App\Contracts\PaymentGateway` (ADR-0004). This is how
   a *guest* pays an *operator*. The platform never touched the money.
2. **The platform's own billing.** Laravel Cashier on the `tenants` model —
   `stripe_id`, `pm_type`, `pm_last_four` — which is how *Kaiki* would charge
   *operators* their subscription. Never implemented; the columns landed in M0
   only because `docs/data-model.md` §0 forbids adding them to SQLite later.

The product owner asked for Stripe to be removed "from everywhere", and when the
two meanings were put to them explicitly, chose **both**.

## Decision

**Viva Wallet Smart Checkout is the only payment gateway.**
`StripeCheckoutGateway` is deleted, along with `PaymentGatewayName::Stripe`,
`IntegrationProvider::Stripe`, its credential fields, its error dictionary and
its config block.

**The platform has no billing provider.** Cashier's columns are removed from
`tenants` and Cashier is no longer assumed by the stack.

## Consequences

### The seam stays open, and that is the point

ADR-0004's whole argument was that a narrow four-method contract makes a second
gateway *a class rather than a refactor*. Nothing about that changes because one
implementation was removed — and the code deliberately reflects it. The
`match` in `GatewayResolver::named()`, the per-gateway error dictionaries and
the `gateways` dataset in `GatewayContractTest` all remain plural shapes with
one entry, because collapsing them to a constant is the change that would be
expensive to undo.

### M7 cannot build subscriptions until a provider is chosen

This is the sharp edge. **M7 is now blocked on a decision that has not been
made**: spec VIS-4 and ARC-14 both name Stripe-via-Cashier as the business
model, and there is nothing behind them any more. Whichever provider is picked,
its columns are an **edit to the M0 migration and a `migrate:fresh`**, never an
`ALTER` — SQLite's constraint is unchanged and is the reason the columns were
there in the first place.

`Plan` and its vessel limits are untouched: what an operator *gets* is unrelated
to how they are charged for it.

### Viva's webhook verification key became a declared field

Not a consequence of the removal so much as something it uncovered.
`VivaSmartCheckoutGateway::verifyWebhook()` has always read `webhook_secret` and
refused when it is missing, while `IntegrationProvider::issuesWebhookSecret()`
answered `false` for Viva — so the form never asked for the key, and an operator
who did not somehow supply one had **every Viva webhook silently rejected**.

That was survivable while a second gateway existed. With one gateway it is the
only way a payment is ever confirmed, so `issuesWebhookSecret()` now returns
true for Viva.

### One test was deleted rather than faked

"Clears the previous default when a second gateway claims it" required two
payment gateways. The clearing code in `SaveIntegrationCredential` is kept — it
is the seam above — but the branch is now unreachable, and a test that
manufactured the race with a provider that is not a gateway would assert
something the product does not do. The gap is recorded in `docs/BUILD-LOG.md`
so the next gateway brings its test back rather than inheriting silent coverage.

### `docs/BRIEF.md` is not edited

The brief is historical (`CLAUDE.md`), and rewriting it would falsify the record
of what was originally asked for. It still says "Stripe via Laravel Cashier";
this ADR is what supersedes it, exactly as ADR-0014 supersedes its PHP version.

## Alternatives considered

**Keep Stripe as a second gateway.** Rejected by the product owner. Greek
operators asked for Viva; a second gateway is a second set of credentials to
support, a second dictionary to maintain and a second sandbox to keep working,
for demand that has not appeared.

**Remove the operator gateway but keep Cashier.** Offered explicitly and
declined. It would have left the platform's billing intact and M7 unblocked, at
the cost of the codebase still depending on Stripe.

**Leave the columns in place, unused.** Rejected: columns named after a provider
are how M7 quietly builds on one nobody chose, and the migration comment now
says so.
