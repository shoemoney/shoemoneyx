import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { computed, effectScope, ref, watch } from 'vue';
import { deskHeaders, updateCsrfToken } from '../../../resources/js/api.js';

const component = await readFile(new URL('../../../resources/js/pages/Settings.vue', import.meta.url), 'utf8');
const source = component.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import[\s\S]*?from [\"'][^\"']+[\"'];$/gm, '').replace(/^import [\"'][^\"']+[\"'];$/gm, '');

function setup({ api, publicDemo = false }) {
    const mounted = [];
    const create = new Function(
        'ref',
        'onMounted',
        'inject',
        'computed',
        'watch',
        'api',
        'publicDemo',
        'updateCsrfToken',
        'useConfirmRisk',
        'describeSettingChange',
        'describePaperReset',
        'trapTab',
        'nextTick',
        source + '\nreturn { currentPassword, newPassword, confirmPassword, passwordBusy, passwordError, canChangePassword, passwordTooShort, passwordMismatch, passwordTooLong, submitPasswordChange, save, reset, act, msg, error, settingsWrites, requestSave };',
    );
    const scope = effectScope();
    const state = scope.run(() => create(
        ref,
        (fn) => mounted.push(fn),
        () => async () => {},
        computed,
        watch,
        api,
        publicDemo,
        updateCsrfToken,
        () => ({ request: ref(null), ask: async () => true, confirm() {}, cancel() {} }),
        () => null,
        () => ({}),
        () => {},
        async (fn) => fn?.(),
    ));
    return { state, mounted, stop: () => scope.stop() };
}

function passwordDocument(token = 'stale-token') {
    const meta = {
        content: token,
        attribute: token,
        setAttribute(name, value) {
            if (name === 'content') this.attribute = value;
        },
    };
    return {
        meta,
        document: {
            querySelector: (sel) => (sel === 'meta[name="csrf-token"]' ? meta : null),
        },
    };
}

function validFields(state, password = 'a-new-password-1') {
    state.currentPassword.value = 'current-password-1';
    state.newPassword.value = password;
    state.confirmPassword.value = password;
}

describe('settings master password', { concurrency: false }, () => {
test('settings renders a change-password panel for the onboarding endpoint', () => {
    assert.match(component, /id="rc-password-title"/);
    assert.match(component, /Change password/);
    assert.match(component, /id="rc-current-password"/);
    assert.match(component, /id="rc-new-password"/);
    assert.match(component, /id="rc-confirm-password"/);
    assert.match(component, /autocomplete="current-password"/);
    assert.match(component, /autocomplete="new-password"/);
    assert.match(component, /\/onboarding\/master-password/);
    assert.match(component, /updateCsrfToken/);
    assert.match(component, /id="rc-password-short"/);
    assert.match(component, /id="rc-password-long"/);
    assert.match(component, /id="rc-password-mismatch"/);
    assert.match(component, /:aria-invalid="passwordTooShort \|\| passwordTooLong \|\| passwordMismatch \|\| undefined"/);
    assert.match(component, /:aria-describedby="newPasswordDescribedBy"/);
    assert.match(component, /:aria-invalid="passwordMismatch \|\| undefined"/);
    assert.match(component, /:aria-describedby="passwordMismatch \? 'rc-password-mismatch' : undefined"/);
    assert.match(component, /:disabled="publicDemo \|\| passwordBusy" @click="requestSave\('mode', 'paper'\)/);
    assert.match(component, /:disabled="publicDemo \|\| passwordBusy" @click="act\('start'\)/);
    assert.equal((component.match(/type="password"/g) || []).length >= 4, true);
});

test('a valid change posts the current and new passwords and refreshes the csrf token', async (t) => {
    const posts = [];
    const { meta, document } = passwordDocument();
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    const api = {
        async post(url, body) {
            posts.push({ url, body, csrfDuring: meta.content });
            return { ok: true, csrf_token: 'fresh-token' };
        },
    };
    const { state, stop } = setup({ api });
    t.after(stop);
    validFields(state);

    await state.submitPasswordChange();

    assert.deepEqual(posts, [{
        url: '/onboarding/master-password',
        body: {
            current_password: 'current-password-1',
            password: 'a-new-password-1',
            password_confirmation: 'a-new-password-1',
        },
        csrfDuring: 'stale-token',
    }]);
    assert.equal(meta.content, 'fresh-token');
    assert.equal(meta.attribute, 'fresh-token');
    assert.equal(deskHeaders()['X-CSRF-TOKEN'], 'fresh-token');
    assert.equal(state.currentPassword.value, '');
    assert.equal(state.newPassword.value, '');
    assert.equal(state.confirmPassword.value, '');
    assert.equal(state.passwordError.value, '');
    assert.equal(state.passwordBusy.value, false);
    assert.equal(state.msg.value, 'master password changed');
});

test('a rejected change leaves the csrf token and the typed passwords alone', async (t) => {
    const { meta, document } = passwordDocument();
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    let calls = 0;
    const api = {
        async post() {
            calls += 1;
            throw new Error('The current password is wrong.');
        },
    };
    const { state, stop } = setup({ api });
    t.after(stop);
    validFields(state);

    await state.submitPasswordChange();

    assert.equal(calls, 1);
    assert.equal(meta.content, 'stale-token');
    assert.equal(meta.attribute, 'stale-token');
    assert.equal(state.passwordError.value, 'The current password is wrong.');
    assert.equal(state.currentPassword.value, 'current-password-1');
    assert.equal(state.newPassword.value, 'a-new-password-1');
    assert.equal(state.confirmPassword.value, 'a-new-password-1');
    assert.equal(state.msg.value, '');
    assert.equal(state.passwordBusy.value, false);
});

test('a success without a csrf token does not wipe the one the page already has', async (t) => {
    const { meta, document } = passwordDocument('keep-me');
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    const api = { async post() { return { ok: true }; } };
    const { state, stop } = setup({ api });
    t.after(stop);
    validFields(state);

    await state.submitPasswordChange();

    assert.equal(meta.content, 'keep-me');
    assert.equal(meta.attribute, 'keep-me');
    assert.equal(deskHeaders()['X-CSRF-TOKEN'], 'keep-me');
    assert.equal(state.msg.value, 'master password changed');
});

test('client-side password problems never hit the network', async (t) => {
    let calls = 0;
    const { meta, document } = passwordDocument();
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    const api = { async post() { calls += 1; return { ok: true, csrf_token: 'nope' }; } };
    const { state, stop } = setup({ api });
    t.after(stop);

    await state.submitPasswordChange();
    assert.equal(calls, 0);
    assert.equal(state.canChangePassword.value, false);
    assert.equal(state.passwordTooShort.value, false);

    state.currentPassword.value = 'current-password-1';
    state.newPassword.value = 'short';
    state.confirmPassword.value = 'short';
    assert.equal(state.passwordTooShort.value, true);
    assert.equal(state.canChangePassword.value, false);
    await state.submitPasswordChange();

    state.newPassword.value = 'a-new-password-1';
    state.confirmPassword.value = 'a-different-one';
    assert.equal(state.passwordMismatch.value, true);
    await state.submitPasswordChange();

    const tooLong = 'a'.repeat(73);
    state.newPassword.value = tooLong;
    state.confirmPassword.value = tooLong;
    assert.equal(state.passwordTooLong.value, true);
    await state.submitPasswordChange();

    const tooManyBytes = 'é'.repeat(37);
    state.newPassword.value = tooManyBytes;
    state.confirmPassword.value = tooManyBytes;
    assert.equal(state.passwordTooLong.value, true);
    assert.equal(new TextEncoder().encode(tooManyBytes).length > 72, true);
    await state.submitPasswordChange();

    const sixEmoji = '😀'.repeat(6);
    assert.equal(sixEmoji.length, 12);
    assert.equal([...sixEmoji].length, 6);
    state.newPassword.value = sixEmoji;
    state.confirmPassword.value = sixEmoji;
    assert.equal(state.passwordTooShort.value, true);
    assert.equal(state.canChangePassword.value, false);
    await state.submitPasswordChange();

    const twelveEmoji = '😀'.repeat(12);
    assert.equal([...twelveEmoji].length, 12);
    assert.equal(new TextEncoder().encode(twelveEmoji).length <= 72, true);
    state.newPassword.value = twelveEmoji;
    state.confirmPassword.value = twelveEmoji;
    assert.equal(state.passwordTooShort.value, false);
    assert.equal(state.canChangePassword.value, true);

    const maxBytes = 'é'.repeat(36);
    state.newPassword.value = maxBytes;
    state.confirmPassword.value = maxBytes;
    assert.equal(new TextEncoder().encode(maxBytes).length, 72);
    assert.equal(state.passwordTooLong.value, false);
    assert.equal(state.canChangePassword.value, true);
    await state.submitPasswordChange();

    assert.equal(calls, 1);
    assert.equal(meta.content, 'nope');
    assert.equal(state.msg.value, 'master password changed');
    assert.equal(state.passwordError.value, '');
});

test('a field validation error is shown instead of the generic summary', async (t) => {
    const { document } = passwordDocument();
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    const api = {
        async post() {
            const error = new Error('The given data was invalid.');
            error.errors = {
                current_password: ['The current password is wrong.'],
                password: ['Choose a password of at least 12 characters.'],
            };
            throw error;
        },
    };
    const { state, stop } = setup({ api });
    t.after(stop);
    validFields(state);

    await state.submitPasswordChange();

    assert.equal(state.passwordError.value, 'The current password is wrong. Choose a password of at least 12 characters.');
    assert.equal(state.currentPassword.value, 'current-password-1');
    assert.equal(state.msg.value, '');
});

test('other settings writes are blocked until the new csrf token is installed', async (t) => {
    const calls = [];
    let release;
    const { meta, document } = passwordDocument();
    globalThis.document = document;
    t.after(() => { delete globalThis.document; });
    const api = {
        async post(url) {
            calls.push(['post', url, meta.content]);
            if (url === '/onboarding/master-password') {
                await new Promise((resolve) => { release = resolve; });
                return { ok: true, csrf_token: 'fresh-token' };
            }
            return { ok: true };
        },
        async put(url, body) {
            calls.push(['put', url, meta.content, body]);
            return { ok: true };
        },
        async del(url) {
            calls.push(['del', url, meta.content]);
        },
        async get() {
            calls.push(['get', meta.content]);
            return { params: {}, overrides: {}, mode: 'paper', strategy: 'mr', strategies: [] };
        },
    };
    const { state, stop } = setup({ api });
    t.after(stop);
    validFields(state);

    const pending = state.submitPasswordChange();
    assert.equal(state.passwordBusy.value, true);
    await state.save('mode', 'paper');
    await state.reset('mode');
    await state.act('stop');
    assert.deepEqual(calls, [['post', '/onboarding/master-password', 'stale-token']]);

    release();
    await pending;

    assert.equal(meta.content, 'fresh-token');
    assert.equal(state.passwordBusy.value, false);
    await state.save('mode', 'paper');
    assert.deepEqual(calls.at(-2), ['put', '/settings', 'fresh-token', { key: 'mode', value: 'paper' }]);
});

test('password rotation cannot overtake an existing settings write', async (t) => {
    for (const operation of ['save', 'reset', 'act']) {
        const calls = [];
        let release;
        const wait = () => new Promise(resolve => { release = resolve; });
        const api = {
            async put() { calls.push('put'); await wait(); },
            async del() { calls.push('delete'); await wait(); },
            async post(url) {
                calls.push(url);
                if (url === '/desk/stop') await wait();
                return { ok: true, csrf_token: 'fresh-token' };
            },
            async get() { return { params: {}, overrides: {}, mode: 'paper', strategy: 'mr', strategies: [] }; },
        };
        const { state, stop } = setup({ api });
        t.after(stop);
        validFields(state);
        const pending = operation === 'save' ? state.save('test.parameter', 1)
            : operation === 'reset' ? state.reset('test.parameter') : state.act('stop');
        assert.equal(state.settingsWrites.value, 1, operation);
        await state.submitPasswordChange();
        assert.equal(calls.includes('/onboarding/master-password'), false, operation);
        assert.equal(state.passwordBusy.value, false, operation);
        release();
        await pending;
        assert.equal(state.settingsWrites.value, 0, operation);
        await state.submitPasswordChange();
        assert.equal(calls.at(-1), '/onboarding/master-password', operation);
    }
});

test('the public demo cannot submit a password change', async (t) => {
    let calls = 0;
    const api = { async post() { calls += 1; return { ok: true, csrf_token: 'nope' }; } };
    const { state, stop } = setup({ api, publicDemo: true });
    t.after(stop);
    validFields(state);
    assert.equal(state.canChangePassword.value, true);

    await state.submitPasswordChange();

    assert.equal(calls, 0);
    assert.equal(state.msg.value, '');
});
});
