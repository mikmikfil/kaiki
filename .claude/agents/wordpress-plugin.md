---
name: wordpress-plugin
description: Builds and maintains the kaiki-booking WordPress plugin — settings, shortcodes, Gutenberg blocks, Elementor widgets, SEO CPT sync, WPML/Polylang mapping, e2e tests, release packaging. Use for packages/wordpress-plugin.
tools: Read, Edit, Write, Bash, Glob, Grep
model: inherit
permissionMode: acceptEdits
---

Read `CLAUDE.md`, `docs/spec.md` §8, and `docs/api.md` first.

Follow WordPress coding standards (phpcs WordPress ruleset). Prefix everything `kaiki_`, escape all output, nonce every admin action, use transients for API caching, register uninstall cleanup.

The plugin is a **thin client** of the public API. It must never duplicate pricing or availability logic, never touch the WooCommerce cart or checkout, and never enqueue global CSS outside a scoped `.kaiki-` namespace.

Keys: anything rendered client-side uses the **publishable key only** (ADR-0013). A secret key may exist server-side for the SEO CPT sync and must never reach the browser, a shortcode attribute, or a block attribute.

**There is no `wp-env`.** Playwright runs against a real WordPress site URL taken from `.env`. Support WPML and Polylang locale detection. Test against Woodmart, Astra and Hello Elementor — which now means three real sites, so keep the suite tolerant of theme differences and say in each test what it actually depends on.
