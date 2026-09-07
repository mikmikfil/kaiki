/// <reference types="vite/client" />

/**
 * The bundle's own version, replaced at build time (WGT-11, ADR-0011).
 *
 * A `define`, not a runtime read of `package.json`: the analytics events carry
 * `widget_version` and an operator debugging a page needs to know which bundle
 * they are looking at, but shipping the package manifest into a public file to
 * find out would ship everything else in it too.
 */
declare const __KAIKI_WIDGET_VERSION__: string;
