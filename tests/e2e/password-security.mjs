// Real browser/authentication acceptance on a disposable desk. See password-security.md.
// API helpers deliberately have NO default X-Desk-Token: cookies, CSRF and header clients are
// tested separately. Provider fixtures, when enabled, live in the PHP transport, not Playwright.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const PROFILE = process.env.E2E_PROFILE || 'local';
assert(['local', 'aws'].includes(PROFILE), 'E2E_PROFILE must be local or aws');
const BASE = (process.env.E2E_BASE || `https://127.0.0.1:${PROFILE === 'aws' ? 18443 : 18444}`).replace(/\/$/, '');
const HTTP = (process.env.E2E_HTTP_BASE || `http://127.0.0.1:${PROFILE === 'aws' ? 18131 : 18132}`).replace(/\/$/, '');
const OUT = process.env.E2E_OUT || path.resolve('output/playwright/password-security', PROFILE);
const MARKER = process.env.E2E_TLS_STATUS_PATH;
const BOOTSTRAP = 'i-0abc123def4567890';
const OWNER = 'A'.repeat(36) + 'b'.repeat(36); // Exactly 72 ASCII bytes.
const ROTATED = 'e2e-rotated-owner-password-2026';
const UNICODE = 'é'.repeat(36); // Exactly 72 UTF-8 bytes, 36 characters.
const FIXTURE_KEY = 'sk-or-v1-e2e-local-fixture-only';
const results = [];
const consoleErrors = [];
const pageErrors = [];
const contexts = [];
fs.mkdirSync(OUT, { recursive: true });
assert.equal(Buffer.byteLength(OWNER), 72);
assert.equal(Buffer.byteLength(UNICODE), 72);
if (PROFILE === 'aws') {
    assert(MARKER && path.isAbsolute(MARKER), 'AWS profile requires absolute E2E_TLS_STATUS_PATH');
    assert.equal(new URL(BASE).protocol, 'https:', 'AWS positive control requires genuine HTTPS');
}
for (const base of [BASE, HTTP]) {
    assert(['127.0.0.1', '[::1]', 'localhost'].includes(new URL(base).hostname), 'Refusing a non-loopback desk');
}

function check(name, condition, detail = '') {
    results.push({ name, ok: Boolean(condition), detail });
    console.log(`${condition ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
    assert(condition, name);
}
function marker(contents) {
    assert(MARKER, 'Marker control is available only in AWS profile');
    if (contents === null) fs.rmSync(MARKER, { force: true });
    else fs.writeFileSync(MARKER, contents + '\n');
}
function secretKeyPaths(value, parent = '$') {
    if (!value || typeof value !== 'object') return [];
    return Object.entries(value).flatMap(([key, child]) => {
        const keyPath = `${parent}[${JSON.stringify(key)}]`;
        // Settings keys may be nested objects or flattened dotted paths. Detect the actual
        // protected key (including historical case/space/accent aliases), not safe metadata
        // such as master_password_hint, password_set or onboarding's master-password step.
        const canonical = key.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
        return /^master_password(?:\.|$)/.test(canonical)
            ? [keyPath]
            : secretKeyPaths(child, keyPath);
    });
}
const trustedMarker = (host = new URL(BASE).hostname) => `trusted ip:${host} ${new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')}`;
async function request(ctx, url, { method = 'GET', data, headers = {}, base = BASE } = {}) {
    const response = await ctx.request.fetch(base + url, {
        method, data, headers: { Accept: 'application/json', ...headers },
        maxRedirects: 0,
    });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    return { status: response.status(), body, headers: response.headers() };
}
async function csrf(page) {
    return page.locator('meta[name="csrf-token"]').getAttribute('content');
}
async function browserApi(page, url, { method = 'GET', data, token = 'current', headers = {} } = {}) {
    return page.evaluate(async ({ url, method, data, token, headers }) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch('/api' + url, {
            method,
            headers: {
                Accept: 'application/json',
                ...(data === undefined ? {} : { 'Content-Type': 'application/json' }),
                ...(token === 'current' && csrf ? { 'X-CSRF-TOKEN': csrf } : token ? { 'X-CSRF-TOKEN': token } : {}),
                ...headers,
            },
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        const text = await res.text();
        let body;
        try { body = JSON.parse(text); } catch { body = text; }
        return { status: res.status, body };
    }, { url, method, data, token, headers });
}
async function newPage(browser) {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true,
    });
    contexts.push(context);
    const page = await context.newPage();
    page.on('pageerror', (error) => pageErrors.push(String(error.message)));
    page.on('console', (message) => {
        // Browser resource errors are expected for deliberate 401/403/419/422/503 probes.
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) consoleErrors.push(message.text());
    });
    return { context, page };
}
async function render(page, name, scope = 'body') {
    const measurement = await page.evaluate((scope) => {
        const root = document.querySelector(scope);
        if (!root) throw new Error(`Missing rendered scope ${scope}`);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const text = [];
        let node;
        while ((node = walker.nextNode())) {
            if (!node.textContent.trim()) continue;
            const el = node.parentElement;
            if (el.closest('script,style,noscript,svg,[aria-hidden="true"]')) continue;
            const style = getComputedStyle(el);
            const box = el.getBoundingClientRect();
            if (style.visibility !== 'visible' || !box.width || !box.height) continue;
            text.push({ text: node.textContent.trim().slice(0, 90), size: parseFloat(style.fontSize) });
        }
        for (const el of root.querySelectorAll('input,button,select,textarea')) {
            const box = el.getBoundingClientRect();
            if (box.width && box.height) text.push({ text: el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.tagName, size: parseFloat(getComputedStyle(el).fontSize) });
        }
        return {
            minimum: text.length ? Math.min(...text.map((x) => x.size)) : 0,
            undersized: text.filter((x) => x.size < 18),
            overflow: document.documentElement.scrollWidth > innerWidth + 1,
            body: document.body.innerText,
        };
    }, scope);
    fs.writeFileSync(path.join(OUT, `${name}.json`), JSON.stringify(measurement, null, 2));
    await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true, timeout: 10000 });
    check(`${name}: rendered readable text`, measurement.minimum >= 18, JSON.stringify(measurement.undersized.slice(0, 5)));
    check(`${name}: no horizontal overflow`, !measurement.overflow);
    check(`${name}: no unbound values`, !/\[object Object\]|\bundefined\b|\bNaN\b|\{[A-Za-z_][A-Za-z0-9_.]*\}/.test(measurement.body));
}
async function views(page, name, scope) {
    await render(page, `${name}-desktop`, scope);
    await page.setViewportSize({ width: 390, height: 844 });
    await render(page, `${name}-mobile`, scope);
    await page.setViewportSize({ width: 1280, height: 900 });
}
async function login(page, password) {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);
}

const browser = await chromium.launch({
    headless: true,
    ...(process.env.E2E_BROWSER_CHANNEL ? { channel: process.env.E2E_BROWSER_CHANNEL } : {}),
});
let active;
let failed = false;
try {
    const owner = await newPage(browser);
    const { context, page } = owner;
    active = page;
    const anonymous = await newPage(browser);
    let oldBootstrap;
    if (PROFILE === 'aws') {
        for (const [label, content] of [
            ['missing', null], ['fallback', `fallback ip:127.0.0.1 ${new Date().toISOString()}`],
            ['malformed', 'trustedanything ip:127.0.0.1 2026-10-24T01:55:00Z'],
            ['wrong-host', trustedMarker('203.0.113.7')],
        ]) {
            marker(content);
            await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
            check(`${label} TLS: no credential field`, await page.locator('input[name="password"]').count() === 0);
            check(`${label} TLS: no sign-in submit`, await page.getByRole('button', { name: 'Sign in', exact: true }).count() === 0);
            const blocked = await request(context, '/api/onboarding', { headers: { 'X-Desk-Token': BOOTSTRAP } });
            check(`${label} TLS: bootstrap header denied before auth`, blocked.status === 503 && blocked.body?.error === 'trusted_tls_required');
            const setup = await request(anonymous.context, '/api/onboarding/master-password', {
                method: 'POST', headers: { 'X-Desk-Token': BOOTSTRAP },
                data: { password: OWNER, password_confirmation: OWNER },
            });
            check(`${label} TLS: direct setup denied`, setup.status === 503 && setup.body?.error === 'trusted_tls_required');
        }
        await views(page, 'aws-tls-blocked', 'body');
        const xsrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
        assert(xsrf, 'GET /login must create a real CSRF cookie');
        const blockedLogin = await request(context, '/login', {
            method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) },
            data: { password: BOOTSTRAP },
        });
        check('pending TLS: direct HTML login denied with valid CSRF', blockedLogin.status === 503);
        marker(trustedMarker());
        const spoofed = await request(anonymous.context, '/api/onboarding', {
            base: HTTP, headers: { 'X-Desk-Token': BOOTSTRAP, 'X-Forwarded-Proto': 'https' },
        });
        check('HTTP cannot spoof genuine TLS with X-Forwarded-Proto', spoofed.status === 503 && spoofed.body?.error === 'trusted_tls_required');
        await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
        check('trusted HTTPS: form becomes available', await page.locator('input[name="password"]').isVisible());
        await views(page, 'aws-trusted-login', 'body');
        await login(page, '  ' + BOOTSTRAP.toUpperCase() + '  ');
        check('normalized bootstrap login reaches onboarding', new URL(page.url()).pathname === '/onboarding');
        check('bootstrap browser session remains setup-only', (await request(context, '/api/status')).status === 403);
        check('bootstrap header remains setup-only', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': BOOTSTRAP } })).status === 403);
        oldBootstrap = await newPage(browser);
        await login(oldBootstrap.page, BOOTSTRAP);
        marker('fallback ip:127.0.0.1 ' + new Date().toISOString());
        check('existing bootstrap session is blocked if TLS status drops', (await request(context, '/api/onboarding')).status === 503);
        await page.goto(BASE + '/onboarding', { waitUntil: 'networkidle' });
        check('blocked bootstrap page returns to login without a loop', new URL(page.url()).pathname === '/login' && await page.locator('input[name="password"]').count() === 0);
        marker(trustedMarker());
        await page.goto(BASE + '/onboarding', { waitUntil: 'networkidle' });
        check('same bootstrap session resumes after genuine TLS recovery', new URL(page.url()).pathname === '/onboarding');
    } else {
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check('fresh local desk opens password onboarding', new URL(page.url()).pathname === '/onboarding');
        check('keyless local desk protects normal API', (await request(context, '/api/status')).status === 403);
        for (const headers of [{ Origin: 'https://unrelated.example', 'Sec-Fetch-Site': 'cross-site' }, { Via: '1.1 proxy' }, { Host: 'rebound.example' }]) {
            const denied = await request(anonymous.context, '/api/onboarding/master-password', { method: 'POST', headers, data: { password: OWNER, password_confirmation: OWNER } });
            check(`keyless setup rejects ${Object.keys(headers)[0]}`, denied.status === 403);
        }
    }

    await page.getByLabel('New password', { exact: true }).waitFor();
    await views(page, 'password-setup', '.onboarding-page');
    const originalCsrf = await csrf(page);
    for (const [name, password, confirmation] of [
        ['empty', '', ''], ['short', 'short', 'short'],
        ['mismatch', 'long-enough-e2e-password', 'different-e2e-password'],
        ['over72-ASCII', OWNER + 'x', OWNER + 'x'],
        ['over72-UTF8', UNICODE + 'é', UNICODE + 'é'],
        ...(PROFILE === 'aws' ? [['bootstrap-reuse', BOOTSTRAP, BOOTSTRAP]] : []),
    ]) {
        const invalid = await browserApi(page, '/onboarding/master-password', { method: 'POST', data: { password, password_confirmation: confirmation } });
        check(`${name} first password is rejected`, invalid.status === 422);
    }
    const pw = page.getByLabel('New password', { exact: true });
    const confirmation = page.getByLabel('Confirm new password', { exact: true });
    await pw.fill('too-short');
    await confirmation.fill('too-short');
    check('short password cannot be submitted in UI', await page.getByRole('button', { name: 'Continue', exact: true }).isDisabled());
    await pw.fill(OWNER);
    await confirmation.fill('mismatched-long-password');
    check('mismatched confirmation cannot be submitted in UI', await page.getByRole('button', { name: 'Continue', exact: true }).isDisabled());
    await confirmation.fill(OWNER);
    const passwordResponse = page.waitForResponse((response) => response.url().endsWith('/api/onboarding/master-password') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    check('real UI accepts an exact 72-byte owner password', (await passwordResponse).status() === 200);
    await page.getByLabel('OpenRouter API key', { exact: true }).waitFor();
    const ownerCsrf = await csrf(page);
    check('first setup rotates the browser CSRF token', ownerCsrf && ownerCsrf !== originalCsrf);
    check('first setup grants cookie-only owner API access', (await request(context, '/api/status')).status === 200);
    check('separate anonymous context has no owner access', (await request(anonymous.context, '/api/status')).status === 401);
    if (oldBootstrap) {
        check('another bootstrap session is revoked after owner setup', (await request(oldBootstrap.context, '/api/onboarding')).status === 401);
        check('bootstrap header is revoked after owner setup', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': BOOTSTRAP } })).status === 401);
    }
    const mutation = { key: 'size.max_open_positions', value: 7 };
    check('cookie mutation without CSRF is rejected', (await browserApi(page, '/settings', { method: 'PUT', token: null, data: mutation })).status === 419);
    check('cookie mutation with pre-setup CSRF is rejected', (await browserApi(page, '/settings', { method: 'PUT', token: originalCsrf, data: mutation })).status === 419);
    check('cookie mutation with current CSRF succeeds', (await browserApi(page, '/settings', { method: 'PUT', data: mutation })).status === 200);

    // Finish the real wizard through the browser. Only outbound provider traffic is fixture-backed.
    await page.getByLabel('OpenRouter API key', { exact: true }).fill(FIXTURE_KEY);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.locator('input[type="radio"][value="coinbase"]').waitFor();
    await page.locator('input[type="radio"][value="coinbase"]').check();
    await page.getByRole('button', { name: 'Continue with coinbase, paper', exact: true }).click();
    await page.getByRole('heading', { name: 'Import a community strategy', exact: true }).waitFor();
    await page.getByRole('button', { name: /^(Skip for now|Continue)$/ }).click();
    await page.getByRole('button', { name: 'Start paper trading', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Start paper trading', exact: true }).click();
    await page.waitForURL('**/dashboard');
    const state = await request(context, '/api/onboarding');
    check('real wizard completes with cookie authentication', state.status === 200 && state.body.completed === true);
    const status = await request(context, '/api/status');
    check('launched desk is in paper mode', status.status === 200 && status.body.health?.mode === 'paper', JSON.stringify({ status: status.status, health: status.body.health }));

    const other = await newPage(browser);
    active = other.page;
    await other.page.goto(BASE + '/', { waitUntil: 'networkidle' });
    check('new browser is redirected to login', new URL(other.page.url()).pathname === '/login');
    await views(other.page, 'owner-login', 'body');
    await login(other.page, 'wrong-e2e-password');
    check('wrong password stays on login with an error', new URL(other.page.url()).pathname === '/login' && (await other.page.locator('body').innerText()).includes('Wrong password.'));
    check('failed login did not grant cookie API access', (await request(other.context, '/api/status')).status === 401);
    await login(other.page, OWNER + 'suffix');
    check('72-byte password plus suffix cannot log in', new URL(other.page.url()).pathname === '/login' && (await request(other.context, '/api/status')).status === 401);
    check('72-byte password plus suffix cannot authenticate a header', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': OWNER + 'suffix' } })).status === 401);
    await login(other.page, OWNER);
    check('exact owner password opens a fresh browser session', new URL(other.page.url()).pathname !== '/login' && (await request(other.context, '/api/status')).status === 200);
    check('exact owner header works independently of cookies', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': OWNER } })).status === 200);
    check('query-string password never authenticates', (await request(anonymous.context, '/api/status?token=' + encodeURIComponent(OWNER))).status === 401);
    const settings = await request(context, '/api/settings');
    const settingsText = JSON.stringify(settings.body);
    const secretPaths = secretKeyPaths(settings.body);
    check('settings contains neither password material nor a bcrypt hash', settings.status === 200 && !settingsText.includes(OWNER) && !settingsText.includes(BOOTSTRAP) && !/\$2[aby]\$/.test(settingsText) && secretPaths.length === 0, JSON.stringify({ status: settings.status, secretKeyPaths: secretPaths }));
    for (const key of ['master_password', 'MASTER_PASSWORD', 'master_password.child']) {
        check(`settings cannot overwrite ${key}`, (await browserApi(page, '/settings', { method: 'PUT', data: { key, value: 'attacker-replacement' } })).status === 422);
    }
    check('settings cannot delete the password', (await browserApi(page, '/settings/master_password', { method: 'DELETE' })).status === 422);
    const change = { password: ROTATED, password_confirmation: ROTATED };
    check('rotation requires current password', (await browserApi(page, '/onboarding/master-password', { method: 'POST', data: change })).status === 422);
    check('rotation rejects a truncated current-password suffix', (await browserApi(page, '/onboarding/master-password', { method: 'POST', data: { ...change, current_password: OWNER + 'suffix' } })).status === 422);
    const beforeRotation = await csrf(page);
    const changed = await browserApi(page, '/onboarding/master-password', { method: 'POST', data: { ...change, current_password: OWNER } });
    check('owner can rotate with the exact current password', changed.status === 200 && typeof changed.body.csrf_token === 'string');
    check('password rotation returns a fresh CSRF token', changed.body.csrf_token !== beforeRotation);
    check('changing browser remains authenticated', (await request(context, '/api/status')).status === 200);
    check('rotation ends another owner browser session', (await request(other.context, '/api/status')).status === 401);
    check('rotation invalidates cached old header token', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': OWNER } })).status === 401);
    check('new header token works after rotation', (await request(anonymous.context, '/api/status', { headers: { 'X-Desk-Token': ROTATED } })).status === 200);
    check('stale page CSRF cannot mutate after rotation', (await browserApi(page, '/settings', { method: 'PUT', data: mutation })).status === 419);
    await page.reload({ waitUntil: 'networkidle' });
    check('reload supplies CSRF for subsequent cookie mutations', (await browserApi(page, '/settings', { method: 'PUT', data: mutation })).status === 200);
    if (PROFILE === 'aws') {
        marker('fallback ip:127.0.0.1 ' + new Date().toISOString());
        await login(other.page, ROTATED);
        check('owner login remains usable after bootstrap TLS marker falls back', (await request(other.context, '/api/status')).status === 200);
        await login(anonymous.page, BOOTSTRAP);
        check('old bootstrap key cannot regain login after owner setup', new URL(anonymous.page.url()).pathname === '/login');
    }

    // A genuine SDK tool round-trip, audited by the real application ChatClient/Gate.
    const usageBefore = await request(context, '/api/ai/usage');
    const conversation = await browserApi(page, '/agent/conversations', { method: 'POST', data: {} });
    check('authenticated browser creates a persisted agent conversation', conversation.status === 201 && Number.isInteger(conversation.body.id));
    const turn = await browserApi(page, `/agent/conversations/${conversation.body.id}/turn`, { method: 'POST', data: { message: 'Read the current arena scoreboard.' } });
    check('actual graph executes read-only arena tool and concludes', turn.status === 200 && turn.body.error === null && JSON.stringify(turn.body.tool_events).includes('arena_scoreboard') && String(turn.body.content).includes('real arena_scoreboard tool call'));
    const saved = await request(context, `/api/agent/conversations/${conversation.body.id}`);
    check('SDK tool result and assistant completion persist', saved.status === 200 && saved.body.messages.some((message) => message.role === 'tool') && saved.body.messages.at(-1)?.role === 'assistant');
    const usageAfter = await request(context, '/api/ai/usage');
    check('real provider adapter records both fixture-backed model calls', usageAfter.status === 200 && usageAfter.body.calls >= usageBefore.body.calls + 2);

    // UTF-8 boundary is measured in bytes by the backend, not JavaScript string length.
    const unicodeChange = await browserApi(page, '/onboarding/master-password', { method: 'POST', data: { current_password: ROTATED, password: UNICODE, password_confirmation: UNICODE } });
    check('exact72-byte multibyte owner password is accepted', unicodeChange.status === 200);
    await login(other.page, UNICODE + 'é');
    check('multibyte password plus suffix is rejected at login', new URL(other.page.url()).pathname === '/login');
    await login(other.page, UNICODE);
    check('exact multibyte password authenticates', (await request(other.context, '/api/status')).status === 200);
    const restored = await browserApi(other.page, '/onboarding/master-password', { method: 'POST', data: { current_password: UNICODE, password: ROTATED, password_confirmation: ROTATED } });
    check('restore documented synthetic owner password for later app smoke', restored.status === 200);
    await other.page.reload({ waitUntil: 'networkidle' });
    const logoutCsrf = await csrf(other.page);
    const logout = await request(other.context, '/logout', { method: 'POST', data: { _token: logoutCsrf } });
    check('real logout redirects to login', logout.status === 302 && logout.headers.location.endsWith('/login'));
    check('logout ends cookie API access', (await request(other.context, '/api/status')).status === 401);
    const storage = await context.storageState();
    check('browser localStorage contains no owner or bootstrap credential', !JSON.stringify(storage.origins).includes(OWNER) && !JSON.stringify(storage.origins).includes(ROTATED) && !JSON.stringify(storage.origins).includes(UNICODE) && !JSON.stringify(storage.origins).includes(BOOTSTRAP));
    check('no JavaScript page errors', pageErrors.length === 0, pageErrors.join('; '));
    check('no unexpected console errors', consoleErrors.length === 0, consoleErrors.join('; '));
} catch (error) {
    failed = true;
    if (!results.some((result) => !result.ok)) results.push({ name: 'journey completed', ok: false, detail: String(error.stack || error) });
    console.error(String(error.stack || error));
    if (active && !active.isClosed()) await active.screenshot({ path: path.join(OUT, '99-failure.png'), fullPage: true, timeout: 10000 }).catch(() => {});
} finally {
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ profile: PROFILE, base: BASE, fixtureBacked: true, journeyComplete: !failed, syntheticFinalOwnerPassword: failed ? null : ROTATED, results, pageErrors, consoleErrors }, null, 2));
    console.log(`${results.filter((result) => result.ok).length}/${results.length} checks passed; ${OUT}`);
    const cleanup = async () => {
        await Promise.allSettled(contexts.map((context) => context.close()));
        await browser.close();
    };
    let timeout;
    await Promise.race([
        cleanup(),
        new Promise((resolve) => { timeout = setTimeout(resolve, 10000); }),
    ]).finally(() => clearTimeout(timeout));
}
// A browser transport that will not close must not leave acceptance waiting indefinitely.
process.exit(failed ? 1 : 0);
