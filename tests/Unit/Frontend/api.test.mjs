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
