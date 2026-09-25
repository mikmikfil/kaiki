/*
 * PostCSS for the panel theme only, run by `npm run build:panel`.
 *
 * Filament's stylesheet begins `@import 'tailwindcss/base'`, which on its own
 * would resolve to the project's Tailwind v4 — a package with no `base.css`.
 * Here those imports go to the v3 install (`tailwindcss3`, an npm alias),
 * which is the Tailwind Filament 3 is written for. Everything else resolves
 * as usual.
 */
const path = require('node:path')

const tailwindV3 = path.dirname(require.resolve('tailwindcss3/package.json'))

module.exports = {
    plugins: [
        require('postcss-import')({
            resolve: (id) => (/^tailwindcss\/[a-z]+$/.test(id) ? path.join(tailwindV3, id.slice('tailwindcss/'.length) + '.css') : id),
        }),
        require('tailwindcss3/nesting'),
        require('tailwindcss3')({ config: path.join(__dirname, 'tailwind.config.js') }),
        require('autoprefixer'),
    ],
}
