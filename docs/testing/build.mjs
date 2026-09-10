/**
 * Assemble the testing walkthrough and print it to a PDF.
 *
 *   node docs/testing/capture.mjs     # the screenshots — walks the journey
 *   node docs/testing/build.mjs       # the document
 *
 * ## It borrows the manual's stylesheet rather than growing a second one
 *
 * `docs/manual/manual.css` already decides how a Kaiki document reads on paper
 * — the measure, the figures, the note boxes, the `@page` margins. This adds
 * only what a runbook needs and the manual does not: terminal blocks, and a
 * numbered-step list that survives a page break.
 *
 * Two documents that look almost alike are worse than two that look different,
 * because the reader cannot tell which one they are holding. These are meant to
 * look like the same product from the same people.
 */

import { chromium } from '@playwright/test';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const MANUAL = resolve(HERE, '..', 'manual');

/** The document, in order. */
const PARTS = [
  'front.html',
  'part-a-setup.html',
  'part-b-access.html',
  'part-c-new-operator.html',
  'part-d-first-login.html',
  'part-e-building.html',
  'part-f-guest.html',
  'part-g-back-office.html',
  'part-h-reporting.html',
];

const missing = PARTS.filter((name) => !existsSync(resolve(HERE, name)));

if (missing.length > 0) {
  process.stderr.write(`Missing parts: ${missing.join(', ')}\n`);
  process.exit(1);
}

const body = PARTS
  .map((name) => readFileSync(resolve(HERE, name), 'utf8').trim())
  .join('\n\n');

// Inlined, not linked: Chromium prints a `file://` page with a `file://`
// stylesheet only behind a launch flag, and one `<style>` needs no flag.
const css = readFileSync(resolve(MANUAL, 'manual.css'), 'utf8')
  + '\n\n'
  + readFileSync(resolve(HERE, 'walkthrough.css'), 'utf8');

const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Οδηγός δοκιμών</title>
<style>
${css}
</style>
</head>
<body>
${body}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'walkthrough.html');
const pdfPath = resolve(HERE, 'kaiki-odigos-dokimon.pdf');

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
  // The margins belong to `@page` in the manual's stylesheet, so that the cover
  // can override them for its own first page. Setting them here would win, and
  // the cover would print with a white frame around its colour.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);
