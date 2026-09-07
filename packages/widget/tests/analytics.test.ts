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
});
