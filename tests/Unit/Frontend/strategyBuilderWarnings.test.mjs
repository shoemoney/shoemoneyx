import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const component = await readFile(new URL('../../../resources/js/pages/StrategyBuilder.vue', import.meta.url), 'utf8');
const source = component.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;$/gm, '');
const create = new Function('ref', 'onMounted', 'api', 'localStorage', 'FileReader', `${source}
    return { doc, warnings, currentId, validate, loadOne, importFile, useJsonFromChat, restoreVersion };
`);
const oldWarning = { code: 'intent_drift', message: 'Warning for the previous definition' };
const newWarning = { code: 'intent_drift', message: 'Warning for the replacement definition' };
const replacement = { key: 'replacement', name: 'Replacement strategy' };

function setup(overrides = {}) {
    const api = {
        async get(url) {
            if (url === '/strategy-plugins/2') return { id: 2, definition: replacement, current_version: '1.0.0' };
            return { data: [] };
        },
        async post(url) {
            if (url === '/strategy-plugins/validate') return { valid: true, errors: [], warnings: [oldWarning] };
            if (url.endsWith('/restore')) return { version: { definition: replacement }, plugin: { current_version: '1.0.1' } };
            return { plugin: { id: 2, current_version: '1.0.0' }, warnings: [newWarning] };
        },
        ...overrides,
    };
    class FileReader {
        readAsText(file) {
            this.result = file.text;
            queueMicrotask(() => this.onload());
        }
    }
    // Execute the real setup functions with only their browser/API boundaries stubbed.
    return create(value => ({ value }), () => {}, api, { getItem: () => null }, FileReader);
}

async function previouslyValidated(overrides) {
    const state = setup(overrides);
    await state.validate();
    assert.deepEqual(state.warnings.value, [oldWarning]);
    return state;
}

test('loading another saved definition clears the previous warnings', async () => {
    const state = await previouslyValidated();
    await state.loadOne(2);
    assert.deepEqual(JSON.parse(state.doc.value), replacement);
    assert.deepEqual(state.warnings.value, []);
});

for (const text of [JSON.stringify(replacement), '{ malformed JSON']) {
    test(`importing ${text.startsWith('{ malformed') ? 'invalid' : 'valid'} JSON clears the previous warnings`, async () => {
        const state = await previouslyValidated();
        state.importFile({ target: { files: [{ text }], value: 'strategy.json' } });
        await Promise.resolve();
        assert.equal(state.doc.value, text);
        assert.deepEqual(state.warnings.value, []);
    });
}

test('using invalid chat JSON clears previous warnings even though it cannot be saved', async () => {
    const state = await previouslyValidated();
    state.useJsonFromChat('```json\n{ malformed JSON\n```');
    assert.equal(state.doc.value, '{ malformed JSON');
    assert.deepEqual(state.warnings.value, []);
});

test('using valid chat JSON keeps the warnings returned by saving the new definition', async () => {
    const state = await previouslyValidated();
    state.useJsonFromChat('```json\n' + JSON.stringify(replacement) + '\n```');
    await Promise.resolve();
    assert.deepEqual(JSON.parse(state.doc.value), replacement);
    assert.deepEqual(state.warnings.value, [newWarning]);
});

test('restoring a version clears warnings attached to the previous definition', async () => {
    const state = await previouslyValidated();
    state.currentId.value = 2;
    await state.restoreVersion('1.0.0');
    assert.deepEqual(JSON.parse(state.doc.value), replacement);
    assert.deepEqual(state.warnings.value, []);
});

test('a failed load leaves the unchanged document and its warnings intact', async () => {
    const state = await previouslyValidated({ get: async () => { throw Error('Network failed'); } });
    const original = state.doc.value;
    await state.loadOne(2);
    assert.equal(state.doc.value, original);
    assert.deepEqual(state.warnings.value, [oldWarning]);
});

test('a failed restore leaves the unchanged document and its warnings intact', async () => {
    const state = await previouslyValidated({
        post: async (url) => {
            if (url.endsWith('/restore')) throw Error('Restore failed');
            return { valid: true, warnings: [oldWarning] };
        },
    });
    state.currentId.value = 2;
    const original = state.doc.value;
    await state.restoreVersion('1.0.0');
    assert.equal(state.doc.value, original);
    assert.deepEqual(state.warnings.value, [oldWarning]);
});

test('cancelled imports and chat replies without JSON do not discard current warnings', async () => {
    const state = await previouslyValidated();
    const original = state.doc.value;
    state.importFile({ target: { files: [] } });
    state.useJsonFromChat('No definition provided.');
    assert.equal(state.doc.value, original);
    assert.deepEqual(state.warnings.value, [oldWarning]);
});
