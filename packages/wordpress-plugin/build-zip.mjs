/**
 * Build the installable plugin zip.
 *
 *   npm run plugin:zip
 *   node packages/wordpress-plugin/build-zip.mjs
 *
 * Writes `packages/wordpress-plugin/dist/kaiki-booking-<version>.zip`, whose one
 * top-level folder is `kaiki-booking/` — the shape WordPress's «Upload Plugin»
 * expects, and the folder name the plugin's slug and text domain assume.
 *
 * ## What it does, in order
 *
 * 1. Reads the version from the `Version:` header of `kaiki-booking.php` and
 *    **fails** unless the `VERSION` constant in the same file and the
 *    `Stable tag:` of `readme.txt` say the same. Three places, one number; a
 *    zip whose admin screen and readme disagree is a support ticket.
 * 2. Runs `php tools/build-translations.php`, so the `.mo` and `.json` in the
 *    zip are compiled from the `.po` as it is now, not as it was at the last
 *    commit that remembered to.
 * 3. Copies the plugin to a temporary folder, leaving out what `.distignore`
 *    lists (tests, dev config, dotfiles, the dev `vendor/`).
 * 4. Runs `composer install --no-dev --optimize-autoloader` in that copy.
 *    `kaiki-booking.php` requires `vendor/autoload.php` for the plugin's own
 *    PSR-4 classes; `composer.json` has no runtime packages, so what ships is
 *    the autoloader and nothing else. The manifest is then removed again.
 * 5. Zips the copy. The zip is written here rather than by `zip`, `tar -a` or
 *    `Compress-Archive`, because none of those is on every machine and the
 *    last one has in the past written backslashes into entry names, which
 *    unpacks on a Linux host as files literally called `src\Plugin.php`.
 *
 * Needs `php` (8.1+) and `composer` on PATH. Set `KAIKI_PHP` to a php binary to
 * use a different one for the translations step.
 */

import { spawnSync } from 'node:child_process';
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateRawSync } from 'node:zlib';

const HERE = dirname(fileURLToPath(import.meta.url));
const SLUG = 'kaiki-booking';
const PLUGIN = resolve(HERE, SLUG);
const DIST = resolve(HERE, 'dist');
const IS_WINDOWS = process.platform === 'win32';

function fail(message) {
  process.stderr.write(`\nplugin:zip failed: ${message}\n`);
  process.exit(1);
}

function step(message) {
  process.stdout.write(`\n> ${message}\n`);
}

function run(command, args, cwd, env = process.env) {
  // `composer` is a `.bat` on Windows, which only a shell can start. Its
  // arguments here are fixed flags with no spaces or quotes, so they go to the
  // shell as one string (Node deprecates a shell plus an argument array).
  const viaShell = IS_WINDOWS && command === 'composer';
  const result = viaShell
    ? spawnSync([command, ...args].join(' '), { cwd, env, stdio: 'inherit', shell: true })
    : spawnSync(command, args, { cwd, env, stdio: 'inherit' });

  if (result.error) {
    fail(`could not run ${command}: ${result.error.message}`);
  }

  if (result.status !== 0) {
    fail(`${command} ${args.join(' ')} exited with ${result.status}`);
  }
}

// 1. The version, three times over.

step('Checking the version');

const main = readFileSync(join(PLUGIN, `${SLUG}.php`), 'utf8');
const readme = readFileSync(join(PLUGIN, 'readme.txt'), 'utf8');

const header = main.match(/^\s*\*\s*Version:\s*(\S+)\s*$/m)?.[1];
const constant = main.match(/^\s*const\s+VERSION\s*=\s*'([^']+)'\s*;/m)?.[1];
const stable = readme.match(/^Stable tag:\s*(\S+)\s*$/m)?.[1];

if (!header) {
  fail(`no "Version:" header in ${SLUG}.php`);
}

const versions = { [`${SLUG}.php Version header`]: header, [`${SLUG}.php VERSION constant`]: constant, 'readme.txt Stable tag': stable };
const disagree = Object.entries(versions).filter(([, value]) => value !== header);

if (disagree.length > 0) {
  fail(
    `the version is not the same everywhere:\n${Object.entries(versions)
      .map(([where, value]) => `  ${where}: ${value ?? '(missing)'}`)
      .join('\n')}`,
  );
}

if (!/^\d+\.\d+\.\d+([-.][0-9A-Za-z.]+)?$/.test(header)) {
  fail(`"${header}" does not look like a version`);
}

process.stdout.write(`  ${header} (header, constant and Stable tag agree)\n`);

// 2. Translations.

step('Compiling translations');

// The compiler stamps the block editor JSON with the time it ran, so every build
// would leave a committed file modified when nothing in it changed. Where the
// only difference is that stamp, the committed bytes go back.
const LANGUAGES = join(PLUGIN, 'languages');
const REVISION_DATE = /"translation-revision-date":"[^"]*"/;
const jsonBefore = new Map(
  readdirSync(LANGUAGES)
    .filter((name) => name.endsWith('.json'))
    .map((name) => [name, readFileSync(join(LANGUAGES, name), 'utf8')]),
);

run(process.env.KAIKI_PHP || 'php', [join(HERE, 'tools', 'build-translations.php')], HERE);

for (const [name, before] of jsonBefore) {
  const path = join(LANGUAGES, name);
  const after = existsSync(path) ? readFileSync(path, 'utf8') : null;

  if (after !== null && after !== before && after.replace(REVISION_DATE, '') === before.replace(REVISION_DATE, '')) {
    writeFileSync(path, before);
  }
}

// 3. The staging copy.

step('Copying the plugin without what .distignore lists');

/** `.distignore` as a list of matchers over a root-relative, `/`-separated path. */
function readDistignore(file) {
  if (!existsSync(file)) {
    fail(`${file} is missing`);
  }

  return readFileSync(file, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('#'))
    .map((line) => {
      const anchored = line.startsWith('/');
      const pattern = line.replace(/^\/+|\/+$/g, '');
      const regex = new RegExp(
        `^${pattern
          .split('*')
          .map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
          .join('[^/]*')}$`,
      );

      // Anchored: the whole path from the root. Otherwise: any single name in it.
      return anchored
        ? (path) => regex.test(path)
        : (path) => path.split('/').some((name) => regex.test(name));
    });
}

const ignored = readDistignore(join(PLUGIN, '.distignore'));
const isIgnored = (path) => ignored.some((matches) => matches(path));

const work = mkdtempSync(join(tmpdir(), 'kaiki-plugin-zip-'));
const stage = join(work, SLUG);

process.on('exit', () => rmSync(work, { recursive: true, force: true }));

cpSync(PLUGIN, stage, {
  recursive: true,
  filter: (source) => {
    const path = relative(PLUGIN, source).split(sep).join('/');

    return path === '' || !isIgnored(path);
  },
});

// 4. The production autoloader.

step('Installing the production autoloader');

for (const file of ['composer.json', 'composer.lock']) {
  if (existsSync(join(PLUGIN, file))) {
    cpSync(join(PLUGIN, file), join(stage, file));
  }
}

run(
  'composer',
  ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts'],
  stage,
  // The staging copy is not a git checkout, so Composer cannot guess the root
  // package's version and says so. The plugin header already knows it.
  { ...process.env, COMPOSER_ROOT_VERSION: header },
);

// Composer also warns that the Elementor classes in `src/Elementor/*-class*.php`
// do not follow PSR-4. That is deliberate (see `widget-class.php`): those files
// are required by hand once Elementor has loaded, never autoloaded.

for (const file of ['composer.json', 'composer.lock']) {
  rmSync(join(stage, file), { force: true });
}

if (!existsSync(join(stage, 'vendor', 'autoload.php'))) {
  fail('composer did not write vendor/autoload.php');
}

const devLeftovers = ['phpunit', 'squizlabs', 'wp-coding-standards', 'phpcompatibility', 'dealerdirect'].filter((name) =>
  existsSync(join(stage, 'vendor', name)),
);

if (devLeftovers.length > 0) {
  fail(`dev packages ended up in vendor/: ${devLeftovers.join(', ')}`);
}

// 5. The zip.

step('Writing the zip');

/** Every file and folder under `root`, folders first, sorted, as `/` paths. */
function walk(root, prefix) {
  const entries = [];

  for (const name of readdirSync(root).sort()) {
    const full = join(root, name);
    const path = `${prefix}/${name}`;

    if (statSync(full).isDirectory()) {
      entries.push({ path: `${path}/`, full, directory: true });
      entries.push(...walk(full, path));
    } else {
      entries.push({ path, full, directory: false });
    }
  }

  return entries;
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n;

  for (let k = 0; k < 8; k++) {
    c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  }

  return c >>> 0;
});

function crc32(buffer) {
  let crc = 0xffffffff;

  for (const byte of buffer) {
    crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  }

  return (crc ^ 0xffffffff) >>> 0;
}

function dosDateTime(date) {
  const year = Math.max(date.getFullYear(), 1980);

  return {
    time: (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2),
    date: ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate(),
  };
}

function zip(entries) {
  const locals = [];
  const centrals = [];
  let offset = 0;

  for (const entry of entries) {
    const name = Buffer.from(entry.path, 'utf8');
    const raw = entry.directory ? Buffer.alloc(0) : readFileSync(entry.full);
    const deflated = entry.directory ? raw : deflateRawSync(raw, { level: 9 });
    const store = entry.directory || deflated.length >= raw.length;
    const data = store ? raw : deflated;
    const crc = entry.directory ? 0 : crc32(raw);
    const { time, date } = dosDateTime(statSync(entry.full).mtime);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4); // version needed: 2.0
    local.writeUInt16LE(0x0800, 6); // names are UTF-8
    local.writeUInt16LE(store ? 0 : 8, 8);
    local.writeUInt16LE(time, 10);
    local.writeUInt16LE(date, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(raw.length, 22);
    local.writeUInt16LE(name.length, 26);
    local.writeUInt16LE(0, 28);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE((3 << 8) | 20, 4); // made by: Unix, 2.0 - so the mode below is read
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(store ? 0 : 8, 10);
    central.writeUInt16LE(time, 12);
    central.writeUInt16LE(date, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(data.length, 20);
    central.writeUInt32LE(raw.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt16LE(0, 30); // extra
    central.writeUInt16LE(0, 32); // comment
    central.writeUInt16LE(0, 34); // disk
    central.writeUInt16LE(0, 36); // internal attributes
    central.writeUInt32LE(
      entry.directory ? ((0o40755 << 16) | 0x10) >>> 0 : (0o100644 << 16) >>> 0,
      38,
    );
    central.writeUInt32LE(offset, 42);

    locals.push(local, name, data);
    centrals.push(central, name);
    offset += local.length + name.length + data.length;
  }

  const centralSize = centrals.reduce((sum, part) => sum + part.length, 0);

  if (entries.length > 0xffff || offset + centralSize > 0xffffffff) {
    fail('the plugin is too large for a zip without Zip64');
  }

  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralSize, 12);
  end.writeUInt32LE(offset, 16);
  end.writeUInt16LE(0, 20);

  return Buffer.concat([...locals, ...centrals, end]);
}

const entries = [{ path: `${SLUG}/`, full: stage, directory: true }, ...walk(stage, SLUG)];

for (const required of [`${SLUG}/${SLUG}.php`, `${SLUG}/readme.txt`, `${SLUG}/uninstall.php`, `${SLUG}/vendor/autoload.php`]) {
  if (!entries.some((entry) => entry.path === required)) {
    fail(`${required} is not in the build`);
  }
}

mkdirSync(DIST, { recursive: true });

const output = join(DIST, `${SLUG}-${header}.zip`);
const archive = zip(entries);

writeFileSync(output, archive);

const files = entries.filter((entry) => !entry.directory).length;

process.stdout.write(
  `\n${relative(process.cwd(), output) || output}\n  ${files} files, ${(archive.length / 1024).toFixed(1)} KB\n`,
);
