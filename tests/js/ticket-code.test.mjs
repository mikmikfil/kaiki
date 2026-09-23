/**
 * What the in-page camera hands to `scan()` (resources/js/boarding/ticket-code.js).
 *
 * Plain `node --test`, no framework: the parser is one pure function, and the
 * page's safety depends on it — a QR that is not a ticket must never reach the
 * boarding queue. Run with `npm run test:js`.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ticketCodeFrom } from '../../resources/js/boarding/ticket-code.js';

test('reads the code out of a ticket QR, which is the boarding URL', () => {
    assert.equal(ticketCodeFrom('https://kaiki.gr/app/boarding?ticket=TCK-7F3K9Q'), 'TCK-7F3K9Q');
    assert.equal(ticketCodeFrom('http://127.0.0.1:8000/app/boarding?ticket=ABC123&x=1'), 'ABC123');
});

test('accepts a ticket printed while the site answered on another address', () => {
    assert.equal(ticketCodeFrom('http://192.168.1.20:8000/app/boarding?ticket=ABC123'), 'ABC123');
});

test('accepts a bare code, as somebody would type it', () => {
    assert.equal(ticketCodeFrom('TCK-7F3K9Q'), 'TCK-7F3K9Q');
    assert.equal(ticketCodeFrom('  TCK-7F3K9Q \n'), 'TCK-7F3K9Q');
});

test('decodes an escaped code in the query', () => {
    assert.equal(ticketCodeFrom('https://kaiki.gr/app/boarding?ticket=A%2FB'), 'A/B');
});

test('refuses a QR that is not a ticket', () => {
    assert.equal(ticketCodeFrom('https://example.com/menu'), null);
    assert.equal(ticketCodeFrom('https://kaiki.gr/app/boarding?ticket='), null);
    assert.equal(ticketCodeFrom('WIFI:S:Quay;T:WPA;P:secret phrase;;'), null);
    assert.equal(ticketCodeFrom('http://'), null);
});

test('refuses nothing at all', () => {
    assert.equal(ticketCodeFrom(''), null);
    assert.equal(ticketCodeFrom('   '), null);
    assert.equal(ticketCodeFrom(null), null);
    assert.equal(ticketCodeFrom(undefined), null);
});
