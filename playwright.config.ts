import { defineConfig, devices } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The end-to-end run (spec TST-3, ENV-23, issue 111).
 *
 * ## The retry policy is zero, and that is a decision
 *
 * The issue is explicit about it: *"an intermittent failure in a booking flow is
 * a real bug — a race between the hold, the availability cache and the redirect.
 * A suite that retries until green is a suite that will let exactly that
 * through."* Playwright's project default is to retry on CI, so leaving the
 * field out would have quietly enabled the thing the issue forbids.
 *
 * What replaces retries is **traces and screenshots on the first failure**, so a
 * red run can be read rather than re-run.
 *
 * ## One worker
 *
 * Every spec books against one seeded departure with a real capacity. Parallel
 * workers would compete for seats and produce exactly the intermittent failure
 * this suite exists to detect — with no way to tell it from a real race.
 *
 * ## The server is a real one
 *
 * `php artisan serve` against a **separate SQLite file**, seeded fresh by
 * `kaiki:e2e-prepare`. Not the developer's database: a suite that books through
 * somebody's working data leaves confirmed bookings in it, and a suite that
 * migrates it fresh loses their afternoon.
 */

const PORT = Number(process.env.KAIKI_E2E_PORT ?? 8123);
const BASE_URL = `http://127.0.0.1:${PORT}`;

/**
 * The operator's website: a **real** second origin on loopback, served by
 * `support/fixture-server.mjs`.
 *
 * Not an intercepted route. Chrome's Local Network Access check refuses a
 * request from a synthesised page to a loopback address, so the bundle never
 * loaded — and turning that check off with a launch flag would be passing the
 * suite by switching off a browser security feature. A real origin needs no
 * flag and makes the widget's requests genuinely cross-origin, which is the
 * only way SEC-7's allow-list is exercised rather than stepped around.
 */
const FIXTURE_PORT = Number(process.env.KAIKI_E2E_FIXTURE_PORT ?? 8124);
export const FIXTURE_ORIGIN = `http://127.0.0.1:${FIXTURE_PORT}`;

const DATABASE = resolve('database/e2e.sqlite');

/**
 * A fresh database file before anything reads it.
 *
 * `migrate:fresh` needs the file to exist for SQLite, and `touch` is not a
 * thing on every platform this repository is developed on.
 */
function prepareDatabase(): void {
  mkdirSync(resolve('database'), { recursive: true });
  rmSync(DATABASE, { force: true });
  writeFileSync(DATABASE, '');
}

const php = process.env.KAIKI_PHP ?? 'php';

const serverEnv = {
  ...process.env,
  APP_ENV: 'local',
  APP_DEBUG: 'false',
  APP_URL: BASE_URL,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: DATABASE,
  // The hosted pages are scoped to this host, and for one run it is the server
  // itself — otherwise `/{operator}` never matches and the run tests nothing.
  KAIKI_HOSTED_HOST: '127.0.0.1',
  // The webhook and the sandbox settlement both queue work. `sync` runs it
  // inline, which is what makes a confirmation visible by the time the redirect
  // lands rather than whenever a worker happens to exist.
  QUEUE_CONNECTION: 'sync',
  // One minute, so `hold-expiry.spec.ts` can watch a hold run out **for real**
  // rather than against a mocked clock, which is what the issue asks for. The
  // booking walk takes about five seconds, so a minute is not tight for the
  // other specs — and a hold that expired mid-walk would be a real bug worth
  // seeing anyway.
  KAIKI_HOLD_MINUTES: '1',
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
  MAIL_MAILER: 'log',
};

if (!existsSync(DATABASE) || process.env.KAIKI_E2E_SEED !== 'false') {
  prepareDatabase();

  execFileSync(
    php,
    ['artisan', 'kaiki:e2e-prepare', `--origin=${FIXTURE_ORIGIN}`],
    { env: serverEnv, stdio: 'inherit' },
  );
}

export default defineConfig({
  testDir: 'packages/widget/e2e',
  outputDir: 'packages/widget/e2e/.results',

  // Deliberate, and the issue says why. See the file docblock.
  retries: 0,
  workers: 1,
  fullyParallel: false,

  // A booking walks four steps against a real server; the default 30 s is tight
  // and a timeout that fires under CI load is a flake by another name.
  timeout: 60_000,
  expect: { timeout: 10_000 },

  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

  use: {
    baseURL: BASE_URL,
    // Pinned, because a native `<input type="date">` takes its segments in the
    // browser locale's order and the keyboard walk types them. An unpinned
    // locale makes that test pass or fail depending on whose machine ran it.
    locale: 'en-US',
    timezoneId: 'Europe/Athens',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      // The subset the release gates run: all four mounts, against the built
      // artefact. Fast enough to sit in front of every alias repoint.
      name: 'smoke',
      testMatch: /smoke\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Everything else — the full booking, the keyboard run, the accessibility
      // scan, the hold expiry. Nightly, and on demand.
      name: 'full',
      testIgnore: /smoke\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: [
    {
      command: `${php} artisan serve --host=127.0.0.1 --port=${PORT}`,
      // `/api/v1/health` needs a key, and a 401 never reads as ready. The
      // manifest is public and is served by the same application.
      url: `${BASE_URL}/widget/manifest.json`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: serverEnv,
    },
    {
      command: 'node packages/widget/e2e/support/fixture-server.mjs',
      url: `${FIXTURE_ORIGIN}/health`,
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
      env: {
        ...process.env,
        KAIKI_E2E_FIXTURE_PORT: String(FIXTURE_PORT),
        KAIKI_E2E_APP_URL: BASE_URL,
      },
    },
  ],
});
