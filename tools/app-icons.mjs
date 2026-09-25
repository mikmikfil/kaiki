/**
 * The panel's app icons (PWA), drawn from one SVG and rendered by Chromium.
 *
 *   node tools/app-icons.mjs            writes public/images/app-icon/*.png
 *   PREVIEW=dir node tools/app-icons.mjs   also a contact sheet, dir/app-icons.png
 *
 * The mark is the favicon's (`public/favicon.svg`): a white «K» over one light
 * wave on the panel navy, #0F2E57 — the blue of the sign-in band and the
 * sidebar. A monogram rather than the «Kaiki» wordmark, at every size: an icon
 * is seen at 48px on a home screen, where five letters are a smudge, and a
 * launcher picks the 192 or the 512 by screen density, so a wordmark at one
 * size and a K at the other would be two different icons on two phones. The
 * name is under the icon anyway, and on the splash screen.
 *
 * Four shapes of the same drawing:
 *   icon-*       rounded square on transparency, `purpose: any` (desktop Chrome)
 *   maskable-*   full bleed, the mark inside the central 80% circle that every
 *                Android mask keeps (`purpose: maskable`)
 *   apple-touch  full bleed and opaque: iOS rounds the corners itself and turns
 *                transparency black
 *
 * And the two shortcuts in the manifest (a long press on the icon), each a
 * heroicon — the same ones the panel shows for boarding and the calendar —
 * white on the navy, inside the safe circle, since Android masks those too.
 *
 * The PNGs are committed (they are assets, not build output); rerun this after
 * changing the drawing.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';

const NAVY = '#0F2E57';
const WAVE = '#8FB0DA';
const OUT = resolve('public/images/app-icon');

/** The mark in a 100×100 box, centred on (50, 50). */
const MARK = `
    <path d="M38 20v44M38 46 61 20M45 39.5 63 64" fill="none" stroke="#fff" stroke-width="10.5" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M24 80.5q6.5-5 13 0t13 0 13 0 13 0" fill="none" stroke="${WAVE}" stroke-width="4.5" stroke-linecap="round"/>`;

/**
 * @param {'any'|'maskable'|'apple'} shape
 */
function svg(shape) {
    // How much of the tile the mark takes. The mark's own extent is about
    // 57×66 units; at 0.62 the farthest corner sits 32 units from the centre,
    // inside the maskable safe circle's 40.
    const scale = { any: 0.8, maskable: 0.62, apple: 0.74 }[shape];
    const offset = 50 - 50 * scale;
    const tile = shape === 'any'
        ? `<rect width="100" height="100" rx="22" fill="${NAVY}"/>`
        : `<rect width="100" height="100" fill="${NAVY}"/>`;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">${tile}`
        + `<g transform="translate(${offset} ${offset}) scale(${scale})">${MARK}</g></svg>`;
}

const icons = [
    ['icon-192.png', 'any', 192],
    ['icon-512.png', 'any', 512],
    ['maskable-192.png', 'maskable', 192],
    ['maskable-512.png', 'maskable', 512],
    ['apple-touch-icon.png', 'apple', 180],
];

/** A heroicon (outline) from the panel's own icon set, white, on the navy. */
function shortcut(name) {
    const icon = readFileSync(resolve(`vendor/blade-ui-kit/blade-heroicons/resources/svg/o-${name}.svg`), 'utf8')
        .replace(/stroke="currentColor"/, 'stroke="#fff"')
        .replace(/<svg /, '<svg x="26" y="26" width="48" height="48" ');

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="${NAVY}"/>${icon}</svg>`;
}

const shortcuts = [
    ['shortcut-boarding.png', 'qr-code', 96],
    ['shortcut-calendar.png', 'calendar-days', 96],
];

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 1 });

for (const [file, shape, size] of icons) {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(
        `<html><body style="margin:0;background:transparent">`
        + svg(shape).replace('<svg ', `<svg width="${size}" height="${size}" style="display:block" `)
        + `</body></html>`,
    );
    await page.screenshot({ path: join(OUT, file), omitBackground: shape === 'any' });
    console.log(`${file} ${size}×${size}`);
}

for (const [file, name, size] of shortcuts) {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(
        `<html><body style="margin:0">`
        + shortcut(name).replace('<svg ', `<svg width="${size}" height="${size}" style="display:block" `)
        + `</body></html>`,
    );
    await page.screenshot({ path: join(OUT, file) });
    console.log(`${file} ${size}×${size}`);
}

if (process.env.PREVIEW) {
    mkdirSync(process.env.PREVIEW, { recursive: true });

    // The icons as they are met: a home screen at 48, 60 and 96 px, the
    // maskable ones under Android's circle and squircle, and the safe zone.
    const tile = (src, px, radius) => `<figure><img src="${src}" width="${px}" height="${px}" style="border-radius:${radius}"><figcaption>${px}px</figcaption></figure>`;
    const url = (file) => 'data:image/png;base64,' + readFileSync(join(OUT, file)).toString('base64');
    const html = `<html><body style="margin:0;padding:24px;font:13px system-ui;background:#E9EEF5;display:grid;gap:18px">
        <style>figure{margin:0;display:inline-flex;flex-direction:column;align-items:center;gap:6px;margin-right:18px;vertical-align:bottom}figcaption{color:#475569}</style>
        <div><b>any</b><br>${tile(url('icon-512.png'), 160, '0')}${tile(url('icon-192.png'), 96, '0')}${tile(url('icon-192.png'), 48, '0')}${tile(url('icon-192.png'), 32, '0')}</div>
        <div><b>maskable</b> (circle, squircle, safe zone)<br>${tile(url('maskable-512.png'), 160, '50%')}${tile(url('maskable-512.png'), 160, '30%')}
            <figure><div style="position:relative;width:160px;height:160px"><img src="${url('maskable-512.png')}" width="160" height="160"><div style="position:absolute;inset:16px;border:2px dashed #F59E0B;border-radius:50%"></div></div><figcaption>80% safe zone</figcaption></figure>
            ${tile(url('maskable-192.png'), 48, '50%')}</div>
        <div><b>apple-touch-icon</b> (iOS rounds it)<br>${tile(url('apple-touch-icon.png'), 120, '22%')}${tile(url('apple-touch-icon.png'), 60, '22%')}</div>
        <div><b>shortcuts</b><br>${tile(url('shortcut-boarding.png'), 48, '50%')}${tile(url('shortcut-calendar.png'), 48, '50%')}</div>
    </body></html>`;
    await page.setViewportSize({ width: 820, height: 620 });
    await page.setContent(html);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: join(process.env.PREVIEW, 'app-icons.png'), fullPage: true });
}

await browser.close();
