import { describe, expect, it, vi } from 'vitest';

import { analytics, EVENTS, scrub } from '../src/analytics';

/*
 * WGT-11 and GDR-12: *"No guest name, email, phone or token may appear in an
 * event detail."*
 *
 * The payload is an allow-list rather than a denylist, and this is the file that
 * says why: a denylist leaks the first field somebody adds. So the test hands
 * the emitter every piece of personal data the booking flow touches and asserts
 * the other side is clean, field by field.
 */

describe('the event payload', () => {
    it('keeps only the documented fields', () => {
        const clean = scrub({
            product_uuid: 'uuid-1',
            date: '2026-07-18',
            pax: 4,
            value_cents: 18000,
            currency: 'EUR',
            error_code: 'sold_out',
        });

        expect(clean).toEqual({
            product_uuid: 'uuid-1',
            date: '2026-07-18',
            pax: 4,
            value_cents: 18000,
            currency: 'EUR',
            error_code: 'sold_out',
        });
    });

    it('drops every piece of personal data, one field at a time', () => {
        const clean = scrub({
            product_uuid: 'uuid-1',
            guest_name: 'Μαρία Παπαδοπούλου',
            email: 'maria@example.com',
            phone: '+306912345678',
            manage_token: '5O1tklHktcv3jqiicgCqbVpZkZNWRhKD44WOIwRj',
            guest: { email: 'nested@example.com' },
        });

        expect(clean).toEqual({ product_uuid: 'uuid-1' });
        expect(JSON.stringify(clean)).not.toContain('example.com');
        expect(JSON.stringify(clean)).not.toContain('5O1tkl');
    });

    it('drops an object even under an allowed key, because nothing needs one', () => {
        expect(scrub({ pax: { adults: 2, guest: 'Maria' } })).toEqual({});
    });
});

describe('emitting', () => {
    it('dispatches the eight named events with the widget version attached', () => {
        const target = new EventTarget();
        const seen: string[] = [];

        for (const name of EVENTS) {
            target.addEventListener(name, (event) => {
                seen.push(name);
                expect((event as CustomEvent).detail.widget_version).toBe('1.2.3');
            });
        }

        const emitter = analytics(true, '1.2.3', target);

        for (const name of EVENTS) {
            emitter.emit(name, { product_uuid: 'uuid-1' });
        }

        expect(seen).toEqual([...EVENTS]);
    });

    it('dispatches nothing at all when the operator switched analytics off', () => {
        const target = new EventTarget();
        const listener = vi.fn();

        target.addEventListener('kaiki:ready', listener);

        analytics(false, '1.2.3', target).emit('kaiki:ready');

        // Not a stripped event and not an empty one: WGT-7's `data-analytics`
        // means the page hears nothing.
        expect(listener).not.toHaveBeenCalled();
    });

    it('counts the event in the operator own panel, with the scrubbed detail', () => {
        const fetchMock = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));
        vi.stubGlobal('fetch', fetchMock);

        analytics(true, '1.2.3', new EventTarget(), {
            apiBase: 'https://book.kaiki.test/api/v1',
            key: 'pk_test',
        }).emit('kaiki:booking-confirmed', {
            product_uuid: 'uuid-1',
            value_cents: 4200,
            guest_email: 'nikos@example.com',
            manage_token: 'secret',
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('https://book.kaiki.test/api/v1/events');
        expect(init.keepalive).toBe(true);

        const body = JSON.parse(String(init.body)) as Record<string, unknown>;

        // The allow-list is the only thing deciding what leaves the browser, so
        // the guest's email and the manage token are not in it — which is the
        // assertion that matters here, the same one GDR-12 turns on.
        expect(body).toEqual({ event: 'kaiki:booking-confirmed', product_uuid: 'uuid-1', value_cents: 4200 });

        vi.unstubAllGlobals();
    });

    it('sends nothing to Kaiki when the operator switched analytics off', () => {
        const fetchMock = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));
        vi.stubGlobal('fetch', fetchMock);

        analytics(false, '1.2.3', new EventTarget(), {
            apiBase: 'https://book.kaiki.test/api/v1',
            key: 'pk_test',
        }).emit('kaiki:ready');

        expect(fetchMock).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('never lets a refused count reach the page', () => {
        vi.stubGlobal('fetch', () => {
            throw new Error('blocked');
        });

        const target = new EventTarget();
        const listener = vi.fn();

        target.addEventListener('kaiki:ready', listener);

        // The DOM event still fires and `emit` still returns: a counter that
        // can throw inside a booking flow is worse than no counter.
        expect(() =>
            analytics(true, '1.2.3', target, { apiBase: 'https://book.kaiki.test/api/v1', key: 'pk_test' }).emit(
                'kaiki:ready',
            ),
        ).not.toThrow();

        expect(listener).toHaveBeenCalledTimes(1);

        vi.unstubAllGlobals();
    });
});
