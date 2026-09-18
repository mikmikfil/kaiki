/**
 * Screenshot any page of a running Kaiki at the sizes people actually use.
 *
 * Mike's standing rule (2026-09-18): whatever gets built has to be looked at on
 * other screens, **especially phones** — the panel has already shipped two bugs
 * that existed only at 390px, and the guest pages are opened on a quay far more
 * often than on a desk.
 *
 * Usage, with the local servers running:
 *
 *   node tools/screens.mjs http://127.0.0.1:8000/b/<token>
 *   node tools/screens.mjs booking=http://127.0.0.1:8000/b/<token>,checkout=…
 *
 * Options through the environment:
 *   SHOT_DIR   where the PNGs go (default: storage/app/screens)
 *   SHOT_ONLY  a subset of sizes, e.g. `phone` or `phone,desktop`
 *   SHOT_LOGIN `email:password`, for panel screens — each context signs in at
 *              /app/login first (or SHOT_LOGIN_URL for /admin)
 *
 * Phone shots are full-page, so a column that grows past the fold is caught in
 * one image; the wider ones are viewport-sized, which is what a first look at a
 * desktop layout actually is.
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const OUT = process.env.SHOT_DIR ?? resolve('storage/app/screens');
mkdirSync(OUT, { recursive: true });

const args = process.argv.slice(2);

if (args.length === 0) {
    console.error('Give me at least one URL, or name=URL pairs.');
    process.exit(1);
}

/** `name=url` pairs, or bare URLs named after their last path segment. */
const pages = args
    .flatMap((argument) => argument.split(','))
    .filter(Boolean)
    .map((entry) => {
        const at = entry.indexOf('=');

        if (at === -1) {
            const url = new URL(entry);
            const name = url.pathname.split('/').filter(Boolean)[0] ?? 'page';

            return [name, entry];
        }

        return [entry.slice(0, at), entry.slice(at + 1)];
    });

const sizes = [
    ['phone', { ...devices['iPhone 13'].viewport }, true],
    ['tablet', { width: 768, height: 1024 }, false],
    ['desktop', { width: 1280, height: 900 }, false],
].filter(([label]) => !process.env.SHOT_ONLY || process.env.SHOT_ONLY.split(',').includes(label));

const browser = await chromium.launch();

/*
 * One context for every shot, resized between them.
 *
 * Two earlier shapes both failed: a context per size signed in three times and
 * tripped the panel's login throttle, and a shared `storageState` copied from a
 * sign-in context did not carry the session. One context keeps one session and
 * photographs each size by changing the viewport, which is also what a person
 * dragging a window does.
 */
const context = await browser.newContext({ locale: 'el-GR' });
const page = await context.newPage();

if (process.env.SHOT_LOGIN) {
    const [email, password] = process.env.SHOT_LOGIN.split(':');
    const loginUrl = process.env.SHOT_LOGIN_URL ?? new URL('/app/login', pages[0][1]).toString();

    await page.goto(loginUrl, { waitUntil: 'networkidle', timeout: 30_000 });
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => ! url.pathname.endsWith('/login'), { timeout: 30_000 })
        .catch(() => console.warn('sign-in did not leave the login page'));
}

for (const [name, url] of pages) {
    for (const [label, viewport, fullPage] of sizes) {
        await page.setViewportSize(viewport);

        try {
            await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });
        } catch (error) {
            // A page that never goes idle — a map tile server, a font — is
            // still worth photographing; a page that refuses to load is not.
            console.warn(`${name} ${label}: ${error.message.split('\n')[0]}`);
        }

        // Fonts, map tiles and Livewire's first paint settle a beat after the
        // network does; a form redrawn a moment later is the usual reason a
        // panel screenshot looks half-built.
        await page.waitForTimeout(900);

        // Walk the page before photographing it, so anything `loading="lazy"`
        // — the meeting-point map, most images — has been asked for. A
        // full-page screenshot does not scroll, so without this the bottom of
        // a long page is photographed with its iframes still empty.
        await page.evaluate(async () => {
            const step = window.innerHeight;

            for (let y = 0; y < document.body.scrollHeight; y += step) {
                window.scrollTo(0, y);
                await new Promise((resolve) => setTimeout(resolve, 120));
            }

            window.scrollTo(0, 0);
        });

        await page.waitForTimeout(700);
        await page.screenshot({ path: `${OUT}/${name}-${label}.png`, fullPage });

        console.log(`${OUT}/${name}-${label}.png`);
    }
}

await context.close();
await browser.close();
