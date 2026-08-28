# ADR-0012: Guest document encryption approach and the GDPR purge retention window

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner (with a data-protection view)
- Related: §4 (BookingGuest), §10 (GDPR), §14 M6.36 of `docs/BRIEF.md`; requirements GDR-1 … GDR-12, SEC-11

## Context
`BookingGuest` stores passenger name, date of birth, nationality and document type plus number; §4 marks the document number encrypted and §10 requires auto-purge of guest documents N days after departure (default 90, configurable), plus per-guest data export and deletion. The manifest that goes to the Λιμεναρχείο needs the document number in clear text at export time, and the harbour may ask for it after the trip, so "encrypt and throw away the key" is not viable. The choice is about *which* encryption primitive, because it decides whether the operator can ever search by document number and how key rotation works. Blocks **M6**; the column definition must be right in **M2** when `BookingGuest` is created.

## Options

### Option A — Laravel `encrypted` cast (AES-256-GCM under `APP_KEY`) on document number, with a blind index only if search is ever required
Pros
- Exactly what §3 already mandates for credentials; one mechanism in the codebase.
- Transparent to Eloquent; the manifest exporter simply reads the attribute.
- Rotation is a re-encryption command over a bounded set of rows.
Cons
- Not searchable or sortable. Acceptable: no requirement in the brief searches by document number, and adding one would be a privacy regression.
- Ciphertext is non-deterministic, so a uniqueness constraint on document number is impossible (also fine; duplicates are legitimate).
- Compromise of `APP_KEY` plus a database dump exposes every document number, same threat model as ADR-0004.

### Option B — Per-tenant data encryption key, wrapped by `APP_KEY` (envelope encryption)
Pros
- Blast radius of a single leaked key is one operator.
- Supports per-tenant "shred the key" deletion, which is a strong GDPR erasure story.
Cons
- A custom cast and key-management code that must be right; a bug makes data unreadable and there is no recovery.
- Key rotation and backup restore become materially harder to reason about for a two-person team.
- No package in §3 covers it, so it is bespoke code on the compliance path.

### Option C — Do not store document numbers at all; collect them at manifest time into a short-lived export
Pros
- Best possible privacy posture; nothing to purge.
Cons
- Contradicts §4 and §6: the guest-details flow exists precisely to collect these ahead of departure, and crew need the manifest without chasing guests on the day.

## Options — retention window
- **90 days after departure** (the brief default) — safe margin over any plausible harbour follow-up, still short.
- **30 days after departure** — tighter data minimisation; risks purging before a late authority request.
- **Configurable per tenant, 30–365, default 90** — what §10 asks for.

## Recommendation
**Option A** with the retention window **configurable per tenant, minimum 30 days, maximum 365, default 90**. The `encrypted` cast is the boring, already-mandated primitive; envelope encryption is real work on the compliance-critical path with a catastrophic failure mode and can be added later behind the same accessor if a customer demands it. Purge document type and number only — name, date of birth and nationality are needed for accounting and dispute history and follow the general booking retention policy, which should be stated separately in the privacy policy.

## Consequences if accepted
- `booking_guests.document_number` and `document_type` use the `encrypted` cast; both are excluded from model `toArray()` output, from Sentry payloads, from structured logs and from the standard bookings/guests CSV export unless the operator explicitly requests the manifest export.
- `PurgeGuestDocuments` scheduled job runs daily, clears document fields for guests whose departure is older than `tenant.document_retention_days`, and writes an audit row (count, tenant, run time) without any personal data.
- Per-guest export and delete actions operate by lead-guest email across bookings, produce a machine-readable JSON plus CSV, and are logged.
- A test asserts document fields never appear in logs, in exception traces, or in any API response body.
- The privacy policy and DPA (M8) must state the retention window and the purge behaviour; the onboarding wizard shows it.
