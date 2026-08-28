# ADR-0007: Human booking reference format and collision strategy

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §4 (Booking) of `docs/BRIEF.md`; requirements BKG-3, BKG-4

## Context
§4 gives the booking reference as "human, e.g. `KAI-7F3K2`". The example is a fixed brand prefix plus five characters. The reference is read aloud on the phone, typed into a manifest, printed on a ticket, quoted in an email subject and used by crew at the pier, so its alphabet, length, case handling and uniqueness scope matter operationally. It is also the string a guest is most likely to mistype into a support chat. The reference is a public identifier and must not be guessable enough to enumerate bookings (the tokenised pages in §6 are the real access control, but the reference still appears in operator search). Blocks **M2**.

## Options

### Option A — `KAI-XXXXX`, 5 characters from a 31-symbol unambiguous alphabet, unique per tenant, random with retry
Alphabet excludes `0 O I 1 L U` (`U` removed to avoid accidental profanity in combination); stored uppercase, compared case-insensitively; input normalised by uppercasing and stripping non-alphanumerics. ~28.6 million combinations per tenant. Generation retries on unique-constraint violation up to 5 times, then widens to 6 characters.
Pros
- Matches the brief example exactly, including length.
- Short enough to read over the phone in one breath and to fit on a QR ticket header.
- Per-tenant uniqueness keeps the space small per operator while remaining globally addressable as (tenant, reference).
- Retry-on-collision is the standard, boring approach and needs no coordination.
Cons
- Not globally unique, so any cross-tenant surface (super-admin search, platform support) must always show the operator too.
- At very high volume for one operator the birthday-collision retry rate rises; harmless but worth monitoring.

### Option B — `KAI-YY-XXXXX` with a two-digit year segment
Pros
- Instantly tells the operator which season a booking is from; resets the collision space annually.
- Helps accounting and archive searches.
Cons
- Longer to read out; three segments invite transcription errors.
- Deviates from the brief example.

### Option C — Sequential per tenant, zero-padded (`KAI-00001`)
Pros
- Trivially unique, no collisions, no retries; natural ordering.
Cons
- Leaks business volume to competitors and to guests (everyone can see the operator has had 43 bookings).
- Enumerable, so any endpoint that accepts a reference must be rate-limited harder.
- Requires a per-tenant counter row and a lock at the exact moment of highest contention (confirmation).

## Recommendation
**Option A.** It is what the brief already shows, it is the least surprising, and the unambiguous alphabet removes the real-world failure mode (a guest reading `0` as `O` on a windy pier). Keep uniqueness per tenant with a composite unique index, generate at draft creation so the reference is stable through the whole lifecycle, and never reuse a reference from a cancelled or expired booking.

## Consequences if accepted
- `bookings.reference` is `char(9)`, unique on (`tenant_id`, `reference`), assigned at draft creation and immutable.
- A `BookingReference` value object owns the alphabet, generation, normalisation and validation; a rule object validates guest input in EL and EN.
- Operator and super-admin search normalise input before lookup; the super-admin panel always displays the tenant alongside the reference.
- The prefix (`KAI`) is a config value so that the global rename in the brief header changes one constant, not the schema.
- Vouchers and quotes use their own formats and their own tokens; they do not share this alphabet space.
