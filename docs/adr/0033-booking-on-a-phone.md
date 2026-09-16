# ADR-0033: How does a guest book from the trip page on a phone?

- Status: **Accepted (Option A)**
- Date: 2026-09-15
- Decided: 2026-09-15 — the product owner reviewed the prototype and said to build it. The WGT-2 objection was measured out of the way first: the widget is at **23.5 KB gzipped of the 80 KB budget**, 29.3% used, so the sheet has room. WGT-22 remains the live risk and its fallback is part of the work, not a follow-up.
- Deciders: product owner
- Related: `docs/spec.md` WGT-1, WGT-2, WGT-13, WGT-17, WGT-18, WGT-19, WGT-21, WGT-22, WGT-23; HOS-4; [ADR-0011](0011-widget-distribution-and-versioning.md), [ADR-0030](0030-lead-guest-at-checkout.md), [ADR-0031](0031-checkout-stays-on-the-kaiki-page.md)
- Raised by: a mobile review of the hosted trip page on 2026-09-15.
- **Prototype:** <https://claude.ai/artifact/T4xR4PYKWyLWSCZmLi2Rtz> — Option A at phone width, with a «Σήμερα / Πρόταση» switch. Scroll the page, open the sheet, pick the 16th, add two adults; then switch to «Σήμερα» for how far down the card currently sits. It shows the general case only: the WGT-22 and WGT-23 fallbacks are annotated beside it rather than built.
- **Blocks nothing that has shipped.** The trip page works. This decides whether the booking card stops being the last thing on it.

## Context

On a phone, the booking card is at the bottom of the trip page, under everything else.

`resources/views/hosted/product.blade.php` puts the page in `.product-main` and the booking card in `.product-aside`, in that source order. `resources/views/hosted/layout.blade.php` introduces columns only at `min-width: 60rem`. Below that it is one column in source order, so a guest on a phone reads:

```
lead photograph → title + standfirst → five facts → the itinerary
→ tabs (departures, meeting point, vessel) → FAQ → gallery
→ and only here, the price and the date picker
```

Nothing shortens that. `product.blade.php` gives the booking section `id="book"`, and no link on the page points at it.

**The stylesheet already says so.** The comment above the rule records that the card is last on a phone, and declines to fix it with `order: -1` for the right reason: `.product-main` opens with the title and the standfirst, so hoisting the aside alone puts a price and a date picker above the name of the trip they belong to. It calls the real fix a markup change rather than a rule. That judgement stands; this ADR is about what the markup change should be.

### Three things make the card worse once a guest reaches it

- **The widget has no responsive behaviour at all.** The only `@media` in `packages/widget/src` is `prefers-reduced-motion` in `shadow.ts`. It renders identically at 375 px and at 1440 px.
- **The selection disappears.** `FourLines` shows title, duration, port and vessel — static product metadata. It never shows the chosen date, time or party, so the departure a guest picked on the date step is gone from view on the party step.
- **The price never moves.** `Από 55,00 €` stands through date, party and extras; the real total first appears on `/c/{manage_token}`. A guest who has chosen two adults is looking at a number that is not what they will pay.

Separately, `PartyStep.tsx` caps the party at a hardcoded `max="99"` rather than at the vessel's capacity or the departure's remaining seats.

### What is technically true today

No ancestor of the mount on the hosted page sets `transform`, `filter`, `perspective`, `contain` or `will-change`; the only transforms in the hosted stylesheet are leaf hover decorations. So a `position: fixed` element inside the widget's Shadow root anchors to the viewport **on the hosted page**. That is not a promise we can make about an arbitrary WordPress page — see Consequences.

## Options

### Option A — the booking card becomes a bottom sheet below 60rem, owned by the widget

`.product-aside` is fixed to the bottom of the viewport on narrow screens, collapsed to a peek bar and expandable to the full booking walk. Same DOM, same widget instance, same state: a CSS state change on the existing card, not a second component. Desktop keeps the sticky sidebar unchanged.

The peek bar carries the price, a one-line summary of what has been chosen, and the primary action; all three track progress, so the bar doubles as the step indicator WGT-18's walk does not have:

| State | Price | Summary line | Action |
|---|---|---|---|
| Nothing chosen | `από 55,00 €` | `Διαλέξτε ημερομηνία` | `Κράτηση` |
| Date chosen | `από 55,00 €` | `16 Σεπ · 09:00` | `Συνέχεια` |
| Party chosen | `110,00 €` | `16 Σεπ · 09:00 · 2 άτομα` | `Κράτηση` |

Two details the prototype settled that prose had not:

- **`από` disappears the moment a real total exists.** It is not decoration — it is the difference between an indication and a promise, and a bar still reading `από 55,00 €` after two adults have been entered is lying on every scroll.
- **The peek button stays short.** An earlier draft of this table gave it `Κράτηση 110,00 €`; at 372 px the amount is already sitting 19 px away on the same row and the two do not fit. The pinned CTA inside the open sheet is where the label repeats the amount, because that is where the width is.

Two states only — peek and expanded, no half-height snap point. Expanded is capped at `85svh` (not `vh`: iOS Safari's collapsing chrome makes `vh` overshoot and clip the button) with the price header pinned at the top, the walk scrolling between, and the CTA pinned at the bottom so the action is never scrolled away.

Because WGT-23 says the widget renders nothing without JavaScript, the **peek bar is server-rendered by the hosted page** as a plain anchor to `#book`, and the widget upgrades it into a sheet. HOS-4 requires the page to work without JavaScript for all content, so this is the floor rather than an extra.

Three prerequisites are part of the same decision, because the bar is dishonest without them:

1. **A live total** — a `POST /api/v1/price-quote` on party change, debounced, through the WGT-17 cache. WGT-13 is untouched: the widget still computes nothing.
2. **`FourLines` carries the selection**, not just the product.
3. **The party is capped at the departure's remaining seats**, not at 99.

### Option B — the hosted page owns the sheet

The same design, built in Blade and the hosted stylesheet rather than in the widget. No bundle cost, no Shadow-DOM positioning question, no WGT-22 exposure.

But the sheet would then exist only on `book.{platform-domain}` pages. Every operator who embeds the widget on their own site — which is what the WordPress plugin is for, and what WGT-3 and ADR-0011 exist to distribute — keeps the current mobile experience. It also splits the booking UI across two codebases that have to stay in step.

### Option C — no sheet; hoist `.product-head` so the card is third

Lift the title, standfirst and facts out of `.product-main` so that a phone reads photograph, title, booking card, then the page. Pure markup and CSS, no fixed positioning, no new JavaScript, no WGT-22 risk, and it is the change the existing stylesheet comment already scoped.

It puts the card near the top, but it does not follow the guest down the page: someone who reads the itinerary and the FAQ first — which is most of them, on a trip they have not taken — is back to scrolling up.

## Recommendation

**Option A**, with Option C's hoist folded into it so that the no-JavaScript and transformed-ancestor fallbacks land somewhere sensible rather than at the bottom of the page.

If A is rejected on bundle size or on WGT-22 risk, **C is the fallback** and is worth doing on its own.

## Consequences if A is accepted

**Costs to accept with it, not discover afterwards:**

- **WGT-22 is the real risk.** A `position: fixed` element — inside a Shadow root or not — is trapped by any ancestor with `transform`, `filter`, `perspective`, `contain: paint` or `will-change`. Elementor, Divi and WPBakery all apply transforms to animated sections. WGT-22 requires the widget to render correctly inside "iframe-heavy page builders", so the widget must **detect a transformed ancestor at mount and fall back to the in-flow card**. That detection is code, and it needs a test with a transformed host.
- **WGT-2 is a hard CI gate at 80 KB gzipped.** Sheet, focus trap, scroll lock and ancestor detection are new bytes. Measure before accepting, not after.
- **WGT-21 is FIXED at WCAG 2.1 AA**, so Escape, a focus trap, `inert` on the page behind and focus returned to the opener are requirements rather than polish. A native `<dialog>` gets most of them free and is worth the `::backdrop` awkwardness inside a Shadow root.
- **WGT-19's hold countdown belongs in the peek bar** once a draft exists, or a guest who collapses the sheet loses sight of the clock that is running against them.
- **The page needs bottom padding** equal to the bar, or the footer and the last FAQ sit under it permanently.
- **Safe area.** Nothing in the hosted layout handles `env(safe-area-inset-bottom)` today; without it the CTA sits under the iPhone home indicator.
- **Stacking.** The gallery lightbox is `z-index: 60`. The sheet and the lightbox have to agree, and a sheet over an open photograph is wrong.
- **One new request per party change**, where the walk previously made none.

**What improves, beyond the scroll:** the walk gains a progress indicator it does not have; the chosen departure stops vanishing between steps; the quoted price stops being wrong; and a party larger than the boat is refused at the point of entry instead of at checkout.

## What this does not decide

- **The checkout page.** `/c/{manage_token}` has its own mobile ordering defect — `.checkout-side` is first in the DOM, so a phone meets the pay button above the form it submits — and ADR-0031 settled that the checkout stays where it is. That is a separate change and does not need this one.
- **Whether the WordPress plugin's SEO pages render the peek bar.** They would need to, to get the same no-JavaScript floor; scope it when the plugin next moves.
- **The from-price wording.** `από 55,00 €` before a date is chosen is a separate copy question.
