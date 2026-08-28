# ADR-0019: Policy for packages that §3 does not list but the MVP scope requires

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3, §15 (shared agent rules) of `docs/BRIEF.md`; requirements ARC-10 … ARC-14

## Context
§3 fixes the stack table and the agent rules forbid introducing any package outside it without an ADR. Auditing the MVP scope against that table shows several capabilities that are required by §6 to §11 but have no package named for them, so the rule will be hit repeatedly during M2 to M6 and will block work each time. The gaps found so far: QR code generation for e-tickets and myDATA invoice QR (§6, §10); iCal feed generation and parsing (§2, §9); CSV export for manifests, bookings and guests (§9); MJML compilation for email templates (§3 Email); phone-number validation and normalisation for SMS delivery (§3 SMS); role and permission management beyond the three roles in §4; image processing for logo upload and auto-resize (§7); and a static site generator for the docs site (§11). Deciding the *policy* now avoids nine separate stalls later. Blocks nothing today; blocks **M2** onward the first time it is hit.

## Options

### Option A — Batch-approve a named shortlist now; anything else still needs its own ADR
Approve, by name and with a stated reason, the smallest set that closes the gaps: `bacon/bacon-qr-code` (QR, no runtime deps, MIT, used by Laravel Fortify already), `spatie/icalendar-generator` plus `sabre/vobject` for parsing imported feeds, `league/csv` (streaming CSV, avoids memory blow-ups on large manifests), `propaganistas/laravel-phone` (wraps giggsey/libphonenumber for SMS normalisation), `spatie/laravel-permission` **only if** the three fixed roles prove insufficient, `intervention/image` or `spatie/image` for logo resizing, MJML via an npm dev dependency with compiled HTML committed (no PHP package, no runtime dependency). Docs site deferred to its own ADR at M7.
Pros
- Removes eight future stalls in one decision; all are boring, widely used, well-maintained Laravel-ecosystem packages, which is exactly the §3 preference.
- Each package is named with its justification, so the audit trail is preserved.
- Keeps the rule intact for genuinely significant additions (search engines, state machines, event sourcing, alternative queue drivers).
Cons
- Approving packages before the code that needs them exists risks approving one that turns out unnecessary. Mitigate by requiring that each is added only at the moment it is first used, with the usage cited in the pull request.

### Option B — One ADR per package, written when first needed
Pros
- Maximum scrutiny; nothing is added speculatively.
Cons
- Eight interruptions during implementation milestones, each requiring a human answer before work can continue. Given the one-issue-per-session workflow (§0), this is expensive.

### Option C — Avoid packages: hand-roll QR, iCal, CSV and phone normalisation
Pros
- Zero new dependencies; no supply-chain surface.
Cons
- QR encoding and iCal recurrence parsing are exactly the kind of fiddly, well-solved problems that should never be hand-rolled; libphonenumber cannot be reimplemented sensibly. This trades a small dependency risk for a large correctness and maintenance risk.

## Recommendation
**Option A.** Approve the named shortlist as an explicit amendment to the §3 stack table, with the rule that each is installed only when first used and cited in the pull request that adds it. Keep the ADR requirement in force for anything not on the list, and for the docs-site generator, which has a wider blast radius and can wait for M7. Add `composer audit` and `npm audit` (already required by §12) as the ongoing control.

## Consequences if accepted
- `docs/spec.md` §3 carries an "Approved packages" table: the §3 originals plus the shortlist, each with the requirement ID that justifies it.
- Agents may install a listed package without a new ADR; installing anything else remains a hard stop.
- CI enforces the list: a test asserts `composer.json` requires nothing outside the approved set.
- MJML stays a build-time dependency; compiled email HTML is committed so production never needs Node.
- A future ADR covers the docs-site generator before M7.
