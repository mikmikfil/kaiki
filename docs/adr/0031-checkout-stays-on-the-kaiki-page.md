# ADR-0031 — The checkout stays on Kaiki's page

- **Status:** Accepted
- **Date:** 2026-09-11
- **Decided by:** the product owner
- **Affects:** WGT-18, ADR-0030, PAY-1

## Context

On 2026-09-11 the product owner asked whether the payment could happen without
the guest leaving the operator's own website, as FareHarbor does with an
overlay, and then asked for it to be built.

Two facts were established first:

- **Viva's own documentation advises against running Smart Checkout in an
  iframe.** Inside a frame it loses Apple Pay, saved cards, and every method
  that performs its own redirect (Klarna, IRIS, BLIK, P24 and others).
- **Kaiki's pages refuse to be framed on purpose** (`frame-ancestors 'none'`,
  `X-Frame-Options: DENY`): the checkout link carries the booking's manage
  token, which is a credential.

The only workable form was a real browser window over the operator's page,
opened inside the click and watched by polling the booking's status. It was
built, and withdrawn before it was committed.

## Decision

**The checkout stays as it is.** The widget takes the guest's date, party and
extras on the operator's site, then sends the whole page to Kaiki's checkout at
`/c/{manage_token}` (ADR-0030); the payment is taken on Viva's page from there.
In the product owner's words: «μου αρέσει όπως είναι».

## Consequences

Decided at the same time, and built with it:

- **After a successful payment** the guest lands on their Kaiki booking page
  (`/b/{token}`) — ticket, meeting point, changes, cancellation.
- **Both Kaiki pages offer «back to the website»**: the page the guest started
  on, which the widget sends as `origin_url` with the draft and the API keeps
  only on an origin the key allows; failing that, the operator's hosted home
  page.
- **A failed or cancelled payment returns to the checkout page** with a message
  and the guest's details still filled in, while the seats are held.
- **Viva needs somewhere to send the guest back to**: `/pay/viva/success` and
  `/pay/viva/failure`, shown on the operator's Integrations page for them to
  paste into their Viva payment source. They navigate only — confirmation stays
  with the webhook.

If an overlay is asked for again, the window-over-the-page design above is the
starting point, not an iframe.
