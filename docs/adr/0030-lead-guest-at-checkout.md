# ADR-0030 — The lead guest is collected at checkout, not at draft creation

- **Status:** Accepted
- **Date:** 2026-09-09
- **Decided by:** the product owner
- **Amends:** BKG-7, WGT-18
- **Affects:** BKG-5 (FIXED, unchanged), BKG-7, BKG-9, WGT-18, WGT-19, TOK-1, GDR-9

## Context

The product owner described the flow they want in one sentence:

> *"path needs to be this: user select dates, selects adults, childs etc. then
> goes to checkout where they need to complete all the στοιχεια and then pays."*

and again, about the trip page specifically:

> *"στο single page απλά να υπάρχει ημερομηνία, μετά άτομα (ενήλικες, παιδιά,
> βρέφη) και μετά πάμε για checkout. εκεί θα συμπληρώνουν στοιχεία για πρώτη
> φορά."*

WGT-18 has the widget walking five steps — date, party, extras, **contact and
consent**, review — inside an embed that is typically 380 pixels wide on
somebody else's page. BKG-7 makes the lead guest's name, email and consent
*required at draft creation*, which is what forced the contact step to come
before anything else could happen.

Two things are wrong with that, and the second is the one that matters:

1. **A name and an email are asked for before a price is shown.** The guest is
   made to identify themselves in order to find out what the trip costs. Every
   booking engine that does this loses people at that step, and the widget's own
   review step is where the total finally appeared.
2. **A draft is not a booking by a person. It is a hold on seats.** BKG-9 is
   explicit that the seats are merely held until the checkout redirect commits
   them. Requiring an identity to take a hold conflates two different things
   that happen at two different times.

## The options

**A. Keep BKG-7 and collect the details in the widget.** No code changes, and it
is the flow the product owner has just rejected in writing, twice.

**B. Create the draft only when the checkout form is submitted.** No schema
change, no contract change. It reintroduces the exact defect issue #111 found:
the hold would begin and end in the same request, so `hold_expires_at`,
`Hold.tsx` and `countdown.ts` would again be code that nothing can observe. The
form-filling minutes are precisely the window a hold exists to cover.

**C. Make the lead guest nullable on a draft, and require it before payment.**
The widget takes a date and a party, creates the draft, and hands the guest to
the hosted checkout page at `/c/{manage_token}` (TOK-1) with the hold already
running. The checkout page asks for the name, the email, the phone, the
passenger manifest where the trip needs one, and the consent — then pays.

## The decision

**C.**

`bookings.guest_name` and `bookings.guest_email` become nullable. Nothing else
about a booking changes: BKG-A1 still lists a lead guest among a Booking's
fields, and every confirmed booking still has one.

**The invariant moves rather than disappearing.** It used to be "no draft
without a lead guest". It is now **"no payment without a lead guest and a
recorded consent"**, enforced in `StartCheckout` — the single point both the API
and the hosted checkout page go through on the way to a gateway. That is a
stronger place for it: the old rule could be satisfied by a name typed into a
draft that was then abandoned, and it said nothing at all about the moment money
moves.

BKG-5 is FIXED and is **unchanged**: availability read → `POST /bookings`
creating a draft with a hold → `POST /bookings/{id}/checkout` returning a
gateway redirect → webhook → `confirmed`. The order is the same order. Only the
step at which a person types their name has moved, from before the first arrow
to between the second and the third.

GDR-9 is unaffected in substance — consent is still stored as a timestamp with
an IP address — but it is now recorded on the checkout page rather than in the
embed. It is recorded *closer* to the payment it authorises, which is what a
consent record is for.

## What this costs

- `POST /api/v1/bookings` accepts a request with no `guest` object and no
  `terms_accepted`. An integrator who sends them still gets the old behaviour,
  so this is additive at the contract level, but the API's own documentation has
  to say when they become required.
- `POST /api/v1/bookings/{id}/checkout` gains a refusal — `422
  lead_guest_required` — that no client met before.
- Sixteen readers of `guest_name` exist across manifests, exports, invoices and
  the charter agreement. Every one of them reads a **confirmed** booking, so
  none changes behaviour; they change types.
- WGT-18's contact and review steps are deleted. The review step was already the
  known defect it was written to fix: it took a `quote` prop nothing supplied,
  so it rendered «Υπολογίζουμε την τιμή σας…» permanently and the guest pressed
  pay having never been shown a total. The checkout page shows the price out of
  `price_snapshot`, and `CheckoutPageTest` asserts the figure is on the page.

## Consequences

The widget shrinks. Two steps, two components and a form's worth of validation
leave the bundle, which the 80 KB budget (WGT-1) is grateful for. The embed
becomes what it should have been: a way to choose a date and a party. Everything
that needs a keyboard happens on a full-width page on the operator's own domain,
where a passenger manifest of eight people is a form rather than a hostage
situation.
