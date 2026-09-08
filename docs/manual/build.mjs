/**
 * Assemble the manual and print it to a PDF.
 *
 *   node docs/manual/capture.mjs      # the screenshots — needs a running Kaiki
 *   node docs/manual/build.mjs        # the book
 *
 * Chapters are separate files because they are written by different people at
 * different times, and a single HTML file of forty pages is a file nobody wants
 * to edit twice. The order below is the book's order and is the only place it
 * is stated.
 *
 * ## Printed by Chromium, through Playwright
 *
 * The repository already depends on it for the end-to-end run, so the manual
 * needs no PDF library of its own — and printing through the same engine that
 * took the screenshots means the figures land at the size the stylesheet says
 * they will. `manual.css` is written for `@page`; everything that decides how
 * this reads on paper is in there rather than here.
 */

import { chromium } from '@playwright/test';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

/** The book, in order. */
const CHAPTERS = [
  'chapter-front.html',
  'chapter-a-what-is-kaiki.html',
  'chapter-b-first-steps.html',
  'chapter-c-daily.html',
  'chapter-d-money.html',
  'chapter-e-website.html',
  'chapter-f-settings.html',
  'part-z.html',
  'chapter-roles.html',
  'chapter-h-admin.html',
  'part-theta.html',
  'chapter-whats-coming.html',
];

const missing = CHAPTERS.filter((name) => !existsSync(resolve(HERE, name)));

if (missing.length > 0) {
  process.stderr.write(`Missing chapters: ${missing.join(', ')}\n`);
  process.exit(1);
}

const body = CHAPTERS
  .map((name) => readFileSync(resolve(HERE, name), 'utf8').trim())
  .join('\n\n');

const css = readFileSync(resolve(HERE, 'manual.css'), 'utf8');

/*
 * The stylesheet is inlined rather than linked.
 *
 * Chromium prints a `file://` page with its own `file://` stylesheet only when
 * local file access is allowed, and that is a launch flag. One `<style>` needs
 * no flag and makes the assembled HTML a single file somebody can open, read
 * and send on.
 */
const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Το εγχειρίδιο του διοργανωτή</title>
<style>
${css}
</style>
</head>
<body>
${body}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'manual.html');
const pdfPath = resolve(HERE, 'kaiki-manual.pdf');

writeFileSync(htmlPath, html);
process.stdout.write(`html   ${htmlPath}\n`);

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`file://${htmlPath.replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });

// The web fonts come from Google and the page has to have them before it is
// measured — a print taken mid-swap sets the whole book in the fallback.
await page.evaluate(() => document.fonts.ready);

await page.pdf({
  path: pdfPath,
  format: 'A4',
  printBackground: true,
  // The margins are `@page`'s, in `manual.css`, where the cover can override
  // them for its own first page. Setting them here as well would win and the
  // cover would print with a white frame around its colour.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);
