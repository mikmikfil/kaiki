#!/usr/bin/env node
/**
 * WGT-2's 80 KB budget, as a gate that fails (NFR-3, ENV-23).
 *
 * The issue's own note: *"Build the budget check first, before there is anything
 * to measure — a gate added after the fact is a gate that gets raised instead of
 * enforced."* This existed before the first component did.
 *
 * **Gzipped**, because that is what a browser downloads and what WGT-2 fixes.
 * Measuring the raw bytes would be measuring something nobody pays for, and
 * would put the budget at roughly three times the real one.
 *
 * The printout is the point as much as the exit code: a number with a headroom
 * figure beside it is what stops a bundle drifting to 79 KB unnoticed. A run
 * that passes still tells CI how close it came.
 */
import { gzipSync } from 'node:zlib';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const BUDGET_BYTES = 80 * 1024;

const bundle = fileURLToPath(new URL('../dist/kaiki-widget.js', import.meta.url));

if (!existsSync(bundle)) {
  console.error('No bundle at packages/widget/dist/kaiki-widget.js — run `npm run widget:build` first.');
  process.exit(1);
}

const raw = readFileSync(bundle);
const gzipped = gzipSync(raw, { level: 9 });

const kb = (bytes) => (bytes / 1024).toFixed(1);
const share = ((gzipped.length / BUDGET_BYTES) * 100).toFixed(1);

console.log(`kaiki-widget.js — ${kb(raw.length)} KB raw, ${kb(gzipped.length)} KB gzipped`);
console.log(`WGT-2 budget: ${kb(BUDGET_BYTES)} KB gzipped — using ${share}%, ${kb(BUDGET_BYTES - gzipped.length)} KB left`);

if (gzipped.length > BUDGET_BYTES) {
  console.error(
    `\nOver budget by ${kb(gzipped.length - BUDGET_BYTES)} KB.\n` +
      'WGT-2 is FIXED at 80 KB gzipped. The budget is what keeps the widget usable on a phone\n' +
      'in a harbour on one bar of signal — raise it in the spec with a reason, or make the\n' +
      'bundle smaller. Do not raise it here.',
  );
  process.exit(1);
}
