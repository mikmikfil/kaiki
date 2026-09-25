import { render } from 'preact';
import { beforeEach, describe, expect, it } from 'vitest';

import { depositSplit, splitLine } from '../src/booking/price';
import { translator } from '../src/i18n';
import { Peek } from '../src/mounts/booking/Sheet';

/*
 * «€X τώρα, €Y αργότερα» under the total (Mike, 2026-09-25).
 *
 * The widget still computes nothing (WGT-13): both amounts arrive formatted in
 * `data.deposit`, and the only decision made here is which sentence they go
 * into — «αργότερα», or «στο σκάφος» when the operator collects the balance on
 * the day.
 */

const deposit = {
  type: 'percent',
  percent: 30,
  amount_cents: 3900,
  amount_formatted: '39,00 €',
  balance_cents: 9100,
  balance_formatted: '91,00 €',
  balance_due_at: '2026-07-17T06:00:00Z',
  balance_on_board: false,
};

let host: HTMLElement;

beforeEach(() => {
  document.body.innerHTML = '<div id="host"></div>';
  host = document.getElementById('host') as HTMLElement;
});

describe('reading the deposit off a quote', () => {
  it('takes both halves as the server formatted them', () => {
    expect(depositSplit(deposit)).toEqual({ now: '39,00 €', later: '91,00 €', onBoard: false });
  });

  it('knows when the balance is paid on the boat', () => {
    expect(depositSplit({ ...deposit, balance_due_at: null, balance_on_board: true })?.onBoard).toBe(true);
  });

  it('finds no split on a quote without a deposit', () => {
    expect(depositSplit({ ...deposit, type: 'none', amount_cents: 0, balance_cents: 0 })).toBeNull();
    expect(depositSplit(undefined)).toBeNull();
  });

  it('finds no split when there is nothing left to pay later', () => {
    expect(depositSplit({ ...deposit, balance_cents: 0 })).toBeNull();
  });

  it('finds no split in a body an older server sent without the balance formatted', () => {
    const { balance_formatted: _dropped, ...older } = deposit;

    expect(depositSplit(older)).toBeNull();
  });
});

describe('the line under the total', () => {
  it('says «αργότερα» in Greek', () => {
    const split = depositSplit(deposit);

    expect(split).not.toBeNull();
    expect(splitLine(split!, translator('el'))).toBe('39,00 € τώρα, 91,00 € αργότερα');
  });

  it('says «στο σκάφος» when that is where the balance is paid', () => {
    const split = depositSplit({ ...deposit, balance_on_board: true });

    expect(splitLine(split!, translator('el'))).toBe('39,00 € τώρα, 91,00 € στο σκάφος');
    expect(splitLine(split!, translator('en'))).toBe('39,00 € now, 91,00 € on the boat');
  });

  it('sits under the price in the bar, and is absent without a deposit', () => {
    const noop = (): void => undefined;

    render(
      <Peek price="130,00 €" split="39,00 € τώρα, 91,00 € αργότερα" summary="16 Σεπ" action="Συνέχεια" ready open={false} onToggle={noop} />,
      host,
    );

    const texts = [...host.querySelectorAll('.kaiki-peek-text > span')].map((node) => node.className);

    expect(texts).toEqual(['kaiki-peek-price', 'kaiki-peek-split', 'kaiki-peek-summary']);
    expect(host.querySelector('.kaiki-peek-split')?.textContent).toBe('39,00 € τώρα, 91,00 € αργότερα');

    render(<Peek price="130,00 €" summary="16 Σεπ" action="Συνέχεια" ready open={false} onToggle={noop} />, host);

    expect(host.querySelector('.kaiki-peek-split')).toBeNull();
  });
});
