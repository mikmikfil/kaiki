import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The operator's website — a real server on a real second origin (issue 111).
 *
 * ## Why a second server rather than an intercepted route
 *
 * The first version of this fulfilled `http://operator.example/**` from inside
 * the test with `page.route()`, which is tidier and does not work: Chrome's
 * Local Network Access check refuses a request from a synthesised page to a
 * loopback address, and the widget could not even load its bundle. Disabling
 * the check with a launch flag would have made the suite pass by switching off
 * a browser security feature — and a suite that does that is a suite that will
 * one day hide a real cross-origin bug.
 *
 * A genuine second origin on loopback needs no flag, and it buys something the
 * interception never could: the widget's requests are **real cross-origin
 * requests**, with a real preflight, against a key whose allow-list names this
 * exact origin. SEC-7 is exercised rather than stepped around.
 *
 * ## Three pages, because WGT-22 names two environments
 *
 * - `/strict-csp` — no `unsafe-inline` anywhere. The widget injects its styles
 *   into the shadow root by script, and a page that had to weaken its policy to
 *   take a booking is a page an operator's security review sends back.
 * - `/hostile-css` — a theme that resets `box-sizing`, restyles every `button`,
 *   hides what the widget inserts and shouts at every element. All of it is what a shadow root exists
 *   for.
 * - `/` — an ordinary page, for everything that is not about the host.
 */

const STATE = resolve(process.env.KAIKI_E2E_STATE ?? 'packages/widget/e2e/.state.json');
const PORT = Number(process.env.KAIKI_E2E_FIXTURE_PORT ?? 8124);
const APP = process.env.KAIKI_E2E_APP_URL ?? 'http://127.0.0.1:8123';

function state() {
  return JSON.parse(readFileSync(STATE, 'utf8'));
}

const HOSTILE = `
  * { box-sizing: content-box !important; font-family: "Comic Sans MS", cursive !important; }
  button { background: #ff00ff !important; color: #00ff00 !important; padding: 3rem !important; border-radius: 0 !important; }
  /* Scoped to the operator's own content area, which is what a page builder's
     reset actually looks like: it hides anything the theme did not put there.
     Not a bare descendant rule on every div, which would hide the operator's own
     markup too — a fixture that breaks the host page is not testing the widget.
     (No backticks in here, either: this string is a template literal.) */
  #embed div, #embed span:not(.kaiki-keep) { display: none !important; }
  input, select, textarea { width: 4px !important; font-size: 4px !important; }
  h1, h2, h3 { text-transform: lowercase !important; letter-spacing: 1rem !important; }
  :root { font-size: 42px; }
`;

function page(url) {
  const params = url.searchParams;
  const run = state();

  const attributes = [
    `src="${APP}/widget/kaiki-widget.js"`,
    `data-key="${run.key}"`,
    params.get('mount') ? `data-mount="${params.get('mount')}"` : '',
    params.get('product') ? `data-product="${params.get('product')}"` : '',
    params.get('locale') ? `data-locale="${params.get('locale')}"` : '',
    params.get('analytics') === 'false' ? 'data-analytics="false"' : '',
  ]
    .filter(Boolean)
    .join(' ');

  const hostile = url.pathname === '/hostile-css';

  // The strict page carries no inline `<style>` at all, because its own policy
  // forbids one. That is the honest fixture: an operator with a policy this
  // strict is not writing inline styles either.
  const styles = url.pathname === '/strict-csp'
    ? ''
    : `<style>body { margin: 0; font: 16px/1.5 system-ui, sans-serif; } .page { max-width: 46rem; margin: 0 auto; padding: 2rem 1rem; }${hostile ? HOSTILE : ''}</style>`;

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aegean Blue — book a trip</title>
  ${styles}
</head>
<body>
  <div class="page">
    <h1>Aegean Blue</h1>
    <p>Half-day sailing from Rafina.</p>
    <div id="embed"><script ${attributes}></script></div>
  </div>
</body>
</html>`;
}

createServer((request, response) => {
  const url = new URL(request.url ?? '/', `http://127.0.0.1:${PORT}`);

  if (url.pathname === '/health') {
    response.writeHead(200, { 'content-type': 'text/plain' }).end('ok');

    return;
  }

  const headers = { 'content-type': 'text/html; charset=utf-8' };

  if (url.pathname === '/strict-csp') {
    // WGT-22, and note what is absent: no `unsafe-inline`, no `unsafe-hashes`,
    // no nonce. The only script allowed is the bundle, from the application's
    // own origin.
    headers['content-security-policy'] = [
      `default-src 'none'`,
      `script-src ${APP}`,
      `connect-src ${APP}`,
      `style-src 'self'`,
      `img-src 'self' data:`,
      `base-uri 'none'`,
      `form-action 'none'`,
    ].join('; ');
  }

  response.writeHead(200, headers).end(page(url));
}).listen(PORT, '127.0.0.1', () => {
  process.stdout.write(`fixture host on http://127.0.0.1:${PORT}\n`);
});
