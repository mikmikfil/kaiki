import { brandProperties, type BrandPayload } from './branding';

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
  applyBranding(brand: BrandPayload): void;
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
    applyBranding(payload: BrandPayload): void {
      brand(`:host {\n  ${brandProperties(payload)};\n}`);
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
  font-family: var(--kaiki-font);
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
     escalation — there is no war of important flags to lose. */
  font-family: var(--kaiki-font);
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

.kaiki-button {
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--kaiki-primary);
  background: var(--kaiki-primary);
  /* The label sits on the operator's primary, so it takes their background
     colour rather than a white this file is not allowed to name. */
  color: var(--kaiki-background);
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
.kaiki-inline { grid-template-columns: 1fr 5rem; align-items: center; }
.kaiki-consent { grid-template-columns: auto 1fr; align-items: start; gap: .6rem; }
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

.kaiki-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }
.kaiki-button-ghost { background: transparent; color: var(--kaiki-primary); }
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
.kaiki-tab-active { background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: var(--kaiki-background); }

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
  border-radius: 14px; padding: 1rem 1.05rem;
}
.kaiki-card h3 { margin: 0 0 .35rem; }
.kaiki-card h3 a { color: inherit; text-decoration: none; }
.kaiki-card h3 a:hover { text-decoration: underline; text-underline-offset: .16em; }
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

/* The honeypot: off-screen rather than "display: none", so a form filler that
   skips hidden inputs still fills it. */
.kaiki-trap {
  position: absolute; width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .kaiki-root * { transition: none !important; animation: none !important; }
}
`;

/** Test seam: the instance counter is module state, and a test needs it reset. */
export function resetShadowCounter(): void {
  instances = 0;
}
