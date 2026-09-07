import { readFileSync } from 'node:fs';

import { defineConfig } from 'vite';

const { version } = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')) as {
  version: string;
};

/**
 * The widget build (spec WGT-1 FIXED, WGT-2 FIXED, WGT-4).
 *
 * ## One file, IIFE, no imports at runtime
 *
 * WGT-1 fixes the shape: a single `kaiki-widget.js` an operator drops into a
 * page they do not control. That rules out code splitting, dynamic `import()`
 * and a separate CSS file — every one of them is a second request the host page
 * has to be able to make, from an origin their Content-Security-Policy may not
 * allow, at a path a page builder may rewrite.
 *
 * `inlineDynamicImports` and `cssCodeSplit: false` are what enforce it; without
 * them a lazily imported mount would silently become a second chunk and the
 * embed snippet would stop working on exactly the sites that need it most.
 *
 * ## `iife`, not `umd`
 *
 * UMD adds a CommonJS and an AMD branch for a bundle that is only ever loaded
 * by a `<script src>` tag. Bytes against the 80 KB budget for two module
 * systems nobody will use here.
 *
 * ## No hashed filename
 *
 * ADR-0011 versions the bundle by **path** — `/widget/v1.4.2/kaiki-widget.js`
 * with a moving alias — so the filename is stable and the directory carries the
 * version. A content hash in the name would mean the operator's embed snippet
 * changes on every release, which is the one thing a snippet in a thousand
 * WordPress themes must never do.
 */
export default defineConfig({
  // WGT-11's `widget_version`, and the only place the manifest is read. A
  // bundle that fetched its own version at runtime would be shipping the
  // manifest to every guest.
  define: {
    __KAIKI_WIDGET_VERSION__: JSON.stringify(version),
  },
  build: {
    target: 'es2019',
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,
    lib: {
      entry: 'src/index.tsx',
      name: 'KaikiWidget',
      formats: ['iife'],
      fileName: () => 'kaiki-widget.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
        // Nothing is external: the host page provides no libraries and must
        // not be asked to.
        extend: true,
      },
    },
    minify: 'esbuild',
    // The gate that matters is `npm run widget:size`, which measures the
    // gzipped bytes WGT-2 actually fixes. This one only stops Vite printing a
    // warning about a limit that is not the limit.
    chunkSizeWarningLimit: 200,
  },
  esbuild: {
    jsx: 'automatic',
    jsxImportSource: 'preact',
    // `console` and `debugger` survive in development builds and are dropped
    // from the production bundle: a widget that logs into somebody else's
    // console is a widget their developer files a bug about.
    drop: process.env.NODE_ENV === 'development' ? [] : ['console', 'debugger'],
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
});
