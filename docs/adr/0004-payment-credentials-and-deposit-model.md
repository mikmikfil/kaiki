# ADR-0004: Per-operator gateway credential storage and the deposit/balance model

- Status: **Accepted (Option A + D)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §2, §3 (Payments), §4 (Payment), §6 of `docs/BRIEF.md`; requirements PAY-1 … PAY-22, TOK-6

## Context
Guests pay the operator, never the platform (§1, FIXED). Each tenant therefore holds their own Viva Smart Checkout or Stripe Checkout credentials, which Kaiki must store, use server-side, and never expose to the widget or the WordPress plugin. Separately, a booking may be paid in full or by deposit with a balance link (§2, §6), which means one booking can produce two guest-facing payment sessions at different times, potentially months apart and possibly for a changed amount. Both decisions shape the `Payment` table, the webhook handlers and the `/b/{manage_token}` page. Blocks **M2**.

## Options — part 1: credential storage

### Option A — `encrypted` cast columns on a `payment_gateway_accounts` table, one row per tenant per gateway per mode
Pros
- Exactly what §3 mandates ("Operator credentials encrypted at rest, `encrypted` cast"); no new infrastructure.
- Naturally supports live and sandbox credential pairs per tenant for §11 sandbox mode.
- Rotation is a re-encryption pass over a small table.
Cons
- A database dump plus `APP_KEY` compromises every operator gateway. Mitigated by encrypting backups with a separate key and keeping `APP_KEY` out of the backup set.
- No per-tenant key separation.

### Option B — Stripe Connect / marketplace onboarding instead of raw credentials
Pros
- Kaiki never stores a secret; revocation is one click.
Cons
- Platform-collected payments and Stripe Connect are explicitly **out of scope for MVP** (§2), and Viva has no equivalent for this shape. Rejected on scope grounds.

### Option C — External secret store (Vault or cloud KMS envelope encryption)
Pros
- Per-secret access audit; key rotation without touching the database.
Cons
- New infrastructure on a single Hetzner VPS, a new failure mode inside the payment path, and a dependency outside §3. Disproportionate for MVP.

## Options — part 2: deposit and balance

### Option D — Two independent checkout sessions (`deposit`, then `balance`)
The booking confirms on a successful deposit payment; the balance is a second Checkout / Smart Checkout session created on demand from `/b/{manage_token}`, priced from `booking.balance_cents` at the moment the guest opens the page.
Pros
- Needs only the "create a checkout session" primitive, which both gateways have — no card-on-file, no off-session SCA mandate, no gateway-specific saved payment methods. Keeps one `PaymentGateway` contract as §3 requires.
- The balance may legitimately change (extras added, pax changed) because the session is minted lazily.
- Refunds map one-to-one onto `Payment` rows, matching the §4 `kind: full | deposit | balance | refund`.
Cons
- The guest must actively pay; needs balance reminders and the unpaid-balance dashboard (§9).
- Two gateway fees instead of one.

### Option E — One payment intent, later capture or off-session charge of the balance
Pros
- The guest pays once; balance collection is automatic.
Cons
- Requires storing a mandate or customer per operator account, SCA exemptions and gateway-specific off-session flows. Viva Smart Checkout has no comparable path, so the two gateways diverge sharply, breaking the single contract.
- Authorisation holds expire long before a charter date months ahead.

## Recommendation
**Option A plus Option D.** A is what §3 already mandates and needs no new infrastructure; residual risk is handled by separate backup encryption and never logging credentials. D is the only model both gateways support behind one abstraction, and it mirrors what Greek operators already do (deposit now, balance before departure). Pair it with a per-tenant balance due rule — see ADR-0018.

## Consequences if accepted
- `payment_gateway_accounts`: `tenant_id`, `gateway`, `mode` (`live` | `sandbox`), encrypted credential JSON, `is_default`, `verified_at`, `last_verified_error`.
- `App\Contracts\PaymentGateway` needs only `createCheckoutSession(Booking, PaymentKind, Money): RedirectTarget`, `verifyWebhook(Request): bool`, `refund(Payment, Money): RefundResult`, `describeError(string): TranslatableMessage`.
- `Payment` rows are created in `pending` before redirect with an idempotency key; the verified webhook is the only authority for `succeeded`.
- `/b/{manage_token}` renders a "Pay balance" button that mints a fresh session; emailed balance links point at that page, never at a gateway URL that can go stale.
- Balance reminders and the unpaid-balance dashboard become M2 and M5 work.
- Secret credentials never leave the server; the widget only ever receives a redirect URL (SEC-9).
