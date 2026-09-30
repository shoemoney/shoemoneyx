import test from 'node:test';
import assert from 'node:assert/strict';
// Importing the client does not require location/localStorage or a desk credential.
import { api } from '../../../resources/js/api.js';

test('private-network API client sends JSON requests without desk credentials', async (t) => {
    const calls = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        calls.push({ url, options });
        return { ok: true, text: async () => '{"ok":true}' };
    });

    assert.deepEqual(await api.get('/settings'), { ok: true });
    await api.post('/desk/stop');
    await api.put('/settings', { key: 'size.kelly_cap_pct', value: '0.04' });
    await api.del('/settings/size.kelly_cap_pct');

    assert.deepEqual(calls.map(({ url, options }) => [url, options.method]), [
        ['/api/settings', 'GET'],
        ['/api/desk/stop', 'POST'],
        ['/api/settings', 'PUT'],
        ['/api/settings/size.kelly_cap_pct', 'DELETE'],
    ]);
    for (const { options } of calls) {
        assert.deepEqual(options.headers, { Accept: 'application/json', 'Content-Type': 'application/json' });
    }
    assert.equal(calls[0].options.body, undefined);
    assert.equal(calls[1].options.body, '{}');
    assert.deepEqual(JSON.parse(calls[2].options.body), { key: 'size.kelly_cap_pct', value: '0.04' });
    assert.equal(calls[3].options.body, undefined);
    assert.equal(Object.hasOwn(api, 'token'), false);
});

test('request failures still reach page error handling', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false,
        status: 422,
        statusText: 'Unprocessable Content',
        text: async () => '{"message":"mode must be paper or live"}',
    }));
    await assert.rejects(api.put('/settings', { key: 'mode', value: 'invalid' }), /mode must be paper or live/);
});

test('logged-in browser sends the session CSRF token, never a stored password', async (t) => {
    globalThis.document = { querySelector: (sel) => (sel === 'meta[name="csrf-token"]' ? { content: 'csrf-abc' } : null) };
    t.after(() => { delete globalThis.document; });
    const calls = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        calls.push(options);
        return { ok: true, text: async () => '{}' };
    });

    await api.post('/desk/stop');

    assert.equal(calls[0].headers['X-CSRF-TOKEN'], 'csrf-abc');
    assert.equal(calls[0].headers['X-Desk-Token'], undefined);
});

test('an expired or logged-out session sends the browser back to login', async (t) => {
    const visited = [];
    globalThis.location = { assign: (url) => visited.push(url) };
    t.after(() => { delete globalThis.location; });
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, status: 401, statusText: 'Unauthorized', text: async () => '{"message":"bad desk token"}',
    }));

    await assert.rejects(api.get('/status'), /bad desk token/);
    assert.deepEqual(visited, ['/login']);
});

test('a rotated CSRF token reloads the page to pick up the fresh one', async (t) => {
    let reloaded = 0;
    globalThis.location = { assign() {}, reload: () => { reloaded++; } };
    t.after(() => { delete globalThis.location; });
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, status: 419, statusText: 'Page Expired', text: async () => '{"message":"CSRF token mismatch"}',
    }));

    await assert.rejects(api.post('/desk/stop'), /CSRF token mismatch/);
    assert.equal(reloaded, 1);
});

test('a session limited to the set-password screen is sent to onboarding, once', async (t) => {
    const visited = [];
    globalThis.location = { pathname: '/dashboard', assign: (url) => visited.push(url) };
    t.after(() => { delete globalThis.location; });
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, status: 403, statusText: 'Forbidden', text: async () => '{"error":"set_password_required"}',
    }));

    await assert.rejects(api.get('/status'), /set_password_required/);
    assert.deepEqual(visited, ['/onboarding']);

    globalThis.location.pathname = '/onboarding';
    await assert.rejects(api.get('/status'), /set_password_required/);
    assert.deepEqual(visited, ['/onboarding']);
});
