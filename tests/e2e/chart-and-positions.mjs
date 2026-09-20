// End-to-end: chart with a real fill, and backward pagination past the loaded left edge.
//
// login (onboard a fresh desk first, mirrors tests/e2e/login-and-ai.mjs) -> seed one known paper
// BTC-USD position + its opening fill directly through the Position/Fill models (no manual-order
// API is exposed by routes/api.php, so this is the "open one via tinker" path the task calls
// for) -> open /chart/BTC-USD -> assert the lightweight-charts canvas has real height -> assert
// the page's own /api/udf/marks fetch carries our fixture fill as a literal 'B' mark -> assert the
// position panel shows the exact entry price the fixture recorded -> drag-pan the chart left edge
// until it crosses the backward-pagination threshold and assert a second, older /api/udf/history
// request actually landed (an earlier 'to' than the first, and a non-zero bar count back).
//
// Run against an isolated desk (never the shared LAN database) — see the worktree setup this
// journey was built against:
//   E2E_BASE=http://127.0.0.1:8013 E2E_OUT=storage/e2e \
//   PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright \
//   OPENROUTER_API_KEY=sk-or-... node tests/e2e/chart-and-positions.mjs
//
// Needs a Playwright install: `npm i -D playwright && npx playwright install chromium`, or point
// PLAYWRIGHT_PATH at an existing node_modules/playwright (a global install works fine). The fixture
// step shells out to `php artisan tinker` against the SAME repo this file lives in, so it always
// runs against whatever desk is under test. Exit 0 only when every assertion held.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const pwPath = process.env.PLAYWRIGHT_PATH || 'playwright';
const { chromium } = require(pwPath);

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8013';
const OUT = process.env.E2E_OUT || path.join(process.cwd(), 'storage', 'e2e');
const PASSWORD = process.env.E2E_PASSWORD || 'e2e-master-password-2026';
const OR_KEY = process.env.OPENROUTER_API_KEY;
const SYMBOL = 'BTC-USD';
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

// Same formatting rules as resources/js/api.js's fmt.px — replicated (not imported) because this
// is a plain Node script, not a Vite module graph. Locale is pinned to en-US on both sides (the
// number below and the browser context created further down) so a grouping-comma mismatch can't
// produce a false failure on a box whose OS locale differs from the CI/desk's.
function fmtPx(n) {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  n = Number(n);
  const d = n >= 1000 ? 2 : n >= 1 ? 4 : n >= 0.01 ? 6 : 8;
  return n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

// ---- Fixture: one known paper BTC-USD position + its opening fill -----------------------------
// routes/api.php exposes no manual-order/enter endpoint (only /desk/cycle, which runs the real
// strategy and would pick its own product/price/time non-deterministically), so this opens the
// position directly through the models, the way the task's fallback instructs. The entry price
// and time are read off the last seeded 1m candle rather than hardcoded, so the fixture is a real
// fill sitting inside whatever candle window this desk actually backfilled, on any run.
function seedFixture() {
  const php = `
$product = '${SYMBOL}';
// idempotent: drop any earlier e2e fixture first so reruns don't pile up phantom positions.
\\App\\Models\\Fill::whereIn('position_id', \\App\\Models\\Position::where('strategy', 'e2e-fixture')->pluck('id'))->delete();
\\App\\Models\\Position::where('strategy', 'e2e-fixture')->delete();

$candle = \\App\\Models\\Candle::where('product_id', $product)->where('timeframe', '1m')->orderByDesc('candle_start')->first();
if (!$candle) { echo 'E2E_FIXTURE_ERROR no-1m-candle-for-' . $product; exit(1); }

$entryPrice = round((float) $candle->close, 2);
$openedAt = $candle->candle_start;
$entryUsd = 500.00;
$feesUsd = 3.00;
$qty = round(($entryUsd - $feesUsd) / $entryPrice, 8);

$position = \\App\\Models\\Position::create([
    'mode' => 'paper', 'strategy' => 'e2e-fixture', 'product_id' => $product, 'status' => 'open',
    'quantity' => $qty, 'entry_price' => $entryPrice, 'entry_usd' => $entryUsd, 'fees_usd' => $feesUsd,
    'peak_price' => $entryPrice, 'last_price' => $entryPrice, 'opened_at' => $openedAt,
    'meta' => ['e2e' => true, 'decision_price' => $entryPrice],
]);
$fill = \\App\\Models\\Fill::create([
    'position_id' => $position->id, 'mode' => 'paper', 'product_id' => $product, 'side' => 'BUY', 'kind' => 'entry',
    'requested_usd' => $entryUsd, 'filled_usd' => $entryUsd, 'filled_qty' => $qty,
    'decision_price' => $entryPrice, 'fill_price' => $entryPrice, 'fee_usd' => $feesUsd,
    'fee_pct' => $feesUsd / $entryUsd, 'status' => 'filled',
]);
// Fill.created_at (not opened_at) is what UdfController::marks() filters on — back-date it to the
// same candle so the mark falls inside the from/to window Chart.vue actually requests.
\\Illuminate\\Support\\Facades\\DB::table('fills')->where('id', $fill->id)->update(['created_at' => $openedAt, 'updated_at' => $openedAt]);

echo 'E2E_FIXTURE ' . json_encode([
    'position_id' => $position->id,
    'fill_id' => $fill->id,
    'entry_price' => $entryPrice,
    'quantity' => $qty,
    'opened_at' => (string) $openedAt,
    'epoch' => (int) \\Illuminate\\Support\\Carbon::parse($openedAt, 'UTC')->timestamp,
]);
`;
  const out = execFileSync('php', ['artisan', 'tinker'], { cwd: REPO_ROOT, input: php, encoding: 'utf8' });
  const m = out.match(/E2E_FIXTURE (\{.*\})/);
  if (!m) throw new Error('tinker fixture seed did not report E2E_FIXTURE json: ' + out.slice(0, 800));
  return JSON.parse(m[1]);
}

const ctxOpts = { viewport: { width: 1280, height: 900 }, locale: 'en-US', ignoreHTTPSErrors: process.env.E2E_INSECURE === '1' };
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

try {
  const fixture = seedFixture();
  check('fixture: a paper BTC-USD position + fill were seeded via the models', Number.isInteger(fixture.position_id) && Number.isInteger(fixture.fill_id), JSON.stringify(fixture));

  const { fresh, p2 } = await onboardAndLogin();

  // ---- Chart page: track every /api/udf/history and /api/udf/marks response from first byte ---
  const historyResponses = [];
  const marksResponses = [];
  p2.on('response', async (r) => {
    const u = r.url();
    if (u.includes('/api/udf/history')) {
      const qs = new URL(u).searchParams;
      let body = null;
      try { body = await r.json(); } catch {}
      historyResponses.push({ to: Number(qs.get('to')), from: Number(qs.get('from')), countback: Number(qs.get('countback')), status: r.status(), tLen: Array.isArray(body?.t) ? body.t.length : null, s: body?.s });
    } else if (u.includes('/api/udf/marks')) {
      let body = null;
      try { body = await r.json(); } catch {}
      marksResponses.push({ status: r.status(), body });
    }
  });

  await p2.goto(BASE + `/chart/${SYMBOL}`, { waitUntil: 'networkidle' });

  // ---- Canvas: lightweight-charts must actually be rendering, not a collapsed 0px container ---
  const canvas = p2.locator('.cl-chart-viewport canvas').first();
  await canvas.waitFor({ timeout: 20000 });
  await canvas.scrollIntoViewIfNeeded();
  await p2.waitForFunction(() => performance.getEntriesByType('resource').some((e) => e.name.includes('/api/udf/history')), null, { timeout: 20000 }).catch(() => {});
  await p2.waitForTimeout(800); // let the initial candle/volume/marker paint settle
  const canvasBox = await canvas.boundingBox().catch(() => null);
  check('chart canvas (.cl-chart-viewport canvas) has real height > 200px', (canvasBox?.height ?? 0) > 200, JSON.stringify(canvasBox));

  check('the first /api/udf/history request for BTC-USD succeeded with real bars', historyResponses.length > 0 && historyResponses[0].status === 200 && historyResponses[0].s === 'ok' && historyResponses[0].tLen > 0, JSON.stringify(historyResponses[0]));

  // ---- Marks: the page's own /api/udf/marks fetch must carry our fixture fill as a 'B' mark ----
  // lightweight-charts paints markers straight onto the canvas raster (no per-marker DOM node to
  // query, and the candlestick series' own upColor is the identical green used for a buy marker,
  // so pixel-scanning the canvas cannot tell the two apart). The literal, falsifiable proxy for
  // "the B marker is drawn" is the exact upstream payload markers.setMarkers() is fed: assert the
  // page's real /api/udf/marks response — not a stub, the same fetch mapUdfMarks() consumes —
  // contains our known fill id at our known time labelled 'B'.
  let matchingMark = null;
  for (let i = 0; i < 40 && !matchingMark; i++) {
    for (const m of marksResponses) {
      const idx = (m.body?.id || []).findIndex((id) => String(id) === String(fixture.fill_id));
      if (idx !== -1) { matchingMark = { time: m.body.time[idx], label: m.body.label[idx] }; break; }
    }
    if (!matchingMark) await p2.waitForTimeout(250);
  }
  check(`chart's own /api/udf/marks fetch carries fixture fill #${fixture.fill_id} as label 'B' at t=${fixture.epoch}`, matchingMark?.label === 'B' && matchingMark?.time === fixture.epoch, JSON.stringify({ matchingMark, marksResponsesSeen: marksResponses.length }));

  // ---- Position panel: literal entry-price text match, formatted the way the page formats it --
  const expectedEntry = fmtPx(fixture.entry_price);
  const panel = p2.locator('.mw-position-instrument');
  await panel.waitFor({ timeout: 15000 });
  await p2.waitForFunction(
    (needle) => document.querySelector('.mw-position-instrument')?.innerText.includes(needle),
    expectedEntry,
    { timeout: 15000 },
  ).catch(() => {});
  const panelText = (await panel.innerText()).replace(/\s+/g, ' ');
  check(`position panel shows status "open"`, /\bopen\b/i.test(panelText), panelText.slice(0, 200));
  check(`position panel shows the exact entry price "${expectedEntry}"`, panelText.includes(expectedEntry), panelText.slice(0, 200));
  await shot(p2, '01-chart-with-fill');

  // ---- Backward pagination: drag-pan past the loaded left edge, then verify a second, older
  //      /api/udf/history request actually landed (not just a poll refresh of the right edge). ---
  const firstHistoryToSet = new Set(historyResponses.filter((h) => h.status === 200 && h.s === 'ok').map((h) => h.to));
  const firstTo = Math.min(...firstHistoryToSet);
  const box = await canvas.boundingBox();
  const cy = box.y + box.height / 2;
  const startX = box.x + box.width * 0.85; // grab near the loaded (right/recent) edge
  await p2.mouse.move(startX, cy);
  await p2.mouse.down();
  let olderHistory = null;
  // Panning right drags the chart's content rightward, revealing older bars from the left edge —
  // repeat a large drag in a few big strokes (a real chart needs ~1000+ loaded bars of travel,
  // not one small nudge) until the older-window request actually shows up in the network log.
  for (let i = 0; i < 10 && !olderHistory; i++) {
    await p2.mouse.move(startX + 1400 * (i + 1), cy, { steps: 12 });
    await p2.waitForTimeout(300);
    olderHistory = historyResponses.find((h) => h.status === 200 && Number.isFinite(h.to) && h.to < firstTo);
  }
  await p2.mouse.up();
  if (!olderHistory) {
    // Direction guess was wrong for this build — try the opposite drag before giving up.
    await p2.mouse.move(startX, cy);
    await p2.mouse.down();
    for (let i = 0; i < 10 && !olderHistory; i++) {
      await p2.mouse.move(startX - 1400 * (i + 1), cy, { steps: 12 });
      await p2.waitForTimeout(300);
      olderHistory = historyResponses.find((h) => h.status === 200 && Number.isFinite(h.to) && h.to < firstTo);
    }
    await p2.mouse.up();
  }
  await shot(p2, '02-after-pan');
  check('panning past the left edge triggered a second /api/udf/history request with an earlier "to" than the first', !!olderHistory, JSON.stringify({ firstTo, olderHistory }));
  check('the older-window request came back with real bars (candle count grew)', (olderHistory?.tLen ?? 0) > 0, JSON.stringify(olderHistory));

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
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} checks passed; artifacts in ${OUT}`);
process.exit(failed ? 1 : 0);
