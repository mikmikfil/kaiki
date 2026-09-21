# ADR-0034 — OTA channels: GetYourGuide first, direct, behind a flag

- **Status:** Accepted (Option A)
- **Date:** 2026-09-21
- **Decided by:** the product owner
- **Affects:** **OOS-2 (FIXED)** — amended from *out of scope* to *in scope, flag-gated*;
  **EXT-1 (FIXED)** — resolved, `App\Contracts\Channel` is built; **EXT-2** — the
  `channel_manager` Pennant flag it named is wired up for the first time
- **Amends a FIXED requirement.** OOS-2 says *"any pull request that implements one
  of these is rejected on scope grounds regardless of quality"*. That sentence was
  the product owner's own guard against this exact scope, and it is lifted here
  deliberately rather than walked past.

## Context

Kaiki sells from its own hosted pages and, through the WordPress plugin, from an
operator's site. The product owner asked on 2026-09-21 for the next sales channel:
GetYourGuide, then Viator, then Click&Boat.

Three documents said no. `docs/spec.md:105` (OOS-2) lists the OTA channel manager as
out of scope and rejects any PR that builds one. `docs/KAIKI-ΕΛΛΗΝΙΚΑ.md:493` argues
against it on positioning grounds — *«μεγάλη δουλειά, και αντίθετη στη θέση»* — with
`:28` framing the OTAs as taking 20–30% and owning the customer.
`docs/manual/chapter-whats-coming.html:126-129` promises operators, in the PDF they
are handed, that Kaiki will **not** do this.

Two documents planned for it anyway. **EXT-1 (FIXED)** names
`App\Contracts\Channel` — *"an abstract channel interface with exactly one
implementation, `IcalChannel`"* covering push availability, pull bookings, map
external product ids, acknowledge cancellations, so that *"adding
Viator/GetYourGuide/Bókun later must not require changing the availability or
booking domains"*. **EXT-2** reserves a `channel_manager` Pennant flag, default off.
Neither was ever built: there is no `Channel` interface in `app/`, no `IcalChannel`,
and no Pennant anywhere. Today's iCal is a one-directional pull
(`app/Domain/Availability/Actions/SyncIcalSource.php`) that writes `VesselBlock` rows.

### What the requirement actually is

The product owner's own framing, verbatim:

> *«γενικά αυτό που με νοιάζει είναι η διαθεσιμότητα και το πρόγραμμα. να μην
> υπάρχει overlap μεταξύ των πλατφορμών»*

So this ADR is not about "an integration". It is about **one availability truth
across every platform that sells the same boat**. Every option below is judged on
whether it can promise that, and the answer is what decides it.

### The fact that decides the shape

GetYourGuide's supplier API is a set of endpoints **Kaiki hosts and GetYourGuide
calls** — `GET /1/get-availabilities`, `POST /1/reserve`, `/1/cancel-reservation`,
`/1/book`, `/1/cancel-booking`, `/1/notify`, plus product and pricing-category reads,
all under HTTP Basic. There is a smaller outbound half at
`supplier-api.getyourguide.com` for availability push and ticket redemption.
Viator's supplier API has the same direction: the supplier implements, Viator calls.

**GetYourGuide keeps no copy of the stock.** It asks, every time. That is what makes
the no-overlap promise achievable rather than aspirational.

## Options

### Option A — Direct supplier connection, `Channel` first, GetYourGuide as its first implementation

Build EXT-1's interface, wrap today's iCal pull as its second implementation so it is
shaped by more than one caller, then implement GetYourGuide against it: inbound
endpoints mapped onto the existing availability and booking actions, outbound
availability push on every seat movement.

- **Pro — one truth, structurally.** A GetYourGuide reservation *is* a `HoldSeats`
  call on the same `departures` row the operator's own page sells from. Overlap is
  not prevented by reconciliation; it is impossible by construction.
- **Pro — inherits everything already decided.** Vessel blocks, charter conflicts,
  iCal blocks from other platforms, capacity, the turnaround buffer: the channel
  calls `CheckSeatAvailability` and gets all of it, permanently and for free.
- **Pro — no third party in the commercial relationship.** The operator's own
  GetYourGuide contract; Kaiki stores the credentials and uses them.
- **Con — Kaiki's uptime becomes the operator's sales.** If Kaiki is unreachable,
  GetYourGuide cannot sell at all. Today an outage costs the operator their own page;
  after this it costs a sales channel too.
- **Con — certification is theirs, not ours.** Registration, a contract and
  three-phase certification stand between working code and a live channel.

### Option B — Connect once to a channel manager (Bókun) and reach many OTAs through it

- **Pro — more channels for one integration.**
- **Con — commission on top of commission**, and the intermediary is Bókun, owned by
  Tripadvisor, which owns Viator. A competitor between the operator and their stock.
- **Con — it does not remove the work, it changes the recipient.** There is still a
  connection to build, against a less public spec.
- **Con — it is the copy-based model.** Inventory is pushed, the far side sells from
  its own copy, and the two are reconciled afterwards. That is precisely where
  double-bookings come from, and it fails the one requirement this ADR exists for.
- **Con — contrary to the product's stated positioning**, which is that the operator
  keeps their own customer.

### Option C — Leave OOS-2 as it stands

- **Pro — honest about scope**, and the manual stays true.
- **Con — the product owner has asked for it twice**, phase 2 of the actionseaze plan
  names these three connectors, and the roadmap already prices them at 8–12 hours each.
  Declining is a product decision that has already been made the other way.

## Decision

**Option A, and the flag is part of the decision, not a footnote.**

`App\Contracts\Channel` and the GetYourGuide implementation are built now. They stay
behind the **`channel_manager` Pennant flag (EXT-2), default off**, and the
per-merchant switch in `/admin` cannot be turned on in production until GetYourGuide's
certification has passed. OOS-2 changes from *out of scope* to *in scope, flag-gated,
GetYourGuide only*; Viator becomes a second implementation of the same interface, and
Click&Boat stays unscoped until its partner documents are in hand.

### The rules that make the no-overlap promise true

These are the acceptance criteria for every pull request in this work. A change that
breaks one of them breaks the only thing the feature was asked for.

1. **The channel never computes availability.** It calls `CheckSeatAvailability` and
   `CheckVesselAvailability`. It issues no queries of its own against `departures`.
2. **A reservation is a real hold.** `/1/reserve` creates a draft `Booking` and calls
   `HoldSeats`, moving `departures.seats_held`. No parallel hold concept exists.
3. **A booking is a real booking**, created down the `ImportBooking` path so the nine
   `BookingConfirmed` listeners do not fire — GetYourGuide has already sent the guest
   their voucher — while the seat accounting is identical.
4. **Vessel-level conflicts are inherited, not re-implemented.** A private charter's
   `VesselBlock` stops GetYourGuide seats on that boat through rule 1, as do the
   `external_ical` blocks other platforms already write.
5. **The hold TTL must match GetYourGuide's reserve window.**
   `config('kaiki.booking.hold_minutes')` is 15. If their window is longer, we release
   a seat they still believe is held, resell it, and then receive a `book` for it.
   **This is the one genuine double-sale path in the design**, and the number comes
   from their certification documents, not from a guess.
6. **Push after every seat movement**, so their cached date picker goes stale in the
   safe direction only: it can show a date as available when it is not, and the live
   `reserve` refuses it. It can never hide availability that exists.
7. **Reconcile nightly** into the existing failure feed, as evidence rather than as
   the mechanism.

### What this does not decide

**Commission and net rates stay out of scope**, as `docs/BRIEF.md:58` has them. This
connects availability and bookings. Nothing here computes what anybody is owed.

## Consequences if accepted

- `docs/spec.md` OOS-2 is rewritten with a `(per ADR-0034)` citation; EXT-1 is resolved
  and EXT-2 is partially resolved (one flag, wired).
- **`docs/manual/chapter-whats-coming.html:126-129` becomes false the day the flag
  opens** and must be rewritten then — not before, because until certification passes
  the sentence is still true. It ships in the PDF handed to operators, so this is a
  customer-facing correction rather than an internal one.
- Kaiki gains a public, unauthenticated-by-session route group
  (`/channels/getyourguide/1/*`) outside `/api/v1`, with its own Basic-auth middleware
  and its own tenant resolution. `docs/api.md` §"auth model" must say that `/api/v1`'s
  `pk_`/`sk_` model is **not** the only way in any more.
- An operator's availability becomes dependent on Kaiki's reachability in a way it was
  not before. That belongs in the M8 uptime conversation.
- The interface exists, so Viator is an implementation rather than a project — which
  was EXT-1's entire purpose, four milestones early.

## Amendments after acceptance

**2026-09-21 — rule 5's double-sale path is closed, and it was never their number
to give.** The decision above named the hold window as *"the one genuine double-sale
path in the design"* and said the figure had to come from GetYourGuide's
certification documents. Both halves were wrong, and the product owner found the
page that says so.

Their own supply documentation describes **Reservation Expiration** as a supplier
feature — *"provides recommended times for customers to complete checkout, with a
default of 60 minutes if not supported"* — and their OpenAPI confirms the mechanism:
`ReservationResponse` carries `reservationExpiration`, a date-time **the supplier
returns**.

So Kaiki does not adopt their window; it **declares its own**. `/1/reserve` answers
with the `hold_expires_at` it just wrote, and GetYourGuide honours that instead of
assuming an hour. The gap rule 5 warned about — a hold dying while they still
believe in it — cannot open, because the two numbers are the same number.

This also removes the reason to lengthen a hold for OTA traffic. Sixty minutes of
dead inventory on a twelve-seat boat is a real cost, and it is now avoidable rather
than inherited: the per-channel window in `config/kaiki.php` stays, because a channel
may still want a different one, but its default no longer has anything to fear.

Two other facts from the same schema, recorded so the next issue does not
rediscover them: `gygBookingReference` is required on both `reserve` and `book`, so
it is the idempotency key a retried `book` is matched on; and `BookingResponse`
returns `tickets[].ticketCode` — **Kaiki issues the ticket codes**, which are the
boarding codes BKG-20 already produces.
