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
// The agent turn runs a multi-tool StrategyAgent loop; on free models that legitimately exceeds
// the assist-chat budget (observed >120 s against the AMI, whose endpoints allow 300 s).
const AGENT_TIMEOUT = Number(process.env.E2E_AGENT_TIMEOUT_MS || 300000);
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

// A marketplace AMI boots with the EC2 instance ID as the bootstrap master password, so the login
// gate comes BEFORE the wizard there. Set E2E_BOOTSTRAP_PASSWORD to walk that path; leave it unset
// for a bare desk whose root goes straight to onboarding. E2E_INSECURE=1 accepts a self-signed cert.
const BOOTSTRAP = process.env.E2E_BOOTSTRAP_PASSWORD || '';
const ctxOpts = { viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: process.env.E2E_INSECURE === '1' };

const browser = await chromium.launch({ headless: true });
let current = null;
try {
  // ---- 1. First-run wizard --------------------------------------------------------------
  const setup = await browser.newContext(ctxOpts);
  const page = await setup.newPage();
  current = page;
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) console.log(`  http ${r.status()} ${r.request().method()} ${r.url()}`); });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  if (BOOTSTRAP) {
    check('AMI desk gates on /login before the wizard', page.url().endsWith('/login'), page.url());
    const hint = await page.locator('body').innerText();
    check('login page shows the instance-ID hint', /instance ID/i.test(hint));
    await page.locator('input[name="password"]').fill(BOOTSTRAP);
    await page.locator('button:has-text("Sign in")').click();
    await page.waitForLoadState('networkidle');
  }
  check('root redirects to onboarding on a fresh desk', page.url().endsWith('/onboarding'), page.url());
  await shot(page, '01-onboarding');

  const pw = page.locator('input[placeholder*="master password"]');
  await pw.waitFor({ timeout: 15000 });
  await pw.fill(PASSWORD);
  await page.locator('button:has-text("Continue")').first().click();
  const keyInput = page.locator('input[placeholder="sk-or-v1-…"]');
  await keyInput.waitFor({ timeout: 15000 });
  // The wizard renders exactly one step's markup at a time (v-else-if), so a real transition
  // both retires the master-password input from the DOM and leaves the openrouter key input
  // enabled (busy flips back to false only after /api/onboarding/master-password + refresh()
  // both resolved) — neither is implied merely by keyInput having become visible.
  const pwGone = (await pw.count()) === 0;
  const keyInputEnabled = await keyInput.isEnabled();
  check('master-password step advanced to openrouter step', pwGone && keyInputEnabled, `pwInputGone=${pwGone} keyInputEnabled=${keyInputEnabled}`);

  if (!OR_KEY) throw new Error('OPENROUTER_API_KEY is required for the openrouter wizard step');
  await keyInput.fill(OR_KEY);
  await page.locator('button:has-text("Continue")').first().click();
  const coinbase = page.locator('input[type="radio"][value="coinbase"]');
  await coinbase.waitFor({ timeout: 30000 });
  // submitOpenRouter() only calls refresh() (which advances currentStep to 'exchange') after
  // the POST to /api/onboarding/openrouter resolves without throwing; a rejected/invalid key
  // throws first, leaves currentStep on 'openrouter' and renders the error banner instead — so
  // an absent banner here is evidence the server-side OpenRouter validation actually passed,
  // not just that some step rendered a coinbase radio.
  const orError = page.locator('.mw-error[role="alert"]');
  const orErrorCount = await orError.count();
  check('openrouter step accepted the key (server validated it against OpenRouter)', orErrorCount === 0, `errorBannerCount=${orErrorCount}`);

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
  const fresh = await browser.newContext(ctxOpts);
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
  let convId = null;
  p2.on('request', (r) => {
    const m = r.url().match(/\/api\/agent\/conversations\/(\d+)\/turn$/);
    if (m) convId = Number(m[1]);
  });
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
  // Two chats share the .chat/.msg markup: the SMX assist chat is the first .chat, the agent
  // conversation the second. Scope every count to its own container or the assist reply passes
  // the agent check before the agent has even sent.
  const assistChat = p2.locator('.chat').nth(0);
  const agentChat = p2.locator('.chat').nth(1);
  const draft = p2.locator('input[placeholder="Describe your strategy…"]');
  await draft.waitFor({ timeout: 15000 });
  const assistBefore = await assistChat.locator('.msg.assistant').count();
  await draft.fill('Reply with exactly the single word PONG and nothing else.');
  await draft.press('Enter');
  await p2.waitForFunction((n) => document.querySelectorAll('.chat')[0]?.querySelectorAll('.msg.assistant').length > n, assistBefore, { timeout: AI_TIMEOUT });
  const assistReply = (await assistChat.locator('.msg.assistant').last().innerText()).trim();
  check('assist chat produced a model-written assistant reply', assistReply.length > 0 && !/^Error:|^Connect your OpenRouter|^Pick a model|^\(empty reply\)/.test(assistReply), assistReply.slice(0, 120));
  await shot(p2, '07-assist-chat');

  // agent conversation
  const agentDraft = p2.locator('input[placeholder="Describe your setup…"]');
  await agentDraft.waitFor({ timeout: 15000 });

  const agentBefore = await agentChat.locator('.msg.assistant').count();
  await agentDraft.fill('I want a simple BTC-USD mean-reversion strategy on 1h candles.');
  await agentDraft.press('Enter');
  await p2.waitForFunction((n) => document.querySelectorAll('.chat')[1]?.querySelectorAll('.msg.assistant').length > n, agentBefore, { timeout: AGENT_TIMEOUT });
  const agentReply = (await agentChat.locator('.msg.assistant').last().innerText()).trim();
  const canned = /^Error:|^Connect your OpenRouter|^Pick a model|^\(no reply\)|^\(empty reply\)/;
  check('agent turn produced a model-written assistant reply', agentReply.length > 0 && !canned.test(agentReply), agentReply.slice(0, 120));
  const convStatus = convId === null ? null : await p2.evaluate(async (id) => {
    const token = localStorage.getItem('desk_token') || '';
    const r = await fetch(`/api/agent/conversations/${id}`, { headers: { Accept: 'application/json', 'X-Desk-Token': token } });
    return r.status;
  }, convId);
  check('agent conversation persisted (GET by the id the page created is 200)', convStatus === 200, `id=${convId} status=${convStatus}`);
  await shot(p2, '08-agent-chat');

  // ---- 4. Chart page: lightweight-charts' history/marks fetches must carry the token ---------
  const udf = [];
  p2.on('response', (r) => { if (r.url().includes('/api/udf/')) udf.push({ path: new URL(r.url()).pathname, status: r.status() }); });
  await p2.goto(BASE + '/chart/BTC-USD', { waitUntil: 'networkidle' });
  await p2.waitForFunction(() => performance.getEntriesByType('resource').some(e => e.name.includes('/api/udf/history')), null, { timeout: 30000 }).catch(() => {});
  const history = udf.find(u => u.path.endsWith('/api/udf/history'));
  check('chart fetched /api/udf/history with 200', history?.status === 200, JSON.stringify(udf.slice(0, 6)));
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
