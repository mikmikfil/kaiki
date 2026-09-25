/**
 * Assemble «Πώς είναι φτιαγμένο» and print it to a PDF.
 *
 *   node docs/architecture/build.mjs
 *
 * ## No screenshots, on purpose
 *
 * The manual and the trip guide photograph a running Kaiki, because they are
 * about screens. This one is about structure, and a screenshot of a structure
 * is a diagram that goes stale the first time a class moves. Everything here is
 * prose and numbers, and the numbers are counted from the repository.
 *
 * ## It borrows the manual's stylesheet
 *
 * `docs/manual/manual.css` already decides every question a printed Kaiki
 * document has — the cover, the parts, the notes, the tables. A second
 * stylesheet would drift from it the first time either is touched. The `.ka-*`
 * rules in `guide.css` are the few things this booklet has and the manual does
 * not.
 *
 * ## And it lands on the Desktop
 *
 * Asked for there (2026-09-22), like the trip guide.
 */

import { chromium } from '@playwright/test';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

const GUIDE = resolve(HERE, 'guide.html');
const CSS = resolve(HERE, '..', 'manual', 'manual.css');
const EXTRA = resolve(HERE, 'guide.css');

for (const path of [GUIDE, CSS, EXTRA]) {
  if (! existsSync(path)) {
    process.stderr.write(`Missing ${path}\n`);
    process.exit(1);
  }
}

const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Πώς είναι φτιαγμένο</title>
<style>
${readFileSync(CSS, 'utf8')}
${readFileSync(EXTRA, 'utf8')}
</style>
</head>
<body>
${readFileSync(GUIDE, 'utf8').trim()}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'architecture.html');
const pdfPath = resolve(HERE, 'kaiki-architecture.pdf');

writeFileSync(htmlPath, html);
process.stdout.write(`html   ${htmlPath}\n`);

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`file://${htmlPath.replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });

// The web fonts come from Google and have to be there before the page is
// measured: a print taken mid-swap sets the whole booklet in the fallback.
await page.evaluate(() => document.fonts.ready);

await page.pdf({
  path: pdfPath,
  format: 'A4',
  printBackground: true,
  // The margins are `@page`'s, in `manual.css`, so the cover can override them
  // for its own first page. Setting them here as well would win, and the cover
  // would print with a white frame around its colour.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);

const desktop = resolve(homedir(), 'Desktop', 'Kaiki - Η αρχιτεκτονική.pdf');

copyFileSync(pdfPath, desktop);

process.stdout.write(`copy   ${desktop}\n`);
