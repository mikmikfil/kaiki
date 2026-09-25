/**
 * Walk Kaiki as a phone on the Wi-Fi address, and say what broke.
 *
 * Every other automated browser check opens Kaiki on 127.0.0.1 — and a
 * browser treats loopback as a **secure context**, so a whole class of bug is
 * invisible to them. On 2026-09-11 every booking started from the hosted trip
 * page broke on the LAN: `crypto.randomUUID` does not exist on
 * `http://192.168.x.x`, the widget called it, and the demo page on 127.0.0.1
 * went on working. `navigator.mediaDevices` (the boarding camera) is missing
 * there too. A guest's phone, and Mike's, reach Kaiki exactly that way.
 *
 * So this opens every page with a phone profile (390×844, touch, an iPhone's
 * user agent, el-GR) over the **non-loopback** host, and reports per page:
 *
 *   - the page's own HTTP status, when it is 400 or worse;
 *   - uncaught JS errors (`pageerror`) and `console.error` lines;
 *   - failed sub-requests (network failures, and responses of 400 or worse);
 *   - horizontal overflow — the document wider than the screen — and the
 *     outermost elements that poke past the right edge.
 *
 * Guest side (the hosted operator pages, on `KAIKI_HOSTED_HOST`): the home
 * page, search (plain and with a party), contact, legal, every trip linked
 * from the home page, and the booking widget walked through date → party →
 * extras up to the last button before checkout, which is **never pressed**.
 *
 * Operator side (`APP_URL`, on the LAN host): every GET page `/app` registers
 * — from `php artisan route:list` — as owner, manager and crew, plus one record
 * of each `{record}` route, found by following the first matching link on that
 * resource's list.
 *
 * ## It never writes
 *
 * Every non-GET request is answered by the script itself and never reaches
 * the server — the one exception is Livewire's own `/livewire/update` in the
 * panel (sign-in, polls and lazy widgets; nothing on a page is clicked there).
 * The widget's price quote (`POST /api/v1/price-quote`, which holds nothing)
 * is let through; its analytics beacon gets a fake 204; anything else that tries to
 * write is reported as a finding (`blocked write`), because a page that writes
 * just by being looked at is itself worth knowing about.
 *
 * Usage, with the dev servers running on 0.0.0.0:
 *
 *   node tools/phone-sweep.mjs                    # both sides
 *   node tools/phone-sweep.mjs --only=guest       # or --only=panel
 *   node tools/phone-sweep.mjs --json             # also write storage/app/phone-sweep.json
 *   node tools/phone-sweep.mjs --json=out.json
 *   node tools/phone-sweep.mjs --panel=http://192.168.1.43:8000 --hosted=http://192.168.1.43:8001
 *
 * Environment: PHONE_SWEEP_USERS=`email:password,…` replaces the three demo
 * accounts; PHP_BIN points at a PHP 8.4 (default C:/Users/Mike/php84/php.exe
 * when it exists, else `php`); PHONE_SWEEP_OPERATOR the hosted slug (default:
 * the first operator the home page `/` of the hosted host links to, else
 * `aegean-blue`).
 *
 * Exit code: 0 when nothing was found, 1 on any finding (noise excluded),
 * 2 when the sweep itself could not run.
 */
import { chromium, devices } from 'playwright';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { networkInterfaces } from 'node:os';

// ---------------------------------------------------------------- settings

const flags = Object.fromEntries(
    process.argv.slice(2).map((argument) => {
        const [key, ...rest] = argument.replace(/^--/, '').split('=');

        return [key, rest.length ? rest.join('=') : true];
    }),
);

function envFile() {
    try {
        return Object.fromEntries(
            readFileSync(resolve('.env'), 'utf8')
                .split(/\r?\n/)
                .map((line) => line.match(/^\s*([A-Z0-9_]+)\s*=\s*"?([^"#\r\n]*)"?/))
                .filter(Boolean)
                .map((match) => [match[1], match[2].trim()]),
        );
    } catch {
        return {};
    }
}

const env = envFile();

/** The first non-loopback IPv4 address of this machine: what a phone would type. */
function lanAddress() {
    for (const addresses of Object.values(networkInterfaces())) {
        for (const address of addresses ?? []) {
            if (address.family === 'IPv4' && !address.internal && address.address.startsWith('192.168.')) {
                return address.address;
            }
        }
    }

    return null;
}

function isLoopback(host) {
    return /^(127\.|localhost$|\[?::1\]?$)/.test(host);
}

const hostedAuthority = env.KAIKI_HOSTED_HOST ?? `${lanAddress()}:8001`;
const HOSTED = String(flags.hosted ?? `http://${hostedAuthority}`).replace(/\/$/, '');

const panelFromEnv = (() => {
    const url = new URL(env.APP_URL ?? 'http://127.0.0.1:8000');

    if (isLoopback(url.hostname)) {
        url.hostname = new URL(HOSTED).hostname; // the same machine, over the Wi-Fi
    }

    return url.origin;
})();
const PANEL = String(flags.panel ?? panelFromEnv).replace(/\/$/, '');

const ONLY = typeof flags.only === 'string' ? flags.only : null;
const JSON_OUT = flags.json === true ? resolve('storage/app/phone-sweep.json') : flags.json ? resolve(flags.json) : null;
const MAX_OFFENDERS = 5;

const USERS = (process.env.PHONE_SWEEP_USERS
    ?? 'maria@aegean-blue.example:password,giorgos@aegean-blue.example:password,nikos@aegean-blue.example:password')
    .split(',')
    .filter(Boolean)
    .map((pair) => {
        const at = pair.lastIndexOf(':');

        return { email: pair.slice(0, at), password: pair.slice(at + 1) };
    });

for (const [label, base] of [['panel', PANEL], ['hosted', HOSTED]]) {
    if (isLoopback(new URL(base).hostname)) {
        console.error(`The ${label} host ${base} is loopback — the whole point is the Wi-Fi address. Pass --${label}=http://<lan-ip>:port.`);
        process.exit(2);
    }
}

// ---------------------------------------------------------------- the phone

const phone = {
    ...devices['iPhone 13'],
    viewport: { width: 390, height: 844 },
    screen: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
};

// ---------------------------------------------------------------- findings

/** @type {Array<{side: string, who: string, page: string, url: string, status: number|null, kind: string, detail: string, noise: boolean}>} */
const findings = [];
/** @type {Array<{side: string, who: string, page: string, url: string, status: number|null, ms: number}>} */
const visited = [];
const contextNotes = new Map();

/**
 * Lines that are about the harness or the outside world, not about Kaiki.
 * Reported under «noise» so they stay visible without failing the run.
 */
function isNoise(kind, detail, url = '') {
    if (/\/favicon\.ico(\?|$)/.test(url)) return true;
    // A navigation away (or a page closing) cancels whatever was in flight.
    if (kind === 'request failed' && /net::ERR_ABORTED|NS_BINDING_ABORTED/.test(detail)) return true;
    // Third-party hosts: maps, fonts, social embeds. Not Kaiki's to fix here.
    try {
        // Only named hosts (maps, fonts, social embeds) are the outside world;
        // an IP literal or `[::]` is this machine — a Vite dev URL leaking
        // into a page is exactly the LAN bug this exists to catch.
        const { host, hostname } = new URL(url);
        const named = /[a-z]/i.test(hostname) && !hostname.startsWith('[') && hostname !== 'localhost';
        if (named && host !== new URL(PANEL).host && host !== new URL(HOSTED).host) return true;
    } catch {
        // not a URL: fall through
    }

    return false;
}

function record(side, who, page, url, status, kind, detail, subject = '') {
    findings.push({ side, who, page, url, status, kind, detail, noise: isNoise(kind, detail, subject) });
}

// ---------------------------------------------------------------- watching a page

/**
 * Attach listeners to a page; returns a function that drains what they saw
 * since the last drain, so each visit gets its own lines.
 */
function watch(page, side) {
    let seen = [];

    page.on('pageerror', (error) => seen.push(['uncaught JS error', `${error.name}: ${error.message}`, '']));
    page.on('console', (message) => {
        if (message.type() !== 'error') return;
        const at = message.location()?.url ?? '';
        seen.push(['console error', message.text(), at]);
    });
    page.on('requestfailed', (request) => {
        const failure = request.failure()?.errorText ?? 'failed';
        if (failure === 'blocked-by-sweep') return;
        seen.push(['request failed', `${request.method()} ${request.url()} — ${failure}`, request.url()]);
    });
    page.on('response', (response) => {
        const request = response.request();
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) return; // the page's own status is reported separately
        if (response.status() >= 400) {
            seen.push(['sub-request ≥ 400', `${response.status()} ${request.method()} ${response.url()}`, response.url()]);
        }
    });

    return () => {
        const out = seen;
        seen = [];

        return out;
    };
}

/**
 * Answer every write in the browser, never on the server.
 *
 * In the panel, Livewire's update endpoint is let through: without it nobody
 * can sign in and no lazy widget draws, and nothing on a page is clicked.
 */
async function guardWrites(context, side, onBlocked) {
    await context.route('**/*', async (route) => {
        const request = route.request();
        const method = request.method();

        if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
            return route.continue();
        }

        const url = new URL(request.url());

        if (side === 'panel' && url.pathname.endsWith('/livewire/update')) {
            return route.continue();
        }

        // A POST that only reads: the price of a party, which by its own
        // docblock holds no seat and creates nothing.
        if (/\/api\/v\d+\/price-quote$/.test(url.pathname)) {
            return route.continue();
        }

        if (/\/api\/v\d+\/events$/.test(url.pathname)) {
            // The widget's analytics beacon: answered as the server would, and
            // not counted — browsing is supposed to emit these.
            return route.fulfill({ status: 204, body: '' });
        }

        onBlocked(`${method} ${request.url()}`);

        return route.fulfill({ status: 409, contentType: 'application/json', body: '{"message":"blocked by phone-sweep"}' });
    });
}

// ---------------------------------------------------------------- measuring a page

/** Everything the page says about itself: overflow, and the secure-context facts. */
async function inspect(page) {
    return page.evaluate((maxOffenders) => {
        const width = window.innerWidth;
        const root = document.documentElement;
        const offenders = [];

        const describe = (element) => {
            let label = element.tagName.toLowerCase();
            if (element.id) label += `#${element.id}`;
            const classes = typeof element.className === 'string' ? element.className.trim().split(/\s+/).filter(Boolean).slice(0, 3) : [];
            if (classes.length) label += `.${classes.join('.')}`;
            const text = (element.innerText ?? element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40);

            return text ? `${label} «${text}»` : label;
        };

        /**
         * Is this element's overflow somebody else's business? An ancestor that
         * scrolls or clips sideways (a wide table in an `overflow-x: auto`
         * box) contains it; a `position: fixed` layer entirely off-screen is a
         * closed drawer — Filament's sidebar on a phone — not overflow.
         */
        const contained = (element) => {
            for (let node = element.parentElement ?? element.getRootNode()?.host; node && node !== root && node !== document.body;
                node = node.parentElement ?? node.getRootNode()?.host) {
                const style = getComputedStyle(node);
                if (['auto', 'scroll', 'hidden', 'clip'].includes(style.overflowX)) return true;
                if (style.position === 'fixed') {
                    const box = node.getBoundingClientRect();
                    if (box.left >= width - 1 || box.right <= 1) return true;
                }
            }

            return false;
        };

        const visible = (element, box) => {
            if (box.width === 0 || box.height === 0) return false;
            const style = getComputedStyle(element);

            return style.visibility !== 'hidden' && style.display !== 'none' && Number(style.opacity) !== 0;
        };

        const all = [];
        const collect = (scope) => {
            for (const element of scope.querySelectorAll('*')) {
                all.push(element);
                if (element.shadowRoot) collect(element.shadowRoot);
            }
        };
        collect(document);

        const poking = new Set();
        for (const element of all) {
            const box = element.getBoundingClientRect();
            if (box.right <= width + 1 || !visible(element, box)) continue;
            const style = getComputedStyle(element);
            if (style.position === 'fixed' && (box.left >= width - 1)) continue; // an off-screen drawer
            if (contained(element)) continue;
            poking.add(element);
        }

        // Only the outermost: a card that is too wide makes every child too wide.
        for (const element of poking) {
            const parent = element.parentElement ?? element.getRootNode()?.host;
            if (parent && poking.has(parent)) continue;
            if (offenders.length < maxOffenders) {
                offenders.push(`${describe(element)} → right ${Math.round(element.getBoundingClientRect().right)}px`);
            }
        }

        return {
            scrollWidth: Math.max(root.scrollWidth, document.body?.scrollWidth ?? 0),
            innerWidth: width,
            offenders,
            offenderCount: [...poking].filter((element) => !poking.has(element.parentElement ?? element.getRootNode()?.host)).length,
            secure: window.isSecureContext,
            randomUUID: typeof globalThis.crypto?.randomUUID === 'function',
            mediaDevices: typeof navigator.mediaDevices?.getUserMedia === 'function',
        };
    }, MAX_OFFENDERS);
}

async function settle(page) {
    await page.waitForLoadState('networkidle', { timeout: 6_000 }).catch(() => {});
    await page.waitForTimeout(500);
}

/** Open one URL, and turn whatever it did into findings. */
async function visit(page, drain, side, who, name, url) {
    const started = Date.now();
    let status = null;

    try {
        const response = await page.goto(url, { waitUntil: 'load', timeout: 30_000 });
        status = response?.status() ?? null;
    } catch (error) {
        record(side, who, name, url, null, 'navigation failed', error.message.split('\n')[0]);
    }

    await settle(page);

    if (status !== null && status >= 400) {
        record(side, who, name, url, status, 'HTTP status', `${status} for ${url}`);
    }

    // A panel page that lands on the sign-in form means the session is gone,
    // and every line after it would be about the login page.
    const landed = page.url();

    try {
        const facts = await inspect(page);

        if (facts.scrollWidth > facts.innerWidth + 1) {
            record(side, who, name, url, status, 'horizontal overflow',
                `document ${facts.scrollWidth}px wide on a ${facts.innerWidth}px screen`);
        }
        for (const offender of facts.offenders) {
            record(side, who, name, url, status, 'element past the edge', offender);
        }
        if (facts.offenderCount > facts.offenders.length) {
            record(side, who, name, url, status, 'element past the edge', `…and ${facts.offenderCount - facts.offenders.length} more`);
        }

        const origin = new URL(landed).origin;
        if (!contextNotes.has(origin)) {
            contextNotes.set(origin, facts);
        }
    } catch (error) {
        record(side, who, name, url, status, 'inspect failed', error.message.split('\n')[0]);
    }

    for (const [kind, detail, subject] of drain()) {
        // Chrome repeats the document's own status as a console line; the
        // status is already reported above.
        if (kind === 'console error' && subject === landed && /status of \d{3}/.test(detail)) continue;
        record(side, who, name, url, status, kind, detail, subject);
    }

    visited.push({ side, who, page: name, url, landed, status, ms: Date.now() - started });

    return { status, landed };
}

// ---------------------------------------------------------------- the guest side

async function guestSweep(browser) {
    const context = await browser.newContext(phone);
    const page = await context.newPage();
    const drain = watch(page, 'guest');
    let current = { name: '', url: '' };

    await guardWrites(context, 'guest', (what) =>
        record('guest', 'guest', current.name, current.url, null, 'blocked write', what));

    const operator = process.env.PHONE_SWEEP_OPERATOR ?? 'aegean-blue';
    const base = `${HOSTED}/${operator}`;
    const pages = [
        ['home', base],
        ['search', `${base}/search`],
        ['search with a party', `${base}/search?date=${inDays(3)}&adults=2&children=1`],
        ['contact', `${base}/contact`],
        ['legal', `${base}/legal`],
        ['unknown trip (404 expected)', `${base}/no-such-trip-${Date.now()}`],
    ];

    for (const [name, url] of pages) {
        current = { name, url };
        const { status } = await visit(page, drain, 'guest', 'guest', name, url);

        if (name.startsWith('unknown trip')) {
            // A 404 here is the right answer, not a finding.
            if (status === 404) {
                // …and so is the console line Chrome writes for any 404 document.
                removeWhere((f) => f.url === url && (f.kind === 'HTTP status'
                    || (f.kind === 'console error' && /status of 404/.test(f.detail))));
            }
        }
    }

    // Every trip the home page and search link to.
    await page.goto(`${base}/search`, { waitUntil: 'load' }).catch(() => {});
    const home = await page.locator('a[href]').evaluateAll((anchors, prefix) => anchors
        .map((a) => a.href.split('#')[0].replace(/\?.*$/, ''))
        .filter((href) => href.startsWith(prefix + '/') && !/\/(search|contact|legal)$/.test(href)),
    `${base}`).catch(() => []);
    drain();
    const trips = [...new Set(home)];

    for (const url of trips) {
        const name = `trip ${url.split('/').pop()}`;
        current = { name, url };
        await visit(page, drain, 'guest', 'guest', name, url);
    }

    if (trips.length === 0) {
        record('guest', 'guest', 'trips', base, null, 'no trips found', 'neither the home page nor search linked to a trip');
    }

    // The booking widget, up to the checkout button and not one tap further.
    for (const url of trips.slice(0, 3)) {
        const outcome = await walkWidget(page, drain, url, (name) => { current = { name, url }; });
        if (outcome.reachedLastStep) break;
    }

    await context.close();

    return trips.length;
}

function inDays(n) {
    const date = new Date(Date.now() + n * 86_400_000);

    return date.toISOString().slice(0, 10);
}

function removeWhere(predicate) {
    for (let i = findings.length - 1; i >= 0; i--) {
        if (predicate(findings[i])) findings.splice(i, 1);
    }
}

/** @type {Array<{trip: string, steps: string[], reachedLastStep: boolean, stoppedBecause: string}>} */
const widgetWalks = [];

async function walkWidget(page, drain, url, setCurrent) {
    const name = `booking widget ${url.split('/').pop()}`;
    const walk = { trip: url, steps: [], reachedLastStep: false, stoppedBecause: '' };
    widgetWalks.push(walk);
    setCurrent(name);

    await page.goto(url, { waitUntil: 'load', timeout: 30_000 }).catch(() => {});
    await settle(page);
    drain(); // the page itself was already swept above; only the walk counts here

    const widget = page.locator('[data-kaiki-widget]').first();

    const note = async (step) => {
        walk.steps.push(step);
        await page.waitForTimeout(400);
        const facts = await inspect(page).catch(() => null);
        if (facts && facts.scrollWidth > facts.innerWidth + 1) {
            record('guest', 'guest', `${name} · ${step}`, url, null, 'horizontal overflow',
                `document ${facts.scrollWidth}px wide on a ${facts.innerWidth}px screen`);
        }
        for (const offender of facts?.offenders ?? []) {
            record('guest', 'guest', `${name} · ${step}`, url, null, 'element past the edge', offender);
        }
        for (const [kind, detail, subject] of drain()) {
            record('guest', 'guest', `${name} · ${step}`, url, null, kind, detail, subject);
        }
    };

    if (await widget.count() === 0) {
        walk.stoppedBecause = 'no widget on the page';

        return walk;
    }

    // On a phone the widget is a sheet behind a peek bar.
    const peek = page.locator('.kaiki-peek').first();
    if (await peek.isVisible().catch(() => false)) {
        await peek.tap();
        await note('sheet opened');
    }

    // The first bookable day, up to four months ahead.
    let picked = false;
    for (let month = 0; month < 4 && !picked; month++) {
        await page.locator('.kaiki-days').first().waitFor({ timeout: 8_000 }).catch(() => {});
        const day = page.locator('.kaiki-day-pick').first();
        if (await day.count()) {
            const label = await day.getAttribute('aria-label');
            await day.tap();
            await note(`day picked (${label})`);
            picked = true;
        } else {
            const next = page.locator('.kaiki-calendar-step').nth(1);
            if (!(await next.count())) break;
            await next.tap();
            await page.waitForTimeout(600);
        }
    }

    if (!picked) {
        walk.stoppedBecause = 'no bookable day in four months';

        return walk;
    }

    const time = page.locator('.kaiki-time input').first();
    if (await time.count() && !(await time.isChecked())) {
        await time.check({ force: true });
        await note('departure chosen');
    }

    // «Συνέχεια» until the button becomes «Συνέχεια στην κράτηση» — that one
    // creates the hold, and is never pressed.
    for (let guard = 0; guard < 5; guard++) {
        const button = page.locator('.kaiki-actions .kaiki-button:not(.kaiki-button-ghost)').first();
        await button.waitFor({ timeout: 5_000 }).catch(() => {});
        const text = ((await button.textContent().catch(() => '')) ?? '').trim();

        if (text === '' ) {
            walk.stoppedBecause = 'no forward button';
            break;
        }

        // On the party step, one more adult if nobody is counted yet.
        const up = page.locator('.kaiki-step-up').first();
        if (!(await button.isEnabled()) && await up.count()) {
            await up.tap();
            await note('guest added');
        }

        if (text !== 'Συνέχεια' && text !== 'Next' && text !== 'Continue') {
            walk.reachedLastStep = true;
            walk.stoppedBecause = `stopped before «${text}» (creates the hold)`;
            const ready = await button.isEnabled();
            await note(`last step, «${text}» ${ready ? 'enabled' : 'disabled'}`);
            if (!ready) {
                record('guest', 'guest', name, url, null, 'widget stuck', `«${text}» stayed disabled on the last step, with a guest added`);
            }
            break;
        }

        if (!(await button.isEnabled())) {
            walk.stoppedBecause = `«${text}» stayed disabled`;
            record('guest', 'guest', name, url, null, 'widget stuck', `«${text}» stayed disabled after ${walk.steps.at(-1)}`);
            break;
        }

        await button.tap();
        await note('continue');
    }

    return walk;
}

// ---------------------------------------------------------------- the operator side

function phpBinary() {
    if (process.env.PHP_BIN) return process.env.PHP_BIN;
    if (existsSync('C:/Users/Mike/php84/php.exe')) return 'C:/Users/Mike/php84/php.exe';

    return 'php';
}

/** GET screens of /app, straight from the router, so a new page is swept the day it lands. */
function panelRoutes() {
    const json = execFileSync(phpBinary(), ['artisan', 'route:list', '--json', '--name=filament.app', '--method=GET'], {
        encoding: 'utf8',
        maxBuffer: 32 * 1024 * 1024,
    });

    return JSON.parse(json)
        .filter((route) => /GET/.test(route.method))
        .filter((route) => !/\.(auth\.|logout|login|password-reset|email-verification|tenant-registration)/.test(route.name ?? ''))
        .filter((route) => !/\.(sw|download)$/.test(route.name ?? '')) // a service worker and a file, not screens
        .map((route) => ({ name: route.name, uri: route.uri }));
}

async function signIn(page, drain, user) {
    await page.goto(`${PANEL}/app/login`, { waitUntil: 'load', timeout: 30_000 });
    await page.fill('input[type="email"]', user.email);
    await page.fill('input[type="password"]', user.password);
    await page.click('button[type="submit"]');
    const ok = await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20_000 }).then(() => true, () => false);
    drain();

    return ok;
}

/** Same-origin panel paths each person's own screens link to, for telling a refusal from a dead link. */
const linkedFromUi = new Map();
/** 403s on screens nobody's UI pointed them to: the role's decision, not a finding. */
const refused = [];

async function panelSweep(browser) {
    const routes = panelRoutes();
    const plain = routes.filter((route) => !route.uri.includes('{'));
    const withRecord = routes.filter((route) => /\{record\}/.test(route.uri) && route.uri.match(/\{/g).length === 1);
    const skipped = routes.filter((route) => route.uri.includes('{') && !withRecord.includes(route));

    // One context per person, all at once: each signs in exactly once (the
    // login throttle), and the three walks overlap instead of queueing.
    await Promise.all(USERS.map((user) => panelSweepAs(browser, user, plain, withRecord)));

    // A 403 is a finding only when the person's own screens led there — the
    // crew «forbidden» of 2026-09-23 was exactly that. A 403 on a route
    // nothing linked them to is their role doing its job.
    removeWhere((finding) => {
        if (finding.side !== 'panel' || finding.kind !== 'HTTP status' || finding.status !== 403) return false;
        const linked = linkedFromUi.get(finding.who)?.has(new URL(finding.url).pathname) ?? false;
        if (linked) {
            finding.detail = `403 for ${finding.url} — and this person's own screens link to it`;

            return false;
        }
        refused.push(`${finding.who}:${finding.page}`);

        return true;
    });

    return { plain: plain.length, withRecord: withRecord.length, skipped: skipped.map((route) => route.uri) };
}

async function panelSweepAs(browser, user, plain, withRecord) {
    const who = user.email.split('@')[0];
    const context = await browser.newContext(phone);
    const page = await context.newPage();
    const drain = watch(page, 'panel');
    let current = { name: '', url: '' };
    const linked = new Set();
    linkedFromUi.set(who, linked);

    await guardWrites(context, 'panel', (what) =>
        record('panel', who, current.name, current.url, null, 'blocked write', what));

    if (!(await signIn(page, drain, user))) {
        record('panel', who, 'login', `${PANEL}/app/login`, null, 'sign-in failed', `${user.email} could not sign in`);
        await context.close();

        return;
    }

    const links = new Map();
    const harvest = async (key) => {
        const hrefs = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((a) => a.href)).catch(() => []);
        links.set(key, hrefs);
        for (const href of hrefs) {
            try {
                const url = new URL(href);
                if (url.origin === PANEL) linked.add(url.pathname);
            } catch {
                // mailto:, tel: and the like
            }
        }
    };

    for (const route of plain) {
        const url = `${PANEL}/${route.uri}`;
        current = { name: route.name, url };
        const { status, landed } = await visit(page, drain, 'panel', who, shortName(route.name), url);

        if (new URL(landed).pathname.endsWith('/login')) {
            record('panel', who, shortName(route.name), url, status, 'signed out', `landed on ${landed}`);
            await signIn(page, drain, user);
        }

        if (status === 200) {
            await harvest(route.uri);
        }
    }

    for (const route of withRecord) {
        const pattern = new RegExp(`^${escapeRegex(`${PANEL}/${route.uri}`).replace('\\{record\\}', '([^/?#]+)')}$`);
        const index = route.uri.replace(/\/\{record\}.*$/, '');
        const found = [...(links.get(index) ?? []), ...[...links.values()].flat()]
            .map((href) => href.split('#')[0].replace(/\?.*$/, ''))
            .find((href) => pattern.test(href));

        if (!found) {
            // Not a finding: crew are refused most lists, and an empty list has no rows.
            visited.push({ side: 'panel', who, page: shortName(route.name), url: `${PANEL}/${route.uri}`, status: 'no record link', ms: 0 });
            continue;
        }

        current = { name: route.name, url: found };
        const { status } = await visit(page, drain, 'panel', who, shortName(route.name), found);
        if (status === 200) await harvest(found);
    }

    await context.close();
}

function shortName(routeName) {
    return routeName.replace(/^filament\.app\./, '').replace(/^(pages|resources)\./, '');
}

function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ---------------------------------------------------------------- run

const started = Date.now();
const browser = await chromium.launch();
let summary = {};

try {
    // The two sides are two servers, so they are walked side by side.
    const [trips, panel] = await Promise.all([
        ONLY !== 'panel' ? guestSweep(browser) : null,
        ONLY !== 'guest' ? panelSweep(browser) : null,
    ]);
    summary = { trips, panel };
} catch (error) {
    console.error(`The sweep itself failed: ${error.stack ?? error}`);
    await browser.close();
    process.exit(2);
}

await browser.close();

// ---------------------------------------------------------------- report

const real = findings.filter((finding) => !finding.noise);
const noise = findings.filter((finding) => finding.noise);
const seconds = ((Date.now() - started) / 1000).toFixed(1);

function table(rows) {
    if (rows.length === 0) return '  (none)';
    const columns = ['side', 'who', 'page', 'kind', 'detail'];
    const clip = (value, n) => { const text = String(value ?? ''); return text.length > n ? `${text.slice(0, n - 1)}…` : text; };
    const widths = { side: 5, who: 8, page: 34, kind: 22, detail: 140 };
    const line = (row) => columns.map((column) => clip(row[column], widths[column]).padEnd(widths[column])).join('  ').trimEnd();

    return [line(Object.fromEntries(columns.map((c) => [c, c.toUpperCase()]))), line(Object.fromEntries(columns.map((c) => [c, '-'.repeat(widths[c])]))), ...rows.map(line)]
        .map((text) => `  ${text}`)
        .join('\n');
}

console.log(`\nPhone sweep — ${visited.length} page visits in ${seconds}s, over ${HOSTED} (guest) and ${PANEL} (panel)`);
for (const [origin, facts] of contextNotes) {
    console.log(`  ${origin}: isSecureContext=${facts.secure}, crypto.randomUUID ${facts.randomUUID ? 'present' : 'MISSING'}, mediaDevices.getUserMedia ${facts.mediaDevices ? 'present' : 'MISSING'}`);
}
for (const walk of widgetWalks) {
    console.log(`  widget on ${walk.trip.split('/').pop()}: ${walk.steps.join(' → ') || '(nothing)'}; ${walk.stoppedBecause}`);
}
if (summary.panel?.skipped?.length) {
    console.log(`  panel routes not opened (parameters other than {record}): ${summary.panel.skipped.join(', ')}`);
}
const noRecord = visited.filter((visit) => visit.status === 'no record link');
if (refused.length) {
    console.log(`  refused, and not linked from that person's screens (a role decision, not a finding): ${refused.join(', ')}`);
}
if (noRecord.length) {
    console.log(`  {record} pages with no link to follow: ${noRecord.map((visit) => `${visit.who}:${visit.page}`).join(', ')}`);
}

console.log(`\nFindings (${real.length}):\n${table(real)}`);
console.log(`\nNoise — third-party hosts, favicons, cancelled requests (${noise.length}):\n${table(noise)}`);

if (JSON_OUT) {
    mkdirSync(dirname(JSON_OUT), { recursive: true });
    writeFileSync(JSON_OUT, JSON.stringify({
        ranAt: new Date().toISOString(),
        seconds: Number(seconds),
        hosted: HOSTED,
        panel: PANEL,
        contexts: Object.fromEntries(contextNotes),
        widgetWalks,
        visited,
        findings: real,
        noise,
        skippedRoutes: summary.panel?.skipped ?? [],
        refusedByRole: refused,
    }, null, 2));
    console.log(`\nJSON: ${JSON_OUT}`);
}

process.exit(real.length > 0 ? 1 : 0);
