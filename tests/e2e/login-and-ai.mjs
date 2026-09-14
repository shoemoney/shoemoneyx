// End-to-end: first-run wizard → master-password login → Strategy Builder AI (assist chat + agent turn).
//
// Run against an isolated desk (never the shared LAN database):
//   S=/tmp/e2e; mkdir -p $S; : > $S/e2e.sqlite
//   export APP_URL=http://localhost:8010 DB_CONNECTION=sqlite DB_DATABASE=$S/e2e.sqlite DB_URL= \
//          SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log
//   php artisan migrate --force && php artisan serve --host=127.0.0.1 --port=8010 &
//   E2E_BASE=http://127.0.0.1:8010 OPENROUTER_API_KEY=sk-or-... E2E_OUT=$S node tests/e2e/login-and-ai.mjs
//
// Needs a Playwright install: `npm i -D playwright && npx playwright install chromium`, or point
// PLAYWRIGHT_PATH at an existing node_modules/playwright. Exit 0 only when every assertion held.
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

const browser = await chromium.launch({ headless: true });
let current = null;
try {
  // ---- 1. First-run wizard --------------------------------------------------------------
  const setup = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await setup.newPage();
  current = page;
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) console.log(`  http ${r.status()} ${r.request().method()} ${r.url()}`); });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  check('root redirects to onboarding on a fresh desk', page.url().endsWith('/onboarding'), page.url());
  await shot(page, '01-onboarding');

  const pw = page.locator('input[placeholder*="master password"]');
  await pw.waitFor({ timeout: 15000 });
  await pw.fill(PASSWORD);
  await page.locator('button:has-text("Continue")').first().click();
  const keyInput = page.locator('input[placeholder="sk-or-v1-…"]');
  await keyInput.waitFor({ timeout: 15000 });
  check('master-password step advanced to openrouter step', true);

  if (!OR_KEY) throw new Error('OPENROUTER_API_KEY is required for the openrouter wizard step');
  await keyInput.fill(OR_KEY);
  await page.locator('button:has-text("Continue")').first().click();
  const coinbase = page.locator('input[type="radio"][value="coinbase"]');
  await coinbase.waitFor({ timeout: 30000 });
  check('openrouter step accepted the key (server validated it against OpenRouter)', true);

  await coinbase.check();
  await page.locator('button:has-text("Continue with coinbase")').click();
  const skip = page.locator('button:has-text("Skip for now"), button:has-text("Continue")').first();
  await skip.waitFor({ timeout: 30000 });
  await skip.click();
  const launch = page.locator('button:has-text("Start paper trading")');
  await launch.waitFor({ timeout: 15000 });
  await shot(page, '02-launch-step');
  await launch.click();
  await page.waitForLoadState('networkidle');

  const ob = await json(setup, '/api/onboarding');
  check('wizard reports completed=true via /api/onboarding', ob.status === 200 && ob.body?.completed === true, JSON.stringify(ob.body?.steps?.map(s => `${s.key}:${s.status}`)));
  check('master_password step recorded as done', ob.body?.steps?.find(s => s.key === 'master-password')?.status === 'done');
  await shot(page, '03-after-launch');
  await setup.close();

  // ---- 2. Login gate in a fresh session ---------------------------------------------------
  const fresh = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p2 = await fresh.newPage();
  current = p2;
  p2.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) console.log(`  http ${r.status()} ${r.request().method()} ${r.url()}`); });
  await p2.goto(BASE + '/', { waitUntil: 'networkidle' });
  check('fresh session is sent to /login once a master password exists', p2.url().endsWith('/login'), p2.url());
  await shot(p2, '04-login');

  await p2.locator('input[name="password"]').fill('definitely-the-wrong-password');
  await p2.locator('button:has-text("Sign in")').click();
  await p2.waitForLoadState('networkidle');
  const wrongText = await p2.locator('body').innerText();
  check('wrong password stays on /login with "Wrong password."', p2.url().endsWith('/login') && /Wrong password\./.test(wrongText), p2.url());

  const apiBefore = await json(fresh, '/api/status');
  await p2.locator('input[name="password"]').fill(PASSWORD);
  await p2.locator('button:has-text("Sign in")').click();
  await p2.waitForLoadState('networkidle');
  check('correct password leaves /login', !p2.url().endsWith('/login') && !p2.url().endsWith('/onboarding'), p2.url());
  await shot(p2, '05-after-login');

  // ---- 3. Internal AI on the Strategy Builder -------------------------------------------
  await p2.goto(BASE + '/builder', { waitUntil: 'networkidle' });
  const status = await json(fresh, '/api/ai/status');
  check('/api/ai/status is 200 and connected', status.status === 200 && status.body?.connected === true, JSON.stringify(status.body));
  const connectedChip = p2.locator('text=/Connected/');
  await connectedChip.first().waitFor({ timeout: 15000 }).catch(() => {});
  check('builder shows the Connected chip', (await connectedChip.count()) > 0);
  const models = await json(fresh, '/api/ai/models');
  const modelList = Array.isArray(models.body) ? models.body : (models.body?.free ?? models.body?.data ?? []);
  check('/api/ai/models returns a non-empty free-model list', models.status === 200 && modelList.length > 0, `${models.status} ${modelList.length} models`);
  await shot(p2, '06-builder');

  // assist chat
  const draft = p2.locator('input[placeholder="Describe your strategy…"]');
  await draft.waitFor({ timeout: 15000 });
  const assistBefore = await p2.locator('.chat .msg.assistant').count();
  await draft.fill('Reply with exactly the single word PONG and nothing else.');
  await draft.press('Enter');
  await p2.waitForFunction((n) => document.querySelectorAll('.chat .msg.assistant').length > n, assistBefore, { timeout: AI_TIMEOUT });
  const assistReply = (await p2.locator('.chat .msg.assistant').last().innerText()).trim();
  check('assist chat produced a model-written assistant reply', assistReply.length > 0 && !/^Error:|^Connect your OpenRouter|^Pick a model|^\(empty reply\)/.test(assistReply), assistReply.slice(0, 120));
  await shot(p2, '07-assist-chat');

  // agent conversation
  const agentDraft = p2.locator('input[placeholder="Describe your setup…"]');
  await agentDraft.waitFor({ timeout: 15000 });
  await agentDraft.fill('I want a simple BTC-USD mean-reversion strategy on 1h candles.');
  await agentDraft.press('Enter');
  await p2.waitForFunction(() => {
    const msgs = [...document.querySelectorAll('.chat .msg.assistant')];
    return msgs.some(m => m.closest('.chat')?.previousElementSibling?.className?.includes('phase') || msgs.length > 0);
  }, null, { timeout: AI_TIMEOUT });
  const agentMsgs = await p2.locator('.chat .msg.assistant').allInnerTexts();
  const agentReply = (agentMsgs.at(-1) || '').trim();
  const canned = /^Error:|^Connect your OpenRouter|^Pick a model|^\(no reply\)|^\(empty reply\)/;
  check('agent turn produced a model-written assistant reply', agentReply.length > 0 && !canned.test(agentReply), agentReply.slice(0, 120));
  const convs = await json(fresh, '/api/agent/conversations/1');
  check('agent conversation persisted (GET /api/agent/conversations/1 is 200)', convs.status === 200, `${convs.status}`);
  await shot(p2, '08-agent-chat');

  // ---- 4. Chart page: TradingView's own datafeed must carry the token ------------------------
  const udf = [];
  p2.on('response', (r) => { if (r.url().includes('/api/udf/')) udf.push({ path: new URL(r.url()).pathname, status: r.status() }); });
  await p2.goto(BASE + '/chart/BTC-USD', { waitUntil: 'networkidle' });
  await p2.waitForFunction(() => performance.getEntriesByType('resource').some(e => e.name.includes('/api/udf/config')), null, { timeout: 30000 }).catch(() => {});
  const cfg = udf.find(u => u.path.endsWith('/api/udf/config'));
  check('chart datafeed fetched /api/udf/config with 200', cfg?.status === 200, JSON.stringify(udf.slice(0, 6)));
  check('no /api/udf request was rejected with 401', udf.length > 0 && udf.every(u => u.status !== 401), `${udf.length} udf responses`);
  await shot(p2, '09-chart');
  await fresh.close();
} catch (e) {
  check('run completed without an unexpected error', false, String(e.message || e).split('\n')[0]);
  if (current && !current.isClosed()) {
    await shot(current, '99-failure').catch(() => {});
    const body = await current.locator('body').innerText().catch(() => '');
    console.log('  page text at failure: ' + body.replace(/\s+/g, ' ').slice(0, 500));
  }
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
console.log(`\n${results.filter(r => r.ok).length}/${results.length} checks passed; artifacts in ${OUT}`);
process.exit(failed ? 1 : 0);
