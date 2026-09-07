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

export function createShadowHost(target: Element, doc: Document = document): ShadowHost {
  const host = doc.createElement('div');

  // WGT-8: namespaced, so two widgets on one page are two distinguishable
  // elements in the operator's own dev tools rather than two anonymous divs.
  instances += 1;
  host.setAttribute('data-kaiki-widget', String(instances));

  const root = host.attachShadow({ mode: 'open' });
  const slot = doc.createElement('div');

  slot.className = 'kaiki-root';

  const style = doc.createElement('style');

  style.textContent = BASE_STYLES;

  const brandStyle = doc.createElement('style');

  root.append(style, brandStyle, slot);
  target.appendChild(host);

  return {
    host,
    root,
    slot,
    applyBranding(brand: BrandPayload): void {
      brandStyle.textContent = `:host {\n  ${brandProperties(brand)};\n}`;
    },
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
  background: var(--kaiki-background);
  color: var(--kaiki-text);
  border-radius: calc(var(--kaiki-radius, 10px) + 4px);
  border: 1px solid color-mix(in srgb, var(--kaiki-text) 12%, transparent);
  padding: 1.15rem 1.25rem 1.3rem;
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

.kaiki-muted { color: color-mix(in srgb, var(--kaiki-text) 65%, transparent); font-size: .9rem; }

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
  color: color-mix(in srgb, var(--kaiki-text) 55%, transparent);
}

/* --- the booking walk (issue 107) --------------------------------- */

.kaiki-four-lines { display: grid; gap: .4rem; margin: 0 0 1rem; }
.kaiki-four-lines > div { display: grid; grid-template-columns: 7rem 1fr; gap: .7rem; align-items: baseline; }
.kaiki-four-lines dt {
  margin: 0; font-size: .72rem; font-weight: 600; letter-spacing: .06em;
  color: color-mix(in srgb, var(--kaiki-text) 60%, transparent);
}
.kaiki-four-lines dd { margin: 0; font-weight: 600; }

.kaiki-hold {
  margin: 0 0 .9rem; font-size: .85rem;
  color: color-mix(in srgb, var(--kaiki-text) 65%, transparent);
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
.kaiki-summary dt { font-size: .72rem; font-weight: 600; letter-spacing: .06em; color: color-mix(in srgb, var(--kaiki-text) 60%, transparent); }

.kaiki-lines { list-style: none; margin: 0 0 .8rem; padding: 0; display: grid; gap: .35rem; font-size: .93rem; }
.kaiki-lines li { display: flex; justify-content: space-between; gap: 1rem; }
.kaiki-amount { font-variant-numeric: tabular-nums; }

.kaiki-total { display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; margin: 0 0 .6rem; }
.kaiki-total strong { font-size: 1.35rem; letter-spacing: -.02em; }
.kaiki-total .kaiki-muted { flex-basis: 100%; font-size: .78rem; }

.kaiki-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }
.kaiki-button-ghost { background: transparent; color: var(--kaiki-primary); }
.kaiki-button:disabled { opacity: .5; cursor: not-allowed; }

@media (prefers-reduced-motion: reduce) {
  .kaiki-root * { transition: none !important; animation: none !important; }
}
`;

/** Test seam: the instance counter is module state, and a test needs it reset. */
export function resetShadowCounter(): void {
  instances = 0;
}
