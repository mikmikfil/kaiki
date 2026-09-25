/*
 * Builds the panel theme: `npm run build:panel`, which `npm run build` runs.
 *
 * Filament 3 is written for Tailwind v3, the rest of the project is on v4, and
 * both are installed — v3 under the npm alias `tailwindcss3`. The Tailwind v3
 * CLI is what Filament's own `make:filament-theme` prescribes when v4 is
 * present; the one thing it cannot do alone is make Filament's preset and its
 * two plugins (`@tailwindcss/forms`, `@tailwindcss/typography`) load v3. They
 * `require('tailwindcss/…')`, which from the project root is v4, and v4's
 * compatibility modules hand back a different font stack and oklch() colours.
 * So for the length of this build, `tailwindcss` means `tailwindcss3`.
 *
 * Output: `public/css/filament/app/theme.css`, ignored by git and registered
 * on both panels with a version from its modification time.
 */
const Module = require('node:module')
const path = require('node:path')

const resolve = Module._resolveFilename

Module._resolveFilename = function (request, ...rest) {
    if (request === 'tailwindcss' || request.startsWith('tailwindcss/')) {
        request = 'tailwindcss3' + request.slice('tailwindcss'.length)
    }

    return resolve.call(this, request, ...rest)
}

const root = path.resolve(__dirname, '../../../..')
const here = path.relative(root, __dirname)

process.chdir(root)
process.argv = [
    process.argv[0],
    require.resolve('tailwindcss3/lib/cli.js'),
    '--input', path.join(here, 'theme.css'),
    '--output', 'public/css/filament/app/theme.css',
    '--postcss', path.join(here, 'postcss.config.cjs'),
    '--minify',
    ...process.argv.slice(2),
]

require('tailwindcss3/lib/cli.js')
