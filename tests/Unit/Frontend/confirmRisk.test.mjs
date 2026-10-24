import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { ref, computed, watch, nextTick, createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import {
    FOCUSABLE, nextTrapIndex, trapTab, useConfirmRisk, isRiskyKey, describeSettingChange, describePaperReset,
} from '../../../resources/js/riskConfirm.js';

const root = new URL('../../../resources/js/', import.meta.url);

test('tab wraps at both ends of the trap and otherwise moves naturally', () => {
    assert.equal(nextTrapIndex(3, 2, false), 0);
    assert.equal(nextTrapIndex(3, 0, true), 2);
    assert.equal(nextTrapIndex(3, 1, false), null);
    assert.equal(nextTrapIndex(3, 1, true), null);
    assert.equal(nextTrapIndex(3, -1, false), 0, 'focus outside the dialog is pulled in');
    assert.equal(nextTrapIndex(3, -1, true), 2);
    assert.equal(nextTrapIndex(0, -1, false), null);
});

test('trapTab keeps focus inside the container and ignores other keys', () => {
    const calls = [];
    const el = name => ({ focus: () => calls.push(name) });
    const [a, b, c] = [el('a'), el('b'), el('c')];
    const container = { querySelectorAll: selector => { assert.equal(selector, FOCUSABLE); return [a, b, c]; }, ownerDocument: { activeElement: c } };
    let prevented = 0;
    assert.equal(trapTab({ key: 'Tab', shiftKey: false, preventDefault: () => prevented++ }, container), true);
    assert.deepEqual(calls, ['a']);
    container.ownerDocument.activeElement = a;
    assert.equal(trapTab({ key: 'Tab', shiftKey: true, preventDefault: () => prevented++ }, container), true);
    assert.deepEqual(calls, ['a', 'c']);
    container.ownerDocument.activeElement = b;
    assert.equal(trapTab({ key: 'Tab', shiftKey: false, preventDefault: () => prevented++ }, container), false);
    assert.equal(trapTab({ key: 'Enter' }, container), false);
    assert.equal(prevented, 2);
});

test('ask resolves with the picked choice on confirm, null on cancel, and cancels a superseded request', async () => {
    const { request, ask, confirm, cancel } = useConfirmRisk();
    const first = ask({ title: 'one' });
    assert.equal(request.value.title, 'one');
    const second = ask({ title: 'two' });
    assert.equal(await first, null, 'asking again cancels the open dialog');
    confirm('all');
    assert.deepEqual(await second, { choice: 'all' });
    assert.equal(request.value, null);

    const third = ask({ title: 'three' });
    cancel();
    assert.equal(await third, null);
    const fourth = ask({ title: 'four' });
    confirm();
    assert.deepEqual(await fourth, { choice: null });
});

test('only risk, sizing, leverage and the mode, strategy and exchange switches need a confirmation', () => {
    for (const key of ['mode', 'strategy', 'exchange', 'risk.hard_stop_pct', 'size.max_leverage', 'size.kelly_cap_pct', 'bank.locked_pct', 'perps.max_margin_use_pct', 'per_product.BTC-USD.size.max_leverage', 'mr.leverage']) {
        assert.equal(isRiskyKey(key), true, key);
    }
    for (const key of ['paper.starting_cash', 'scan.max_candidates', 'universe.min_volume', 'fees.taker_rate', 'telegram.chat_id']) {
        assert.equal(isRiskyKey(key), false, key);
        assert.equal(describeSettingChange(key, 1, 2), null, key);
    }
    assert.equal(describeSettingChange('size.max_leverage', 3, 3), null, 'no change, no popup');
});

test('a leverage increase shows old and new values, explains the risk in plain words and flags it', () => {
    const up = describeSettingChange('size.max_leverage', 3, '10');
    assert.deepEqual(up.changes, [{ label: 'size.max_leverage', from: '3', to: '10' }]);
    assert.match(up.body.join(' '), /liquidation/);
    assert.match(up.body.join(' '), /riskier/);
    assert.equal(up.danger, true);

    const down = describeSettingChange('size.max_leverage', 3, 2);
    assert.match(down.body.join(' '), /more cautious/);
    assert.equal(down.danger, false);

    const lockedDown = describeSettingChange('bank.locked_pct', 0.2, 0.05);
    assert.match(lockedDown.body.join(' '), /riskier/, 'a smaller locked bag is the risky direction');
});

test('switches explain what happens next and going live is a danger prompt', () => {
    const live = describeSettingChange('mode', 'paper', 'live');
    assert.equal(live.danger, true);
    assert.match(live.body.join(' '), /real money/);
    assert.deepEqual(live.changes, [{ label: 'Operating mode', from: 'paper', to: 'live' }]);

    assert.match(describeSettingChange('mode', 'live', 'paper').body.join(' '), /live positions/);
    assert.match(describeSettingChange('strategy', 'mr', 'json').body.join(' '), /exit rules/);
    assert.match(describeSettingChange('exchange', 'coinbase', 'binance').body.join(' '), /not transferred/);
    assert.equal(describeSettingChange('size.kelly_cap_pct', 0.06, 'default').changes[0].to, 'default');
});

test('the paper reset offers main-only and main-plus-seats, main first', () => {
    const request = describePaperReset();
    assert.deepEqual(request.choices.map(c => c.value), ['main', 'all']);
    assert.equal(request.danger, true);
});

async function renderDialog(request) {
    const source = await readFile(new URL('components/ConfirmRisk.vue', root), 'utf8');
    const { descriptor } = parse(source);
    const script = compileScript(descriptor, { id: 'confirm-risk', inlineTemplate: true, templateOptions: { ssr: true, ssrCssVars: [] } });
    const riskUrl = new URL('riskConfirm.js', root).href;
    const code = script.content
        .replace(/from ["']\.\.\/riskConfirm["']/, `from "${riskUrl}"`)
        .replace(/from ["']vue["']/g, `from "${import.meta.resolve('vue')}"`)
        .replace(/from ["']vue\/server-renderer["']/g, `from "${import.meta.resolve('vue/server-renderer')}"`);
    const mod = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
    return renderToString(createSSRApp(mod.default, { request }));
}

test('the rendered dialog is a labelled modal with the explanation, old to new values, scope choices and a cancel', async () => {
    const html = await renderDialog({ ...describePaperReset(), changes: [{ label: 'Paper book', from: 'old state', to: 'new state' }] });
    assert.match(html, /role="dialog"/);
    assert.match(html, /aria-modal="true"/);
    const labelled = html.match(/aria-labelledby="([^"]+)"/)[1];
    const described = html.match(/aria-describedby="([^"]+)"/)[1];
    assert.match(html, new RegExp(`id="${labelled}"[^>]*>\\s*Reset the paper book`));
    assert.match(html, new RegExp(`id="${described}"`));
    assert.match(html, /old state/);
    assert.match(html, /new state/);
    assert.equal((html.match(/type="radio"/g) ?? []).length, 2);
    assert.match(html, /Main desk only/);
    assert.match(html, /Main desk and all arena seats/);
    assert.match(html, />\s*Cancel\s*</);
    assert.match(html, />\s*Reset\s*</);
});

test('nothing renders while there is no request, and a dialog without choices shows no scope picker', async () => {
    assert.equal((await renderDialog(null)).replace(/<!--.*?-->/g, ''), '');
    const html = await renderDialog(describeSettingChange('size.max_leverage', 3, 10));
    assert.doesNotMatch(html, /type="radio"/);
    assert.match(html, /Apply change/);
});

test('the dialog source wires Escape, focus return and the focus trap', async () => {
    const source = await readFile(new URL('components/ConfirmRisk.vue', root), 'utf8');
    assert.match(source, /event\.key === "Escape"/);
    assert.match(source, /trapTab\(event, dialog\.value\)/);
    assert.match(source, /returnFocus/);
    assert.match(source, /\.focus\?\.\(\)/);
});

// Settings.vue: run the real setup code with only the browser and API boundaries stubbed.
async function settingsHarness({ mode = 'paper', strategy = 'mr', params = {}, overrides = {} } = {}) {
    const { descriptor } = parse(await readFile(new URL('pages/Settings.vue', root), 'utf8'));
    const compiled = compileScript(descriptor, { id: 'settings-flow' });
    let body = descriptor.scriptSetup.content;
    for (const node of compiled.scriptSetupAst.filter(n => n.type === 'ImportDeclaration').reverse()) body = body.slice(0, node.start) + body.slice(node.end);
    const calls = [];
    const api = {
        async get() { return { mode, strategy, strategies: [], live_confirm: true, params: { mode, strategy, ...params }, overrides }; },
        async put(url, payload) { calls.push(['put', url, payload]); },
        async del(url) { calls.push(['del', url]); },
        async post(url, payload) { calls.push(['post', url, payload]); return { ok: true }; },
    };
    const mounted = [];
    const create = new Function('ref', 'onMounted', 'inject', 'computed', 'watch', 'api', 'publicDemo', 'useConfirmRisk', 'describeSettingChange', 'describePaperReset', 'nextTick', 'trapTab', 'document', `${body}
        return { load, requestSave, changeStrategy, reset, act, riskRequest, confirmRisk, cancelRisk, liveModal, liveBusy, liveForm, askLivePassword, liveKeydown, msg, error };`);
    const state = create(ref, fn => mounted.push(fn), () => async () => {}, computed, watch, api, false, useConfirmRisk, describeSettingChange, describePaperReset, nextTick, trapTab, { activeElement: null });
    await state.load();
    const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await nextTick(); };
    return { state, calls, settle };
}

test('Settings: a risky edit waits for the dialog, cancel saves nothing, confirm saves once', async () => {
    const { state, calls, settle } = await settingsHarness({ params: { 'size.max_leverage': 3 } });
    const cancelled = state.requestSave('size.max_leverage', '10');
    await settle();
    assert.deepEqual(state.riskRequest.value.changes, [{ label: 'size.max_leverage', from: '3', to: '10' }]);
    assert.equal(calls.length, 0, 'nothing is written before the answer');
    state.cancelRisk();
    assert.equal(await cancelled, false);
    assert.equal(calls.length, 0);

    const confirmed = state.requestSave('size.max_leverage', '10');
    await settle();
    state.confirmRisk();
    assert.equal(await confirmed, true);
    assert.deepEqual(calls[0], ['put', '/settings', { key: 'size.max_leverage', value: '10' }]);
});

test('Settings: harmless settings save without a popup', async () => {
    const { state, calls } = await settingsHarness({ params: { 'scan.max_candidates': 10 } });
    await state.requestSave('scan.max_candidates', '12');
    assert.equal(state.riskRequest.value, null);
    assert.deepEqual(calls[0], ['put', '/settings', { key: 'scan.max_candidates', value: '12' }]);
});

test('Settings: switching to live confirms first and then opens the password step, never saving on its own', async () => {
    const { state, calls, settle } = await settingsHarness();
    const switching = state.requestSave('mode', 'live');
    await settle();
    assert.equal(state.riskRequest.value.danger, true);
    assert.equal(state.liveModal.value, false);
    state.confirmRisk();
    await switching;
    assert.equal(state.liveModal.value, true);
    assert.equal(calls.length, 0, 'the existing live password flow still does the write');
});

test('Settings: a cancelled strategy switch puts the select back', async () => {
    const { state, calls, settle } = await settingsHarness();
    const target = { value: 'json' };
    const changing = state.changeStrategy({ target });
    await settle();
    state.cancelRisk();
    await changing;
    assert.equal(target.value, 'mr');
    assert.equal(calls.length, 0);
});

test('Settings: the paper reset posts the scope the user picked and nothing when cancelled', async () => {
    const { state, calls, settle } = await settingsHarness();
    const cancelled = state.act('paper-reset');
    await settle();
    state.cancelRisk();
    await cancelled;
    assert.equal(calls.length, 0);

    const resetting = state.act('paper-reset');
    await settle();
    state.confirmRisk('all');
    await resetting;
    assert.deepEqual(calls[0], ['post', '/desk/paper-reset', { scope: 'all' }]);

    const mainOnly = state.act('paper-reset');
    await settle();
    state.confirmRisk('main');
    await mainOnly;
    assert.deepEqual(calls[1], ['post', '/desk/paper-reset', { scope: 'main' }]);
});

test('Settings: other desk actions post without a body or a popup', async () => {
    const { state, calls } = await settingsHarness();
    await state.act('halt');
    assert.equal(state.riskRequest.value, null);
    assert.deepEqual(calls[0], ['post', '/desk/halt', undefined]);
});

test('Settings: removing a risky override asks first', async () => {
    const { state, calls, settle } = await settingsHarness({ params: { 'risk.hard_stop_pct': -20 }, overrides: { 'risk.hard_stop_pct': -20 } });
    const removing = state.reset('risk.hard_stop_pct');
    await settle();
    assert.equal(state.riskRequest.value.changes[0].to, 'default');
    state.confirmRisk();
    await removing;
    assert.deepEqual(calls[0], ['del', '/settings/risk.hard_stop_pct']);
});

test('Settings: the live-password dialog closes on Escape but ignores it while saving', async () => {
    const { state } = await settingsHarness();
    state.askLivePassword();
    assert.equal(state.liveModal.value, true);
    let prevented = 0;
    state.liveBusy.value = true;
    state.liveKeydown({ key: 'Escape', preventDefault: () => prevented++ });
    assert.equal(state.liveModal.value, true, 'Escape is ignored while the save is in flight');
    state.liveBusy.value = false;
    state.liveKeydown({ key: 'Escape', preventDefault: () => prevented++ });
    assert.equal(state.liveModal.value, false);
    assert.equal(prevented, 1);
});
