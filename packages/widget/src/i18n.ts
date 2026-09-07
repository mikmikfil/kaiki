import { el } from './locales/el';
import { en, type MessageKey } from './locales/en';

/**
 * The guest's language, and the strings that go with it (WGT-14, WGT-15).
 *
 * ## The resolution order is fixed, and each step exists for a reason
 *
 * WGT-15: `data-locale`, then the host page's `<html lang>`, then the tenant
 * default, then `en`.
 *
 * The operator's explicit attribute wins because they know their audience. The
 * host page's `lang` comes next because a Greek site embedding the widget is
 * telling us something true and free. The tenant default is the operator's own
 * house language, which is right when the page says nothing. English is last
 * because it is the language most likely to be *readable* by somebody who reads
 * neither — not because it is a good answer.
 *
 * `Accept-Language` is deliberately absent, as it is on the hosted pages: a
 * German tourist on a Greek operator's page should get the operator's choice
 * and a visible switch, not a language nobody wrote.
 *
 * ## A missing key is loud in development and quiet in production
 *
 * WGT-14. In development it renders as the key itself, which is unmissable in a
 * screenshot; in production it falls back to English, because a guest mid-way
 * through a booking should not meet `widget.error.network` where a sentence
 * belongs. Neither is the real defence — `npm run widget:guards` fails the build
 * when the two bundles disagree, so a missing key should never reach either.
 */

export const LOCALES = ['el', 'en'] as const;

export type Locale = (typeof LOCALES)[number];

const BUNDLES: Record<Locale, Record<MessageKey, string>> = { el, en: { ...en } };

export function isLocale(value: string | null | undefined): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value);
}

/**
 * WGT-15's chain, applied in order.
 *
 * `<html lang="el-GR">` is a real thing on Greek sites, so the tag is cut at the
 * first subtag rather than compared whole — otherwise the most useful signal on
 * the page would be discarded for being precise.
 */
export function resolveLocale(options: {
  requested?: string | null;
  documentLang?: string | null;
  tenantDefault?: string | null;
}): Locale {
  const candidates = [options.requested, options.documentLang, options.tenantDefault];

  for (const candidate of candidates) {
    const primary = (candidate ?? '').trim().toLowerCase().split('-')[0] ?? '';

    if (isLocale(primary)) {
      return primary;
    }
  }

  return 'en';
}

export interface Translator {
  (key: MessageKey): string;
  readonly locale: Locale;
}

export function translator(locale: Locale, isDevelopment = import.meta.env?.DEV === true): Translator {
  const bundle = BUNDLES[locale];

  const translate = ((key: MessageKey): string => {
    const message = bundle[key] ?? (isDevelopment ? undefined : en[key]);

    return message ?? key;
  }) as { (key: MessageKey): string; locale: Locale };

  translate.locale = locale;

  return translate as Translator;
}

export type { MessageKey };
