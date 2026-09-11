import { SYSTEM_STACK } from './branding';

/**
 * The operator's own look, set in the WordPress plugin (asked for 2026-09-11).
 *
 * The plugin's «Appearance» section offers two choices: *as in Kaiki*, which
 * sends nothing and leaves the branding from the Kaiki panel in charge, and
 * *my own*, which sends up to six attributes on the embed — `data-primary`,
 * `data-on-primary`, `data-text`, `data-background`, `data-font` and
 * `data-radius`. They are applied **over** the branding, value by value, so an
 * operator who set only a button colour keeps every other Kaiki value.
 *
 * ## Checked twice, on purpose
 *
 * The plugin sanitises them when they are saved, and {@see readAppearance}
 * checks them again here, because an embed can be written by hand and the
 * values become CSS inside the shadow root. A colour that is not `#rrggbb`, a
 * font with anything but letters, digits, spaces and hyphens, or a radius
 * outside 0–30 is dropped rather than repaired.
 *
 * ## Nothing here is a colour
 *
 * WGT-9 still holds: every value arrives from the operator's own settings.
 */

export interface Appearance {
  readonly primary: string | null;
  /** The text on a primary-coloured button; the plugin picks the readable one. */
  readonly onPrimary: string | null;
  readonly text: string | null;
  readonly background: string | null;
  /** `inherit` for the theme's own font, a family name, or null for Kaiki's. */
  readonly font: string | null;
  readonly radius: number | null;
}

export const NO_APPEARANCE: Appearance = {
  primary: null,
  onPrimary: null,
  text: null,
  background: null,
  font: null,
  radius: null,
};

const COLOUR = /^#[0-9a-f]{6}$/i;
const FAMILY = /^[A-Za-z0-9 -]{1,60}$/;

/** The six attributes off an embed's dataset, each checked or dropped. */
export function readAppearance(data: DOMStringMap): Appearance {
  const radius = Number.parseInt((data.radius ?? '').trim(), 10);
  const font = (data.font ?? '').trim();

  return {
    primary: colour(data.primary),
    onPrimary: colour(data.onPrimary),
    text: colour(data.text),
    background: colour(data.background),
    font: font === 'inherit' || FAMILY.test(font) ? font : null,
    radius: Number.isInteger(radius) && radius >= 0 && radius <= 30 ? radius : null,
  };
}

/**
 * The custom properties they set, as declarations to follow the branding's.
 *
 * `inherit` for the font takes the theme's own face from the page around the
 * widget: the host element's font crosses into the shadow root through
 * inheritance, which is the one thing about the page allowed to.
 */
export function appearanceProperties(appearance: Appearance): string {
  const declarations: string[] = [];

  push(declarations, '--kaiki-primary', appearance.primary);
  push(declarations, '--kaiki-on-primary', appearance.onPrimary);
  push(declarations, '--kaiki-text', appearance.text);
  push(declarations, '--kaiki-background', appearance.background);

  if (appearance.font === 'inherit') {
    declarations.push('--kaiki-font: inherit');
  } else if (appearance.font !== null) {
    declarations.push(`--kaiki-font: "${appearance.font}", ${SYSTEM_STACK}`);
  }

  if (appearance.radius !== null) {
    declarations.push(`--kaiki-radius: ${appearance.radius}px`);
  }

  return declarations.join(';\n  ');
}

function colour(raw: string | undefined): string | null {
  const value = (raw ?? '').trim();

  return COLOUR.test(value) ? value.toLowerCase() : null;
}

function push(into: string[], property: string, value: string | null): void {
  if (value !== null) {
    into.push(`${property}: ${value}`);
  }
}
