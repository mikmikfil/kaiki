# ADR-0024: Two-factor authentication for panel accounts

- Status: **Proposed — awaiting human decision**
- Date: 2026-08-28
- Deciders: product owner
- Related: spec SEC-15, ARC-19, ARC-21; issue #9

## Context

SEC-15 requires two-factor authentication: **optional for operators, required for super-admins**. The reasoning is sound — a super-admin account can read every operator's bookings and every guest's personal data, so a single stolen password should not be enough.

The `users` table already carries `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at` (data-model §2.1), encrypted, added in #5 because SQLite cannot add columns to an existing table later. **The columns are there; nothing writes them.**

The gap is that neither Laravel nor Filament v3 ships a 2FA enrolment flow. Delivering SEC-15 needs a package, and §3.2 lists none — so ARC-19 makes this a hard stop rather than a judgement call. Installing one quietly would be exactly the drift ADR-0019 was written to prevent.

Enforcing the requirement without an enrolment flow is worse than not enforcing it: a super-admin who has never enrolled would be locked out of the platform panel permanently, with no way in to fix it.

Blocks the SEC-15 criterion of **#9**; nothing else in M0.

## Options

### Option A — `laravel/fortify`
First-party, headless, ships TOTP with QR provisioning and recovery codes. The `two_factor_*` column names in the data model are Fortify's own, so the schema already matches.
Pros
- First-party and maintained by the framework team; the lowest-surprise dependency this project could take.
- Headless by design, so it adds backend routes and actions without imposing views — Filament keeps owning the UI.
- The schema needs no change at all, which is unusual and a strong signal the data model was written with this in mind.
Cons
- Brings a login/registration stack that partly overlaps Filament's own; the unused parts must be explicitly disabled or they become a second, unguarded way in.
- One more package outside §3.

### Option B — A Filament 2FA plugin
Community plugins exist that add 2FA directly to a Filament panel.
Pros
- Purpose-built for this UI; least integration work.
- Nothing to disable, no overlapping auth stack.
Cons
- Community-maintained, and this is the authentication path for accounts that can read every tenant's data — the one place where "widely used" matters more than "convenient".
- Plugin quality and maintenance vary; a stale plugin here ages badly.

### Option C — Hand-rolled TOTP
Pros
- No new dependency; RFC 6238 is genuinely small.
Cons
- Hand-rolling authentication is the standard example of what not to hand-roll. The algorithm is the easy part; recovery codes, replay windows, rate limiting on verification and secure enrolment are where this goes wrong, and getting any of them subtly wrong is invisible until it matters.

### Option D — Defer 2FA to M7 with the rest of the SaaS layer
Pros
- M0 has no real super-admins yet and no operator data to protect; the risk today is close to zero.
- Bundles the decision with impersonation (TEN-7) and the super-admin panel work it naturally belongs beside.
Cons
- SEC-15 sits unmet for six milestones, and by M7 there is production data behind those accounts.
- The columns stay dead in the schema, which invites someone to assume the feature exists.

## Recommendation

**Option A, scheduled with the super-admin panel work in M7** — that is, adopt Fortify as the mechanism now so the decision is settled and the columns have a stated owner, but implement enrolment alongside impersonation rather than in #9.

Fortify's column names already match the schema, which strongly suggests this was the intent when the data model was written. The M7 timing is not deferral for its own sake: 2FA matters when there are real super-admin accounts with real operator data behind them, and that is exactly when impersonation lands.

If you would rather have it sooner, Option A in M0 is perfectly buildable — it is roughly a day, most of it the Filament enrolment screens.

## Consequences if accepted

- `laravel/fortify` joins the approved package list as an amendment to §3.2 (ARC-21).
- Fortify's registration, password-reset and login views are explicitly disabled; Filament remains the only way in. A test asserts Fortify's routes are not reachable.
- `two_factor_confirmed_at` becomes required for `canAccessPanel('admin')`, enforced **only after** the enrolment flow exists, with a documented bootstrap path for the first super-admin.
- Optional for operators, with a panel setting and a nudge rather than a block.
- The M7 issue that implements it references this ADR and SEC-15.
