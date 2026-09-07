import type { Api } from './api-client';

/**
 * The transport BRD-4's live preview uses instead of the network (#110).
 *
 * ## Why the preview needs one at all
 *
 * The branding screen embeds the **real widget** — the issue is explicit that a
 * hand-drawn preview is a second implementation of the widget's appearance and
 * will diverge on the first change to either. But inside the panel there is no
 * publishable key to fetch with: a plaintext key is never stored, only its hash,
 * its prefix and its last four characters. Minting a real key to draw a preview
 * with would be creating a credential to look at a colour.
 *
 * So the panel puts the operator's own branding and a couple of their own trips
 * on the page, and this answers from that. Same bundle, same components, same
 * styles, same shadow root — only the transport differs, and it is the one part
 * of the widget a preview cannot exercise anyway.
 *
 * ## It refuses writes
 *
 * A preview is a picture of a booking form, not a booking form. `post()` rejects
 * rather than pretending, so a mount that tried to create a draft in a preview
 * fails loudly in development instead of quietly holding seats an operator did
 * not sell.
 */

export interface PreviewPayload {
  readonly branding?: unknown;
  readonly products?: unknown;
  readonly availability?: unknown;
}

declare global {
  // eslint-disable-next-line no-var
  var __kaikiPreview: PreviewPayload | undefined;
}

export function isPreview(): boolean {
  return typeof globalThis.__kaikiPreview === 'object' && globalThis.__kaikiPreview !== null;
}

export function previewClient(): Api {
  const payload = globalThis.__kaikiPreview ?? {};

  return {
    async get<T>(path: string): Promise<T> {
      if (path.startsWith('/branding')) {
        return { data: payload.branding ?? {} } as T;
      }

      if (path.startsWith('/products')) {
        return { data: payload.products ?? [] } as T;
      }

      if (path.startsWith('/availability')) {
        return { data: payload.availability ?? [] } as T;
      }

      return { data: null } as T;
    },

    async post<T>(): Promise<T> {
      // Loudly, and only ever in a panel: nothing in a preview may reach the
      // booking path.
      throw new Error('preview_is_read_only');
    },

    invalidate(): void {
      // Nothing is cached, because nothing was fetched.
    },
  };
}
