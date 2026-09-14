import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire('C:/Users/Mike/kaiki/package.json');
const { chromium } = require('@playwright/test');
const OUT = resolve(dirname(fileURLToPath(import.meta.url)), 'shots');
const G = 'http://192.168.1.43:8001/aegean-blue';
const b = await chromium.launch();
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  for (const [name, url, h] of [
    ['guest-home', `${G}?lang=el`, 1500],
    ['guest-home-en', `${G}?lang=en`, 1500],
    ['guest-trip', `${G}/proino-kolymvitiko?lang=el`, 1600],
    ['guest-search', `${G}/search?lang=el`, 1400],
  ]) {
    await p.setViewportSize({ width: 1440, height: h });
    await p.goto(url, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1600);
    await p.screenshot({ path: resolve(OUT, `${name}.png`) });
    console.log('ok', name);
  }
  await ctx.close();
}
{
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  for (const [name, url] of [['phone-guest-home', `${G}?lang=el`], ['phone-guest-trip', `${G}/proino-kolymvitiko?lang=el`]]) {
    await p.goto(url, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1600);
    await p.screenshot({ path: resolve(OUT, `${name}.png`) });
    console.log('ok', name);
  }
  await ctx.close();
}
await b.close();
