# The Kaiki WordPress plugin

For an operator who already has a WordPress site. It puts your trips and a
working booking form on your own pages, in your own theme, in Greek or English.

Spec: §8 (WPP-1 … WPP-15). ADR-0013 for what a key may do; ADR-0015 for why the
tests run against a real site rather than a local one.

---

## Five minutes, start to finish

1. Install and activate the plugin.
2. **Settings → Kaiki Booking**, paste your **publishable key** (`pk_live_…`),
   press **Save**.
3. Press **Test the connection**. It tells you one of four things, and three of
   them tell you exactly what to change.
4. Put `[kaiki_booking product="…"]` on a page. The trip id is in your Kaiki
   panel, on the trip.

That is the whole setup. Everything below is optional.

---

## The four shortcodes

| Shortcode | What appears |
|---|---|
| `[kaiki_booking product="…"]` | The booking form for one trip: date, party, extras, details, pay. |
| `[kaiki_list]` | Your trips as a grid. `category="shared"` narrows it. |
| `[kaiki_calendar product="…"]` | A month of availability for one trip. |
| `[kaiki_enquiry]` | An enquiry form. `product="…"` ties it to one trip; without it, it is about your fleet in general. |

Blocks and Elementor widgets do exactly the same thing with a mouse instead of a
paste — they are wrappers around these four, so whichever you use, the page ends
up the same.

**The booking script loads only on pages that use one of them.** A page with no
shortcode has nothing extra on it at all.

### When something is wrong with a shortcode

You will see a message explaining it; a visitor will see a short, neutral line
and nothing else. That split is deliberate — the person who can fix it should be
told what to fix, and everybody else should be told as little as possible.

---

## Language

The plugin follows the page: WPML first, then Polylang, then your site language,
mapped to Greek or English. Anything else becomes English.

If your site is in English but your guests are Greek, pin it under
**Settings → Kaiki Booking → Language**.

---

## Live updates

Paste the **update secret** from your Kaiki panel and give Kaiki the address the
settings page shows you. Then a price change in Kaiki appears on your site
immediately rather than when the cache next expires.

Without it, nothing breaks — your trips are simply refreshed on a timer.

---

## Trip pages for search engines

Off by default, and the plugin is complete without it. Switched on, each trip
also becomes a page on your own site at `/tours/…`, with the description and
details written into the page so a search engine can read them.

This is the **only** feature that needs a secret key, and the key is used only by
your site's own scheduled task. **Never paste it into a page, a post, a widget or
a theme file** — anything on a page is public, and a secret key on a page is a
key somebody else can use. If it ever appears on one, delete it in your Kaiki
panel and make a new one.

---

## What the plugin does not do

- **It stores no guest details** (WPP-12). Bookings live in Kaiki; this site
  holds no booking records and no personal data.
- **It never touches WooCommerce** — not the cart, not the session, not the
  checkout (WPP-2).
- **It computes nothing.** Every price and every seat count comes from Kaiki, so
  your site and your invoices cannot disagree.
- **It adds no global styles.** Everything it renders is inside a shadow root, so
  your theme cannot reach into the booking form and the booking form cannot leak
  out onto your pages.

---

## If Kaiki is unreachable

Your pages still render. Visitors see a short message where the booking form
would be, and — for your trip lists — the last catalogue this site saw, rather
than a hole in the page. Availability is never shown from a cache: a guest shown
seats that are gone would book a boat that is full.

You, as an editor, see a message saying which of three things went wrong.
