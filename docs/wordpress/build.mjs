/**
 * Assemble the WordPress guide and print it to a PDF.
 *
 *   node docs/wordpress/build.mjs
 *
 * ## No `capture.mjs`, and that is deliberate for now
 *
 * The operator manual and the testing walkthrough both take their figures from
 * a running Kaiki. This one would need a running **WordPress**, and the project
 * has never had one — `chapter-whats-coming.html` says so in as many words: the
 * plugin is built and tested, and the release is waiting on a real site to test
 * the release against.
 *
 * So the guide is written first and illustrated later, rather than waiting on
 * infrastructure. Every screen it describes is named exactly as the plugin
 * labels it, which is the part a reader actually navigates by. When there is a
 * site, a `capture.mjs` goes here beside this file and the figures drop in.
 *
 * ## It borrows the manual's stylesheet
 *
 * `docs/manual/manual.css` decides how a Kaiki document reads on paper. Two
 * documents that look almost alike are worse than two that look different,
 * because the reader cannot tell which one they are holding — these are meant
 * to look like the same product from the same people.
 */

import { chromium } from '@playwright/test';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const MANUAL = resolve(HERE, '..', 'manual');

/** The guide, in order. */
const PARTS = [
  'front.html',
  'part-a-what-it-is.html',
  'part-b-install.html',
  'part-c-settings.html',
  'part-d-placing.html',
  'part-e-trip-pages.html',
  'part-f-trip-page.html',
  'part-g-woocommerce.html',
  'part-h-when-it-breaks.html',
  'part-i-not-yet.html',
];

const missing = PARTS.filter((name) => !existsSync(resolve(HERE, name)));

if (missing.length > 0) {
  process.stderr.write(`Missing parts: ${missing.join(', ')}\n`);
  process.exit(1);
}

const body = PARTS.map((name) => readFileSync(resolve(HERE, name), 'utf8').trim()).join('\n\n');

// Inlined, not linked: Chromium prints a `file://` page with a `file://`
// stylesheet only behind a launch flag, and one `<style>` needs no flag.
const css =
  readFileSync(resolve(MANUAL, 'manual.css'), 'utf8') + '\n\n' + readFileSync(resolve(HERE, 'guide.css'), 'utf8');

const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Οδηγός WordPress</title>
<style>
${css}
</style>
</head>
<body>
${body}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'guide.html');
const pdfPath = resolve(HERE, 'kaiki-odigos-wordpress.pdf');

writeFileSync(htmlPath, html);
process.stdout.write(`html   ${htmlPath}\n`);

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`file://${htmlPath.replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);

await page.pdf({
  path: pdfPath,
  format: 'A4',
  printBackground: true,
  // The margins belong to `@page` in the manual's stylesheet, so the cover can
  // override them for its own first page. Setting them here would win, and the
  // cover would print with a white frame around its colour.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);
