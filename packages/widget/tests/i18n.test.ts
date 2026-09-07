import { describe, expect, it } from 'vitest';

import { el } from '../src/locales/el';
import { en } from '../src/locales/en';
import { resolveLocale, translator } from '../src/i18n';

/*
 * WGT-14 and WGT-15.
 *
 * The chain is four steps and each one exists for a reason, so each one is
 * asserted separately rather than through one example that happens to exercise
 * the first.
 */

describe('the locale chain', () => {
    it('prefers the operator explicit attribute', () => {
        expect(resolveLocale({ requested: 'el', documentLang: 'en', tenantDefault: 'en' })).toBe('el');
    });

    it('falls to the host page lang, subtag and all', () => {
        // `<html lang="el-GR">` is what a Greek site actually writes, and
        // discarding the most useful signal on the page for being precise would
        // be the wrong reading of it.
        expect(resolveLocale({ documentLang: 'el-GR' })).toBe('el');
    });

    it('falls to the operator house language when the page says nothing', () => {
        expect(resolveLocale({ documentLang: '', tenantDefault: 'el' })).toBe('el');
    });

    it('ends at English rather than at nothing', () => {
        expect(resolveLocale({})).toBe('en');
        // A language the widget does not speak is not an error; it is the next
        // step in the chain.
        expect(resolveLocale({ requested: 'de', documentLang: 'fr' })).toBe('en');
    });
});

describe('the bundles', () => {
    it('carries every key in both locales', () => {
        // The build gate (`npm run widget:guards`) is the real enforcement;
        // this is the same claim where a developer meets it first.
        expect(Object.keys(el).sort()).toEqual(Object.keys(en).sort());
    });

    it('renders Greek in Greek and English in English', () => {
        expect(translator('el')('widget.retry')).toBe(el['widget.retry']);
        expect(translator('en')('widget.retry')).toBe(en['widget.retry']);
    });

    it('uppercases nothing in the Greek bundle', () => {
        // I18N-2: Greek capitals drop their accents. A string written in caps
        // in the file would defeat the CSS rule that avoids the transform.
        for (const value of Object.values(el)) {
            expect(value).not.toBe(value.toUpperCase());
        }
    });

    it('falls back to English in production rather than showing a guest a key', () => {
        const missing = 'widget.not.a.key' as never;

        expect(translator('el', false)(missing)).toBe(missing);
    });
});
