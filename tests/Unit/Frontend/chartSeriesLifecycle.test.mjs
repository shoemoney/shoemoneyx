import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import { ref, reactive, computed, watch, nextTick, effectScope } from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { cursorTarget } from '../../../resources/js/components/chart/marketLens.js';
import { deskHeaders } from '../../../resources/js/api.js';
import { mapUdfHistory, mapUdfMarks, mergeOlderHistory, mergeMarks } from '../../../resources/js/components/chart/udfMapping.js';
const base = new URL('../../../', import.meta.url);
const requests = [];
const api = { get(url) { return new Promise((resolve, reject) => requests.push({ url, resolve, reject })); } };
let timers = new Map(), timerId = 0;
globalThis.setInterval = (fn) => { const id = ++timerId; timers.set(id, fn); return id; };
globalThis.clearInterval = (id) => timers.delete(id);
globalThis.document = { hidden: false, listeners: new Set(), addEventListener(_n, fn) { this.listeners.add(fn); }, removeEventListener(_n, fn) { this.listeners.delete(fn); }, emit() { for (const fn of [...this.listeners]) fn(); } };
globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }), addEventListener() {}, removeEventListener() {} };
globalThis.localStorage = { getItem: (key) => (key === 'desk_token' ? 'token' : null) };
function deferredRequests() { return requests.splice(0); }
async function instance(file, initialProps, exposed) {
    const { descriptor } = parse(await fs.readFile(new URL(file, base), 'utf8'), { filename: file });
    const compiled = compileScript(descriptor, { id: file });
    let source = descriptor.scriptSetup.content;
    // Remove imports by their parsed spans, including multiline and side-effect imports.
    for (const node of compiled.scriptSetupAst.filter(node => node.type === 'ImportDeclaration').reverse()) {
        source = source.slice(0, node.start) + source.slice(node.end);
    }
    const mounted = [], unmounted = [], scope = effectScope(), props = reactive(initialProps);
    const create = new Function(
        'ref', 'computed', 'watch', 'onMounted', 'onBeforeUnmount', 'onUnmounted', 'defineProps', 'useRouter', 'useRoute',
        'api', 'cursorTarget', 'deskHeaders', 'createChart', 'CandlestickSeries', 'HistogramSeries', 'CrosshairMode',
        'createSeriesMarkers', 'mapUdfHistory', 'mapUdfMarks', 'mergeOlderHistory', 'mergeMarks',
        `${source}\nreturn {${exposed}}`,
    );
    const result = scope.run(() => create(
        ref, computed, watch, (fn) => mounted.push(fn), (fn) => unmounted.push(fn), (fn) => unmounted.push(fn),
        () => props, () => ({ replace() {} }), () => ({ query: {} }), api, cursorTarget, deskHeaders,
        createChart, CandlestickSeries, HistogramSeries, CrosshairMode, createSeriesMarkers, mapUdfHistory, mapUdfMarks,
        mergeOlderHistory, mergeMarks,
    ));
    return { result, props, mounted, unmount() { for (const fn of unmounted) fn(); scope.stop(); } };
}

// --- lightweight-charts fakes: just enough surface for Chart.vue's calls -----------------------
const CandlestickSeries = 'candlestick', HistogramSeries = 'histogram';
const CrosshairMode = { Normal: 0 };
let failCreate = false;
const charts = [];
class FakeSeries {
    constructor(kind, options) { this.kind = kind; this.options = options; this._data = []; this._coordPrice = 125; this._priceScale = { applyOptions() {} }; }
    setData(rows) { this._data = rows; }
    data() { return this._data; }
    update(bar) { const i = this._data.findIndex((b) => b.time === bar.time); if (i >= 0) this._data[i] = bar; else this._data.push(bar); }
    coordinateToPrice() { return this._coordPrice; }
    priceScale() { return this._priceScale; }
}
class FakeTimeScale {
    constructor() { this.handlers = new Set(); this.logicalHandlers = new Set(); this.visibleRange = { from: 300, to: 600 }; }
    subscribeVisibleTimeRangeChange(fn) { this.handlers.add(fn); }
    unsubscribeVisibleTimeRangeChange(fn) { this.handlers.delete(fn); }
    subscribeVisibleLogicalRangeChange(fn) { this.logicalHandlers.add(fn); }
    unsubscribeVisibleLogicalRangeChange(fn) { this.logicalHandlers.delete(fn); }
    getVisibleRange() { return this.visibleRange; }
    emit(range) { this.visibleRange = range; for (const fn of [...this.handlers]) fn(range); }
    emitLogical(range) { for (const fn of [...this.logicalHandlers]) fn(range); }
}
class FakePriceScale { constructor() { this.visibleRange = { from: 100, to: 200 }; } getVisibleRange() { return this.visibleRange; } }
class FakeChart {
    constructor(container, options) {
        if (failCreate) throw Error('Missing chart engine');
        this.container = container; this.options = options; this.series = [];
        this.crosshairHandlers = new Set(); this._timeScale = new FakeTimeScale(); this._priceScale = new FakePriceScale();
        this.removed = false; charts.push(this);
    }
    addSeries(kind, options) { const s = new FakeSeries(kind, options); this.series.push(s); return s; }
    applyOptions(options) { this.options = { ...this.options, ...options }; }
    subscribeCrosshairMove(fn) { this.crosshairHandlers.add(fn); }
    unsubscribeCrosshairMove(fn) { this.crosshairHandlers.delete(fn); }
    timeScale() { return this._timeScale; }
    priceScale() { return this._priceScale; }
    emitCrosshair(param) { for (const fn of [...this.crosshairHandlers]) fn(param); }
    remove() { this.removed = true; }
}
function createChart(container, options) { return new FakeChart(container, options); }
const markersApis = [];
function createSeriesMarkers(series, initial) { const m = { series, markers: initial, setMarkers(next) { this.markers = next; } }; markersApis.push(m); return m; }

// --- fetch fakes: Chart.vue talks straight to /api/udf/{history,marks} now, no datafeed class --
const fetches = [];
globalThis.fetch = (url, init) => new Promise((resolve, reject) => fetches.push({ url, init, resolve, reject }));
function nextFetch() { const f = fetches.shift(); if (!f) throw new Error('expected a queued fetch'); return f; }
function respondJson(entry, body, ok = true) { entry.resolve({ ok, json: async () => body }); }
async function settle() { for (let i = 0; i < 6; i++) await Promise.resolve(); await nextTick(); }
function history(times, opens = times.map(() => 100)) {
    return { s: 'ok', t: times, o: opens, h: opens.map((o) => o + 2), l: opens.map((o) => o - 1), c: opens.map((o) => o + 1), v: times.map(() => 10) };
}

const page = await instance('resources/js/pages/Chart.vue', { symbol: 'BTC-USD' }, 'symbol,interval,range,products,error,guardian,effects,loading,chartContainer');
const aims = []; page.result.guardian.value = { aim: (t) => aims.push(t), leave() {} };
const mountedPromise = page.mounted[0]();
assert.equal(charts.length, 1, 'mount() creates exactly one chart');
const lwChart = charts[0];
assert.deepEqual(lwChart.series.map((s) => s.kind), ['candlestick', 'histogram'], 'candlestick series is added before the volume histogram');
assert.equal(page.result.loading.value, true);

const initial = nextFetch();
assert.match(initial.url, /\/api\/udf\/history\?.*symbol=BTC-USD.*resolution=1/);
assert.equal(initial.init.headers['X-Desk-Token'], 'token', 'the desk token rides along on every history fetch');
respondJson(initial, history([100, 160, 220]));
await settle();
assert.equal(page.result.loading.value, false, 'loading clears once the first bar set lands');
assert.deepEqual(lwChart.series[0].data().map((c) => c.time), [100, 160, 220]);
assert.equal(timers.size, 1, 'a poll timer is armed after the first successful load');
const initialMarks = nextFetch();
assert.match(initialMarks.url, /\/api\/udf\/marks\?.*symbol=BTC-USD/);
respondJson(initialMarks, { id: ['seed-1'], time: [150], label: ['B'] });
await settle();
assert.deepEqual(markersApis.at(-1).markers.map((m) => m.id), ['seed-1']);
console.log('PASS: mount creates the candle+volume series, loads the first history window, and arms the realtime poll');

const pollCallback = [...timers.values()][0];
pollCallback();
const pollFetch = nextFetch();
assert.match(pollFetch.url, /\/api\/udf\/history\?.*symbol=BTC-USD.*countback=5/, 'the realtime poll asks for a handful of bars, not the full window');
respondJson(pollFetch, history([220, 280])); // 220 is the already-loaded tail bar; 280 is new
await settle();
assert.deepEqual(lwChart.series[0].data().map((c) => c.time), [100, 160, 220, 280], 'the poll updates the tail bar in place and appends the new one');
respondJson(nextFetch(), { id: [], time: [], label: [] }); // this poll tick has no new fills
await settle();
assert.deepEqual(markersApis.at(-1).markers.map((m) => m.id), ['seed-1'], 'an empty poll response must never wipe markers already on the chart');
console.log('PASS: the realtime poll re-fetches only the tail and merges markers instead of replacing them');

lwChart.timeScale().emit({ from: 10, to: 20 }); // matches the currently rendered BTC-USD:1 series
assert.deepEqual(page.result.range.value, { from: 10, to: 20 });

page.result.symbol.value = 'ETH-USD';
await nextTick();
assert.equal(page.result.range.value, null, 'switching series clears the stale visible range immediately');
assert.equal(timers.size, 0, 'the previous poll timer is cleared before the new series loads');
const ethFetch = nextFetch();
assert.match(ethFetch.url, /symbol=ETH-USD/);
page.result.symbol.value = 'SOL-USD';
await nextTick();
const solFetch = nextFetch();
assert.match(solFetch.url, /symbol=SOL-USD/);
respondJson(ethFetch, history([1, 2])); // stale response for a selection the user has already left
await settle();
assert.deepEqual(lwChart.series[0].data().map((c) => c.time), [100, 160, 220, 280], 'the stale ETH response never overwrites the chart');
respondJson(solFetch, history([500, 560]));
await settle();
respondJson(nextFetch(), { id: [], time: [], label: [] }); // SOL marks
await settle();
assert.deepEqual(lwChart.series[0].data().map((c) => c.time), [500, 560], 'the winning SOL response is the one that renders');
assert.equal(timers.size, 1);
console.log('PASS: rapid symbol changes issue fresh requests and a stale response is discarded by request id');

lwChart._timeScale.visibleRange = { from: 300, to: 600 };
lwChart._priceScale.visibleRange = { from: 100, to: 200 };
lwChart.series[0]._coordPrice = 125;
page.result.chartContainer.value = { clientHeight: 400 };
lwChart.emitCrosshair({ time: 450, point: { x: 12, y: 34 } });
assert.deepEqual(aims[0], { x: .5, y: .75, time: 450, price: 125, pixelX: 12, pixelY: 34, paneHeight: 400 });
page.result.effects.value = false;
lwChart.emitCrosshair({ time: 460, point: { x: 1, y: 1 } });
assert.equal(aims.length, 1, 'crosshair tracking pauses while effects are off');
page.result.effects.value = true;
console.log('PASS: crosshair reads price off the candle series and forwards pixel + normalized coordinates to the guardian');

// --- backward pagination, in a fresh instance so it doesn't disturb page's state above ---------
const page2 = await instance('resources/js/pages/Chart.vue', { symbol: 'BTC-USD' }, 'symbol,interval,guardian');
page2.result.guardian.value = { aim() {}, leave() {} };
page2.mounted[0]();
const page2Chart = charts.at(-1);
respondJson(nextFetch(), history([100, 160, 220]));
await settle();
respondJson(nextFetch(), { id: [], time: [], label: [] });
await settle();

// two rapid left-edge pans before the fetch resolves only trigger one request (single-flight guard)
page2Chart.timeScale().emitLogical({ from: 10, to: 40 });
page2Chart.timeScale().emitLogical({ from: 5, to: 35 });
const pageFetch = nextFetch();
assert.match(pageFetch.url, /\/api\/udf\/history\?.*symbol=BTC-USD/);
assert.equal(fetches.length, 0, 'a pagination fetch already in flight is not duplicated');

// the user leaves BTC-USD before that page resolves — its bars must never land
page2.result.symbol.value = 'ETH-USD';
await nextTick();
const page2EthFetch = nextFetch();
respondJson(pageFetch, history([40, 60, 80]));
await settle();
respondJson(page2EthFetch, history([700, 760]));
await settle();
respondJson(nextFetch(), { id: [], time: [], label: [] }); // ETH's own initial marks
await settle();
assert.deepEqual(page2Chart.series[0].data().map((c) => c.time), [700, 760], 'a pagination response for a series the user already left never merges in');

// panning left on the now-rendered series merges the older page in and widens the marks fetch
page2Chart.timeScale().emitLogical({ from: 10, to: 40 });
const olderFetch = nextFetch();
assert.match(olderFetch.url, /\/api\/udf\/history\?.*symbol=ETH-USD/);
respondJson(olderFetch, history([500, 640])); // short page: fewer than the requested countback
await settle();
respondJson(nextFetch(), { id: ['fill-1'], time: [720], label: ['B'] }); // widened marks fetch
await settle();
assert.deepEqual(page2Chart.series[0].data().map((c) => c.time), [500, 640, 700, 760], 'the older page is prepended in ascending, deduped order');
assert.deepEqual(markersApis.at(-1).markers.map((m) => m.id), ['fill-1'], 'a mark from the newly-loaded range is added');

// a page short of the requested bar count means there is nothing further back — stop paginating
page2Chart.timeScale().emitLogical({ from: 0, to: 10 });
assert.equal(fetches.length, 0, 'pagination stops once a page came back short of the requested bar count');
page2.unmount();
console.log('PASS: backward pagination single-flights, discards a stale response from an abandoned series, merges older bars/marks in, and stops after a short page');

page.unmount();
assert.equal(lwChart.removed, true);
assert.equal(lwChart.crosshairHandlers.size, 0);
assert.equal(lwChart._timeScale.handlers.size, 0);
assert.equal(lwChart._timeScale.logicalHandlers.size, 0);
assert.equal(timers.size, 0, 'unmount clears the realtime poll timer');
lwChart.emitCrosshair({ time: 1, point: { x: 1, y: 1 } });
assert.equal(aims.length, 1, 'a crosshair event after unmount is not forwarded');
// requests is shared across every instance() this file creates, so grab page's own /products
// entry now — draining it later (e.g. cleaning up the broken-constructor instance below) would
// discard it before we get a chance to resolve it.
const request = deferredRequests().find((r) => r.url === '/products');
request.resolve([{ product_id: 'LATE' }]);
await mountedPromise;
assert.deepEqual(page.result.products.value, [], 'a /products response that lands after unmount is dropped');
console.log('PASS: unmount removes the chart, drops all subscriptions, and ignores late responses');

failCreate = true;
const broken = await instance('resources/js/pages/Chart.vue', { symbol: 'BTC-USD' }, 'error');
broken.mounted[0]();
assert.match(broken.result.error.value, /Missing chart engine/);
broken.unmount();
deferredRequests();
failCreate = false;
console.log('PASS: chart initialization error stays contained');

const smx = await instance('resources/js/components/SmxPanel.vue', { symbol: 'BTC-USD', tf: '1m', range: null, height: 170 }, 'data,error');
smx.mounted[0](); let pending = deferredRequests(); assert.equal(pending.length, 1);
document.hidden = true; smx.props.symbol = 'ETH-USD'; await nextTick(); assert.equal(requests.length, 0);
pending[0].resolve({ t: [1], marker: 'old BTC' }); await Promise.resolve(); await nextTick(); assert.equal(smx.result.data.value, null);
document.hidden = false; document.emit(); pending = deferredRequests(); assert.match(pending[0].url, /ETH-USD.*1m/);
smx.props.tf = '15s'; await nextTick(); const latest = deferredRequests()[0]; assert.match(latest.url, /ETH-USD.*15s/);
latest.resolve({ t: [1], marker: 'new ETH 15s' }); await Promise.resolve();
pending[0].resolve({ t: [1], marker: 'old ETH 1m' }); await Promise.resolve(); assert.equal(smx.result.data.value.marker, 'new ETH 15s');
const poll = [...timers.values()][0]; poll(); poll(); pending = deferredRequests(); assert.equal(pending.length, 1);
document.hidden = true; smx.props.symbol = 'SOL-USD'; await nextTick(); pending[0].reject(Error('old request failure')); await Promise.resolve(); assert.equal(smx.result.error.value, ''); assert.equal(smx.result.data.value, null);
document.hidden = false; document.emit(); pending = deferredRequests(); assert.match(pending[0].url, /SOL-USD/); smx.unmount(); pending[0].resolve({ t: [1], marker: 'after unmount' }); await Promise.resolve();
assert.equal(smx.result.data.value, null); assert.equal(document.listeners.size, 0); assert.equal(timers.size, 0);
console.log('PASS: hidden-series invalidation, immediate visibility refresh, out-of-order indicators, overlapping poll exclusion, stale failure rejection and unmount cleanup');
