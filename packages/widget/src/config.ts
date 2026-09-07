/**
 * One embed's configuration, read off its `<script>` tag (WGT-3, WGT-7).
 *
 * ## The script tag is the whole API
 *
 * WGT-3 fixes the shape — `<script src="…/kaiki-widget.js" data-key="pk_…">` —
 * and WGT-7 the attributes. An operator pastes one line into a page builder;
 * anything that needed a second line, an inline `<script>` or a global would be
 * a line their CSP blocks or their theme strips.
 *
 * ## `data-mount` defaults by what else is present
 *
 * WGT-7: `booking` when `data-product` is there, `list` otherwise. That is the
 * sentence an operator would write themselves — "put this trip's booking form
 * here" versus "put my trips here" — so the attribute exists for the cases where
 * they mean something else, not for the common ones.
 */

/** The four mounts of WGT-6. The last two arrive in #108. */
export type MountName = 'booking' | 'list' | 'calendar' | 'enquiry';

export const MOUNTS: readonly MountName[] = ['booking', 'list', 'calendar', 'enquiry'];

export interface WidgetConfig {
  /** The publishable key. A `sk_` here would be an operator's key in a page's source. */
  readonly key: string;
  readonly mount: MountName;
  readonly productUuid: string | null;
  readonly category: string | null;
  /** WGT-15's first step. Null means "not asked for", not "English". */
  readonly locale: string | null;
  readonly theme: string | null;
  /** WGT-11: on by default, and an operator may turn the events off. */
  readonly analytics: boolean;
  /** A CSS selector for the node to mount into; null means "where the script tag is". */
  readonly target: string | null;
  /** Where the API lives, derived from the script's own `src`. */
  readonly apiBase: string;
}

/**
 * Every Kaiki embed on the page, in document order.
 *
 * Reads `document.currentScript` when it can and falls back to a query, because
 * `currentScript` is null inside a module and inside anything that re-inserts
 * the tag — which is what half the page builders in WPP-2's list do.
 */
export function findEmbeds(doc: Document = document): HTMLScriptElement[] {
  return Array.from(doc.querySelectorAll<HTMLScriptElement>('script[data-key]')).filter((script) =>
    /kaiki-widget(\.min)?\.js/.test(script.src),
  );
}

export function readConfig(script: HTMLScriptElement): WidgetConfig | null {
  const key = (script.dataset.key ?? '').trim();

  // No key, no widget. Rendering an error here would put the operator's
  // misconfiguration in front of their guests; the console line is for the
  // person who can fix it, and it is dropped from the production bundle.
  if (key === '') {
    return null;
  }

  const productUuid = value(script.dataset.product);

  return {
    key,
    mount: mountOf(script.dataset.mount, productUuid),
    productUuid,
    category: value(script.dataset.category),
    locale: value(script.dataset.locale),
    theme: value(script.dataset.theme),
    // Anything but the literal string "false" leaves them on: an operator who
    // typed `data-analytics="no"` meant to switch them off, and a value that
    // silently meant "on" would be worse than either reading.
    analytics: (script.dataset.analytics ?? 'true').toLowerCase() === 'true',
    target: value(script.dataset.target),
    apiBase: apiBaseFrom(script.src),
  };
}

function mountOf(raw: string | undefined, productUuid: string | null): MountName {
  const named = (raw ?? '').trim().toLowerCase() as MountName;

  if (MOUNTS.includes(named)) {
    return named;
  }

  return productUuid === null ? 'list' : 'booking';
}

/**
 * The API origin, taken from the origin the bundle itself was served from.
 *
 * Not configurable, and that is deliberate: a `data-api` attribute would let a
 * compromised page point a live publishable key at somebody else's server, and
 * the bundle is served by the platform that owns the API. ADR-0011 keeps them
 * on one origin for exactly this reason.
 */
function apiBaseFrom(src: string): string {
  try {
    return new URL(src).origin + '/api/v1';
  } catch {
    // A relative `src` on a page with no `<base>`: same origin by definition.
    return '/api/v1';
  }
}

function value(raw: string | undefined): string | null {
  const trimmed = (raw ?? '').trim();

  return trimmed === '' ? null : trimmed;
}
