# ADR-0022: Invoice numbering scope and gap policy

- Status: **Accepted (Option A + C (per-tenant external mode))**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner, **operator's accountant**
- Related: §10 (myDATA) of `docs/BRIEF.md`; `docs/data-model.md` §2.6 (`invoices`), §8 item 5; ADR-0003; requirements CMP-9, CMP-14

## Context
`invoices` carries `series` and `number`. Greek practice and AADE expectations around numbering are stricter than a generic SaaS invoice counter: numbering is per series, and whether a series may contain gaps — and who owns the series when an operator already issues invoices from their own accounting software — is a compliance question, not an engineering preference. It bites specifically when a myDATA submission fails: if the number is allocated before the send attempt, a permanent failure leaves a hole in the sequence. `docs/data-model.md` records a provisional default (`unique(tenant, series, year, number)`, allocated at send, gaps allowed and logged) so M1's schema is not blocked, but the answer must come from an accountant before M6 issuance ships. Blocks **M6**; the schema shape blocks **M1** (issue #18) only insofar as the columns must exist.

## Options

### Option A — Per `(tenant, series, year)`, number allocated at the send attempt, gaps allowed and logged
Pros
- Allocating late means a number is only burned when a submission is actually attempted, minimising holes.
- Yearly reset matches how most Greek operators and their accountants already think about series.
- A logged gap with its failure reason is defensible in an audit.
Cons
- Gaps still occur on hard failures and must be explainable; some accountants will not accept any gap.
- Concurrent issuance needs a lock on the counter — another place needing the ADR-0006 treatment on SQLite vs MySQL.

### Option B — Per `(tenant, series)` continuous, no yearly reset, gaps forbidden
Number allocated only after AADE returns a `mark`; a failed submission reserves nothing and is retried without consuming a number.
Pros
- No gaps, ever — the strictest reading, and the easiest to defend.
Cons
- The invoice has no number until AADE responds, so the PDF cannot be generated on confirmation; the guest's document arrives late or in two stages.
- A partial failure (AADE accepted but the response was lost) risks a duplicate submission; recovery needs a reconciliation against AADE rather than a local retry.

### Option C — The operator owns numbering; Kaiki records the number they supply
For operators who already invoice from their own software, Kaiki issues nothing and stores the external number and `mark` for reconciliation.
Pros
- Avoids two systems fighting over one series, which is a real risk for an operator with an existing accountant workflow.
Cons
- Removes the automation the product is selling; the operator still types invoices somewhere else.
- Only viable as a per-tenant *mode*, not as the platform default.

## Recommendation
**Option A as the default, with Option C available as a per-tenant setting** (`invoicing_mode: kaiki | external`). Late allocation keeps gaps rare, the yearly reset matches local practice, and the escape hatch means an operator with an entrenched accounting workflow is not forced to migrate before they trust the platform. The gap question itself must be put to an accountant before M6 — this ADR should not be marked Accepted on engineering judgement alone.

## Consequences if accepted
- `invoices` keeps `unique(tenant_id, series, year, number)`; `number` is nullable until allocated.
- A `series_counters` row per `(tenant, series, year)` is locked during allocation, following ADR-0006's portable pattern.
- Every gap writes an `invoice_number_gaps` audit row with the failure reason, surfaced in the panel in Greek.
- `invoicing_mode = external` disables issuance and exposes an "record external invoice" form instead.
- **This ADR needs an accountant's sign-off, not just the product owner's.**
