# The Kaiki widget

The booking widget an operator puts on their own site. One `<script>` tag, no
build step, no plugin — the tag never changes, and neither does anything else on
their page.

Spec: §7.1 (WGT-1 … WGT-23), ADR-0011 for versioning, ADR-0023 for the Shadow
DOM. `docs/api.md` is the authority on every endpoint named here.

---

## The embed

```html
<script
  src="https://book.kaiki.gr/widget/kaiki-widget.js"
  data-key="pk_live_xxxxxxxxxxxx"
  data-product="8f14e45f-ceea-467a-9f39-9c0e15d0a1e2"
></script>
```

That is the whole thing. The widget renders where the tag sits.

### Attributes

| Attribute | Required | Default | What it does |
|---|---|---|---|
| `data-key` | yes | — | The operator's **publishable** key. Safe on a public page: it may read a catalogue and start a booking, and it may do nothing else. |
| `data-mount` | no | `booking` with `data-product`, otherwise `list` | Which of the four mounts to render: `list`, `booking`, `calendar`, `enquiry`. |
| `data-product` | for `booking` | — | The product's UUID. |
| `data-category` | no | — | Narrows a `list` mount to one category, and narrows the fetch itself. |
| `data-locale` | no | the page's `lang`, then the operator's default | `el` or `en`. |
| `data-analytics` | no | `true` | `false` switches off the events below. Anything else leaves them on. |
| `data-target` | no | — | A CSS selector to render into, for page builders that will not let a script sit where the widget should appear. |
| `data-link` | no | — | `trip` on a `calendar` mount: every day with room becomes a link to the trip's page on Kaiki, with that day already chosen. Without it the calendar is read-only. |
| `data-date` | no | — | A `YYYY-MM-DD` the `booking` mount opens on, at the party step. Kaiki's own trip page sets it from its `?date=`; you rarely need to. |

**The calendar that leads to the booking** — for a website of your own. Put the
calendar on your trip's page, and a guest who presses a day lands on the same
trip on Kaiki with the day chosen, picks the party and pays:

```html
<script src="https://book.kaiki.app/widget/v1/kaiki-widget.js"
        data-key="pk_live_…"
        data-mount="calendar"
        data-product="PRODUCT-UUID"
        data-link="trip"
        defer></script>
```

There is **no `data-api`**. The widget calls the origin it was served from, which
is what makes a stolen embed snippet useless somewhere else.

There is no theme attribute either. Guest-facing surfaces are light, settled in
the design review of 2026-09-04: an operator picks their colours against white,
on a boat, in daylight, and handing the decision to a visitor's night mode meant
rendering those colours on a dark ground the operator had no way to see.

### Several widgets on one page

Every `<script data-key>` on the page is a separate embed with its own Shadow
root, so a landing page can carry a list at the top and a booking form further
down. They share one HTTP cache and one branding fetch.

---

## Content-Security-Policy (WGT-22)

The widget needs **no `unsafe-inline`**. Its styles are injected into the Shadow
root by script, which is the entire reason it does not ask for that permission —
an embed that forced `unsafe-inline` onto an operator's site would be weakening
their page to sell them a booking form.

A working policy, with `book.kaiki.gr` standing in for the platform domain:

```
Content-Security-Policy:
  script-src 'self' https://book.kaiki.gr;
  connect-src 'self' https://book.kaiki.gr;
  style-src 'self';
```

- `script-src` — the bundle.
- `connect-src` — the API. The widget calls exactly five paths under
  `/api/v1`: `/branding`, `/products`, `/availability`, `/bookings` and
  `/enquiries`. `WidgetCompatibilityTest` fails if that list ever grows without
  this line growing with it.
- `style-src` — `'self'` is enough. **Add `https://fonts.googleapis.com` and
  `https://fonts.gstatic.com` only if the operator chose a Google font**; with
  the default system font stack the widget makes no third-party request at all
  (WGT-10, GDR-12).

No `img-src` entry is needed. The widget loads no images: the operator's identity
is carried by their colours and their name, which is also what makes it render
the same on a page that blocks remote images.

If a page builder runs the widget inside an iframe of its own, the policy above
belongs on the document the iframe loads.

---

## Analytics events

The widget dispatches eight `CustomEvent`s on `window`, so an operator can wire
their own analytics without the widget knowing which one they use:

`kaiki:ready`, `kaiki:product-viewed`, `kaiki:availability-loaded`,
`kaiki:booking-started`, `kaiki:checkout-started`, `kaiki:booking-confirmed`,
`kaiki:enquiry-submitted`, `kaiki:error`.

```html
<script>
  window.addEventListener('kaiki:booking-confirmed', (event) => {
    gtag('event', 'purchase', {
      items: [{ item_id: event.detail.product_uuid }],
      value: event.detail.value_cents / 100,
      currency: event.detail.currency,
    });
  });
</script>
```

Every payload passes an allow-list before it leaves the widget, and it is short:
`widget_version`, `mount`, `locale`, `product_uuid`, `departure_uuid`, `date`,
`pax`, `value_cents`, `currency`, `error_code`. Nothing else survives — a guest's
name, email and phone are never in an event, and neither is a booking reference
or a manage token. `data-analytics="false"` stops all eight.

---

## Versions, and why the tag never changes (ADR-0011)

Two paths serve the same bytes:

| Path | Cache | Who uses it |
|---|---|---|
| `/widget/kaiki-widget.js` | 5 minutes, revalidated | **operators** — this is the embed |
| `/widget/v0.1.0/kaiki-widget.js` | one year, immutable | the platform, internally |

An operator's `<script>` tag names the alias, because it lives in a theme file,
a page builder or a support email from two years ago — nowhere anybody is going
to edit. Releases move the alias; the snippet stays as it was written.

`GET /widget/manifest.json` says which version the alias currently points at.

That also means a release reaches every embed at once, which is why repointing
the alias is gated on three checks passing first — the 80 KB size budget, a
Playwright run on all four mounts against the built artefact, and the API
compatibility test. See `.github/workflows/widget-release.yml`; `CiGatesTest`
holds that shape in place.

**An operator cannot pin a version**, and this is deliberate: a pinned embed is
code nobody is testing against the current API.

---

## What the widget does not do

- **It renders nothing without JavaScript** (WGT-23). Crawlable content is the
  hosted pages' job, and the WordPress plugin's.
- **It never shows a price for a `quote` product** (BKG-24). Not hidden with
  CSS — the element is not rendered, so the number is not in the DOM.
- **It never computes a price.** Every figure a guest sees came from the API
  (WGT-13), because a widget that could do arithmetic is a widget that can
  disagree with the invoice.
- **It never hardcodes a colour** (WGT-9). Everything is a `--kaiki-*` custom
  property, which is also how the branding screen's live preview repaints as an
  operator types.
