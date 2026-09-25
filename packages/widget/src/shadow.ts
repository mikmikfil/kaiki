import { brandProperties, SYSTEM_STACK, type BrandPayload } from './branding';

/**
 * The boundary between the widget and somebody else's page (WGT-1, WGT-22).
 *
 * ## A shadow root, because the host's CSS is not a partner
 *
 * WGT-1 is FIXED on this. The widget runs inside pages with `* { box-sizing }`
 * resets, themes that style every `button` on the page, and builders that wrap
 * everything in three divs with their own grid. A shadow root is the only
 * boundary a browser enforces in both directions: their rules cannot reach in,
 * and ours cannot leak out onto their site.
 *
 * `mode: 'open'` rather than closed — an operator's developer debugging a
 * booking form at midnight should be able to look inside, and `closed` protects
 * nothing here that the page's own JavaScript could not reach anyway.
 *
 * ## Styles are injected by script, so no `unsafe-inline` is needed
 *
 * WGT-22. A `<style>` element created with `document.createElement` and filled
 * through `textContent` is **not** an inline style for CSP purposes — the policy
 * governs markup the parser sees, and this never goes through the parser. So the
 * widget renders on a host with `style-src 'self'` and no nonce, which is the
 * strict policy a serious operator's site actually has.
 *
 * `adoptedStyleSheets` would be neater and is used when it exists, with the
 * `<style>` element as the fallback for Safari before 16.4 — still a real share
 * of the phones a guest books from on a quay.
 *
 * ## Everything visible derives from the operator's palette
 *
 * There is not one colour value in this file. WGT-9 forbids it and
 * `npm run widget:guards` greps the built bundle for hex, so neutrals are
 * `color-mix()` of the operator's own `--kaiki-text` and `--kaiki-background`.
 * That is not a trick to pass the check: it is what makes an operator changing
 * one colour change the whole widget, which is what BRD-8 promises them.
 */

export interface ShadowHost {
  readonly host: HTMLElement;
  readonly root: ShadowRoot;
  /** Where a mount renders. Styles live outside it, so a re-render cannot drop them. */
  readonly slot: HTMLElement;
  /** The branding, then `overrides` — the WordPress plugin's own look — on top. */
  applyBranding(brand: BrandPayload, overrides?: string): void;
}

let instances = 0;

export function createShadowHost(target: Element, doc: Document = document, before: Node | null = null): ShadowHost {
  const host = doc.createElement('div');

  // WGT-8: namespaced, so two widgets on one page are two distinguishable
  // elements in the operator's own dev tools rather than two anonymous divs.
  instances += 1;
  host.setAttribute('data-kaiki-widget', String(instances));

  // The five properties that decide whether the widget is on the page at all,
  // set **inline** and `important` — the one thing an author stylesheet cannot
  // outrank, even with its own `!important`. Anything cosmetic is left alone: a
  // widget that fought the operator's page over a margin would be a widget that
  // never fits into it.
  for (const [property, value] of [
    ['display', 'block'],
    ['visibility', 'visible'],
    ['opacity', '1'],
    ['position', 'static'],
    ['max-height', 'none'],
  ] as const) {
    host.style.setProperty(property, value, 'important');
  }

  const root = host.attachShadow({ mode: 'open' });
  const slot = doc.createElement('div');

  slot.className = 'kaiki-root';

  const brand = adopt(root, BASE_STYLES, doc);

  root.appendChild(slot);
  target.insertBefore(host, before);

  return {
    host,
    root,
    slot,
    applyBranding(payload: BrandPayload, overrides = ''): void {
      // One block, overrides last: a later declaration of the same custom
      // property wins, so only what the operator set changes.
      brand(`:host {\n  ${brandProperties(payload)}${overrides === '' ? '' : `;\n  ${overrides}`};\n}`);
    },
  };
}

/**
 * Put a stylesheet into a shadow root, and hand back a way to replace a second.
 *
 * A **constructed** sheet where the browser has them — it is not markup, so
 * `style-src` does not govern it — and a `<style>` element where it does not.
 */
function adopt(root: ShadowRoot, base: string, doc: Document): (css: string) => void {
  if (typeof CSSStyleSheet === 'function' && 'adoptedStyleSheets' in root) {
    try {
      const baseSheet = new CSSStyleSheet();
      const brandSheet = new CSSStyleSheet();

      baseSheet.replaceSync(base);
      root.adoptedStyleSheets = [baseSheet, brandSheet];

      return (css: string): void => brandSheet.replaceSync(css);
    } catch {
      // Some engine refused a constructed sheet. Fall through rather than leave
      // the widget with no styles at all.
    }
  }

  const style = doc.createElement('style');
  const brandStyle = doc.createElement('style');

  style.textContent = base;
  root.append(style, brandStyle);

  return (css: string): void => {
    brandStyle.textContent = css;
  };
}

/**
 * The widget's own stylesheet.
 *
 * Light only, and deliberately: the design review of 2026-09-04 settled that
 * guest-facing surfaces are light and that light/dark is a dashboard concern, so
 * there is no `prefers-color-scheme` block here. The colours are the operator's
 * and they chose them against their own background.
 *
 * Nothing is upper-cased anywhere in it (I18N-2): Greek capitals drop their
 * accents and browsers disagree about the final sigma.
 */
const BASE_STYLES = `
:host {
  all: initial;
  display: block;
  /* The host page's font size is not ours to inherit — a theme with 62.5% on
     the root would make the whole widget ten pixels tall. */
  font-size: 16px;
  line-height: 1.5;
  font-family: var(--kaiki-font, ${SYSTEM_STACK});
  color: var(--kaiki-text);
  text-align: start;
}

.kaiki-root {
  box-sizing: border-box;

  /* The frame, and the four variables that let a host page take it off.

     A custom property set on the host element inherits through the shadow
     boundary, which is the only thing about a page that may reach in here — and
     that is deliberate: it can restyle the surface the widget sits on and
     nothing else.

     Why it is needed: on Kaiki's own hosted trip page the widget is dropped
     into a card the page has already drawn, and the result was a white bordered
     box inside a white bordered box, two radii and two paddings deep. On
     somebody else's site the frame is right and stays the default — there, the
     widget is a stranger in a page and has to say where it begins. */
  background: var(--kaiki-surface-background, var(--kaiki-background));
  border-radius: var(--kaiki-surface-radius, calc(var(--kaiki-radius, 10px) + 4px));
  border: var(--kaiki-surface-border, 1px solid color-mix(in srgb, var(--kaiki-text) 12%, transparent));
  padding: var(--kaiki-surface-padding, 1.15rem 1.25rem 1.3rem);

  /* **Every inherited property, restated here.**
     The rules on ':host' above are the polite version and they are not enough:
     an inherited property is decided on the *host element*, which lives in the
     operator's document, so a theme with 'font-family: … !important' on '*' wins
     there and the whole shadow tree inherits it. Issue 111's hostile-CSS fixture
     rendered the entire booking form in Comic Sans that way.
     Nothing in the operator's stylesheet can match an element inside a shadow
     root, so restating them on this element is the fix rather than an
     escalation — there is no war of important flags to lose.
     The page's '--kaiki-font' when it sets one (Kaiki's own pages set Inter),
     else the branding's family, else the system's — never whatever the
     operator's theme put on '*'. */
  font-family: var(--kaiki-font, ${SYSTEM_STACK});
  font-size: 16px;
  font-weight: 400;
  font-style: normal;
  line-height: 1.5;
  letter-spacing: normal;
  word-spacing: normal;
  text-transform: none;
  text-align: start;
  text-indent: 0;
  white-space: normal;
  color: var(--kaiki-text);
}

.kaiki-root *,
.kaiki-root *::before,
.kaiki-root *::after { box-sizing: inherit; }

/* Links are never underlined (2026-09-11). Every link here is set in the
   operator's primary or weight instead, and keeps the focus ring below. */
.kaiki-root a { text-decoration: none; }

.kaiki-root p { margin: 0 0 .7rem; }
.kaiki-root p:last-child { margin-bottom: 0; }

.kaiki-heading {
  font-weight: 700;
  font-size: 1.05rem;
  letter-spacing: -.012em;
  margin: 0 0 .5rem;
}

/* Secondary text, defined once.
   It was five different percentages until issue 111's axe scan found the
   footer at 3.75:1 against white — below AA — and the other four could not be
   checked without reading each rule. One value, and 70% is where the operator's
   own text colour clears 4.5:1 on their own background for the smallest size
   this file uses. There is no colour named here, because WGT-9 does not allow
   one: it is a mix of the two the operator chose. */
.kaiki-root { --kaiki-secondary-text: color-mix(in srgb, var(--kaiki-text) 70%, transparent); }

.kaiki-muted { color: var(--kaiki-secondary-text); font-size: .9rem; }

/* The second answer on a screen that has two, and the lesser of them. It reads
   as a way out rather than as an alternative to the action beside it: no fill,
   the secondary ink, and the same 44px target because a thumb does not care
   which of the two it is aiming at. */
.kaiki-button-quiet {
  margin-inline-start: .5rem;
  border-color: transparent;
  background: transparent;
  color: var(--kaiki-secondary-text);
  font-weight: 500;
}

.kaiki-button-quiet:hover {
  color: var(--kaiki-text);
  border-color: color-mix(in srgb, var(--kaiki-text) 16%, transparent);
}

.kaiki-button {
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--kaiki-primary);
  background: var(--kaiki-primary);
  /* The label sits on the operator's primary, so it takes their background
     colour rather than a white this file is not allowed to name — or the
     readable colour the WordPress plugin worked out for its own button colour. */
  color: var(--kaiki-on-primary, var(--kaiki-background));
  border-radius: var(--kaiki-radius, 10px);
  padding: .62rem 1.1rem;
  min-height: 44px;
}

.kaiki-button:hover { background: color-mix(in srgb, var(--kaiki-primary) 88%, var(--kaiki-text)); }

/* A11Y-1: a visible focus ring that does not depend on the host page's reset,
   in the operator's accent so it reads as part of the widget. */
.kaiki-root :focus-visible {
  outline: 2px solid var(--kaiki-accent);
  outline-offset: 2px;
}

.kaiki-error {
  border: 1px solid var(--kaiki-accent);
  border-left-width: 4px;
  border-radius: var(--kaiki-radius, 10px);
  background: color-mix(in srgb, var(--kaiki-accent) 8%, var(--kaiki-background));
  padding: .9rem 1rem;
}

.kaiki-footer {
  margin-top: 1rem;
  padding-top: .7rem;
  border-top: 1px solid color-mix(in srgb, var(--kaiki-text) 10%, transparent);
  font-size: .76rem;
  color: var(--kaiki-secondary-text);
}

/* --- the booking walk (issue 107) --------------------------------- */

.kaiki-four-lines { display: grid; gap: .4rem; margin: 0 0 1rem; }
.kaiki-four-lines > div { display: grid; grid-template-columns: 7rem 1fr; gap: .7rem; align-items: baseline; }
.kaiki-four-lines dt {
  margin: 0; font-size: .72rem; font-weight: 600; letter-spacing: .06em;
  color: var(--kaiki-secondary-text);
}
.kaiki-four-lines dd { margin: 0; font-weight: 600; }

.kaiki-hold {
  margin: 0 0 .9rem; font-size: .85rem;
  color: var(--kaiki-secondary-text);
}
/* The warning uses the operator's accent, which is the colour their own page
   uses for attention — a red this file is not allowed to name would also be a
   colour nobody chose. */
.kaiki-hold-warning { color: var(--kaiki-accent); font-weight: 600; }

.kaiki-step { display: grid; gap: .8rem; margin-bottom: 1.1rem; }
.kaiki-field { display: grid; gap: .3rem; font-size: .9rem; }
.kaiki-field > span { font-weight: 600; }
.kaiki-field input, .kaiki-field textarea {
  font: inherit; color: inherit; background: var(--kaiki-background);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 22%, transparent);
  border-radius: var(--kaiki-radius, 10px);
  padding: .55rem .65rem; min-height: 44px; width: 100%;
}
/* 9.5rem and not 5rem: the row now carries − field + rather than a bare
   number, and the label keeps whatever is left. */
.kaiki-inline { grid-template-columns: 1fr 9.5rem; align-items: center; }

/* **− αριστερά, ο αριθμός στη μέση, + δεξιά** (Mike, 2026-09-23).

   A phone shows no spinner on a number input, so the only way to change the
   party was to raise the keyboard over the sheet and type. Three cells, with
   the figure between the two buttons that change it, and each button a full
   44px target.

   The input keeps its own border rather than sitting in a shared pill: it is
   still typeable, and a field that looks like a field says so. */
.kaiki-stepper { display: grid; grid-template-columns: 44px 1fr 44px; gap: .35rem; align-items: center; }

.kaiki-stepper button {
  font: inherit; font-size: 1.15rem; line-height: 1;
  min-height: 44px; min-width: 44px; padding: 0;
  display: flex; align-items: center; justify-content: center;
  color: var(--kaiki-text); background: var(--kaiki-background);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 22%, transparent);
  border-radius: var(--kaiki-radius, 10px);
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.kaiki-stepper button:disabled { opacity: .35; cursor: default; }

.kaiki-stepper input { text-align: center; padding-inline: .2rem; }

/* The native spinner is a second, smaller pair of arrows next to the two real
   ones, and on a phone it is not there at all — so it is nothing but a
   misaligned decoration on a desktop. */
.kaiki-stepper input::-webkit-outer-spin-button,
.kaiki-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.kaiki-stepper input { -moz-appearance: textfield; appearance: textfield; }
.kaiki-consent { grid-template-columns: auto 1fr; align-items: start; gap: .6rem; }

/* The enquiry form in two steps (Mike, 2026-09-24): a numbered line per step,
   the day and the people side by side in one bordered box, email and phone on
   one row, the optional line marked as such, and the button in the accent. */
.kaiki-enquiry .kaiki-enquiry-sub { margin-top: -.5rem; }
.kaiki-enquiry-pick {
  display: grid; grid-template-columns: 1fr 1fr;
  border: 1.5px solid color-mix(in srgb, var(--kaiki-text) 16%, transparent);
  border-radius: calc(var(--kaiki-radius, 10px) + 4px); overflow: hidden;
}
.kaiki-enquiry-cell { display: grid; gap: .15rem; padding: .6rem .8rem; min-width: 0; }
.kaiki-enquiry-cell + .kaiki-enquiry-cell { border-inline-start: 1.5px solid color-mix(in srgb, var(--kaiki-text) 16%, transparent); }
.kaiki-enquiry-cell > span { font-size: .78rem; font-weight: 600; color: color-mix(in srgb, var(--kaiki-text) 65%, transparent); }
.kaiki-enquiry-cell input[type="date"] { font: inherit; color: inherit; border: 0; background: transparent; padding: 0; min-height: 30px; width: 100%; }
.kaiki-enquiry-stepper { display: grid; grid-template-columns: 30px 1fr 30px; align-items: center; gap: .25rem; }
.kaiki-enquiry-stepper button {
  inline-size: 30px; block-size: 30px; border-radius: 50%; padding: 0; cursor: pointer;
  font: inherit; font-weight: 700; line-height: 1; color: var(--kaiki-primary);
  background: var(--kaiki-background); border: 1.5px solid color-mix(in srgb, var(--kaiki-text) 16%, transparent);
}
.kaiki-enquiry-stepper button:disabled { opacity: .35; cursor: default; }
.kaiki-enquiry-stepper input {
  font: inherit; font-weight: 700; text-align: center; border: 0; background: transparent; padding: 0; min-height: 30px; width: 100%; color: inherit;
  -moz-appearance: textfield; appearance: textfield;
}
.kaiki-enquiry-stepper input::-webkit-outer-spin-button,
.kaiki-enquiry-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.kaiki-enquiry-cell:focus-within { background: color-mix(in srgb, var(--kaiki-accent) 6%, var(--kaiki-background)); }
.kaiki-enquiry-cell input:focus-visible { outline: none; }
.kaiki-enquiry-two { display: grid; grid-template-columns: 1fr 1fr; gap: .7rem; }
.kaiki-enquiry-two > * { min-width: 0; }
@media (max-width: 22rem) { .kaiki-enquiry-two { grid-template-columns: 1fr; } }
.kaiki-enquiry .kaiki-field input, .kaiki-enquiry .kaiki-field textarea { border-width: 1.5px; border-radius: calc(var(--kaiki-radius, 10px) + 2px); }
.kaiki-enquiry .kaiki-field input:focus, .kaiki-enquiry .kaiki-field textarea:focus {
  outline: none; border-color: var(--kaiki-accent);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--kaiki-accent) 14%, transparent);
}
.kaiki-enquiry-optional { font-style: normal; font-weight: 400; color: color-mix(in srgb, var(--kaiki-text) 55%, transparent); }
.kaiki-enquiry-actions { display: grid; gap: .5rem; }
.kaiki-enquiry-actions .kaiki-button {
  inline-size: 100%; justify-content: center; min-height: 50px; font-size: 1rem; font-weight: 700;
  background: var(--kaiki-accent); border-color: var(--kaiki-accent); color: var(--kaiki-on-primary, var(--kaiki-background));
  box-shadow: 0 8px 20px color-mix(in srgb, var(--kaiki-accent) 25%, transparent);
}
.kaiki-enquiry-actions .kaiki-button:hover { background: color-mix(in srgb, var(--kaiki-accent) 86%, var(--kaiki-text)); }
.kaiki-enquiry-note { font-size: .8rem; margin: 0; }
/* In the phone sheet too: the button full width, the note under it. The sheet's
   own row rule (nowrap, side by side) is for back + continue. */
.kaiki-booking.kaiki-enquiry[data-sheet="true"] .kaiki-enquiry-actions { display: grid; gap: .4rem; }
.kaiki-booking.kaiki-enquiry[data-sheet="true"] .kaiki-enquiry-actions .kaiki-button { inline-size: 100%; white-space: nowrap; }
.kaiki-booking.kaiki-enquiry[data-sheet="true"] .kaiki-enquiry-note { text-align: center; }
.kaiki-consent input { min-height: 0; width: auto; }

.kaiki-summary { display: grid; gap: .4rem; margin: 0 0 .9rem; }
.kaiki-summary > div { display: grid; grid-template-columns: 7rem 1fr; gap: .7rem; }
.kaiki-summary dt, .kaiki-summary dd { margin: 0; }
.kaiki-summary dt { font-size: .72rem; font-weight: 600; letter-spacing: .06em; color: var(--kaiki-secondary-text); }

.kaiki-lines { list-style: none; margin: 0 0 .8rem; padding: 0; display: grid; gap: .35rem; font-size: .93rem; }
.kaiki-lines li { display: flex; justify-content: space-between; gap: 1rem; }
.kaiki-amount { font-variant-numeric: tabular-nums; }

.kaiki-total { display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; margin: 0 0 .6rem; }
.kaiki-total strong { font-size: 1.35rem; letter-spacing: -.02em; }
.kaiki-total .kaiki-muted { flex-basis: 100%; font-size: .78rem; }

/* A guest who left the checkout page without paying, and came back here. One
   line, above the form, offering the page they left rather than a fresh start
   that would hold a second set of seats. */
.kaiki-resume {
  margin: 0 0 .9rem; padding: .55rem .7rem;
  font-size: .85rem; line-height: 1.4;
  border: 1px solid color-mix(in srgb, var(--kaiki-primary) 35%, transparent);
  background: color-mix(in srgb, var(--kaiki-primary) 7%, transparent);
  border-radius: var(--kaiki-radius, 10px);
}
.kaiki-resume a { color: var(--kaiki-primary); font-weight: 600; }

.kaiki-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }.kaiki-button-ghost { background: transparent; color: var(--kaiki-primary); }
/* Its own hover. The shared .kaiki-button:hover darkens the fill to the
   primary colour, which on a button whose text is that same primary colour put
   «Πίσω» dark on dark. A tint keeps the text readable in any operator's brand. */
.kaiki-button-ghost:hover { background: color-mix(in srgb, var(--kaiki-primary) 8%, var(--kaiki-background)); }
.kaiki-button:disabled { opacity: .5; cursor: not-allowed; }

/* --- the list, calendar and enquiry mounts (issue 108) ------------- */

.kaiki-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
.kaiki-tab {
  font: inherit; font-size: .85rem; cursor: pointer;
  background: transparent; color: var(--kaiki-text);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 20%, transparent);
  border-radius: var(--kaiki-radius, 10px);
  padding: .4rem .8rem; min-height: 40px;
}
.kaiki-tab-active { background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: var(--kaiki-on-primary, var(--kaiki-background)); }

/* Cards are equal height with the price row pinned to the bottom — settled on
   4 September, because prices at different heights read as a mistake. 14px on
   cards, the radius variable on controls. */
.kaiki-cards {
  list-style: none; margin: 0; padding: 0;
  display: grid; gap: .9rem;
  grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
}
.kaiki-card {
  display: flex; flex-direction: column;
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 12%, transparent);
  border-radius: 14px; overflow: hidden;
}
/* The photograph runs to the card's edges; the text keeps the padding. One
   ratio for every card, so a row of photos lines up whatever was uploaded. */
.kaiki-card-image {
  display: block; width: 100%; height: auto; max-width: 100%;
  aspect-ratio: 16 / 10; object-fit: cover;
  background: color-mix(in srgb, var(--kaiki-text) 6%, var(--kaiki-background));
}
.kaiki-card-body { display: flex; flex-direction: column; flex: 1; padding: 1rem 1.05rem; }
.kaiki-card h3 { margin: 0 0 .35rem; }
.kaiki-card h3 a { color: inherit; text-decoration: none; }
/* No underline, at rest or on hover — the product owner's rule of 2026-09-11.
   The hover says the title is a link in the operator's own colour instead. */
.kaiki-card h3 a:hover { color: var(--kaiki-primary); }
.kaiki-card .kaiki-facts { font-size: .82rem; margin-bottom: .6rem; }
.kaiki-card-price { margin: auto 0 0; padding-top: .6rem; }
.kaiki-card-price strong { font-size: 1.1rem; }
.kaiki-on-request { font-weight: 600; color: var(--kaiki-primary); }

.kaiki-calendar-head {
  display: flex; align-items: center; justify-content: space-between; gap: .6rem;
  margin-bottom: .6rem;
}

/* The month arrows are a square each, not two full buttons: «Προηγούμενος» and
   «Επόμενος» side by side were wider than the month name between them, and the
   name is the thing being read. The words survive as the accessible name. */
.kaiki-calendar-step {
  font: inherit; font-size: 1.1rem; line-height: 1; cursor: pointer;
  inline-size: 2rem; block-size: 2rem; flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; color: var(--kaiki-text);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 15%, transparent);
  border-radius: var(--kaiki-radius, 10px);
}
.kaiki-calendar-step:hover { border-color: var(--kaiki-primary); color: var(--kaiki-primary); }

/* Seven columns, shared by the headings and the days, so a Saturday is under
   «Σα» at every screen width. An auto-filled track was the old answer and it
   moved the 14th to a different column on every phone. */
.kaiki-weekdays,
.kaiki-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: .25rem; }

.kaiki-weekdays {
  margin-bottom: .25rem;
  font-size: .7rem; font-weight: 600; text-align: center;
  color: var(--kaiki-secondary-text);
}

.kaiki-days { list-style: none; margin: 0; padding: 0; }

.kaiki-day {
  aspect-ratio: 1; min-inline-size: 0;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--kaiki-radius, 10px);
  font-size: .85rem; color: var(--kaiki-secondary-text);
  background: color-mix(in srgb, var(--kaiki-text) 4%, transparent);
}

/* The days before the first of the month. Nothing at all, so the grid starts
   on the right weekday without drawing boxes that mean nothing. */
.kaiki-day-blank { background: none; }

/* Green and red, and neither is the operator's brand colour. This is the one
   place on the page where the colour *is* the meaning rather than the identity,
   and a red that is really navy tells nobody anything.

   The status is in each cell's accessible name as well (A11Y-1): about one man
   in twelve cannot tell these two apart.

   Written as CSS named colours mixed with the operator's own background rather
   than as hex, and not to slip past the WGT-9 guard: the rule is that no *brand*
   colour is hardcoded, and these are not brand colours — they are the meaning of
   the cell. Mixing each into the operator's own background is what keeps them
   at home on a page whose ground they chose. */
.kaiki-day-available {
  color: color-mix(in srgb, seagreen 78%, black);
  background: color-mix(in srgb, seagreen 13%, var(--kaiki-background));
  box-shadow: inset 0 0 0 1px color-mix(in srgb, seagreen 32%, transparent);
  font-weight: 600;
}

.kaiki-day-sold_out {
  color: color-mix(in srgb, firebrick 82%, black);
  background: color-mix(in srgb, firebrick 10%, var(--kaiki-background));
  box-shadow: inset 0 0 0 1px color-mix(in srgb, firebrick 25%, transparent);
}

/* Struck through, and this is not decoration.

   A month grid has no room to write «εξαντλήθηκε» in a cell, so the words moved
   to the legend and to each cell's accessible name — which serves a screen
   reader and does nothing at all for a colour-blind guest looking at the grid.
   The rule through the number is the second signal they need, and it is the
   convention every calendar already uses for a day that is gone. */
.kaiki-day-sold_out .kaiki-day-number { text-decoration: line-through; }

/* Asked for rather than sold, so neither green nor red: an amber that says
   "there is an answer, and a person gives it". */
.kaiki-day-on_request {
  color: color-mix(in srgb, chocolate 75%, black);
  background: color-mix(in srgb, chocolate 12%, var(--kaiki-background));
  box-shadow: inset 0 0 0 1px color-mix(in srgb, chocolate 28%, transparent);
}

.kaiki-day-number { font-variant-numeric: tabular-nums; }

/* A day the guest can actually take fills its whole cell, so the tap target is
   the square they are aiming at rather than the two characters in the middle
   of it. Transparent rather than unstyled: the cell behind it already carries
   the colour that says what the day is. */
.kaiki-day-pick {
  font: inherit; color: inherit; cursor: pointer;
  inline-size: 100%; block-size: 100%;
  display: flex; align-items: center; justify-content: center;
  background: transparent; border: 0; padding: 0;
  border-radius: inherit;
}

.kaiki-day-pick:hover { background: color-mix(in srgb, currentColor 12%, transparent); }

/* The day they chose. A ring in the operator's own colour rather than a fill:
   the fill is already saying whether the day is free, and overwriting it would
   trade one fact for the other. */
.kaiki-day-picked { box-shadow: inset 0 0 0 2px var(--kaiki-primary); }
.kaiki-day-picked .kaiki-day-number { font-weight: 700; }

/* What the colours mean, once, under the grid. */
.kaiki-legend {
  list-style: none; margin: .7rem 0 0; padding: 0;
  display: flex; flex-wrap: wrap; gap: .2rem 1rem;
  font-size: .75rem; color: var(--kaiki-secondary-text);
}
.kaiki-legend li { display: inline-flex; align-items: center; gap: .35rem; }
.kaiki-swatch { inline-size: .7rem; block-size: .7rem; border-radius: 3px; flex: none; }

/* The struck-through day is what a sold-out cell looks like, so the legend has
   to look like one too — a plain red square would teach the colour and leave
   the rule through the number unexplained. */
.kaiki-legend .kaiki-day-sold_out { text-decoration: line-through; }

/* Read but not seen. Same mechanism as the honeypot below: off screen rather
   than "display: none", which would take it out of the accessibility tree
   along with the view. */
.kaiki-visually-hidden {
  position: absolute; width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
  margin: 0;
}

/* The honeypot: off-screen rather than "display: none", so a form filler that
   skips hidden inputs still fills it. */
.kaiki-trap {
  position: absolute; width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}

/* ---- the day's sailings ----------------------------------------------

   Radios rather than a select: there are two or three of them, they are the
   thing being decided, and a closed dropdown hides both the choice and the fact
   that a choice exists. The input stays a real radio for the keyboard and the
   accessibility tree; the label is what gets drawn. */
.kaiki-times {
  border: 0; margin: 1rem 0 0; padding: 0;
  display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(6.25rem, 1fr));
}

.kaiki-times legend {
  padding: 0;
  margin-bottom: .45rem;
  font-size: .92rem;
  font-weight: 600;
}

.kaiki-time {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: .1rem;
  padding: .55rem .6rem;
  text-align: center;
  min-height: 44px;
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 16%, transparent);
  border-radius: var(--kaiki-radius, 10px);
  cursor: pointer;
}

.kaiki-time:has(input:checked) {
  border-color: var(--kaiki-primary);
  background: color-mix(in srgb, var(--kaiki-primary) 8%, var(--kaiki-background));
}

.kaiki-time:has(input:focus-visible) {
  outline: 2px solid var(--kaiki-primary);
  outline-offset: 2px;
}

/* The radio stays for the keyboard and the accessibility tree; the chip is
   what is drawn. */
.kaiki-time input { position: absolute; inset: 0; margin: 0; opacity: 0; cursor: pointer; }
.kaiki-time-at { font-size: 1.02rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.kaiki-time:has(input:checked) .kaiki-time-at { color: var(--kaiki-primary); }
.kaiki-time-left { font-size: .76rem; color: var(--kaiki-secondary-text); }

/* The day and time already chosen, above «Πόσα άτομα;» (2026-09-25). */
.kaiki-picked {
  display: flex; align-items: center; justify-content: space-between; gap: .6rem;
  margin: 0 0 .9rem; padding: .35rem .35rem .35rem .8rem; border-radius: var(--kaiki-radius, 10px);
  background: color-mix(in srgb, var(--kaiki-primary) 7%, var(--kaiki-background));
  font-weight: 600; font-size: .95rem;
}
.kaiki-link {
  font: inherit; font-weight: 700; color: var(--kaiki-primary); background: none; border: 0;
  min-height: 44px; padding: 0 .6rem; cursor: pointer; text-decoration: none;
}
/* One sailing: nothing to choose, so it reads as a fact, not a button. */
.kaiki-times-one .kaiki-time { cursor: default; }
.kaiki-times-one .kaiki-time input { cursor: default; }

/* ---- the bottom sheet (ADR-0033) -------------------------------------

   Below 60rem the booking card leaves the flow and pins to the bottom of the
   screen, collapsed to a bar. It is the same element in both arrangements —
   [data-sheet="true"] is the whole difference — because two elements would be
   two calendars, two pieces of state and one of them always stale.

   The sheet is only ever switched on when a probe has confirmed that a fixed
   element in here can actually reach the viewport (WGT-22). On a page that
   traps it, "data-sheet" never becomes true and this block never applies. */

/* The frame comes off when the card leaves the flow. Without this the host page
   keeps an empty bordered box where the widget used to be, and the sheet draws
   its own surface on top of it — two cards, one of them containing nothing. */
.kaiki-root:has(.kaiki-booking[data-sheet="true"]) {
  padding: 0;
  border: 0;
  background: transparent;
  box-shadow: none;
  min-height: 0;
}

.kaiki-booking[data-sheet="true"] {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  z-index: 2147483000;

  display: flex;
  flex-direction: column;

  /* **A half sheet, not a cover** (Mike, 2026-09-23, direction Α of the mobile
     mockups): the page stays visible above it, so a guest can still see which
     trip they are booking and never loses their place.

     90svh (Mike, 2026-09-23: *«κάνε μεγαλύτερο»*, then *«κάνε ακόμα πιο ψηλό
     αυτό το popup»*). 68 was too mean — a whole month needs about 94% of a
     664-pixel screen once the price, the steps and the button are counted — and
     80 still left a long month one scroll short.

     90 is the ceiling, not a step on the way to 100: a strip of the page has to
     stay visible above the sheet or direction Α turns into the full-screen
     cover it was chosen instead of, and the guest loses which trip they are
     booking. About 66 pixels of a 664-pixel screen, which is a line of the
     trip's title.

     Not "vh". Safari's collapsing chrome makes "vh" taller than the screen, and
     the part that overflows is the bottom — which is where the button is. */
  max-height: 90svh;

  background: var(--kaiki-background);
  border: 0;
  border-top: 1px solid color-mix(in srgb, var(--kaiki-text) 12%, transparent);
  /* Square, both corners. The leading one because the tab rises out of it and
     a curve there would leave a crescent of page showing under it; the trailing
     one because the page's own bar has never had a radius, and a sheet that
     rounds a corner the bar it replaces does not is one more thing moving at
     the handover. */
  border-radius: 0;
  padding: 0;
  box-shadow: 0 -10px 30px -12px color-mix(in srgb, var(--kaiki-text) 34%, transparent);

  transform: translateY(calc(100% - var(--kaiki-peek-height, 5.5rem)));
}

/* The transition arrives a paint after the sheet does, so its own resting
   position is not the first thing it animates to. See useSettled(). */
.kaiki-booking[data-sheet="true"][data-settled="true"] {
  transition: transform .34s cubic-bezier(.32, .72, 0, 1);
}

.kaiki-booking[data-sheet="true"][data-open="true"] { transform: translateY(0); }

/* The bar. A full-width target rather than a chevron: it is the one control on
   the screen and a thumb should not have to aim at it. */
.kaiki-peek {
  position: relative;
  flex: none;
  display: flex;
  align-items: center;
  gap: .6rem;
  width: 100%;
  min-height: var(--kaiki-peek-height, 5.5rem);
  padding: .7rem 1rem .85rem;
  border: 0;
  background: transparent;
  font: inherit;
  color: inherit;
  text-align: start;
  cursor: pointer;
}

.kaiki-peek-text { flex: 1; min-width: 0; }

.kaiki-peek-price {
  display: block;
  font-size: 1.22rem;
  font-weight: 700;
  letter-spacing: -.02em;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}

.kaiki-peek-summary {
  display: block;
  margin-top: .05rem;
  font-size: .82rem;
  color: var(--kaiki-secondary-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kaiki-peek-action {
  flex: none;
  border-radius: var(--kaiki-radius, 10px);
  background: var(--kaiki-primary);
  /* Same reasoning as .kaiki-button: the operator's background on the
     operator's primary, never a white this file is not allowed to name. */
  color: var(--kaiki-on-primary, var(--kaiki-background));
  font-size: .9rem;
  font-weight: 600;
  padding: .62rem 1rem;
  white-space: nowrap;
}

.kaiki-peek-action[data-ready="false"] { opacity: .45; }

/* The tab that stands above the bar.

   Rounded at the top and open at the bottom, in the sheet's own surface and
   the sheet's own hairline, so the bar's top edge runs into it instead of
   under it: the -1px pulls it down over that border line, which is what turns
   two shapes into one outline. It clears the sheet's rounded corner rather
   than sitting in it — at 1.25rem the corner has finished curving.

   The arrow inside turns over when the sheet opens; the tab does not move,
   because it is the handle and a handle that jumps is a handle you have to
   find again. */
.kaiki-peek-tab {
  position: absolute;
  bottom: 100%;
  inset-inline-start: 0;
  margin-bottom: -1px;

  display: flex;
  align-items: center;
  justify-content: center;

  /* No width of its own: the padding draws the shape around the arrow, so the
     two cannot drift apart the way a fixed width and a percentage-sized glyph
     did — that was the wide flat slab with a small mark stranded in it. The
     1rem on the leading side puts the arrow over the price rather than over
     the gutter, so the tab is flush with the screen and its contents are still
     on the column everything else in the bar sits on. */
  padding: .34rem 1rem .4rem;
  box-sizing: border-box;

  background: var(--kaiki-background);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 12%, transparent);
  /* Nothing to draw against the edge of the screen, and nothing to round
     there either: the tab runs off the side rather than floating near it. */
  border-bottom: 0;
  border-inline-start: 0;
  border-radius: 0 .4rem 0 0;
  color: var(--kaiki-primary);
}

.kaiki-peek-tab svg {
  display: block;
  width: 1.2rem;
  height: .7rem;
  overflow: visible;
  transition: transform .28s cubic-bezier(.32, .72, 0, 1);
}

.kaiki-peek-tab[data-open="true"] svg { transform: rotate(180deg); }

@media (prefers-reduced-motion: reduce) {
  .kaiki-peek-tab svg { transition: none; }
}

/* Πού είσαι στα τρία βήματα (direction Α, 2026-09-23). Μία μπάρα ανά βήμα,
   γεμάτες ως αυτό που βλέπεις. Λεπτές και ήσυχες: είναι προσανατολισμός, όχι
   χειριστήριο — δεν πατιούνται και δεν διαβάζονται φωναχτά. */
.kaiki-steps {
  list-style: none;
  display: flex;
  /* Centred, not the default stretch: a flex item with flex:1 and a
     three-pixel height still gets stretched wherever the row is taller than
     its content, and the bars ended up sitting at different heights from one
     another (Mike, 2026-09-23).

     No backticks anywhere in this file: the whole stylesheet is a template
     literal, and one in a comment ends it. That is how v0.4.21 was published
     from a stale build — the compile failed and the publish step did not care. */
  align-items: center;
  gap: .3rem;
  margin: 0;
  /* Κάτω περιθώριο, γιατί χωρίς αυτό οι μπάρες κάθονταν κολλητά πάνω στο
     ημερολόγιο και διαβάζονταν σαν μέρος του (Mike, ίδια μέρα). */
  padding: .7rem 1rem .85rem;
}

.kaiki-steps li {
  block-size: 3px;
  /* Και ρητά, ώστε ούτε η στοίχιση ούτε ο γονιός να μπορούν να το αλλάξουν. */
  min-block-size: 3px;
  max-block-size: 3px;
  flex: 1;
  margin: 0;
  padding: 0;
  border-radius: 2px;
  background: color-mix(in srgb, var(--kaiki-text) 12%, transparent);
}

.kaiki-steps li[data-done="true"] { background: var(--kaiki-primary); }

/* The middle scrolls; the price above it and the buttons below it do not. The
   action never leaves the screen however far down the walk a guest reads. */
.kaiki-booking[data-sheet="true"] .kaiki-sheet-scroll {
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  min-height: 0;
  /* Λίγο αέρα στην κορυφή: χωρίς αυτό το ημερολόγιο ξεκινούσε κολλητά στη
     γραμμή κάτω από τις μπάρες των βημάτων, και οι δύο διαβάζονταν ως ένα
     πράγμα (Mike, 2026-09-23). Το κενό είναι εδώ και όχι στις μπάρες, ώστε να
     ισχύει για κάθε βήμα — και τα άτομα και τα πρόσθετα το χρειάζονται. */
  padding: .8rem 1rem 0;
  border-top: 1px solid color-mix(in srgb, var(--kaiki-text) 9%, transparent);
}

.kaiki-booking[data-sheet="true"] .kaiki-actions {
  flex: none;
  margin: 0;
  padding: .7rem 1rem calc(.8rem + env(safe-area-inset-bottom, 0px));
  border-top: 1px solid color-mix(in srgb, var(--kaiki-text) 9%, transparent);
}

/* **Το πεδίο του κωδικού έκπτωσης κολλημένο δεξιά αριστερά** (Mike,
   2026-09-23, τρίτη αναφορά).

   Three sheet children, not two: the discount field is rendered *after*
   kaiki-sheet-scroll closes, so it can stay above the buttons while a long
   month scrolls behind it. That also puts it outside the only element carrying
   a gutter. kaiki-actions right above had been given its own 1rem for exactly
   this reason and the field never was, so it ran edge to edge on the one
   screen where a guest types.

   No backticks anywhere in this file: the whole stylesheet is one template
   literal, and a pair of them in a comment ends it silently — the build
   succeeds and the widget throws on load.

   Measured, not guessed, after twice reporting a fix that was not one: the
   checkout page's own field was never the one at fault — it sits at 23px on
   every width from 320 to 1024. This is the field that was touching. */
.kaiki-booking[data-sheet="true"] .kaiki-discount {
  flex: none;
  margin: 0;
  padding: .7rem 1rem;
  border-top: 1px solid color-mix(in srgb, var(--kaiki-text) 9%, transparent);
}

/* "Πίσω" and "Συνέχεια στην κράτηση" are not the same size of thing, and giving
   them the same width made the long one wrap onto two lines while the short one
   sat in half a screen of its own. Back takes what it needs; the action takes
   the rest. They stay on one row — wrapped, the primary ends up under a ghost
   button, which reads as the lesser of the two. */
.kaiki-booking[data-sheet="true"] .kaiki-actions { flex-wrap: nowrap; align-items: stretch; }

.kaiki-booking[data-sheet="true"] .kaiki-actions .kaiki-button {
  flex: 1 1 auto;
  min-width: 0;
  padding-inline: .8rem;
}

.kaiki-booking[data-sheet="true"] .kaiki-actions .kaiki-button-ghost {
  flex: 0 0 auto;
  padding-inline: .95rem;
}

/* Closed, the walk is present for the machine and gone for everyone else: not
   tabbable, not announced, not painted. */
.kaiki-booking[data-sheet="true"]:not([data-open="true"]) .kaiki-sheet-scroll,
.kaiki-booking[data-sheet="true"]:not([data-open="true"]) .kaiki-actions {
  visibility: hidden;
}

.kaiki-scrim {
  position: fixed;
  inset: 0;
  z-index: 2147482999;
  border: 0;
  padding: 0;
  background: color-mix(in srgb, var(--kaiki-text) 42%, transparent);
  animation: kaiki-scrim-in .3s ease;
}

@keyframes kaiki-scrim-in { from { opacity: 0; } to { opacity: 1; } }

@media (prefers-reduced-motion: reduce) {
  .kaiki-root * { transition: none !important; animation: none !important; }
}

/* ------------------------------------------------------------------
   The type system of the guest pages (2026-09-24, the typography review in
   docs/mockups/typo): the same nine steps as the operator's site, so the
   booking box stops being the one thing on a trip page set to its own scale.
     caption 13 · small 15 · body 16 · title 18
   Labels 13 / 600 / +0.02em in the secondary ink (was 11.2–12.5px at .06em);
   every field 16px, because iOS zooms the page into any field smaller than
   that the moment it is tapped; the optional marker in the secondary ink
   rather than 55% (3.9:1). Last in the sheet, so it wins over the rules above.
   ------------------------------------------------------------------ */
.kaiki-heading { font-size: 1.125rem; }
.kaiki-muted { font-size: .9375rem; }
.kaiki-four-lines dt,
.kaiki-summary dt,
.kaiki-enquiry-cell > span { font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--kaiki-secondary-text); }
.kaiki-field { font-size: .9375rem; }
.kaiki-field input,
.kaiki-field textarea,
.kaiki-enquiry-cell input { font-size: 1rem; }
.kaiki-enquiry-optional { color: var(--kaiki-secondary-text); }
.kaiki-enquiry-note { font-size: .8125rem; }
.kaiki-weekdays { font-size: .8125rem; }
.kaiki-day { font-size: .9375rem; }
.kaiki-legend { font-size: .8125rem; }
.kaiki-button,
.kaiki-enquiry-actions .kaiki-button { font-size: .9375rem; font-weight: 600; }
.kaiki-hold,
.kaiki-total .kaiki-muted,
.kaiki-time-left,
.kaiki-card .kaiki-facts,
.kaiki-peek-summary { font-size: .8125rem; }
.kaiki-lines,
.kaiki-peek-action { font-size: .9375rem; }
.kaiki-peek-price { font-size: 1.125rem; }
`;

/** Test seam: the instance counter is module state, and a test needs it reset. */
export function resetShadowCounter(): void {
  instances = 0;
}
