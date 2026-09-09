import type { Api } from './api-client';

/**
 * The operator's colours, radius and font, as CSS custom properties (WGT-9, WGT-10).
 *
 * ## Nothing here is a colour
 *
 * WGT-9 is FIXED and absolute: *"No brand colour, radius or font is ever
 * hardcoded in the widget source."* `npm run widget:guards` greps the **built
 * bundle** for hex colours and fails on one, because a behavioural test cannot
 * tell a colour that came from the API from one that happened to match.
 *
 * That rules out fallbacks too — `var(--kaiki-primary, #123A5E)` is a hardcoded
 * brand colour with extra steps. Every neutral the widget needs is derived from
 * the operator's own palette with `color-mix()`: a rule is their text colour at
 * twelve per cent, a hover is their primary mixed toward their background. An
 * operator who changes one colour changes all of them, which is what BRD-8
 * promises.
 *
 * ## One fetch for the whole page
 *
 * WGT-8: three mounts must not mean three branding requests. The promise is
 * memoised per client, so the second and third mounts await the first one's
 * response rather than starting their own.
 *
 * ## The font is a third-party request, so it happens only when chosen
 *
 * WGT-10, and the hosted pages' CSP makes the same rule structural. With
 * `font_source: system` the widget issues **no third-party request at all** —
 * not a preconnect, not a stylesheet — and uses the system stack. The `<link>`
 * goes in the host document's head rather than the shadow root, because that is
 * where a browser looks for one.
 */

export interface BrandPayload {
  readonly colors?: {
    readonly primary?: string;
    readonly secondary?: string;
    readonly accent?: string;
    readonly background?: string;
    readonly text?: string;
  };
  readonly button_radius_px?: number;
  readonly font?: {
    readonly family?: string;
    readonly source?: string;
    readonly css_url?: string | null;
  };
  readonly widget_theme?: string;
  readonly tenant?: { readonly default_locale?: string; readonly name?: string };
}

const pending = new WeakMap<Api, Promise<BrandPayload>>();

/** WGT-8's single fetch, whatever the number of mounts. */
export function loadBranding(client: Api): Promise<BrandPayload> {
  const existing = pending.get(client);

  if (existing !== undefined) {
    return existing;
  }

  const promise = client
    .get<{ data: BrandPayload }>('/branding')
    .then((response) => response.data ?? {})
    // A branding failure must not take the widget with it: an unbranded
    // booking form is worth more to an operator than no booking form, and the
    // custom properties simply stay unset.
    .catch((): BrandPayload => ({}));

  pending.set(client, promise);

  return promise;
}

/**
 * The custom properties WGT-9 names, as a declaration block.
 *
 * A property whose value the API did not send is **omitted**, not defaulted:
 * an absent custom property inherits, and inheriting the host page's text
 * colour is a better failure than painting an operator's widget in a colour
 * nobody chose.
 */
export function brandProperties(brand: BrandPayload): string {
  const declarations: string[] = [];

  const colors = brand.colors ?? {};

  add(declarations, '--kaiki-primary', colors.primary);
  add(declarations, '--kaiki-secondary', colors.secondary);
  add(declarations, '--kaiki-accent', colors.accent);
  add(declarations, '--kaiki-background', colors.background);
  add(declarations, '--kaiki-text', colors.text);

  if (typeof brand.button_radius_px === 'number' && Number.isFinite(brand.button_radius_px)) {
    declarations.push(`--kaiki-radius: ${Math.max(0, Math.round(brand.button_radius_px))}px`);
  }

  // The system stack is named here and nowhere else. It is a font *stack*
  // rather than a family — no third-party request, no download, and the guest's
  // own device decides (WGT-10).
  const family = brand.font?.family;
  const stack = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

  declarations.push(`--kaiki-font: ${family === undefined || family === '' ? stack : `${quote(family)}, ${stack}`}`);

  return declarations.join(';\n  ');
}

/**
 * The operator's Google font, when they chose one (WGT-10).
 *
 * Injected into the **host document**, because a `<link rel=stylesheet>` inside
 * a shadow root loads the sheet but the browser will not apply its `@font-face`
 * rules to the outer document, and operators embed the widget beside their own
 * headings. Idempotent by href, so three mounts add one link.
 */
export function ensureFontLink(brand: BrandPayload, doc: Document = document): void {
  const href = brand.font?.css_url;

  if (typeof href !== 'string' || href === '') {
    return;
  }

  if (doc.querySelector(`link[data-kaiki-font][href="${CSS.escape(href)}"]`) !== null) {
    return;
  }

  const link = doc.createElement('link');

  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute('data-kaiki-font', '');
  link.crossOrigin = 'anonymous';

  doc.head.appendChild(link);
}

function add(into: string[], property: string, value: string | undefined): void {
  if (typeof value === 'string' && value.trim() !== '') {
    into.push(`${property}: ${value.trim()}`);
  }
}

function quote(family: string): string {
  return /^[A-Za-z][A-Za-z0-9\- ]*$/.test(family) ? `"${family}"` : JSON.stringify(family);
}
