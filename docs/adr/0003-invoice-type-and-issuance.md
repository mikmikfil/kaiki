# ADR-0003: Invoice type selection (ΑΛΠ vs ΤΠΥ) and auto-issue vs manual issuance

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner (with the operator accountant)
- Related: §4 (Invoice), §6, §10 (myDATA) of `docs/BRIEF.md`; requirements MYD-1 … MYD-5, BKG-24

## Context
§4 defines `Invoice.type` as `ΑΛΠ` (retail receipt, no customer tax data) or `ΤΠΥ` (services invoice, requires customer ΑΦΜ) and says "Auto-issue on confirmation (setting) or manual". It does not define how the type is chosen, when the choice is locked, or what the default of the setting is. The guest supplies ΑΦΜ optionally and may supply it *after* confirmation, in the guest-details flow, which is exactly when an already-issued ΑΛΠ becomes wrong and needs a cancellation invoice. This blocks **M6** and constrains the checkout and guest-details forms designed in **M2**.

## Options

### Option A — Type derived from data; issuance deferred by a short delay after confirmation
Rule: if the booking carries a validated customer ΑΦΜ plus legal name at issuance time, issue ΤΠΥ, otherwise ΑΛΠ. The issuance job runs N minutes after `BookingConfirmed` (default 15) so the guest can still tick "Χρειάζομαι τιμολόγιο / I need a company invoice" on the confirmation page. Auto-issue defaults **on**.
Pros
- Matches how Greek operators work: most sales are retail, a minority of companies ask for a τιμολόγιο right after paying.
- No operator intervention in the common case; the §12 reliability rules (queued, idempotent, retried) are preserved.
- Type selection is a pure function of booking data, so it is unit-testable and explainable in the operator error feed.
Cons
- The delay window is a new concept to document and to explain to operators.
- A guest who asks for a τιμολόγιο an hour later still triggers cancellation plus re-issue.
- Requires ΑΦΜ validation (format and checksum, EU VAT prefix for foreign customers) before the job runs.

### Option B — Guest chooses receipt vs invoice explicitly during checkout; issue immediately on confirmation
Pros
- No ambiguity and no delay window; the payload is complete at confirmation.
- Cleanest audit story: what was requested is what was issued.
Cons
- Adds a mandatory decision to checkout for every guest, most of whom are tourists who do not know what ΤΠΥ means. Directly costs conversion, which is the core value proposition.
- Checkout gains conditional fields (ΑΦΜ, ΔΟΥ, company name, country) that must be translated, validated and tested in EL and EN.

### Option C — Manual by default; the operator issues from the Filament panel
Pros
- Zero risk of a wrong automatic issuance; the accountant stays in control.
- Simplest possible M6.
Cons
- Every booking becomes a manual task, contradicting "Greek compliance built in" (§1).
- Guaranteed to drift, and myDATA has submission deadlines.

## Recommendation
**Option A**, with per-tenant settings `invoice_auto_issue` (default **on**) and `invoice_auto_issue_delay_minutes` (default 15), plus a light "I need a company invoice" toggle on the confirmation page rather than in checkout. It keeps checkout minimal, keeps the common path automatic, and reduces the cancellation-and-reissue rate. Operators who want Option C simply turn the setting off.

## Consequences if accepted
- Booking gains customer tax fields (ΑΦΜ, ΔΟΥ, legal name, country code) populated from checkout, confirmation page, or guest-details flow.
- `IssueInvoice` is a pure type-resolution function plus a delayed queued job, idempotent on (`booking_id`, `type`, `series`).
- Changing tax details after issuance triggers the cancellation-invoice path (§10) and a re-issue; both appear in the operator error feed.
- Per-tenant settings and the onboarding wizard gain the two invoice settings.
- An ΑΦΜ validation utility (9-digit modulus-11 checksum) is required in `app/Domain/Compliance`.
