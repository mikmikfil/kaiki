# ADR-0002: Where the VAT rate lives and how it is resolved for myDATA

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner (with the operator accountant)
- Related: §10 (myDATA) of `docs/BRIEF.md`; requirements MYD-6 … MYD-12, PRC-14, CAT-11

## Context
myDATA requires a VAT category per invoice line. §10 says passenger transport is typically 13% and other tourist services 24%, marks the actual rates **DECIDE with accountant**, and forbids hardcoding. Two things are being decided and they must not be confused: (a) *which rate applies to which product* — an accountant answer, per operator and possibly per product; (b) *where the rate lives in the system and how the engine resolves it for a given booking line* — the architecture answer, which is what this ADR is for. This blocks **M6** (myDATA issuance), but the storage decision must land in **M1**, because the product/rate-plan schema in `docs/data-model.md` is written then and adding a per-line tax dimension later is a data migration across `price_snapshot` JSON.

## Options

### Option A — `vat_rate_id` on Product (and Extra), resolved per line and snapshotted at booking time
A platform-owned `vat_rates` reference table (code, percent, myDATA `vatCategory` id, `valid_from`, `valid_to`, description EL/EN). Each Product points at one rate; Extras may override. The resolved percent and myDATA category are copied into `price_snapshot` when the booking is priced.
Pros
- One source of truth; a statutory rate change is a new row plus a re-point, never a code deploy.
- Historic invoices stay correct because the snapshot froze the rate at booking time — consistent with the §5.7 price snapshot and §5.9 policy snapshot rules.
- Per-line granularity handles "the cruise is transport, the barbecue extra is catering" without special cases.
- Reduced island-rate regimes and future changes are modelled as data.
Cons
- Operators must set a rate on every product; onboarding needs a default and a validation gate before myDATA can be switched on.
- Needs a small admin surface: super-admin maintains the rate table, operator picks per product.

### Option B — Tenant default percent plus optional per-product override, no reference table
Two nullable columns: `tenants.default_vat_percent` and `products.vat_percent`. The myDATA client maps percent to `vatCategory` in code.
Pros
- Least schema; fastest to build.
- Operators with a single rate configure it once.
Cons
- The percent-to-`vatCategory` mapping lives in PHP, which is a soft form of hardcoding and drifts when AADE changes categories.
- No validity dates, so a mid-season statutory change silently rewrites the meaning of old rows unless every booking snapshots the percent anyway.
- Extras cannot carry a different rate without adding a third column later.

### Option C — Rate lives on the RatePlan
Pros
- Seasonal variation falls out for free.
Cons
- VAT is a property of the service supplied, not of the season it is sold in; duplicating the rate across every RatePlan invites divergence.
- Extras and `on_request` items have no RatePlan, so they need a separate mechanism anyway.

## Recommendation
**Option A.** It keeps rates as data with validity dates, keeps the AADE `vatCategory` mapping next to the percent, gives per-product and per-extra granularity, and the snapshot rule means an accountant changing a rate never rewrites history. The accountant answer then becomes data entry during onboarding rather than an engineering change. Ship the table and the snapshot fields in M1 with a nullable default; gate myDATA activation (M6) on every sellable product having a rate assigned.

## Consequences if accepted
- `docs/data-model.md` gains a platform-owned `vat_rates` table and `vat_rate_id` on `products` and `extras`.
- `price_snapshot` JSON gains per-line `vat_percent` and `vat_category` (PRC-14); displayed prices stay VAT-inclusive integer cents and the net/VAT split is derived for the invoice only.
- The onboarding wizard (§11) gains a VAT step; myDATA activation is gated on it.
- The myDATA client never contains a numeric rate; it reads `vat_category` from the snapshot.
- `docs/compliance/mydata-errors.md` documents AADE responses when the declared category disagrees with the amounts.
