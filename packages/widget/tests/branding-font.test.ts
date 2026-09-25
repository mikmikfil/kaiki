import { describe, expect, it } from 'vitest';

import { brandProperties, SYSTEM_STACK } from '../src/branding';

/*
 * The widget's face (2026-09-24). On Kaiki's own trip page the branding call is
 * skipped and the payload is empty; the widget must then take the page's own
 * `--kaiki-font` (Inter) by inheritance rather than declaring a system stack
 * over it — that declaration is what made the booking box the one thing on the
 * page in a different typeface.
 */
describe('the font the branding declares', () => {
  it('declares nothing when the branding names no family, so the page\'s font inherits', () => {
    expect(brandProperties({})).not.toContain('--kaiki-font');
    expect(brandProperties({ font: { family: '' } } as never)).not.toContain('--kaiki-font');
  });

  it('names the operator\'s family ahead of the system stack when it has one', () => {
    expect(brandProperties({ font: { family: 'Inter' } } as never)).toContain(`--kaiki-font: "Inter", ${SYSTEM_STACK}`);
  });
});
