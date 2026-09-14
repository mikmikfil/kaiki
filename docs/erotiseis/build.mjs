/**
 * Assemble the questions document and print it to a PDF on the desktop.
 *
 *   node build.mjs
 *
 * Same mechanism as docs/manual/build.mjs: Chromium through Playwright, the
 * stylesheet inlined, margins taken from @page. Playwright is resolved from the
 * Kaiki repository's node_modules so nothing new is installed; nothing is
 * written inside the repository.
 */

import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire('C:/Users/Mike/kaiki/package.json');
const { chromium } = require('@playwright/test');

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = process.argv[2]
  ?? 'C:/Users/Mike/Desktop/Kaiki - Ερωτήσεις για λογιστή και δικηγόρο.pdf';

const css = readFileSync(resolve(HERE, 'erotiseis.css'), 'utf8');
const body = readFileSync(resolve(HERE, 'content.html'), 'utf8');

const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Ερωτήσεις για λογιστή και δικηγόρο</title>
<style>
${css}
</style>
</head>
<body>
${body}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'erotiseis.html');
writeFileSync(htmlPath, html);
process.stdout.write(`html   ${htmlPath}\n`);

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto(`file:///${htmlPath.replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);

/*
 * Every question must fit on one page. `break-inside: avoid` cannot split a
 * block, so one taller than the page is simply cut at the foot. Lay out at the
 * page's content width (210 − 2 × 16 mm) in print media, and take answer lines
 * off any block that is too tall, never going below three.
 */
await page.emulateMedia({ media: 'print' });
await page.setViewportSize({ width: Math.round((178 / 25.4) * 96), height: 1200 });

const blocks = await page.evaluate(() => {
  const mm = (px) => (px * 25.4) / 96;
  const LIMIT = 297 - 17 - 19 - 4; // page height − top and bottom margins − safety
  return [...document.querySelectorAll('.q')].map((q) => {
    const id = q.querySelector('.num')?.textContent ?? '?';
    let removed = 0;
    while (mm(q.getBoundingClientRect().height) > LIMIT
      && q.querySelectorAll('.answer .line').length > 3) {
      q.querySelector('.answer .line:last-child').remove();
      removed += 1;
    }
    const h = mm(q.getBoundingClientRect().height);
    return `${id} ${h.toFixed(0)}mm${removed ? ` (-${removed} lines)` : ''}${h > LIMIT ? ' STILL TOO TALL' : ''}`;
  });
});
process.stdout.write(`blocks ${blocks.join(' | ')}\n`);

const fonts = await page.evaluate(() => document.fonts.check('12px Inter', 'Αλφάβητο'));
process.stdout.write(`inter  ${fonts ? 'loaded' : 'NOT loaded (fallback font in use)'}\n`);

// Guard for the document's one hard rule.
const upper = await page.evaluate(() =>
  [...document.querySelectorAll('*')].filter((el) => getComputedStyle(el).textTransform === 'uppercase').length,
);
process.stdout.write(`upper  ${upper} elements with text-transform: uppercase\n`);

await page.pdf({
  path: OUT,
  format: 'A4',
  printBackground: true,
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${OUT}\n`);
