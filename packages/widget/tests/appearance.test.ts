import { describe, expect, it } from 'vitest';

import { appearanceProperties, NO_APPEARANCE, readAppearance } from '../src/appearance';
import { readConfig } from '../src/config';

/*
 * The WordPress plugin's «Appearance» settings (2026-09-11): «as in Kaiki»
 * sends nothing; «my own» sends up to six attributes, applied over the
 * branding value by value. Every value is checked here as well as in the
 * plugin, because it becomes CSS inside the shadow root.
 */

function dataset(values: Record<string, string>): DOMStringMap {
  const script = document.createElement('script');

  for (const [name, value] of Object.entries(values)) {
    script.setAttribute(`data-${name}`, value);
  }

  return script.dataset;
}

describe('reading the operator look off the embed', () => {
  it('reads all six when they are well formed', () => {
    expect(
      readAppearance(
        dataset({
          primary: '#0B5F86',
          'on-primary': '#ffffff',
          text: '#1e1e1e',
          background: '#f9f7f3',
          font: 'Literata',
          radius: '12',
        }),
      ),
    ).toEqual({
      primary: '#0b5f86',
      onPrimary: '#ffffff',
      text: '#1e1e1e',
      background: '#f9f7f3',
      font: 'Literata',
      radius: 12,
    });
  });

  it('drops anything that is not what the plugin would have saved', () => {
    expect(
      readAppearance(
        dataset({ primary: 'red', text: '#12345', font: 'Inter; } :host { display:none', radius: '99' }),
      ),
    ).toEqual(NO_APPEARANCE);
  });

  it('takes «inherit» as the theme font', () => {
    expect(readAppearance(dataset({ font: 'inherit' })).font).toBe('inherit');
  });

  it('is part of the embed configuration, and empty when the embed sets none', () => {
    const script = document.createElement('script');
    script.src = 'https://api.kaiki.app/widget/v1/kaiki-widget.js';
    script.setAttribute('data-key', 'pk_live_1');
    document.body.appendChild(script);

    expect(readConfig(script)?.appearance).toEqual(NO_APPEARANCE);
  });
});

describe('the custom properties it sets', () => {
  it('writes only what was set, so every other Kaiki value stays', () => {
    const css = appearanceProperties({ ...NO_APPEARANCE, primary: '#0b5f86', radius: 0 });

    expect(css).toBe('--kaiki-primary: #0b5f86;\n  --kaiki-radius: 0px');
  });

  it('inherits the theme font, or names a font ahead of the system stack', () => {
    expect(appearanceProperties({ ...NO_APPEARANCE, font: 'inherit' })).toBe('--kaiki-font: inherit');
    expect(appearanceProperties({ ...NO_APPEARANCE, font: 'Literata' })).toMatch(/^--kaiki-font: "Literata", system-ui/);
  });

  it('sets the text colour of the buttons when the plugin sent one', () => {
    expect(appearanceProperties({ ...NO_APPEARANCE, onPrimary: '#111111' })).toBe('--kaiki-on-primary: #111111');
  });

  it('writes nothing at all for «as in Kaiki»', () => {
    expect(appearanceProperties(NO_APPEARANCE)).toBe('');
  });
});
