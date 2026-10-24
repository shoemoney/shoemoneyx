// Real overlapping requests against a fresh disposable profile with at least two FPM workers.
// The server must have MASTER_PASSWORD=i-0abc123def4567890, non-AWS mode, and a fresh DB.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const { request } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const base = process.env.E2E_BASE || 'https://127.0.0.1:18445';
assert(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'Disposable loopback profile required');
const out = process.env.E2E_OUT || 'output/playwright/password-concurrency';
fs.mkdirSync(out, { recursive: true });
const bootstrap = 'i-0abc123def4567890';
const clients = await Promise.all([request.newContext({ ignoreHTTPSErrors: true }), request.newContext({ ignoreHTTPSErrors: true })]);
const evidence = [];
async function send(client, method, url, token, data) {
  const result = await client.fetch(base + url, {
    method, headers: { Accept: 'application/json', 'X-Desk-Token': token },
    ...(data === undefined ? {} : { data }), maxRedirects: 0,
  });
  const text = await result.text();
  let body;
  try { body = JSON.parse(text); } catch { body = text; }
  return { status: result.status(), body };
}
try {
  let token = bootstrap;
  for (let round = 0; round < 4; round++) {
    const candidates = [`concurrent-owner-a-round-${round}`, `concurrent-owner-b-round-${round}`];
    const replies = await Promise.all(clients.map((client, index) => send(client, 'POST', '/api/onboarding/master-password', token, {
      password: candidates[index], password_confirmation: candidates[index],
      ...(round === 0 ? {} : { current_password: token }),
    })));
    const winners = replies.map((reply, index) => reply.status === 200 && reply.body?.ok === true ? index : -1).filter(index => index !== -1);
    evidence.push({ round, phase: round === 0 ? 'first-owner claim' : 'password rotation', statuses: replies.map(reply => reply.status) });
    assert.equal(winners.length, 1, JSON.stringify(replies));
    const winner = winners[0];
    const loser = 1 - winner;
    assert([401, 409, 422].includes(replies[loser].status), JSON.stringify(replies[loser]));
    const winningToken = candidates[winner];
    assert.equal((await send(clients[0], 'GET', '/api/status', winningToken)).status, 200);
    assert.equal((await send(clients[1], 'GET', '/api/status', candidates[loser])).status, 401);
    assert.equal((await send(clients[1], 'GET', '/api/status', token)).status, 401);
    console.log(`PASS overlapping ${evidence.at(-1).phase}: exactly one winner; loser and prior credentials denied`);
    token = winningToken;
  }
} catch (error) {
  evidence.push({ error: error.stack });
  console.error(error.stack);
  process.exitCode = 1;
} finally {
  fs.writeFileSync(`${out}/results.json`, JSON.stringify(evidence, null, 2));
  await Promise.all(clients.map(client => client.dispose()));
}
