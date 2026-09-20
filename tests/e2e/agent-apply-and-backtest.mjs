// End-to-end: the built-in Strategy Agent applies a pasted π v2 strategy and backtests it.
//
// Onboards a fresh desk (mirrors tests/e2e/login-and-ai.mjs), logs back in, connects the agent
// with OPENROUTER_API_KEY the same way, then in the Strategy Builder's agent chat pastes the
// smx-pi-take-profit-v2.json definition with the instruction "apply this strategy, then backtest
// BTC-USD for 30 days with starting cash 25000". The agent chat (StrategyAgent, /api/agent/
// conversations/{id}/turn) is the only chat on this page wired to tools (strategy_json /
// run_backtest) — the separate "SMX AI assist" chatbox (/api/strategy-assist) is stateless
// free-text with no tools and cannot apply or backtest anything, so this journey drives the agent
// chat to actually exercise the capability the task describes. $25,000 (not the tool's own $1,000
// default) is the amount that actually clears the whole-contract floor on this plugin's 5%-of-
// equity entry size — see App\Ai\Tools\RunBacktestTool and config/desk.php perps.map — without it
// every candidate entry sizes under one contract and the engine opens zero trades.
//
// Assertions are all literal outcomes read back through the API (and, for the chat reply, the
// rendered DOM too), not just "the page didn't 500":
//   - a strategy_plugins row with key 'smx-pi-take-profit' exists after the turn, and the
//     strategy_json tool call that made it reported valid:true for that same key
//   - it has a version row for its current_version carrying a non-empty changelog (the agent
//     is instructed to pass one — created_by is expected to stay null, the turn route carries no
//     user email)
//   - a backtests row exists (its id comes straight off the turn's own tool_events, not guessed),
//     finished with status 'done' and no error, ran on BTC-USD, covered a ~30-day window, and its
//     stats show real candle data consumed (obs > 0) — not just "reached a terminal status"
//   - the engine was actually exercised, not just run to a terminal status doing nothing:
//     entry_count > 0, at least one closed trade, and at least one trade exited through the
//     plugin's own take_profit./stop. rule machinery rather than only end_of_test/liquidation
//   - the assistant's final chat reply, and the same message as rendered in the chat DOM (scoped
//     to the agent chat, not the page's separate free-text assist chat), both name the backtest
//     id from the tool result
//
// The agent turn drives OpenRouter's free tier, which is unpinned-model roulette: some free
// providers hard-reject the app's own tool schemas or mangle large tool-call JSON. The server this
// journey runs against must have OPENROUTER_MODEL pinned to a specific free model
// (nvidia/nemotron-3-ultra-550b-a55b:free — verified against this exact system prompt + tool
// schema set to reliably call strategy_json with a well-formed object argument, then run_backtest
// with the right products/days, in one turn) — export it before `php artisan serve`, not here.
//
// Run against an isolated desk (never the shared LAN database):
//   export OPENROUTER_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free   # before `php artisan serve`
//   E2E_BASE=http://127.0.0.1:8012 E2E_OUT=storage/e2e \
//   PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright \
//   OPENROUTER_API_KEY=sk-or-... node tests/e2e/agent-apply-and-backtest.mjs
//
// Needs a Playwright install: `npm i -D playwright && npx playwright install chromium`, or point
// PLAYWRIGHT_PATH at an existing node_modules/playwright (a global install works fine). Exit 0
// only when every assertion held.
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const pwPath = process.env.PLAYWRIGHT_PATH || 'playwright';
const { chromium } = require(pwPath);

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8012';
const OUT = process.env.E2E_OUT || path.join(process.cwd(), 'storage', 'e2e');
const PASSWORD = process.env.E2E_PASSWORD || 'e2e-master-password-2026';
const OR_KEY = process.env.OPENROUTER_API_KEY;
// A multi-tool agent turn (strategy_json then run_backtest) legitimately runs long on a free
// model — mirrors login-and-ai.mjs's AGENT_TIMEOUT rationale (observed >120s against the AMI).
const AGENT_TIMEOUT = Number(process.env.E2E_AGENT_TIMEOUT_MS || 300000);
const BT_POLL_TIMEOUT_MS = Number(process.env.E2E_BT_TIMEOUT_MS || 60000);
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

// A bare \b<id>\b matched the backtest id anywhere it appeared as digits, including inside
// "$1,000" or "1.0.0" — on a fresh sqlite desk the id is always 1, so that pattern passed even
// when the agent never named the backtest at all. The next fix required an id-shaped context
// ("backtest #1", "#1", "backtest 1") instead of a bare number, but its own bare "#<id>"
// alternative still matched a stray "#1" that had nothing to do with a backtest at all (e.g. a
// take-profit "Rung #1"). Require the literal word "backtest" and let only a short, specific gap
// (an optional "id" and/or "#", plus whitespace — never arbitrary prose) sit between it and the
// number, so "Backtest #1", "backtest 1" and "backtest id 1" all match but "Rung #1" and "the
// backtest returned 1 result" (the number there isn't the id, it's an unrelated word away) do not.
function backtestIdPattern(id) {
  return new RegExp('backtest(?:\\s+id)?\\s*#?\\s*' + id + '\\b', 'i');
}

// Belt-and-braces: strip grouped thousands ("$1,000") and dotted decimals/versions ("1.0.0",
// "v1.0") out of the text before testing, so a stray digit trapped inside one of those can never
// satisfy the id pattern even if a context prefix happens to line up right before it.
function stripNumberNoise(text) {
  return text
    .replace(/\d{1,3}(?:,\d{3})+/g, '')
    .replace(/\d+(?:\.\d+)+/g, '');
}

function backtestIdMentioned(text, id) {
  return backtestIdPattern(id).test(stripNumberNoise(text));
}

// Self-check the matcher before the browser even starts — a regression here would otherwise only
// surface as a silently-passing e2e run against a desk whose first backtest id is always 1.
{
  const falsePositive = 'Ending equity: $1,000. Version 1.0.0.';
  const truePositive = 'Backtest #1 finished';
  assert.equal(backtestIdMentioned(falsePositive, 1), false, 'must NOT match id 1 inside "$1,000. Version 1.0.0."');
  assert.equal(backtestIdMentioned(truePositive, 1), true, 'must match "Backtest #1 finished"');
  // Isolate each layer the two checks above blur together: stripNumberNoise alone (a plain "1"
  // sitting next to unrelated prose, no grouped/decimal noise involved) and backtestIdPattern
  // alone (a "#1" with no "backtest" word anywhere near it — the false positive the old
  // "(?:backtest\s*#?|#)" alternation let through).
  assert.equal(backtestIdMentioned('The backtest returned 1 result.', 1), false, 'must NOT match a bare "1" that is just an unrelated word away from "backtest"');
  assert.equal(backtestIdMentioned('Backtest complete. Equity #1,000.', 1), false, 'must NOT match id 1 hiding inside a later "#1,000" figure');
  console.log('PASS self-check: backtest-id matcher rejects numeric noise and bare "#<id>", accepts "Backtest #<id>"');
}

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
const strategyDef = JSON.parse(fs.readFileSync(STRATEGY_PATH, 'utf8'));
const PLUGIN_KEY = strategyDef.key; // smx-pi-take-profit

const ctxOpts = { viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: process.env.E2E_INSECURE === '1' };
const browser = await chromium.launch({ headless: true });
let current = null;

try {
  // ---- 1. First-run wizard (mirrors tests/e2e/login-and-ai.mjs) ---------------------------
  const setup = await browser.newContext(ctxOpts);
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
  const pwGone = (await pw.count()) === 0;
  // keyInputEnabled used to gate this check too, but the input renders visible-and-disabled for
  // one Vue flush right after the step change — a real flake, not a real failure — so the
  // password input actually being gone is the check that means the step advanced.
  check('master-password step advanced to openrouter step', pwGone, `pwInputGone=${pwGone}`);

  if (!OR_KEY) throw new Error('OPENROUTER_API_KEY is required for the openrouter wizard step');
  await keyInput.fill(OR_KEY);
  // Assert the wizard step's own response, not just the absence of an error element — that
  // absence check could never fail unless an error banner happened to render.
  const [orStepResp] = await Promise.all([
    page.waitForResponse((r) => /\/api\/onboarding\/openrouter$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30000 }),
    page.locator('button:has-text("Continue")').first().click(),
  ]);
  const orStepBody = await orStepResp.json().catch(() => null);
  check('openrouter step accepted the key (server validated it against OpenRouter)', orStepResp.status() === 200 && orStepBody?.ok === true && orStepBody?.status === 'done', JSON.stringify(orStepBody));
  const coinbase = page.locator('input[type="radio"][value="coinbase"]');
  await coinbase.waitFor({ timeout: 30000 });

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
  check('wizard reports completed=true via /api/onboarding', ob.status === 200 && ob.body?.completed === true, JSON.stringify(ob.body?.steps?.map((s) => `${s.key}:${s.status}`)));
  await shot(page, '02-after-launch');
  await setup.close();

  // ---- 2. Fresh session -> /login gate -> real login --------------------------------------
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

  // ---- 3. Agent connected? ------------------------------------------------------------------
  await p2.goto(BASE + '/builder', { waitUntil: 'networkidle' });
  const status = await json(fresh, '/api/ai/status');
  check('/api/ai/status is 200 and connected', status.status === 200 && status.body?.connected === true, JSON.stringify(status.body));
  await shot(p2, '03-builder');

  // ---- 4. Paste the π v2 strategy into the agent chat with the apply+backtest instruction ---
  const agentDraft = p2.locator('input[placeholder="Describe your setup…"]');
  await agentDraft.waitFor({ timeout: 15000 });

  // The agent's system prompt (App\Ai\Prompts\StrategyBuilderPrompt) instructs it to interview
  // every phase even when a full strategy is dumped up front, which a highly compliant free
  // model will do literally (asking a Phase-1 clarifying question instead of acting) — so the
  // instruction spells out that this JSON is already complete and to save+backtest immediately,
  // which is what actually gets a tool-calling free model to call strategy_json/run_backtest in
  // this one turn instead of stalling on an interview. The task's literal instruction opens the
  // message; this is the framing that makes it executable in a single turn.
  // starting cash 25000 (RunBacktestTool's `cash` arg — App\Ai\Tools\RunBacktestTool::run()):
  // at the plugin's own entry.size (5% of equity), $1,000 of starting cash only ever sizes a $50
  // ticket, and every product here is whole-contract-floored (config/desk.php perps.map / Perps::
  // contractsFor() — BTC-PERP's 0.01 BTC contract alone prices north of $50 at any realistic BTC
  // price), so every candidate entry gets floored to zero contracts and the engine never opens a
  // trade. $25,000 sizes a ~$1,250 ticket, clearing that floor.
  const message = `apply this strategy, then backtest BTC-USD for 30 days with starting cash 25000. This strategy definition is already complete for every phase (setup, trigger, entry, management, exit, risk) exactly as written below. Do not ask any clarifying questions and do not run the phase interview for it. Call the strategy_json tool now with this exact definition (bump: patch, changelog: a short one-sentence description of this strategy), then call run_backtest for BTC-USD over a 30-day window with cash: 25000. Once you have the backtest result, reply with a summary that writes the backtest id as #<id> (for example "Backtest #7 finished") and includes the number of trades, and stop there — do not call suggest_share, start_arena_seat, or offer anything else this turn.\n\n${JSON.stringify(strategyDef)}`;
  const [turnResp] = await Promise.all([
    p2.waitForResponse((r) => /\/api\/agent\/conversations\/\d+\/turn$/.test(r.url()) && r.request().method() === 'POST', { timeout: AGENT_TIMEOUT }),
    (async () => {
      await agentDraft.fill(message);
      await agentDraft.press('Enter');
    })(),
  ]);
  const turnBody = await turnResp.json().catch(() => null);
  await shot(p2, '04-agent-turn');

  const toolEvents = Array.isArray(turnBody?.tool_events) ? turnBody.tool_events : [];
  fs.writeFileSync(path.join(OUT, 'tool-events.json'), JSON.stringify(toolEvents, null, 2));
  console.log(`  tool_events: ${toolEvents.map((e) => e.tool).join(', ') || '(none)'}`);

  // A tool/upstream failure mid-loop no longer discards the tool_events collected before it —
  // StrategyAgent::turn() now catches it and reports the reason here instead of a bare 500.
  // Require a body at all: `!turnBody?.error` on a null turnBody (unparseable response) is
  // `!undefined` === true, which would pass this check for a turn that never returned JSON.
  check('agent turn completed without an upstream/tool error', !!turnBody && !turnBody.error, String(turnBody?.error || (turnBody ? '' : '(no JSON body)')).slice(0, 300));

  const strategyJsonEvent = toolEvents.find((e) => e.tool === 'strategy_json');
  check('agent turn called the strategy_json tool to save the plugin', !!strategyJsonEvent, JSON.stringify(strategyJsonEvent?.result));
  check('strategy_json tool call actually validated and saved the intended plugin', strategyJsonEvent?.result?.valid === true && strategyJsonEvent?.result?.key === PLUGIN_KEY, JSON.stringify(strategyJsonEvent?.result));
  const backtestEvent = toolEvents.find((e) => e.tool === 'run_backtest');
  check('agent turn called the run_backtest tool', !!backtestEvent, JSON.stringify(backtestEvent?.result));

  const canned = /^Error:|^Connect your OpenRouter|^Pick a model|^\(no reply\)|^\(empty reply\)/;
  const reply = (turnBody?.content || '').trim();
  check('agent turn produced a model-written assistant reply', reply.length > 0 && !canned.test(reply), reply.slice(0, 160));

  // The instruction asked the agent to report the backtest id — assert that literal, not just
  // "a digit somewhere", and read it back out of the rendered chat DOM too so the check named
  // "chat transcript" actually looks at the transcript rather than only the turn's JSON body.
  const backtestIdForReply = backtestEvent?.result?.id;
  const idKnown = Number.isInteger(backtestIdForReply);
  check('chat transcript (API body) mentions the backtest id from the tool result', idKnown && backtestIdMentioned(reply, backtestIdForReply), `backtestId=${backtestIdForReply} reply=${reply.slice(0, 160)}`);
  // Scoped to the agent chat wrapper (data-testid="agent-chat" on StrategyBuilder.vue's <section
  // class="agent">) — the page has a second, separate chatbox (SMX AI assist) whose own bubbles
  // also carry ".msg.assistant", so an unscoped .last() could read the wrong transcript.
  const lastAssistantBubble = (await p2.locator('[data-testid="agent-chat"] .msg.assistant').last().innerText().catch(() => '')).trim();
  check('chat transcript (rendered DOM) mentions the same backtest id', idKnown && backtestIdMentioned(lastAssistantBubble, backtestIdForReply), lastAssistantBubble.slice(0, 160));

  // ---- 5. Verify the plugin + version row via API (never only the HTTP 200 of the turn) -----
  const pluginsList = await json(fresh, '/api/strategy-plugins');
  const pluginRow = (pluginsList.body?.data ?? []).find((p) => p.key === PLUGIN_KEY);
  check(`a plugin with key '${PLUGIN_KEY}' exists`, pluginsList.status === 200 && !!pluginRow, JSON.stringify(pluginsList.body?.data?.map((p) => p.key)));

  const pluginId = pluginRow?.id ?? strategyJsonEvent?.result?.plugin_id ?? null;
  const pluginGet = pluginId ? await json(fresh, `/api/strategy-plugins/${pluginId}`) : { status: 0, body: null };
  const semver = /^\d+\.\d+\.\d+$/;
  check('GET plugin: current_version is a semver string', semver.test(pluginGet.body?.current_version || ''), pluginGet.body?.current_version);

  const versionsGet = pluginId ? await json(fresh, `/api/strategy-plugins/${pluginId}/versions`) : { status: 0, body: null };
  const versionRows = versionsGet.body?.data ?? [];
  const versionRow = versionRows.find((v) => v.version === pluginGet.body?.current_version);
  check('a version row exists for current_version, created by the agent this turn', versionsGet.status === 200 && !!versionRow, JSON.stringify(versionRows.map((v) => ({ version: v.version, created_by: v.created_by, changelog: v.changelog }))));
  if (versionRow) {
    // App\Ai\AgentContext::$userEmail is always null on this turn path, so created_by is expected
    // to stay null — but the turn's own instruction asked the agent to pass a changelog to
    // strategy_json, so a non-empty changelog is a real, failable signal of provenance.
    check('version row carries a non-empty changelog from the agent', typeof versionRow.changelog === 'string' && versionRow.changelog.trim().length > 0, JSON.stringify({ created_by: versionRow.created_by, changelog: versionRow.changelog }));
  }

  // ---- 6. Verify the backtest row reaches a terminal status ---------------------------------
  const backtestId = backtestEvent?.result?.id ?? null;
  check('run_backtest tool result carried a backtest id', Number.isInteger(backtestId), JSON.stringify(backtestEvent?.result));
  const bt = backtestId ? await pollBacktest(fresh, backtestId, BT_POLL_TIMEOUT_MS) : null;
  // A terminal status alone let a crashed backtest pass — require it actually finished, and that
  // it did the work the instruction asked for: BTC-USD, a ~30-day window, real data consumed.
  check('backtest for the applied plugin finished without error', bt?.status === 'done' && bt?.error == null, JSON.stringify({ id: backtestId, status: bt?.status, error: bt?.error }));
  check('backtest actually ran on BTC-USD, the product the instruction named', Array.isArray(bt?.products) && bt.products.includes('BTC-USD'), JSON.stringify(bt?.products));
  const btFromMs = bt?.from ? Date.parse(bt.from) : NaN;
  const btToMs = bt?.to ? Date.parse(bt.to) : NaN;
  const windowDays = Number.isFinite(btFromMs) && Number.isFinite(btToMs) ? (btToMs - btFromMs) / 86400000 : NaN;
  check('backtest window is ~30 days, the window the instruction named', Math.abs(windowDays - 30) <= 1, `from=${bt?.from} to=${bt?.to} days=${windowDays}`);
  const obs = bt?.stats?.obs;
  check('backtest engine actually consumed candle data', typeof obs === 'number' && obs > 0, JSON.stringify(bt?.stats));
  check('backtest is pinned to a version of the applied plugin', pluginId != null && versionRows.some((v) => v.id === bt?.strategy_plugin_version_id), JSON.stringify({ backtestVersionId: bt?.strategy_plugin_version_id, pluginVersionIds: versionRows.map((v) => v.id) }));

  // A terminal 'done' status with zero trades ("finished" by doing nothing) would still have
  // passed every check above — the 25000-cash instruction exists specifically to clear the
  // whole-contract floor and get the engine to actually open and manage a position, so assert
  // that literally: real entries, real closed trades, and at least one trade closed through this
  // strategy's own take_profit/stop machinery (App\Desk\Strategies\JsonPluginStrategy — rule
  // strings like "take_profit.ladder.0" / "stop.pct_from_avg", not just "end_of_test"/"liquidation").
  const entryCount = bt?.stats?.entry_count;
  check('backtest actually opened positions (entry_count > 0)', typeof entryCount === 'number' && entryCount > 0, JSON.stringify({ entry_count: entryCount }));
  const closedTrades = Array.isArray(bt?.trades) ? bt.trades : [];
  check('backtest actually closed trades (trades > 0)', closedTrades.length > 0, JSON.stringify({ trades: closedTrades.length }));
  const exitedViaStrategyRule = closedTrades.some((t) => typeof t?.rule === 'string' && (t.rule.startsWith('take_profit.') || t.rule.startsWith('stop.')));
  check("at least one trade closed via the plugin's own take_profit./stop. rule", exitedViaStrategyRule, JSON.stringify(closedTrades.map((t) => t?.rule)));

  await shot(p2, '05-final');
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
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} checks passed; artifacts in ${OUT}`);
process.exit(failed ? 1 : 0);
