import { render } from 'preact';
import { beforeEach, describe, expect, it } from 'vitest';

import { readConfig } from '../src/config';
import { translator } from '../src/i18n';
import { FourLines } from '../src/mounts/booking/FourLines';

/*
 * «Show the boat's name» (2026-09-11): the WordPress plugin sends
 * `data-vessel="hide"` when the operator switches it off, and the booking
 * form's four lines become three. Without the attribute nothing changes.
 */

const product = {
  title: 'Sunset cruise',
  duration_minutes: 180,
  meeting_point: { name: 'Zea Marina' },
  vessel: { name: 'Odysseas' },
};

let host: HTMLElement;

beforeEach(() => {
  document.body.innerHTML = '<div id="host"></div>';
  host = document.getElementById('host') as HTMLElement;
});

describe('the boat line', () => {
  it('is shown by default', () => {
    render(<FourLines product={product} t={translator('en')} />, host);

    expect(host.textContent).toContain('Odysseas');
    expect(host.querySelectorAll('dt')).toHaveLength(4);
  });

  it('is left out when the operator hides it, and the other three stay', () => {
    render(<FourLines product={product} t={translator('en')} showVessel={false} />, host);

    expect(host.textContent).not.toContain('Odysseas');
    expect(host.textContent).not.toContain('Boat');
    expect(host.textContent).toContain('Sunset cruise');
    expect(host.textContent).toContain('Zea Marina');
    expect(host.querySelectorAll('dt')).toHaveLength(3);
  });

  it('is read off the embed: hidden only for data-vessel="hide"', () => {
    const embed = (value?: string): HTMLScriptElement => {
      const script = document.createElement('script');
      script.src = 'https://api.kaiki.app/widget/v1/kaiki-widget.js';
      script.setAttribute('data-key', 'pk_live_1');

      if (value !== undefined) {
        script.setAttribute('data-vessel', value);
      }

      document.body.appendChild(script);

      return script;
    };

    expect(readConfig(embed())?.showVessel).toBe(true);
    expect(readConfig(embed('hide'))?.showVessel).toBe(false);
    expect(readConfig(embed(' Hide '))?.showVessel).toBe(false);
    expect(readConfig(embed('show'))?.showVessel).toBe(true);
  });
});
