import test from 'node:test';
import assert from 'node:assert/strict';
// Importing the client does not require location/localStorage or a desk credential.
import { api, deskFetch, fmt, updateCsrfToken } from '../../../resources/js/api.js';

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

test('validation failures keep the field errors with the thrown error', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false,
        status: 422,
        statusText: 'Unprocessable Content',
        text: async () => JSON.stringify({
            message: 'The given data was invalid.',
            errors: { current_password: ['The current password is wrong.'] },
        }),
    }));

    await assert.rejects(api.post('/onboarding/master-password', {}), (error) => {
        assert.equal(error.message, 'The given data was invalid.');
        assert.deepEqual(error.errors, { current_password: ['The current password is wrong.'] });
        return true;
    });
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

test('a rotated CSRF token on a write reports a clear message instead of silently reloading', async (t) => {
    let reloaded = 0;
    globalThis.location = { assign() {}, reload: () => { reloaded++; } };
    t.after(() => { delete globalThis.location; });
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, status: 419, statusText: 'Page Expired', text: async () => '{"message":"CSRF token mismatch"}',
    }));

    await assert.rejects(api.post('/desk/stop'), (error) => {
        assert.equal(error.status, 419);
        assert.match(error.message, /session expired, so nothing was changed/);
        return true;
    });
    assert.equal(reloaded, 0);
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

test('deskFetch is the shared path: CSRF read per call, errors carry status and body, blobs pass through', async (t) => {
    const csrf = { content: 'one' };
    globalThis.document = { querySelector: () => csrf };
    t.after(() => { delete globalThis.document; });
    const calls = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        calls.push({ url, options });
        if (url === '/api/quote') return { ok: false, status: 503, statusText: 'Service Unavailable', text: async () => '{"error":"feed down"}' };
        if (url === '/export') return { ok: true, blob: async () => 'BLOB' };
        return { ok: true, text: async () => '{"ok":true}' };
    });

    await deskFetch('/api/x');
    csrf.content = 'two';
    await deskFetch('/api/x', { method: 'POST', body: { a: 1 } });
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'one');
    assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'two');
    assert.equal(calls[1].options.headers['Content-Type'], 'application/json');
    assert.equal(calls[0].options.headers['Content-Type'], undefined);

    await assert.rejects(deskFetch('/api/quote'), (error) => {
        assert.equal(error.status, 503);
        assert.deepEqual(error.data, { error: 'feed down' });
        assert.equal(error.message, 'feed down');
        return true;
    });
    assert.equal(await deskFetch('/export', { as: 'blob' }), 'BLOB');
});

test('fmt never shows negative zero, NaN or infinity', () => {
    assert.equal(fmt.usd(-0), '$0.00');
    assert.equal(fmt.usd(-0.001), '$0.00');
    assert.equal(fmt.usd(-1234.5), '-$1,234.50');
    assert.equal(fmt.usd(''), '—');
    assert.equal(fmt.usd(null), '—');
    assert.equal(fmt.usd('garbage'), '—');
    assert.equal(fmt.usd(Infinity), '—');
    assert.equal(fmt.usd('1e400'), '—');
    assert.equal(fmt.pct(-0), '0.00%');
    assert.equal(fmt.pct(-0.001), '0.00%');
    assert.equal(fmt.pct(0.001), '0.00%');
    assert.equal(fmt.pct(1.234), '+1.23%');
    assert.equal(fmt.pct(-1.234), '-1.23%');
    assert.equal(fmt.pct(''), '—');
    assert.equal(fmt.num(-0), '0');
    assert.equal(fmt.num(-0.001), '0');
    assert.equal(fmt.num(NaN), '—');
    assert.equal(fmt.px(Infinity), '—');
    assert.equal(fmt.px(''), '—');
    assert.equal(fmt.ago('garbage'), '—');
    assert.equal(fmt.ago(''), '—');
    assert.equal(fmt.time('garbage'), '—');
    assert.equal(fmt.mins(NaN), '—');
    assert.equal(fmt.mins(90), '1.5h');
});

test('fmt keeps the minus sign of real losses under a non-Latin-digit locale', (t) => {
    const original = Number.prototype.toLocaleString;
    t.mock.method(Number.prototype, 'toLocaleString', function (_locale, options) {
        return original.call(this, 'ar-EG-u-nu-arab', options);
    });

    assert.match(fmt.usd(-1234.5), /^-\$/);
    assert.doesNotMatch(fmt.usd(-0.001), /-/);
    assert.match(fmt.num(-1234.5), /-/);
    assert.doesNotMatch(fmt.num(-0.001), /-/);
    assert.match(fmt.px(-5), /-/);
});


test('rotated CSRF installation rejects missing or malformed tokens without changing the current token', (t) => {
    const meta = { content: 'existing-token', setAttribute(name, value) { this[name] = value; } };
    globalThis.document = { querySelector: () => meta };
    t.after(() => { delete globalThis.document; });
    for (const value of [null, undefined, '', 123, {}]) {
        assert.equal(updateCsrfToken(value), false);
        assert.equal(meta.content, 'existing-token');
    }
    assert.equal(updateCsrfToken('replacement-token'), true);
    assert.equal(meta.content, 'replacement-token');
    assert.equal(updateCsrfToken('another-token'), true);
    assert.equal(meta.content, 'another-token');
    globalThis.document = { querySelector: () => null };
    assert.equal(updateCsrfToken('unavailable-meta'), false);
});
