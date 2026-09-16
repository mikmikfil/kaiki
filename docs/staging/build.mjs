/**
 * Assemble the staging guide and print it to a PDF.
 *
 *   node docs/staging/build.mjs
 *
 * Writes `docs/staging/kaiki-odigos-staging.pdf` and, when the folder exists,
 * copies it to Mike's desktop as «Kaiki - Οδηγός staging.pdf».
 *
 * ## No figures, and no `capture.mjs`
 *
 * The operator manual photographs a running Kaiki. This guide is about a
 * server that does not exist yet, behind a control panel nobody in the project
 * has seen, so its only picture is the diagram of who calls whom — drawn inline
 * in `part-a-why.html` rather than captured.
 *
 * ## It borrows the manual's stylesheet
 *
 * For the reason `docs/wordpress/build.mjs` gives: documents from the same
 * product should look like it. `staging.css` adds only what a server guide
 * needs — commands, config files, a checklist and a letter.
 *
 * ## Every command in it was read out of the repository
 *
 * `composer.json`, `package.json`, `.env.example`, `config/*.php`,
 * `routes/*.php`, `app/Console/Commands/PublishWidgetCommand.php` and the CI
 * workflow. Where something could not be confirmed from the code — a Viva menu
 * name, a Cretaforce screen — the guide marks it as such rather than guessing
 * in the same voice as the rest. When those files change, this changes too.
 */

import { chromium } from '@playwright/test';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const MANUAL = resolve(HERE, '..', 'manual');

/** The guide, in order. */
const PARTS = [
  'front.html',
  'part-a-why.html',
  'part-b-server.html',
  'part-c-install.html',
  'part-d-env.html',
  'part-e-background.html',
  'part-f-update.html',
  'part-g-wordpress.html',
  'part-h-viva.html',
  'part-i-checklist.html',
  'part-j-trouble.html',
  'part-k-production.html',
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
  readFileSync(resolve(MANUAL, 'manual.css'), 'utf8') + '\n\n' + readFileSync(resolve(HERE, 'staging.css'), 'utf8');

const html = `<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Kaiki — Οδηγός staging</title>
<style>
${css}
</style>
</head>
<body>
${body}
</body>
</html>
`;

const htmlPath = resolve(HERE, 'staging.html');
const pdfPath = resolve(HERE, 'kaiki-odigos-staging.pdf');

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
  // override them for its own first page.
  margin: { top: '0', bottom: '0', left: '0', right: '0' },
  preferCSSPageSize: true,
  displayHeaderFooter: false,
});

await browser.close();

process.stdout.write(`pdf    ${pdfPath}\n`);

// Mike reads it from the desktop. Skipped quietly on any other machine.
const desktop = resolve(process.env.USERPROFILE ?? process.env.HOME ?? '', 'Desktop');

if (existsSync(desktop)) {
  const target = resolve(desktop, 'Kaiki - Οδηγός staging.pdf');
  copyFileSync(pdfPath, target);
  process.stdout.write(`copy   ${target}\n`);
}
