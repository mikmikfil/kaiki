/**
 * Assemble «Μια εκδρομή, από την αρχή ως τη δημοσίευση» and print it to a PDF.
 *
 *   node docs/ekdromi/capture.mjs      # the screenshots — needs a running Kaiki
 *   node docs/ekdromi/build.mjs        # the booklet
 *
 * ## It borrows the manual's stylesheet rather than carrying its own
 *
 * `docs/manual/manual.css` is written for `@page` and already decides every
 * question this booklet has — the cover, the figures, the notes, the tables.
 * A second stylesheet would drift from it the first time either is touched, and
 * two documents from the same product that print differently look like two
 * products. The one thing added here is the `.ka-*` rules at the foot, for the
 * step numbers this booklet has and the manual does not.
 *
 * ## And it lands on the Desktop
 *
 * Unlike the manual, which is copied by hand. The product owner asked for this
 * one on the Desktop (2026-09-22), and a build that puts it there is one fewer
 * thing to remember after a re-run.
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
<title>Kaiki — Μια εκδρομή, από την αρχή ως τη δημοσίευση</title>
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

const htmlPath = resolve(HERE, 'ekdromi.html');
const pdfPath = resolve(HERE, 'kaiki-ekdromi.pdf');

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

const desktop = resolve(homedir(), 'Desktop', 'Kaiki - Πώς φτιάχνω μια εκδρομή.pdf');

copyFileSync(pdfPath, desktop);

process.stdout.write(`copy   ${desktop}\n`);
