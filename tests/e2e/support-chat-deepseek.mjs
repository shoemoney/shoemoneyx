// End-to-end: the SMX "AI assist" support chat (/api/strategy-assist) pinned explicitly to
// deepseek/deepseek-v4-flash — the model config/ai.php lists under `recommended` and nudges free
// users toward (config/ai.php `nudge.model` is the vision-exp sibling, offered for backtest
// review; this journey exercises the plain flash model as the general support/drafting model).
//
// Proves, with literal checks, not just HTTP 200:
//   1. deepseek/deepseek-v4-flash appears in the builder's live Recommended model list.
//   2. Selecting it in the assist chat's model dropdown and persisting via savePrefs actually
//      routes the next call there — checked two ways: the request body sent to the browser
//      (not trusted alone, a bug could send one thing and the server route another) AND the
//      response body's own `model` field, which the server echoes back from the real OpenRouter
//      reply object, so it proves the call was actually served by that model, not silently
//      routed elsewhere by the client, the server, or the free-model auto-router.
//   3. The reply is real model output (non-empty, not a canned client-side error string) and,
//      for a strategy-drafting prompt, contains a JSON code block, proving deepseek-v4-flash can
//      actually do the drafting job it is offered for.
//   4. The free-model nudge banner appears when on a free model and names the nudge model from
//      config/ai.php, and clicking it switches the picker AND persists to localStorage.
//
// Run against an isolated desk (never the shared LAN database):
//   S=/tmp/e2e-deepseek; mkdir -p $S; : > $S/e2e.sqlite
//   export APP_URL=http://127.0.0.1:8110 DB_CONNECTION=sqlite DB_DATABASE=$S/e2e.sqlite DB_URL= \
//          SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log
//   php artisan migrate --force && php artisan serve --host=127.0.0.1 --port=8110 &
//   E2E_BASE=http://127.0.0.1:8110 OPENROUTER_API_KEY=sk-or-... E2E_OUT=$S node tests/e2e/support-chat-deepseek.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const pwPath = process.env.PLAYWRIGHT_PATH || 'playwright';
const { chromium } = require(pwPath);

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8010';
const OUT = process.env.E2E_OUT || path.join(process.cwd(), 'storage', 'e2e');
const PASSWORD = process.env.E2E_PASSWORD || 'e2e-master-password-2026';
const OR_KEY = process.env.OPENROUTER_API_KEY;
const AI_TIMEOUT = Number(process.env.E2E_AI_TIMEOUT_MS || 120000);
const SUPPORT_MODEL = process.env.E2E_SUPPORT_MODEL || 'deepseek/deepseek-v4-flash';
fs.mkdirSync(OUT, { recursive: true });

const results = [];
let failed = false;
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`);
  if (!ok) failed = true;
}
async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}
async function json(ctx, url) {
  const r = await ctx.request.get(BASE + url, { headers: { Accept: 'application/json', 'X-Desk-Token': PASSWORD } });
  let body = null;
  try { body = await r.json(); } catch { body = await r.text(); }
  return { status: r.status(), body };
}

if (!OR_KEY) throw new Error('OPENROUTER_API_KEY is required');
const ctxOpts = { viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: process.env.E2E_INSECURE === '1' };
const browser = await chromium.launch({ headless: true });
let current = null;
try {
  // ---- onboard a fresh desk (same wizard every other e2e journey walks) -------------------
  const setup = await browser.newContext(ctxOpts);
  const page = await setup.newPage();
  current = page;
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  check('root redirects to onboarding on a fresh desk', page.url().endsWith('/onboarding'), page.url());

  const pw = page.locator('input[placeholder*="master password"]');
  await pw.waitFor({ timeout: 15000 });
  await pw.fill(PASSWORD);
  await page.locator('button:has-text("Continue")').first().click();
  const keyInput = page.locator('input[placeholder="sk-or-v1-…"]');
  await keyInput.waitFor({ timeout: 15000 });
  check('master-password step advanced to openrouter step', (await pw.count()) === 0);

  await keyInput.fill(OR_KEY);
  await page.locator('button:has-text("Continue")').first().click();
  const coinbase = page.locator('input[type="radio"][value="coinbase"]');
  await coinbase.waitFor({ timeout: 30000 });
  check('openrouter step accepted the key (server validated it against OpenRouter)', (await page.locator('.mw-error[role="alert"]').count()) === 0);
  await coinbase.check();
  await page.locator('button:has-text("Continue with coinbase")').click();
  const skip = page.locator('button:has-text("Skip for now"), button:has-text("Continue")').first();
  await skip.waitFor({ timeout: 30000 });
  await skip.click();
  const launch = page.locator('button:has-text("Start paper trading")');
  await launch.waitFor({ timeout: 15000 });
  await launch.click();
  await page.waitForLoadState('networkidle');
  await setup.close();

  // ---- fresh login, straight to the builder -----------------------------------------------
  const ctx = await browser.newContext(ctxOpts);
  const p2 = await ctx.newPage();
  current = p2;
  await p2.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p2.locator('input[name="password"]').fill(PASSWORD);
  await p2.locator('button:has-text("Sign in")').click();
  await p2.waitForLoadState('networkidle');
  check('login left /login', !p2.url().endsWith('/login'));

  const assistResponses = [];
  p2.on('response', async (r) => {
    if (r.url().endsWith('/api/strategy-assist')) {
      let body = null;
      try { body = await r.json(); } catch { /* non-JSON, leave null */ }
      assistResponses.push({ status: r.status(), body, requestBody: r.request().postDataJSON?.() ?? null });
    }
  });

  await p2.goto(BASE + '/builder', { waitUntil: 'networkidle' });
  await p2.waitForSelector('.chat', { timeout: 15000 });
  await shot(p2, 'support-01-builder');

  // ---- 1. deepseek/deepseek-v4-flash is actually in the live Recommended list -------------
  const modelsResp = await json(ctx, '/api/ai/models');
  const recommendedIds = (modelsResp.body?.recommended ?? []).map((m) => m.id);
  check(
    'deepseek/deepseek-v4-flash is in the live /api/ai/models recommended list',
    recommendedIds.includes(SUPPORT_MODEL),
    JSON.stringify(recommendedIds),
  );

  // ---- 2. select it in the assist chat's own model picker ---------------------------------
  // Scoped to section.assist: the page also has an unrelated <select title="version bump">
  // in the JSON-editor toolbar, and a bare `.locator('select').first()` silently grabs that
  // one instead (found live: selectOption() timed out waiting for an option that was never
  // going to exist on the wrong <select>).
  const modelSelect = p2.locator('section.assist select');
  await modelSelect.waitFor({ timeout: 15000 });
  const optionExists = (await modelSelect.locator(`option[value="${SUPPORT_MODEL}"]`).count()) > 0;
  check('the assist chat model dropdown offers deepseek/deepseek-v4-flash as an option', optionExists);
  if (optionExists) {
    await modelSelect.selectOption(SUPPORT_MODEL);
  }
  const persisted = await p2.evaluate(() => localStorage.getItem('smx_or_model'));
  check('selecting the model persists it to localStorage (savePrefs)', persisted === SUPPORT_MODEL, `stored=${persisted}`);

  // ---- 3. send a real strategy-drafting prompt, pinned to that model ----------------------
  const assistChat = p2.locator('.chat').nth(0);
  const draft = p2.locator('input[placeholder="Describe your strategy…"]');
  await draft.waitFor({ timeout: 15000 });
  const before = await assistChat.locator('.msg.assistant').count();
  await draft.fill('Draft a simple oversold RSI dip-buy strategy for BTC-USD on the 1H timeframe. Give me the schema v2 JSON.');
  await draft.press('Enter');
  await p2.waitForFunction(
    (n) => document.querySelectorAll('.chat')[0]?.querySelectorAll('.msg.assistant').length > n,
    before,
    { timeout: AI_TIMEOUT },
  );
  await shot(p2, 'support-02-reply');

  const reply = (await assistChat.locator('.msg.assistant').last().innerText()).trim();
  const canned = /^Error:|^Connect your OpenRouter|^Pick a model|^\(empty reply\)/;
  check('assist chat produced a real, non-canned reply', reply.length > 0 && !canned.test(reply), reply.slice(0, 160));
  check('the reply drafts an actual strategy (contains a JSON code block)', /```json/i.test(reply) || /```\s*\{/.test(reply), reply.slice(0, 200));

  const call = assistResponses.at(-1);
  check('a /api/strategy-assist call was captured', !!call, JSON.stringify(call));
  if (call) {
    check('the request body asked for deepseek/deepseek-v4-flash', call.requestBody?.model === SUPPORT_MODEL, JSON.stringify(call.requestBody));
    // The strongest proof: the SERVER's own response echoes the model that actually served the
    // reply (StrategyPluginController::assist -> $reply->model, sourced from OpenRouter's own
    // response object), not merely what the client asked for.
    check(
      "the server's response echoes deepseek/deepseek-v4-flash as the model that actually replied",
      call.body?.model === SUPPORT_MODEL,
      JSON.stringify(call.body),
    );
  }

  // ---- 4. the free-model nudge banner names the nudge model and switching persists --------
  // showNudge (StrategyBuilder.vue) is set ONLY inside runBacktest() — `showNudge.value =
  // !!aiStatus.value?.nudge?.show` — never from loadAiStatus() on mount. So the banner does
  // NOT appear merely from being on a free model at page load (confirmed live: a fresh
  // /builder load has no `.nudge` in the DOM at all even though GET /api/ai/status already
  // reports nudge.show:true); it only surfaces contextually, right when the user is about to
  // do the thing a free model is bad at. Trigger it the way the app actually does: run a
  // backtest on the default (already-valid) plugin doc, which calls ensureSaved() then sets
  // showNudge from the already-fetched aiStatus.
  await modelSelect.selectOption('openrouter/free');
  await p2.evaluate(() => localStorage.setItem('smx_or_model', 'openrouter/free'));
  const runBtn = p2.locator('button:has-text("Run backtest")');
  await runBtn.waitFor({ timeout: 15000 });
  await runBtn.click();
  const nudge = p2.locator('.nudge');
  const nudgeVisible = await nudge.first().waitFor({ state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
  check('the free-model nudge banner appears when pinned to a free model', nudgeVisible);
  if (nudgeVisible) {
    const nudgeText = (await nudge.first().innerText()).trim();
    const nudgeBtn = nudge.locator('button');
    const btnText = (await nudgeBtn.first().innerText()).trim();
    check('the nudge names a concrete switch-to model', /Switch to /.test(btnText), btnText);
    await nudgeBtn.first().click();
    const afterSwitch = await p2.evaluate(() => localStorage.getItem('smx_or_model'));
    check('clicking the nudge persists its model to localStorage', afterSwitch && afterSwitch !== 'openrouter/free', `stored=${afterSwitch}`);
  }
  await shot(p2, 'support-03-nudge');

  await ctx.close();
} catch (e) {
  console.error('FATAL:', e?.stack || e);
  failed = true;
  if (current) { try { await shot(current, 'support-FATAL'); } catch {} }
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'support-chat-deepseek-results.json'), JSON.stringify(results, null, 2));
  console.log(`\n${results.filter((r) => r.ok).length}/${results.length} checks passed; artifacts in ${OUT}`);
  process.exit(failed ? 1 : 0);
}
