// End-to-end: schema-v2 strategy lifecycle through the Strategy Builder UI.
// login -> /builder -> paste smx-pi-take-profit-v2.json -> Validate -> Save (plugin+version
// row via API) -> trigger a backtest from the page -> poll to completion -> assert either real
// trades, or (when the seeded tape never fires the strict momentum signal) a completed run with
// a non-null ending equity PLUS a second, relaxed-threshold copy that does produce trades with a
// take_profit./stop. rule name.
//
// Run against an isolated desk (never the shared LAN database) — see the worktree setup this
// journey was built against:
//   E2E_BASE=http://127.0.0.1:8011 E2E_OUT=storage/e2e \
//   PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright \
//   OPENROUTER_API_KEY=sk-or-... node tests/e2e/strategy-v2-lifecycle.mjs
//
// Needs a Playwright install: `npm i -D playwright && npx playwright install chromium`, or point
// PLAYWRIGHT_PATH at an existing node_modules/playwright (a global install works fine). Exit 0
// only when every assertion held.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const pwPath = process.env.PLAYWRIGHT_PATH || 'playwright';
const { chromium } = require(pwPath);

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8011';
const OUT = process.env.E2E_OUT || path.join(process.cwd(), 'storage', 'e2e');
const PASSWORD = process.env.E2E_PASSWORD || 'e2e-master-password-2026';
const OR_KEY = process.env.OPENROUTER_API_KEY;
const BT_DAYS = Number(process.env.E2E_BT_DAYS || 7); // must fit inside the seeded candle window
const BT_TIMEOUT_MS = Number(process.env.E2E_BT_TIMEOUT_MS || 60000);
fs.mkdirSync(OUT, { recursive: true });

const results = [];
let failed = false;
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`);
  if (!ok) failed = true;
}
async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true }).catch(() => {});
}
async function json(ctx, url, opts = {}) {
  const r = await ctx.request.fetch(BASE + url, {
    method: opts.method || 'GET',
    headers: { Accept: 'application/json', 'X-Desk-Token': PASSWORD, ...(opts.headers || {}) },
    data: opts.data,
  });
  let body = null;
  try { body = await r.json(); } catch { body = await r.text(); }
  return { status: r.status(), body };
}
function sleep(ms) { return new Promise((res) => setTimeout(res, ms)); }

async function pollBacktest(ctx, id, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let last = null;
  while (Date.now() < deadline) {
    const r = await json(ctx, `/api/backtests/${id}`);
    last = r.body;
    if (r.status === 200 && (last?.status === 'done' || last?.status === 'error')) return last;
    await sleep(500);
  }
  return last;
}

const STRATEGY_PATH = path.join(REPO_ROOT, 'resources', 'strategies', 'examples', 'smx-pi-take-profit-v2.json');
const originalDef = JSON.parse(fs.readFileSync(STRATEGY_PATH, 'utf8'));

// Loosened copy: same rules, but the momentum gate is opened so the strict entry signal can
// actually fire against real Coinbase history over a short seeded window (2%/24h + 1.5x volume
// surge simultaneously is a narrow window; -100%/0x is effectively "always true").
const relaxedDef = JSON.parse(JSON.stringify(originalDef));
relaxedDef.key = 'smx-pi-take-profit-relaxed';
relaxedDef.meta.name = 'SMX π Take Profit (relaxed for e2e)';
relaxedDef.signals.momentum.all[0].value = -100; // price_change_h24_pct
relaxedDef.signals.momentum.all[1].value = 0; // volume_surge_h1

const ctxOpts = { viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: process.env.E2E_INSECURE === '1' };
const browser = await chromium.launch({ headless: true });
let current = null;

/** Onboard a fresh desk (mirrors tests/e2e/login-and-ai.mjs) then log back in with a fresh session. */
async function onboardAndLogin() {
  const setup = await browser.newContext(ctxOpts);
  const page = await setup.newPage();
  current = page;
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) console.log(`  http ${r.status()} ${r.request().method()} ${r.url()}`); });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  check('root redirects to onboarding on a fresh desk', page.url().endsWith('/onboarding'), page.url());

  const pw = page.locator('input[placeholder*="master password"]');
  await pw.waitFor({ timeout: 15000 });
  await pw.fill(PASSWORD);
  await page.locator('button:has-text("Continue")').first().click();
  const keyInput = page.locator('input[placeholder="sk-or-v1-…"]');
  await keyInput.waitFor({ timeout: 15000 });
  const pwGone = (await pw.count()) === 0;
  check('master-password step advanced to openrouter step', pwGone);

  if (!OR_KEY) throw new Error('OPENROUTER_API_KEY is required for the openrouter wizard step');
  await keyInput.fill(OR_KEY);
  await page.locator('button:has-text("Continue")').first().click();
  const coinbase = page.locator('input[type="radio"][value="coinbase"]');
  await coinbase.waitFor({ timeout: 30000 });
  const orError = page.locator('.mw-error[role="alert"]');
  check('openrouter step accepted the key (server validated it against OpenRouter)', (await orError.count()) === 0);

  await coinbase.check();
  await page.locator('button:has-text("Continue with coinbase")').click();
  const skip = page.locator('button:has-text("Skip for now"), button:has-text("Continue")').first();
  await skip.waitFor({ timeout: 30000 });
  await skip.click();
  const launch = page.locator('button:has-text("Start paper trading")');
  await launch.waitFor({ timeout: 15000 });
  await launch.click();
  await page.waitForLoadState('networkidle');

  const ob = await json(setup, '/api/onboarding');
  check('wizard reports completed=true via /api/onboarding', ob.status === 200 && ob.body?.completed === true);
  await setup.close();

  // Fresh session -> /login gate -> real login (never reuse the onboarding session's cookies).
  const fresh = await browser.newContext(ctxOpts);
  const p2 = await fresh.newPage();
  current = p2;
  p2.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) console.log(`  http ${r.status()} ${r.request().method()} ${r.url()}`); });
  await p2.goto(BASE + '/', { waitUntil: 'networkidle' });
  check('fresh session is sent to /login once a master password exists', p2.url().endsWith('/login'), p2.url());

  await p2.locator('input[name="password"]').fill(PASSWORD);
  await p2.locator('button:has-text("Sign in")').click();
  await p2.waitForLoadState('networkidle');
  check('correct password leaves /login', !p2.url().endsWith('/login') && !p2.url().endsWith('/onboarding'), p2.url());

  return { fresh, p2 };
}

/**
 * Paste a plugin definition into the builder's JSON editor, Validate it, Save it, then trigger a
 * backtest from the page and return { plugin, backtest } once the run has settled.
 */
async function runLifecycle(fresh, p2, def, label) {
  await p2.goto(BASE + '/builder', { waitUntil: 'networkidle' });
  const editor = p2.locator('textarea');
  await editor.waitFor({ timeout: 15000 });
  await editor.fill(JSON.stringify(def, null, 2));

  // Validate — capture the API payload the click produces, not just the UI badge.
  const [validateResp] = await Promise.all([
    p2.waitForResponse((r) => r.url().endsWith('/api/strategy-plugins/validate') && r.request().method() === 'POST'),
    p2.locator('button:has-text("Validate")').click(),
  ]);
  const validateBody = await validateResp.json().catch(() => null);
  check(`[${label}] Validate reports valid:true with zero errors`,
    validateBody?.valid === true && Array.isArray(validateBody?.errors) && validateBody.errors.length === 0,
    JSON.stringify(validateBody));
  const validBadge = p2.locator('span.ok:has-text("Valid")');
  check(`[${label}] UI shows the "Valid" badge`, (await validBadge.count()) > 0);
  await shot(p2, `${label}-01-validated`);

  // Save — a new plugin/version row. Read the id back off the POST response, then re-fetch the
  // plugin through the same API the page uses to confirm it actually landed.
  const [saveResp] = await Promise.all([
    p2.waitForResponse((r) => r.url().endsWith('/api/strategy-plugins') && r.request().method() === 'POST'),
    p2.locator('button:has-text("Save")').click(),
  ]);
  const saveBody = await saveResp.json().catch(() => null);
  const pluginId = saveBody?.plugin?.id ?? null;
  check(`[${label}] Save returned a plugin id`, Number.isInteger(pluginId), JSON.stringify(saveBody?.plugin));
  await p2.waitForFunction(() => document.body.innerText.includes('Saved as v'), null, { timeout: 10000 }).catch(() => {});
  await shot(p2, `${label}-02-saved`);

  const pluginGet = await json(fresh, `/api/strategy-plugins/${pluginId}`);
  const semver = /^\d+\.\d+\.\d+$/;
  check(`[${label}] GET plugin: key === '${def.key}'`, pluginGet.status === 200 && pluginGet.body?.key === def.key, JSON.stringify(pluginGet.body?.key));
  check(`[${label}] GET plugin: current_version is a semver string`, semver.test(pluginGet.body?.current_version || ''), pluginGet.body?.current_version);

  const versionsGet = await json(fresh, `/api/strategy-plugins/${pluginId}/versions`);
  const hasVersionRow = Array.isArray(versionsGet.body?.data) && versionsGet.body.data.some((v) => v.version === pluginGet.body?.current_version);
  check(`[${label}] a plugin version row exists for current_version`, versionsGet.status === 200 && hasVersionRow, JSON.stringify(versionsGet.body?.data?.map((v) => v.version)));

  // Trigger a backtest from the page itself (not a raw API call) for BTC-USD over the seeded window.
  await p2.locator('input[title="days"]').fill(String(BT_DAYS));
  await p2.locator('input[title="cash"]').fill('1000');
  await p2.locator('input[placeholder="BTC-USD,ETH-USD"]').fill('BTC-USD');
  const [btResp] = await Promise.all([
    p2.waitForResponse((r) => /\/api\/strategy-plugins\/\d+\/backtest$/.test(r.url()) && r.request().method() === 'POST'),
    p2.locator('button:has-text("Run backtest")').click(),
  ]);
  const btBody = await btResp.json().catch(() => null);
  const btId = btBody?.id ?? null;
  check(`[${label}] backtest queued with an id`, Number.isInteger(btId), JSON.stringify(btBody?.id));
  await shot(p2, `${label}-03-backtest-queued`);

  const bt = await pollBacktest(fresh, btId, BT_TIMEOUT_MS);
  check(`[${label}] backtest reached a terminal status`, bt?.status === 'done' || bt?.status === 'error', JSON.stringify({ id: btId, status: bt?.status, error: bt?.error }));

  return { pluginId, plugin: pluginGet.body, backtest: bt };
}

try {
  const { fresh, p2 } = await onboardAndLogin();

  // ---- Original schema-v2 fixture -----------------------------------------------------------
  const original = await runLifecycle(fresh, p2, originalDef, 'original');
  const bt1 = original.backtest;
  const trades1 = Array.isArray(bt1?.trades) ? bt1.trades : [];

  if (trades1.length > 0) {
    check('original strategy backtest produced trades (trades > 0)', true, `trades=${trades1.length}`);
  } else {
    // The strict momentum gate (2%/24h + 1.5x volume surge, same bar) may legitimately never fire
    // against a short seeded tape — that is not a bug in the backtest, so fall back to asserting
    // the run genuinely completed, then prove the pipeline CAN produce trades with a relaxed copy.
    check('original strategy: 0 trades, but backtest completed with status=done and a non-null ending_equity',
      bt1?.status === 'done' && bt1?.ending_equity !== null && bt1?.ending_equity !== undefined,
      JSON.stringify({ status: bt1?.status, ending_equity: bt1?.ending_equity }));

    const relaxed = await runLifecycle(fresh, p2, relaxedDef, 'relaxed');
    const bt2 = relaxed.backtest;
    const trades2 = Array.isArray(bt2?.trades) ? bt2.trades : [];
    check('relaxed-momentum strategy backtest produced trades (trades > 0)', trades2.length > 0, `trades=${trades2.length}`);
    const ruleHit = trades2.find((t) => typeof t?.rule === 'string' && (t.rule.startsWith('take_profit.') || t.rule.startsWith('stop.')));
    check("relaxed backtest has a trade whose rule starts with 'take_profit.' or 'stop.'", !!ruleHit, JSON.stringify(trades2.map((t) => t.rule).slice(0, 10)));
  }

  await shot(p2, '99-final');
  await fresh.close();
} catch (e) {
  check('run completed without an unexpected error', false, String(e.message || e).split('\n')[0]);
  if (current && !current.isClosed()) {
    await shot(current, '99-failure');
    const body = await current.locator('body').innerText().catch(() => '');
    console.log('  page text at failure: ' + body.replace(/\s+/g, ' ').slice(0, 500));
  }
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
console.log(`\n${results.filter(r => r.ok).length}/${results.length} checks passed; artifacts in ${OUT}`);
process.exit(failed ? 1 : 0);
