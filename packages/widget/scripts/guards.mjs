#!/usr/bin/env node
/**
 * The three rules about what is **absent** from the widget (WGT-9, WGT-13, WGT-14).
 *
 * The issue's note says why these are greps rather than tests: *"Both are rules
 * about what is absent, and a behavioural test cannot see the difference between
 * a colour that came from the API and one that happened to match."* A widget
 * rendering the right teal proves nothing about where the teal came from.
 *
 * 1. **No hardcoded brand colour** (WGT-9 FIXED). Scans the built bundle for hex
 *    colours. Everything visible derives from `--kaiki-*` custom properties and
 *    `color-mix()` of them, so there is nothing legitimate for this to find.
 *
 * 2. **No price arithmetic** (WGT-13 FIXED). *"The widget never computes
 *    prices."* Scans the source for arithmetic on anything named `*_cents` —
 *    a multiplication by pax, a sum of extras, a VAT split. Every amount comes
 *    from `POST /price-quote` or the draft booking response, already decided by
 *    the server.
 *
 * 3. **Both locale bundles carry the same keys** (WGT-14). *"A missing key …
 *    fails the build in CI."* Compares the two files' key sets in both
 *    directions.
 *
 * Run after `widget:build`; the first check needs the bundle, the other two read
 * the source.
 */
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const root = fileURLToPath(new URL('..', import.meta.url));
const bundle = join(root, 'dist', 'kaiki-widget.js');
const srcDir = join(root, 'src');

const failures = [];

// --- 1. No hex colour anywhere in the built bundle (WGT-9) --------------------

if (!existsSync(bundle)) {
  failures.push('No bundle at dist/kaiki-widget.js — run `npm run widget:build` first.');
} else {
  const code = readFileSync(bundle, 'utf8');

  // `#` followed by three, four, six or eight hex digits and nothing wordlike
  // after it. Matches `#0B4F4A` and `#fff`, and not `#f` in a URL fragment or
  // an id selector like `#booking`.
  const hex = code.match(/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b(?![\w-])/g) ?? [];

  // A six-hex-digit string that is also a word — `#decade` — is vanishingly
  // unlikely in a minified bundle, so every hit is reported rather than
  // filtered. A false positive here is a minute of somebody's time; a missed
  // one is an operator's brand colour quietly ignored.
  if (hex.length > 0) {
    failures.push(
      `WGT-9: the bundle contains ${hex.length} hex colour(s): ${[...new Set(hex)].slice(0, 8).join(', ')}\n` +
        '  No brand colour, radius or font may be hardcoded. Derive it from a --kaiki-* custom\n' +
        '  property, or from color-mix() of one, so an operator changing a colour changes this too.',
    );
  }
}

// --- 2. No arithmetic on cents in the source (WGT-13) ------------------------

for (const file of sources(srcDir)) {
  const code = readFileSync(file, 'utf8');
  const relative = file.slice(root.length).replace(/\\/g, '/');

  code.split('\n').forEach((line, index) => {
    // `something_cents * n`, `+ x_cents`, `_cents /`, `_cents +=` … The widget
    // may *read* an amount and hand it to a formatter; it may not do sums with
    // one.
    const arithmetic = /_cents\s*(?:[*/+-]|[*/+-]=)|[*/+-]\s*[A-Za-z_$][\w$.]*_cents/;

    if (arithmetic.test(line) && !line.trimStart().startsWith('*') && !line.trimStart().startsWith('//')) {
      failures.push(
        `WGT-13: arithmetic on a cents value at ${relative}:${index + 1}\n  ${line.trim()}\n` +
          '  The widget never computes a price. Ask POST /price-quote, or read the draft booking.',
      );
    }
  });
}

// --- 3. The two locale bundles agree (WGT-14) --------------------------------

const keysOf = (file) => {
  const code = readFileSync(join(srcDir, 'locales', file), 'utf8');

  return new Set([...code.matchAll(/^\s*'([^']+)':/gm)].map((match) => match[1]));
};

const enKeys = keysOf('en.ts');
const elKeys = keysOf('el.ts');

const missingInEl = [...enKeys].filter((key) => !elKeys.has(key));
const missingInEn = [...elKeys].filter((key) => !enKeys.has(key));

if (missingInEl.length > 0 || missingInEn.length > 0) {
  failures.push(
    'WGT-14: the locale bundles disagree.\n' +
      (missingInEl.length > 0 ? `  Missing from el.ts: ${missingInEl.join(', ')}\n` : '') +
      (missingInEn.length > 0 ? `  Missing from en.ts: ${missingInEn.join(', ')}\n` : '') +
      '  Every visible string exists in both locales (I18N-1). A key that falls back to English\n' +
      '  is a widget an operator’s guests notice before the operator does.',
  );
}

// --- report ------------------------------------------------------------------

if (failures.length > 0) {
  console.error(`\n${failures.length} guard failure(s):\n`);
  failures.forEach((failure) => console.error(`- ${failure}\n`));
  process.exit(1);
}

console.log(`Guards passed — no hardcoded colour, no price arithmetic, ${enKeys.size} keys in both locales.`);

function sources(dir) {
  const found = [];

  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      found.push(...sources(path));
    } else if (/\.tsx?$/.test(entry)) {
      found.push(path);
    }
  }

  return found;
}
