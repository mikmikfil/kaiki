/**
 * The English strings, compiled into the bundle (WGT-14).
 *
 * Compiled rather than fetched: a second request for a translation file is a
 * request the host page's Content-Security-Policy may refuse, and a widget that
 * renders in the wrong language because a fetch failed is worse than one that is
 * a kilobyte larger. The 80 KB budget (WGT-2) is what makes that a decision
 * rather than a shrug — two locales of flat strings cost about 1 KB gzipped.
 *
 * **This file is the key registry.** `el.ts` is checked against it by
 * `npm run widget:guards`, which fails the build when a key exists in one
 * locale and not the other — WGT-14's "fails the build in CI".
 */
export const en = {
  'widget.loading': 'Loading…',
  'widget.retry': 'Try again',
  'widget.error.network': 'We could not reach the booking system. Please try again.',
  'widget.error.generic': 'Something went wrong. Please try again.',
  'widget.error.unavailable': 'Online booking is unavailable just now.',
  'widget.error.contact': 'Please contact us and we will take your booking directly.',
  'widget.mount.missing': 'This booking module is not available yet.',
  'widget.powered_by': 'Powered by Kaiki',
} as const;

export type MessageKey = keyof typeof en;
