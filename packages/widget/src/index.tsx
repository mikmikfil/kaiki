import { render } from 'preact';

import { analytics } from './analytics';
import { type Api, ApiClient } from './api-client';
import { ensureFontLink, loadBranding, type BrandPayload } from './branding';
import { findEmbeds, readConfig, type WidgetConfig } from './config';
import { resolveLocale, translator } from './i18n';
import './mounts/booking/register';
import './mounts/calendar/CalendarMount';
import './mounts/enquiry/EnquiryMount';
import './mounts/list/ListMount';
import { isPreview, previewClient } from './preview';
import { createShadowHost } from './shadow';
import { ErrorState, LoadingState, Shell } from './shell';

/**
 * The loader (WGT-3, WGT-8).
 *
 * ## Idempotent, because a page builder will run it twice
 *
 * WGT-8. The same script tag gets re-inserted by Elementor when a section is
 * edited, by a Turbo navigation, and by any theme that lazy-loads a footer. So
 * bootstrapping is guarded by a flag on `window`, and each embed is marked on
 * its own script tag: running the whole file again finds nothing new to do,
 * rather than rendering a second copy of the booking form beside the first.
 *
 * ## One client and one branding fetch for the page
 *
 * Also WGT-8, and the reason is bandwidth on a phone: three mounts on an
 * operator's home page must not be three `GET /branding` calls. The client is
 * keyed by API base and publishable key — two embeds with different keys are
 * genuinely two tenants and get two of everything, which is right and is also
 * the case nobody would think to test.
 *
 * ## What happens before the network answers
 *
 * The shadow host is created and a localised "loading" line rendered
 * immediately. A widget that waits for `GET /branding` before drawing anything
 * is a widget that looks broken on a slow connection — and WGT-16 forbids the
 * blank state that would produce.
 */

const VERSION = __KAIKI_WIDGET_VERSION__;

const BOOTSTRAP_FLAG = '__kaikiWidgetBooted';
const EMBED_FLAG = 'kaikiMounted';

const clients = new Map<string, ApiClient>();

export function boot(doc: Document = document): void {
  for (const script of findEmbeds(doc)) {
    // The tag itself carries the mark. A module-level Set would be defeated by
    // the bundle being evaluated twice, which is exactly what happens when two
    // plugins each enqueue the script.
    if (script.dataset[EMBED_FLAG] === '1') {
      continue;
    }

    const config = readConfig(script);

    if (config === null) {
      continue;
    }

    script.dataset[EMBED_FLAG] = '1';

    mount(config, script, doc);
  }
}

function mount(config: WidgetConfig, script: HTMLScriptElement, doc: Document): void {
  const placement = resolvePlacement(config, script, doc);

  if (placement === null) {
    return;
  }

  const client = clientFor(config);
  const events = analytics(config.analytics, VERSION);
  const shadow = createShadowHost(placement.parent, doc, placement.before);

  // Something on the screen before the first byte of branding arrives.
  const provisional = translator(resolveLocale({ requested: config.locale, documentLang: doc.documentElement.lang }));

  render(<LoadingState t={provisional} />, shadow.slot);

  const draw = (): void => {
    loadBranding(client).then(
      (brand: BrandPayload) => {
        shadow.applyBranding(brand);
        ensureFontLink(brand, doc);

        const t = translator(
          resolveLocale({
            requested: config.locale,
            documentLang: doc.documentElement.lang,
            // WGT-15's third step: the operator's own house language, which
            // only the API knows. There used to be a `brand.locale` fallback
            // behind this; `WidgetCompatibilityTest` showed the API never sends
            // such a field, so it was a branch that could not be taken.
            tenantDefault: brand.tenant?.default_locale ?? null,
          }),
        );

        // Now that WGT-15 has an answer, ask the API in the same language.
        // Set before the Shell renders, so the first availability call a guest
        // triggers already carries it.
        client.setLocale(t.locale);

        render(
          <Shell
            mount={config.mount}
            productUuid={config.productUuid}
            category={config.category}
            t={t}
            poweredBy={config.credit}
            client={client}
            analytics={events}
            locale={t.locale}
            link={config.link}
            date={config.date}
          />,
          shadow.slot,
        );

        events.emit('kaiki:ready', { mount: config.mount, locale: t.locale, product_uuid: config.productUuid ?? undefined });
      },
      () => {
        // `loadBranding` swallows its own failure, so reaching here means the
        // render itself threw. The guest gets a sentence and a button, never an
        // empty box (WGT-16).
        render(<ErrorState t={provisional} network={true} onRetry={draw} />, shadow.slot);
        events.emit('kaiki:error', { mount: config.mount, error_code: 'render_failed' });
      },
    );
  };

  draw();
}

/**
 * WGT-7's `data-target`, defaulting to the script tag's own position.
 *
 * The default is what makes the one-line embed work: an operator pastes the tag
 * where they want the widget, and the widget appears there. A `data-target`
 * selector is for page builders that will not let a script tag live inside a
 * layout column.
 *
 * ## One element in the operator's document, not two
 *
 * This used to insert an anchor `<div>` next to the script and put the host
 * inside it, which meant the widget contributed a `div > div` to a page whose
 * theme it does not control. Issue 111's hostile-CSS fixture hides exactly that
 * shape — a page builder reset hiding a container it did not create — and the
 * whole widget disappeared. Now the host **is** the element, inserted where the
 * anchor used to go, and it defends itself with inline declarations that no
 * author stylesheet can outrank (see `shadow.ts`).
 */
function resolvePlacement(
  config: WidgetConfig,
  script: HTMLScriptElement,
  doc: Document,
): { parent: Element; before: Node | null } | null {
  if (config.target !== null) {
    const found = doc.querySelector(config.target);

    return found === null ? null : { parent: found, before: null };
  }

  const parent = script.parentNode;

  return parent === null ? null : { parent: parent as Element, before: script.nextSibling };
}

function clientFor(config: WidgetConfig): Api {
  // BRD-4's preview: the real widget, answering from data the panel already put
  // on the page rather than from a key the panel does not have.
  if (isPreview()) {
    return previewClient();
  }

  // The declared locale is part of the identity: two embeds on one page in
  // different languages must not share a client and take turns overwriting
  // each other's `Accept-Language`.
  const cacheKey = `${config.apiBase}|${config.key}|${config.locale ?? ''}`;
  const existing = clients.get(cacheKey);

  if (existing !== undefined) {
    return existing;
  }

  const client = new ApiClient(config.apiBase, config.key);

  // WGT-15's first step, so even the branding call is asked for in the language
  // the embed declared. The resolved locale replaces it a moment later.
  client.setLocale(config.locale);

  clients.set(cacheKey, client);

  return client;
}

// The bundle is an IIFE: evaluating it *is* the bootstrap. The flag makes a
// second evaluation — two plugins enqueueing the same script — a no-op rather
// than a second set of widgets.
const globalScope = globalThis as unknown as Record<string, unknown>;

if (typeof window !== 'undefined' && globalScope[BOOTSTRAP_FLAG] !== true) {
  globalScope[BOOTSTRAP_FLAG] = true;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => boot(), { once: true });
  } else {
    boot();
  }
}

export { registerMount } from './mounts';
export { VERSION };
