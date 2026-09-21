/**
 * Print `getyourguide-how-it-works.html` to a PDF on the desktop.
 *
 *   node docs/build-getyourguide-pdf.mjs
 *
 * The same arrangement `docs/manual/build.mjs` uses and for the same reason:
 * the repository already depends on Playwright's Chromium for the end-to-end
 * run, so a one-page explainer needs no PDF library of its own.
 *
 * Unlike the manual this is a single file, so there is nothing to assemble —
 * the stylesheet is already inside it, which also makes the HTML something
 * somebody can open in a browser and send on without the PDF.
 *
 * The output goes to the desktop rather than into the repository: it is a
 * document for a conversation with a customer, not a build artefact.
 */

import { chromium } from '@playwright/test';
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

const htmlPath = resolve(HERE, 'getyourguide-how-it-works.html');

if (!existsSync(htmlPath)) {
  process.stderr.write(`Missing source: ${htmlPath}\n`);
  process.exit(1);
}

const pdfPath = resolve(homedir(), 'Desktop', 'kaiki-getyourguide.pdf');

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`file://${htmlPath.replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });

// The page names Archivo and Source Serif 4 with real fallbacks and loads
// neither — a print of a `file://` page cannot reach a font host anyway, and a
// document that quietly waited for one would set in the fallback after a pause
// rather than immediately. `document.fonts.ready` is still awaited so the
// measurement happens after the fallbacks have resolved.
await page.evaluate(() => document.fonts.ready);

await page.pdf({
  path: pdfPath,
  format: 'A4',
  printBackground: true,
  // `@page` in the document owns the margins. Setting them here as well would
  // win, and the boxes that bleed to the text edge would print framed.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);
