/**
 * The demo operator's photographs, drawn rather than sourced.
 *
 * ## Why the pictures are code
 *
 * Every trip on the demo hosted page needs a picture — a row of text cards is a
 * list, a row of pictures is a shop. The obvious way to get sixteen of those is
 * to download sixteen photographs of somebody else's boat, and that is the one
 * option not open to us: seed data ships in the repository, gets deployed to a
 * demo people are shown, and a stock photograph with no licence attached is a
 * liability sitting in `database/`.
 *
 * So they are illustrations, generated from this file. They read as drawings,
 * which is the honest thing for demo data to look like — nobody mistakes one
 * for the operator's own photograph — and each is a few hundred bytes of SVG
 * rather than an unreviewable binary somebody added once and nobody can change.
 *
 * ## Why they are still JPEGs on disk
 *
 * The panel accepts `image/jpeg`, `image/png` and `image/webp`, and deliberately
 * not SVG — an uploaded SVG is a script. Seeding a format no operator could
 * upload would put demo data outside the rules the product enforces, so the SVG
 * is the source and Chromium prints it to the same 1800×1000 JPEG a camera
 * would produce.
 *
 * ## Running it
 *
 *     node database/seeders/assets/generate.mjs
 *
 * Output lands in `assets/images/` and is committed — the seeder copies from
 * there, so `migrate:fresh --seed` on a clean checkout gets the pictures.
 * Re-running is deterministic: every random-looking number comes from a hash of
 * the scene's own name, so editing one scene does not rewrite the other fifteen.
 */
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, 'images');
const W = 1800;
const H = 1000;

/* ------------------------------------------------------------------ palette */

/**
 * Five times of day. `horizon` is where the sea starts as a fraction of the
 * height: a low sun wants a high horizon so there is sky for it to sit in.
 */
const LIGHT = {
    dawn: {
        sky: ['#1E2C46', '#7A5C7A', '#E9A277'], sea: ['#2A4A5C', '#12303C'],
        disc: '#FFD9A8', discAt: [0.70, 0.505], glow: 300, horizon: 0.56,
        glitter: '#FFD9A8', stars: 0.25,
    },
    morning: {
        sky: ['#A9D8E6', '#D8EEF3', '#F3FAFB'], sea: ['#2E9AA2', '#0E5A5E'],
        disc: '#FFFCEF', discAt: [0.74, 0.22], glow: 260, horizon: 0.52,
        glitter: '#FFFFFF', stars: 0,
    },
    day: {
        sky: ['#5FB6D4', '#A9DCEA', '#E4F5F8'], sea: ['#1C8794', '#0A4C52'],
        disc: '#FFFDF2', discAt: [0.78, 0.14], glow: 220, horizon: 0.50,
        glitter: '#FFFFFF', stars: 0,
    },
    golden: {
        sky: ['#3E4E7A', '#C97A4A', '#F3B96E'], sea: ['#1A5566', '#0B3A44'],
        disc: '#FFE0A8', discAt: [0.68, 0.44], glow: 330, horizon: 0.55,
        glitter: '#FFD79A', stars: 0,
    },
    dusk: {
        sky: ['#101C36', '#33345E', '#7C4E6B'], sea: ['#132A3E', '#08151F'],
        disc: '#EFE7D6', discAt: [0.24, 0.16], glow: 150, horizon: 0.53,
        glitter: '#D9CFC0', stars: 1,
    },
};

/* -------------------------------------------------------------- determinism */

/** FNV-1a. Any hash would do; this one is four lines and stable across runs. */
const hash = (s) => {
    let h = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 0x01000193) >>> 0;
    }

    return h;
};

/** A deterministic 0..1 stream, seeded by the scene's own name. */
const rng = (seed) => {
    let s = seed >>> 0;

    return () => {
        s = (Math.imul(s, 1664525) + 1013904223) >>> 0;

        return s / 4294967296;
    };
};

/* ------------------------------------------------------------------- pieces */

/**
 * Two ranges of hills: a pale far one and a darker near one, so the horizon has
 * depth rather than being a single cut-out.
 */
const islands = (r, light, count) => {
    const y = H * light.horizon;
    let out = '';

    for (const [layer, opacity, spread] of [[0, 0.20, 1.0], [1, 0.40, 0.68]]) {
        const peaks = count + layer;
        const step = (W + 120) / peaks;
        let d = `M-60 ${y + 6}`;

        for (let i = 0; i < peaks; i++) {
            const x = -60 + step * i;
            const h = (18 + r() * 62) * spread + (layer === 0 ? 22 : 0);
            d += ` Q ${(x + step * 0.28).toFixed(0)} ${(y - h).toFixed(0)}`;
            d += ` ${(x + step * 0.5).toFixed(0)} ${(y - h * (0.55 + r() * 0.35)).toFixed(0)}`;
            d += ` Q ${(x + step * 0.78).toFixed(0)} ${(y - h * 0.5).toFixed(0)} ${(x + step).toFixed(0)} ${y + 6}`;
        }

        d += ` L ${W + 60} ${y + 8} L -60 ${y + 8} Z`;
        out += `<path d="${d}" fill="#0d2733" opacity="${opacity}"/>`;
    }

    return out;
};

/**
 * The sun's reflection: short dashes widening towards the viewer. It is the one
 * detail that stops a picture made of two gradients looking like two gradients.
 */
const glitter = (r, light) => {
    const cx = light.discAt[0] * W;
    const top = H * light.horizon;
    let out = '';

    for (let i = 0; i < 46; i++) {
        const t = i / 45;
        const y = top + Math.pow(t, 1.6) * (H - top) + 2;
        const spread = 10 + Math.pow(t, 1.4) * 210;
        const len = (8 + r() * 46) * (0.35 + t);
        const x = cx - spread + r() * spread * 2 - len / 2;
        out += `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${len.toFixed(1)}"`
            + ` height="${(1.4 + t * 3).toFixed(1)}" rx="2" fill="${light.glitter}"`
            + ` opacity="${(0.5 - t * 0.34).toFixed(2)}"/>`;
    }

    return out;
};

/**
 * Chop: short broken dashes scattered over the sea, longer and more separated
 * towards the viewer.
 *
 * The first version of this drew seven full-width sine curves, which at 1800
 * pixels wide have an amplitude of a few pixels over a wavelength of three
 * hundred — they render as horizontal scan lines, and the picture looked like a
 * gradient somebody had ruled. Broken strokes carry the same information about
 * distance without ever forming a line across the frame.
 */
const waves = (r, light) => {
    const top = H * light.horizon;
    let out = '';

    for (let i = 0; i < 260; i++) {
        // Squared, so they crowd near the horizon the way perspective does.
        const t = Math.pow(r(), 0.55);
        const y = top + 14 + t * (H - top);
        const len = (14 + r() * 70) * (0.3 + t * 1.3);
        const x = -60 + r() * (W + 120);
        out += `<rect x="${x.toFixed(0)}" y="${y.toFixed(0)}" width="${len.toFixed(0)}"`
            + ` height="${(1.2 + t * 2.6).toFixed(1)}" rx="2" fill="#ffffff"`
            + ` opacity="${(0.05 + t * 0.07).toFixed(3)}"/>`;
    }

    return out;
};

const stars = (r, light) => {
    if (light.stars === 0) {
        return '';
    }

    let out = '';

    for (let i = 0; i < Math.round(90 * light.stars); i++) {
        const x = r() * W;
        const y = r() * H * light.horizon * 0.8;
        out += `<circle cx="${x.toFixed(0)}" cy="${y.toFixed(0)}" r="${(0.8 + r() * 1.5).toFixed(1)}"`
            + ` fill="#fff" opacity="${(0.25 + r() * 0.5).toFixed(2)}"/>`;
    }

    return out;
};

/* -------------------------------------------------------------------- boats */

const HULL = '#0b1e26';

/**
 * Cabin windows. Daylight reflects the sky off them; after dark somebody has
 * the lights on, which is the whole point of a dinner trip and the difference
 * between a boat at anchor and a shape.
 */
const glassFor = (light) => (light.stars > 0 ? '#F7C979' : '#cfe6ea');

/**
 * Drawn around their own waterline at (0, 0), so a scene places one by
 * translating to a point on the sea and scaling. Every one of them is a
 * silhouette: a drawing with detail invites a comparison with a photograph it
 * cannot win.
 */
const boats = {
    caique: (trim, GLASS) => `
        <path d="M-150 0 q 12 -26 46 -34 L 118 -34 q 34 8 46 34 q -30 22 -92 26 L -58 26 q -62 -4 -92 -26 Z" fill="${HULL}"/>
        <rect x="-56" y="-96" width="118" height="62" rx="8" fill="${HULL}"/>
        <rect x="-40" y="-84" width="34" height="26" rx="4" fill="${GLASS}" opacity=".85"/>
        <rect x="4" y="-84" width="34" height="26" rx="4" fill="${GLASS}" opacity=".85"/>
        <path d="M-84 -34 L-84 -128 L62 -108" stroke="${HULL}" stroke-width="5" fill="none"/>
        <path d="M-150 -6 q 150 -16 314 0" stroke="${trim}" stroke-width="7" fill="none" opacity=".9"/>`,
    sailboat: () => `
        <path d="M-118 0 q 118 30 236 0 q -40 30 -118 30 q -78 0 -118 -30 Z" fill="${HULL}"/>
        <path d="M0 -290 L0 -6" stroke="${HULL}" stroke-width="6"/>
        <path d="M12 -282 Q120 -150 104 -14 L12 -14 Z" fill="#ffffff" opacity=".94"/>
        <path d="M-10 -258 Q-96 -150 -86 -14 L-10 -14 Z" fill="#f2f6f7" opacity=".84"/>`,
    catamaran: (trim, GLASS) => `
        <path d="M-176 0 q 40 26 92 26 l0 -26 Z M176 0 q -40 26 -92 26 l0 -26 Z" fill="${HULL}"/>
        <path d="M-176 -16 L176 -16 L176 4 L-176 4 Z" fill="${HULL}"/>
        <rect x="-104" y="-86" width="208" height="72" rx="12" fill="${HULL}"/>
        <rect x="-88" y="-72" width="60" height="30" rx="5" fill="${GLASS}" opacity=".85"/>
        <rect x="-16" y="-72" width="60" height="30" rx="5" fill="${GLASS}" opacity=".85"/>
        <rect x="-104" y="-100" width="208" height="16" rx="6" fill="${trim}" opacity=".9"/>`,
    rib: (trim, GLASS) => `
        <path d="M-140 0 q 24 -30 70 -34 L116 -34 q 26 10 30 34 Z" fill="${HULL}"/>
        <path d="M-140 0 q 140 22 286 -6 l0 10 q -146 28 -286 6 Z" fill="${trim}" opacity=".95"/>
        <path d="M-30 -34 L-14 -78 L62 -78 L74 -34 Z" fill="${HULL}"/>
        <path d="M-8 -70 L56 -70 L62 -46 L-14 -46 Z" fill="${GLASS}" opacity=".8"/>
        <path d="M-300 18 q 90 -18 172 -6" stroke="#ffffff" stroke-opacity=".45" stroke-width="10" fill="none" stroke-linecap="round"/>
        <path d="M-262 36 q 80 -14 150 -4" stroke="#ffffff" stroke-opacity=".28" stroke-width="7" fill="none" stroke-linecap="round"/>`,
    yacht: (trim, GLASS) => `
        <path d="M-186 0 q 20 -34 78 -40 L150 -40 q 36 12 42 40 q -46 26 -138 30 L-66 30 q -100 -4 -120 -30 Z" fill="${HULL}"/>
        <rect x="-92" y="-104" width="186" height="66" rx="10" fill="${HULL}"/>
        <rect x="-74" y="-92" width="150" height="30" rx="6" fill="${GLASS}" opacity=".8"/>
        <rect x="-52" y="-136" width="96" height="34" rx="8" fill="${HULL}"/>
        <path d="M44 -136 L44 -186" stroke="${HULL}" stroke-width="4"/>
        <path d="M-186 -8 q 186 -18 378 0" stroke="${trim}" stroke-width="8" fill="none" opacity=".9"/>`,
};

/** The ring of stillness a boat at anchor sits in. */
const anchored = (x, y, scale) =>
    `<ellipse cx="${x.toFixed(0)}" cy="${(y + 16 * scale).toFixed(0)}" rx="${(230 * scale).toFixed(0)}" ry="${(20 * scale).toFixed(0)}" fill="#000" opacity=".16"/>`;

/* ----------------------------------------------------------------- features */

/**
 * A headland at one edge of the frame.
 *
 * It **stops at the waterline** rather than running down to the bottom of the
 * picture. The first version filled the corner, and because the shape's outer
 * boundary was a straight line from its foot to the frame edge, every headland
 * came out as a sheer vertical slab of rock rising out of the sea — a wall, not
 * a coast. Land that meets the water where the water starts needs no such edge.
 */
const headland = (light, side) => {
    const y = (H * light.horizon).toFixed(0);
    const flip = side === 'right' ? ` transform="translate(${W} 0) scale(-1 1)"` : '';
    const shape = `M-80 ${y} L-80 ${+y - 40}`
        + ` C -10 ${+y - 250} 150 ${+y - 285} 275 ${+y - 205}`
        + ` C 365 ${+y - 148} 430 ${+y - 62} 545 ${y} Z`;

    return `<g${flip}>
      <path d="${shape}" fill="#12292f" opacity=".88"/>
      <path d="M-80 ${+y - 40} C -10 ${+y - 250} 150 ${+y - 285} 275 ${+y - 205}" fill="none" stroke="#4a6f70" stroke-width="5" opacity=".35"/>
      <path d="${shape}" transform="translate(0 ${+y * 2}) scale(1 -0.30)" fill="#0a1e24" opacity=".22"/>
    </g>`;
};

/**
 * A rock arch, for the cave trip. Foreground on the left, so it frames the
 * boat; a headland on the right for the far side of the cove.
 */
const arch = (light) => {
    const y = H * light.horizon;

    // The opening sits around x = 260 rather than against the left edge,
    // because the trip cards crop this to 3:2 from the middle and an arch whose
    // hole is in the first 150 pixels arrives on the home page as a black blob.
    const crown = `M-40 ${(y - 300).toFixed(0)} C 60 ${(y - 510).toFixed(0)} 700 ${(y - 480).toFixed(0)} 820 ${(y - 230).toFixed(0)}`;
    const rock = `M-40 ${H} L${crown.slice(1)}`
        + ` C 840 ${(y - 120).toFixed(0)} 828 ${(y - 40).toFixed(0)} 806 ${H} L580 ${H}`
        + ` C 580 ${(y - 170).toFixed(0)} 520 ${(y - 262).toFixed(0)} 420 ${(y - 266).toFixed(0)}`
        + ` C 310 ${(y - 270).toFixed(0)} 260 ${(y - 175).toFixed(0)} 260 ${H} Z`;

    return `<g>
      <path d="${rock}" fill="#132a30" opacity=".94"/>
      <path d="${crown}" fill="none" stroke="#3d6266" stroke-width="7" opacity=".3"/>
      ${headland(light, 'right')}
    </g>`;
};

/* ------------------------------------------------------------------- scenes */

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

const scene = (name, spec) => {
    const r = rng(hash(name));
    const trim = spec.trim ?? '#B5511F';

    // Five times of day across sixteen pictures means several scenes share a
    // palette, and the first contact sheet had six of them with the sun in the
    // same corner over the same horizon — a wall of one picture. Nudging the
    // sun and the horizon per scene is what makes a shared palette read as the
    // same coast at the same hour rather than as one file copied six times.
    const base = LIGHT[spec.light];
    const light = {
        ...base,
        discAt: spec.sun ?? [
            clamp(base.discAt[0] + (r() - 0.5) * 0.34, 0.14, 0.86),
            clamp(base.discAt[1] + (r() - 0.5) * 0.10, 0.06, 0.56),
        ],
        horizon: clamp(base.horizon + (r() - 0.5) * 0.06, 0.44, 0.60),
    };

    const y = H * light.horizon;
    const cx = (light.discAt[0] * W).toFixed(0);
    const cy = (light.discAt[1] * H).toFixed(0);

    const fleet = (spec.boats ?? []).map((b) => {
        const bx = b.x * W;
        const by = y + (H - y) * b.y;

        return (b.anchor ? anchored(bx, by, b.scale) : '')
            + `<g transform="translate(${bx.toFixed(0)} ${by.toFixed(0)}) scale(${b.scale})">${boats[b.kind](trim, glassFor(light))}</g>`;
    }).join('');

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="${light.sky[0]}"/>
      <stop offset="62%" stop-color="${light.sky[1]}"/>
      <stop offset="100%" stop-color="${light.sky[2]}"/>
    </linearGradient>
    <linearGradient id="sea" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="${light.sea[0]}"/>
      <stop offset="100%" stop-color="${light.sea[1]}"/>
    </linearGradient>
    <radialGradient id="glow">
      <stop offset="0%" stop-color="${light.disc}" stop-opacity=".85"/>
      <stop offset="100%" stop-color="${light.disc}" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="vignette" cx="50%" cy="46%" r="76%">
      <stop offset="55%" stop-color="#000" stop-opacity="0"/>
      <stop offset="100%" stop-color="#000" stop-opacity=".30"/>
    </radialGradient>
    <filter id="grain" x="0" y="0" width="100%" height="100%">
      <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch"/>
      <feColorMatrix type="saturate" values="0"/>
    </filter>
  </defs>

  <rect width="${W}" height="${y}" fill="url(#sky)"/>
  ${stars(r, light)}
  <circle cx="${cx}" cy="${cy}" r="${light.glow}" fill="url(#glow)"/>
  <circle cx="${cx}" cy="${cy}" r="${spec.light === 'dusk' ? 34 : 62}" fill="${light.disc}" opacity=".95"/>
  ${spec.islands === 0 ? '' : islands(r, light, spec.islands ?? 4)}

  <rect y="${y}" width="${W}" height="${H - y}" fill="url(#sea)"/>
  ${glitter(r, light)}
  ${waves(r, light)}
  ${spec.headland ? headland(light, spec.headland) : ''}
  ${fleet}
  ${spec.arch ? arch(light) : ''}

  <rect width="${W}" height="${H}" fill="url(#vignette)"/>
  <rect width="${W}" height="${H}" filter="url(#grain)" opacity=".055" style="mix-blend-mode:overlay"/>
</svg>`;
};

/* --------------------------------------------------------------------- cast */

/**
 * One entry per picture. For a trip the key is the **product slug**, which is
 * how the seeder finds its file without a second mapping to keep in step.
 */
const SCENES = {
    // The eight trips both demo operators sell.
    'proino-kolymvitiko': { light: 'morning', islands: 5, headland: 'right', boats: [{ kind: 'caique', x: 0.36, y: 0.30, scale: 0.72, anchor: true }] },
    'olimeri-tria-nisia': { light: 'day', islands: 3, boats: [{ kind: 'catamaran', x: 0.54, y: 0.42, scale: 0.78 }] },
    'apogevmatino-psarema': { light: 'golden', islands: 4, boats: [{ kind: 'caique', x: 0.30, y: 0.46, scale: 0.62, anchor: true }] },
    'romantiko-dilino': { light: 'dusk', islands: 3, boats: [{ kind: 'yacht', x: 0.58, y: 0.34, scale: 0.66, anchor: true }] },
    'idiotiki-imera-skafos': { light: 'day', islands: 4, headland: 'left', boats: [{ kind: 'yacht', x: 0.60, y: 0.40, scale: 0.74 }] },
    'spilies-kai-ormoi': { light: 'day', islands: 2, arch: true, boats: [{ kind: 'rib', x: 0.60, y: 0.34, scale: 0.56 }] },
    'istioploia-me-pania': { light: 'day', islands: 3, boats: [{ kind: 'sailboat', x: 0.44, y: 0.36, scale: 0.80 }, { kind: 'sailboat', x: 0.80, y: 0.18, scale: 0.32 }] },
    'metafora-sto-nisi': { light: 'morning', islands: 4, boats: [{ kind: 'rib', x: 0.42, y: 0.44, scale: 0.70 }] },

    // Aegean Blue only.
    'iliovasilema-aigina': { light: 'golden', islands: 4, headland: 'right', boats: [{ kind: 'caique', x: 0.34, y: 0.34, scale: 0.70 }] },
    'idiotiki-naulosi-imeras': { light: 'day', islands: 5, headland: 'left', boats: [{ kind: 'yacht', x: 0.56, y: 0.36, scale: 0.80, anchor: true }] },

    // Ionian Sunset only.
    'ilioyasilema-me-krasi': { light: 'dawn', islands: 3, boats: [{ kind: 'sailboat', x: 0.62, y: 0.34, scale: 0.66, anchor: true }] },
    'misi-mera-idiotiko': { light: 'morning', islands: 4, boats: [{ kind: 'catamaran', x: 0.40, y: 0.38, scale: 0.66 }] },

    // The third demo operator's single trip.
    'imerisia-krouazera': { light: 'day', islands: 4, boats: [{ kind: 'catamaran', x: 0.50, y: 0.40, scale: 0.72 }] },

    // The home page. The hero sits behind a headline, so it is the quietest of
    // the sixteen: nothing in the middle, where the words go.
    'demo-hero': { light: 'golden', islands: 5, headland: 'left', boats: [{ kind: 'sailboat', x: 0.80, y: 0.26, scale: 0.42 }] },
    'demo-story': { light: 'morning', islands: 4, headland: 'right', boats: [{ kind: 'caique', x: 0.46, y: 0.36, scale: 0.68, anchor: true }] },
    'demo-contact': { light: 'dawn', islands: 4, headland: 'left', boats: [{ kind: 'caique', x: 0.58, y: 0.30, scale: 0.52, anchor: true }] },
};

/* ---------------------------------------------------------------------- run */

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });

for (const [name, spec] of Object.entries(SCENES)) {
    const svg = scene(name, spec);

    if (process.env.KEEP_SVG) {
        writeFileSync(join(OUT, `${name}.svg`), svg);
    }

    await page.setContent(`<body style="margin:0;background:#000">${svg}</body>`, { waitUntil: 'load' });
    await page.screenshot({
        path: join(OUT, `${name}.jpg`),
        type: 'jpeg',
        quality: 82,
        clip: { x: 0, y: 0, width: W, height: H },
    });

    process.stdout.write(`${name}.jpg\n`);
}

await browser.close();
