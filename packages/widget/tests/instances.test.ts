import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * WGT-8: *"Multiple widget instances on one page MUST work: the loader is
 * idempotent, shares one API client and one branding fetch, and namespaces its
 * Shadow roots."*
 *
 * This is the file the issue's own acceptance criterion names — *"a test page
 * with three widget instances asserting one branding request"* — and it is
 * worth more than it looks. An operator's home page carries a list mount at the
 * top, a booking mount beside a trip and a calendar in the footer; three
 * branding requests on a phone is three round trips before anything renders,
 * and it is invisible in development where the response is instant.
 *
 * The idempotency half is the same claim from the other side: page builders
 * re-insert script tags. Elementor does it on every section edit, Turbo does it
 * on navigation, and two plugins enqueueing the same bundle is ordinary. The
 * failure that produces — two booking forms stacked on top of each other — is
 * one an operator sees before we do.
 */

const brandPayload = {
  data: {
    colors: { primary: 'oklch(0.42 0.06 180)', text: 'oklch(0.2 0.02 180)', background: 'white' },
    button_radius_px: 10,
    font: { family: 'Inter', source: 'system', css_url: null },
    tenant: { default_locale: 'el' },
  },
};

function embed(key: string, attributes: Record<string, string> = {}): void {
  const script = document.createElement('script');

  script.src = 'https://api.kaiki.app/widget/v1/kaiki-widget.js';
  script.setAttribute('data-key', key);

  for (const [name, value] of Object.entries(attributes)) {
    script.setAttribute(name, value);
  }

  document.body.appendChild(script);
}

async function bootFresh(): Promise<(doc?: Document) => void> {
  // A fresh module registry per test: the client map and the branding promise
  // are deliberately module state — that is *how* they are shared — so a test
  // that reused them would be asserting the previous test's cache.
  vi.resetModules();

  const module = await import('../src/index');

  return module.boot;
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  document.body.innerHTML = '';
  document.documentElement.lang = '';

  fetchMock = vi.fn(() =>
    Promise.resolve(new Response(JSON.stringify(brandPayload), { headers: { 'Content-Type': 'application/json' } })),
  );

  vi.stubGlobal('fetch', fetchMock);
});

describe('three widgets on one page', () => {
  it('makes one branding request between them', async () => {
    embed('pk_test_1');
    embed('pk_test_1', { 'data-product': 'uuid-1' });
    embed('pk_test_1', { 'data-mount': 'calendar', 'data-product': 'uuid-1' });

    const boot = await bootFresh();

    boot(document);

    await vi.waitFor(() => expect(document.querySelectorAll('[data-kaiki-widget]')).toHaveLength(3));
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

    const brandingCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/branding'));

    expect(brandingCalls).toHaveLength(1);
  });

  it('gives each one its own namespaced shadow root', async () => {
    embed('pk_test_1');
    embed('pk_test_1', { 'data-product': 'uuid-1' });

    const boot = await bootFresh();

    boot(document);

    await vi.waitFor(() => expect(document.querySelectorAll('[data-kaiki-widget]')).toHaveLength(2));

    const hosts = [...document.querySelectorAll('[data-kaiki-widget]')];
    const names = hosts.map((host) => host.getAttribute('data-kaiki-widget'));

    expect(new Set(names).size).toBe(2);
    // Both are real shadow roots, so neither can be reached by the host page's
    // stylesheet — which is the whole reason WGT-1 fixes this.
    expect(hosts.every((host) => (host as HTMLElement).shadowRoot !== null)).toBe(true);
  });

  it('fetches twice for two different operators, which is two tenants and not a bug', async () => {
    embed('pk_test_operator_a');
    embed('pk_test_operator_b');

    const boot = await bootFresh();

    boot(document);

    await vi.waitFor(() => expect(document.querySelectorAll('[data-kaiki-widget]')).toHaveLength(2));
    await vi.waitFor(() => {
      const brandingCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/branding'));

      expect(brandingCalls).toHaveLength(2);
    });
  });
});

describe('idempotency', () => {
  it('renders nothing extra when the loader runs again', async () => {
    embed('pk_test_1', { 'data-product': 'uuid-1' });

    const boot = await bootFresh();

    boot(document);
    await vi.waitFor(() => expect(document.querySelectorAll('[data-kaiki-widget]')).toHaveLength(1));

    // A page builder re-running the bundle, or a second plugin enqueueing it.
    boot(document);
    boot(document);

    expect(document.querySelectorAll('[data-kaiki-widget]')).toHaveLength(1);
  });
});

describe('what a guest sees before the network answers', () => {
  it('renders a localised loading line rather than an empty box', async () => {
    document.documentElement.lang = 'el';

    // A fetch that never resolves: the state under test is the one a phone on
    // one bar of signal is in for several seconds.
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));

    embed('pk_test_1');

    const boot = await bootFresh();

    boot(document);

    const host = document.querySelector('[data-kaiki-widget]') as HTMLElement;

    // WGT-16 forbids the blank widget, and the blank widget is what a shell
    // that waited for branding before drawing would show.
    expect(host.shadowRoot?.textContent).toContain('Φόρτωση');
  });
});
