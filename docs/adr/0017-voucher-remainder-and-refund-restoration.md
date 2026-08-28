# ADR-0017: Voucher remainder when a voucher exceeds the booking total, and voucher restoration on cancellation

- Status: **Accepted (Option A + D)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §4 (Voucher), §5.7, §5.9, §9 (Vouchers) of `docs/BRIEF.md`; requirements PRC-20 … PRC-26, BKG-33

## Context
`Voucher` carries `amount_cents` and `remaining_cents`, which implies partial redemption, but the brief never states what happens when a voucher is worth more than the booking it is applied to, nor what happens to a redeemed amount when the booking is later cancelled. Both are real: weather cancellations issue vouchers for the full paid amount (§5.9), and the guest then rebooks something cheaper. Getting this wrong is a direct financial and trust issue for the operator, and it is not derivable from anything else in the brief. Blocks **M2** (pricing snapshot and voucher application).

## Options — voucher exceeding the total

### Option A — Apply `min(remaining, total)`, keep the surplus on the voucher for later use, never pay cash
Pros
- The obvious guest expectation from a gift-card-like instrument; the guest is not punished for booking something cheaper.
- Encourages rebooking, which is exactly the outcome the weather-cancellation workflow wants.
- `remaining_cents` already exists in the schema, so no change.
Cons
- The operator carries a longer-lived liability; needs an expiry reminder (already in §9) and a liability figure in reporting.
- Multiple small redemptions across bookings need a redemption ledger to stay auditable.

### Option B — Apply `min(remaining, total)` and forfeit the surplus
Pros
- Closes the liability immediately; simpler accounting.
Cons
- Reads as punitive on a voucher that was itself issued because the operator cancelled a trip. Bad for trust.

### Option C — Allow the voucher to make the total negative and refund the difference in cash
Pros
- Strictly fairest to the guest.
Cons
- Turns a voucher into a cash instrument, which changes its accounting and tax treatment, and lets a guest launder a non-refundable payment into cash. Reject.

## Options — restoration when a booking paid partly by voucher is cancelled

### Option D — Restore pro-rata by the applicable refund percent, cash refunded only up to the cash actually paid
If a booking of 200 EUR was paid with a 120 EUR voucher and 80 EUR cash, and the policy gives 50%, the refund entitlement is 100 EUR: restore 60 EUR to the voucher and refund 40 EUR in cash. Voucher expiry is unchanged; if the voucher has already expired, the restored amount is issued as a new voucher with a fresh expiry.
Pros
- Consistent: the guest is neither enriched nor penalised by having paid with a voucher.
- Never converts voucher value into cash.
Cons
- Slightly more arithmetic and a case to explain in the operator UI.

### Option E — Restore nothing; refund only the cash portion, up to the refund percent
Pros
- Simplest.
Cons
- A 100% weather refund on a voucher-paid booking would return almost nothing, which is plainly wrong.

## Recommendation
**Option A plus Option D.** They are the combination a guest would consider fair and an operator would consider safe: voucher value stays voucher value, cash stays cash, and neither can be converted into the other. Add a `voucher_redemptions` ledger (voucher, booking, amount, direction, reason, timestamp) so that every movement is auditable and `remaining_cents` is always reconstructible.

## Consequences if accepted
- `voucher_redemptions` table required in `docs/data-model.md`; `vouchers.remaining_cents` becomes a denormalised value derivable from the ledger, asserted by a reconciliation test.
- Voucher application happens after extras and before deposit calculation; `applied = min(remaining_cents, total_after_extras)`; the total can never go below zero.
- If the voucher covers the whole total, no gateway session is created and the booking moves straight to `confirmed` (BKG-19).
- Vouchers are re-validated (existence, tenant, expiry, remaining amount) inside the confirmation transaction with a row lock; expiry is evaluated against the tenant timezone end of day.
- Restoration on cancellation writes a ledger row; an expired voucher yields a new voucher with `force_majeure_voucher_months` validity and links back to the original.
- The operator can override any of this per booking (§5.9) with a mandatory reason recorded.
