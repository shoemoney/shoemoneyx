// Real browser/API/DB paths with the explicitly enabled outbound HTTP fixture.
// Run after password-security.mjs; E2E_PASSWORD must be its final rotated password.
// Seed only the disposable DB first: E2E_FIXTURE_MODE=1 php tests/e2e/fixtures/seed-market.php.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const base = process.env.E2E_BASE || 'https://127.0.0.1:18444';
const password = process.env.E2E_PASSWORD;
assert(password, 'E2E_PASSWORD must explicitly identify the disposable owner credential');
assert(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname), 'Use a local disposable desk');
const out = process.env.E2E_OUT || 'output/playwright/application-smoke';
fs.mkdirSync(out, { recursive: true });
const checks = [];
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_BROWSER_CHANNEL ? { channel: process.env.E2E_BROWSER_CHANNEL } : {}) });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
function pass(name) { checks.push({ name, ok: true }); console.log(`PASS ${name}`); }
async function api(method, url, body) {
  return page.evaluate(async ({ method, url, body }) => {
    const response = await fetch(url, {
      method, headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    return { status: response.status, body: await response.json() };
  }, { method, url, body });
}

try {
  await page.goto(base + '/login', { waitUntil: 'networkidle' });
  await page.getByLabel('Master password', { exact: true }).fill(password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
  assert.equal((await api('GET', '/api/status')).status, 200);
  pass('Real login cookie authorizes the application without a token header');

  // No browser API interception: the actual onboarding controller validates a
  // synthetic provider key through the guarded server-side HTTP fixture.
  let state = await api('GET', '/api/onboarding');
  if (!state.body.completed) {
    await page.goto(base + '/onboarding', { waitUntil: 'networkidle' });
    if (state.body.next_step === 'openrouter') {
      await page.getByLabel('OpenRouter API key', { exact: true }).fill('sk-or-v1-e2e-local-fixture-only');
      await page.getByRole('button', { name: 'Continue', exact: true }).click();
      await page.getByRole('button', { name: /Continue with coinbase/i }).waitFor();
    }
    state = await api('GET', '/api/onboarding');
    if (state.body.next_step === 'exchange') {
      await page.locator('input[type="radio"][value="coinbase"]').check();
      await page.getByRole('button', { name: /Continue with coinbase/i }).click();
      await page.getByRole('button', { name: 'Skip for now', exact: true }).waitFor();
    }
    state = await api('GET', '/api/onboarding');
    if (state.body.next_step === 'strategy-import') {
      await page.getByRole('button', { name: 'Skip for now', exact: true }).click();
      await page.getByRole('button', { name: 'Start paper trading', exact: true }).waitFor();
    }
    await page.getByRole('button', { name: 'Start paper trading', exact: true }).click();
    await page.waitForURL('**/dashboard');
  }
  state = await api('GET', '/api/onboarding');
  assert.equal(state.body.completed, true);
  assert.equal((await api('GET', '/api/status')).body.health?.mode, 'paper');
  pass('UI completes onboarding and launches only the disposable paper desk');
  await page.screenshot({ path: path.join(out, 'dashboard.png'), fullPage: true });

  assert.equal((await api('PUT', '/api/settings', { key: 'perps.whole_contracts', value: false })).status, 200);
  await page.goto(base + '/builder', { waitUntil: 'networkidle' });
  const definition = JSON.parse(fs.readFileSync(new URL('../../resources/strategies/examples/smx-pi-take-profit-v2.json', import.meta.url)));
  definition.key = `e2e-runtime-${Date.now()}`;
  definition.meta.name = 'Disposable E2E pipeline fixture';
  definition.meta.description = 'Synthetic acceptance fixture; no trading edge is asserted.';
  definition.signals.momentum.all[0].value = -100;
  definition.signals.momentum.all[1].value = 0;
  definition.signals.not_overbought.all[0].value = 101;
  await page.locator('textarea').fill(JSON.stringify(definition, null, 2));
  const validatePromise = page.waitForResponse(r => r.url().endsWith('/api/strategy-plugins/validate') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Validate', exact: true }).click();
  const validated = await (await validatePromise).json();
  assert.equal(validated.valid, true, JSON.stringify(validated.errors));
  const savePromise = page.waitForResponse(r => r.url().endsWith('/api/strategy-plugins') && r.request().method() === 'POST');
  await page.getByRole('button', { name: /^Save(?: \(v.*\))?$/ }).click();
  const saved = await (await savePromise).json();
  const pluginId = saved.plugin?.id;
  assert(Number.isInteger(pluginId), JSON.stringify(saved));
  const plugin = await api('GET', `/api/strategy-plugins/${pluginId}`);
  assert.equal(plugin.body.key, definition.key);
  const versions = await api('GET', `/api/strategy-plugins/${pluginId}/versions`);
  assert(versions.body.data.some(v => v.version === plugin.body.current_version));
  pass('Builder validation/save creates a real immutable strategy version');

  await page.locator('input[title="days"]').fill('7');
  await page.locator('input[title="cash"]').fill('1000');
  await page.getByPlaceholder('BTC-USD,ETH-USD', { exact: true }).fill('BTC-USD');
  const backtestPromise = page.waitForResponse(r => /\/api\/strategy-plugins\/\d+\/backtest$/.test(r.url()) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Run backtest', exact: true }).click();
  const started = await (await backtestPromise).json();
  assert(Number.isInteger(started.id), JSON.stringify(started));
  let backtest;
  for (let i = 0; i < 100; i++) {
    backtest = (await api('GET', `/api/backtests/${started.id}`)).body;
    if (['done', 'error'].includes(backtest.status)) break;
    await new Promise(resolve => setTimeout(resolve, 300));
  }
  assert.equal(backtest.status, 'done', JSON.stringify({ status: backtest.status, error: backtest.error }));
  assert(Number.isFinite(Number(backtest.ending_equity)));
  assert(backtest.strategy_plugin_version_id, 'Backtest pins the saved immutable version');
  assert(Array.isArray(backtest.trades) && backtest.trades.length > 0, 'Synthetic tape must exercise actual fills');
  assert(backtest.trades.some(t => /^(take_profit\.|stop\.)/.test(t.rule)), 'A real management rule must execute');
  fs.writeFileSync(path.join(out, 'backtest.json'), JSON.stringify(backtest, null, 2));
  pass('UI backtest completes with real synthetic-tape trades and a pinned version');

  const input = page.getByPlaceholder('Describe your setup…', { exact: true });
  const turnPromise = page.waitForResponse(r => /\/api\/agent\/conversations\/\d+\/turn$/.test(r.url()) && r.request().method() === 'POST');
  await input.fill('Read the current arena scoreboard. Do not modify settings or place orders.');
  await input.press('Enter');
  const turnResponse = await turnPromise;
  const turn = await turnResponse.json();
  assert.equal(turnResponse.status(), 200, JSON.stringify(turn));
  assert.equal(turn.error, null);
  assert(turn.tool_events?.some(event => (event.tool ?? event.name) === 'arena_scoreboard'), JSON.stringify(turn.tool_events));
  assert(typeof turn.content === 'string' && turn.content.length > 0, JSON.stringify(turn));
  await page.locator('.msg.assistant').filter({ hasText: turn.content }).last().waitFor({ state: 'visible' });
  const id = turnResponse.url().match(/conversations\/(\d+)\/turn/)[1];
  const persisted = await api('GET', `/api/agent/conversations/${id}`);
  assert(persisted.body.messages.some(message => message.role === 'tool'), 'Actual tool result is persisted');
  const usage = await api('GET', '/api/ai/usage');
  assert.equal(usage.status, 200);
  pass('Actual SDK graph executes its read-only tool, persists the result and records provider-fixture usage');
  assert.deepEqual(errors, [], 'No browser page errors');
  assert(!/\[object Object\]|\bNaN\b|\{[A-Za-z_][A-Za-z0-9_.]*\}/.test(await page.locator('body').innerText()), 'Rendered text has no unresolved placeholders');
  await page.screenshot({ path: path.join(out, 'builder-agent.png'), fullPage: true });
  pass('Rendered application smoke has no JavaScript errors or unresolved placeholders');
} catch (error) {
  checks.push({ name: 'application smoke', ok: false, detail: error.stack });
  console.error(error.stack);
  await page.screenshot({ path: path.join(out, 'failure.png'), fullPage: true }).catch(() => {});
  process.exitCode = 1;
} finally {
  fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify({ fixture: 'outbound HTTP only; synthetic market; no live trading/provider proof', checks, errors }, null, 2));
  await browser.close();
}
