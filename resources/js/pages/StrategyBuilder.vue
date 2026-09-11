<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api';

const DEFAULT_JSON = `{
  "key": "my-first-strategy",
  "name": "My First Strategy",
  "description": "Describe the edge in one sentence.",
  "version": 1,
  "base": "custom",
  "suggest": { "products": ["BTC-USD"], "days": 30, "cash": 1000 },
  "params": {},
  "scan": {
    "max_candidates": 5,
    "filters": [
      { "field": "indicators.rsi14", "op": "<", "value": 35 }
    ]
  },
  "vet": {
    "rules": [
      { "field": "spread_bps", "op": "<=", "value": 15, "reason": "spread too wide" }
    ]
  },
  "size": { "kelly_fraction": 0.25, "max_pct_book": 6.0 },
  "risk": {
    "rules": [
      { "field": "volume_ratio_6h", "op": "<", "value": 0.2, "action": "close" }
    ]
  }
}`;

const doc = ref(DEFAULT_JSON);
const errors = ref([]);
const valid = ref(null);
const busy = ref(false);
const saved = ref([]);
const saveMsg = ref('');
const currentId = ref(null);

// Versions: every save writes a new immutable row (bump + changelog), restore
// creates a new one carrying an old definition forward.
const currentVersion = ref(null);
const versions = ref([]);
const bump = ref('patch');
const changelog = ref('');
const versionBusy = ref(false);

const reviews = ref([]);
const autoBacktest = ref(false);

// Community strategy sync — pulls from the public shoemoneyx-strategies repo.
const communitySync = ref(null);
const communityBusy = ref(false);

async function loadSyncStatus() {
    try { communitySync.value = await api.get('/strategies/sync/status'); } catch (e) { /* read-only */ }
}

async function importStrategy(remoteId) {
    communityBusy.value = true; errors.value = [];
    try {
        await api.post('/strategies/sync/import', { remote_id: remoteId });
        await Promise.all([loadSyncStatus(), loadSaved()]);
    } catch (e) { errors.value = [e.message]; }
    communityBusy.value = false;
}

async function importAllNew() {
    communityBusy.value = true; errors.value = [];
    try {
        await api.post('/strategies/sync/import-all');
        await Promise.all([loadSyncStatus(), loadSaved()]);
    } catch (e) { errors.value = [e.message]; }
    communityBusy.value = false;
}

async function toggleAutoBacktest() {
    if (!currentId.value) return;
    try {
        autoBacktest.value = (await api.post(`/strategy-plugins/${currentId.value}/auto-backtest`, { enabled: !autoBacktest.value })).auto_backtest;
    } catch (e) { errors.value = [e.message]; }
}

async function loadVersions(id) {
    if (!id) { versions.value = []; return; }
    try { versions.value = (await api.get(`/strategy-plugins/${id}/versions`)).data || []; } catch (e) { /* read-only */ }
}

// Verdicts the 24/7 worker filed on this plugin's unattended backtests.
async function loadReviews(id) {
    if (!id) { reviews.value = []; return; }
    try { reviews.value = (await api.get(`/strategy-plugins/${id}/reviews`)).data || []; } catch (e) { /* read-only */ }
}

async function restoreVersion(version) {
    if (!currentId.value || versionBusy.value) return;
    versionBusy.value = true; errors.value = [];
    try {
        const res = await api.post(`/strategy-plugins/${currentId.value}/versions/${version}/restore`, { bump: bump.value });
        doc.value = JSON.stringify(res.version.definition, null, 2);
        currentVersion.value = res.plugin.current_version;
        applySuggest(res.version.definition);
        saveMsg.value = `Restored v${version} as v${res.plugin.current_version}.`;
        await Promise.all([loadSaved(), loadVersions(currentId.value), loadReviews(currentId.value)]);
    } catch (e) { errors.value = [e.message]; }
    versionBusy.value = false;
}

// Import / export (JSON, Pine, Markdown) + backtest trigger.
const btForm = ref({ days: 30, cash: 1000, products: 'BTC-USD' });

// The strategy carries its own suggested test options — loading, importing,
// or AI-saving a strategy autopopulates every option field from `suggest`.
function applySuggest(def) {
    const s = def?.suggest || {};
    if (Array.isArray(s.products) && s.products.length) btForm.value.products = s.products.join(',');
    if (Number.isInteger(s.days)) btForm.value.days = s.days;
    if (typeof s.cash === 'number' && s.cash > 0) btForm.value.cash = s.cash;
}
const btBusy = ref(false);
const btResult = ref(null);

// SMX assist chatbox. The key lives on the server against the operator's connected
// OpenRouter account — the browser never holds one, only the model preference.
const orModel = ref(localStorage.getItem('smx_or_model') || 'openrouter/free');
const freeModels = ref([]);
const recommendedModels = ref([]);
const customModel = ref('');
const useCustom = ref(false);
const aiStatus = ref({ connected: false, label: null, default_model: 'openrouter/free', nudge: { show: false, model: '', reason: '' } });
const aiUsage = ref(null);
const showNudge = ref(false);

async function loadModels() {
    try {
        const res = await api.get('/ai/models');
        freeModels.value = res.free || [];
        recommendedModels.value = res.recommended || [];
        // A saved custom id that isn't in either live group still needs to show.
        const known = [...freeModels.value, ...recommendedModels.value].some((m) => m.id === orModel.value);
        if (orModel.value && !known) {
            useCustom.value = true;
            customModel.value = orModel.value;
        }
    } catch (e) { useCustom.value = true; }
}

async function loadAiStatus() {
    try {
        aiStatus.value = await api.get('/ai/status');
        if (aiStatus.value.connected) await loadAiUsage();
    } catch (e) { /* read-only */ }
}

async function loadAiUsage() {
    try { aiUsage.value = await api.get('/ai/usage'); } catch (e) { /* read-only */ }
}

function connectOpenRouter() {
    window.location.href = '/ai/openrouter/connect';
}

const chat = ref([]);
const draft = ref('');
const chatBusy = ref(false);

function savePrefs() {
    localStorage.setItem('smx_or_model', orModel.value);
}

function parsed() {
    try { return { ok: true, value: JSON.parse(doc.value) }; }
    catch (e) { return { ok: false, error: e.message }; }
}

async function validate() {
    busy.value = true; errors.value = []; valid.value = null;
    const p = parsed();
    if (!p.ok) { errors.value = ['Invalid JSON: ' + p.error]; valid.value = false; busy.value = false; return; }
    try {
        const res = await api.post('/strategy-plugins/validate', { definition: p.value });
        valid.value = res.valid; errors.value = res.errors || [];
    } catch (e) { errors.value = [e.message]; valid.value = false; }
    busy.value = false;
}

async function save() {
    busy.value = true; saveMsg.value = ''; errors.value = [];
    const p = parsed();
    if (!p.ok) { errors.value = ['Invalid JSON: ' + p.error]; busy.value = false; return; }
    try {
        const res = await api.post('/strategy-plugins', { definition: p.value, bump: bump.value, changelog: changelog.value || undefined });
        currentId.value = res.plugin?.id || null;
        currentVersion.value = res.plugin?.current_version || null;
        saveMsg.value = `Saved as v${currentVersion.value}.`;
        changelog.value = '';
        applySuggest(p.value);
        await Promise.all([loadSaved(), loadVersions(currentId.value), loadReviews(currentId.value)]);
    } catch (e) { errors.value = [e.message]; }
    busy.value = false;
}

async function loadSaved() {
    try { saved.value = (await api.get('/strategy-plugins')).data || []; } catch (e) { /* read-only */ }
}

async function loadOne(id) {
    try {
        const p = await api.get(`/strategy-plugins/${id}`);
        doc.value = JSON.stringify(p.definition, null, 2);
        currentId.value = p.id;
        currentVersion.value = p.current_version || null;
        autoBacktest.value = !!p.auto_backtest;
        applySuggest(p.definition);
        errors.value = []; valid.value = null; saveMsg.value = ''; btResult.value = null;
        await Promise.all([loadVersions(id), loadReviews(id)]);
    } catch (e) { errors.value = [e.message]; }
}

async function ensureSaved() {
    if (currentId.value) return true;
    await save();
    return !!currentId.value;
}

async function exportFmt(fmt) {
    if (!await ensureSaved()) return;
    const token = localStorage.getItem('desk_token') || '';
    const url = `/api/strategy-plugins/${currentId.value}/export/${fmt}`;
    const res = await fetch(url, { headers: token ? { 'X-Desk-Token': token } : {} });
    if (!res.ok) { errors.value = [`export failed: ${res.status}`]; return; }
    const blob = await res.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const ext = fmt === 'json' ? 'strategy.json' : fmt;
    const key = (parsed().ok && parsed().value.key) || 'strategy';
    a.download = `${key}.${ext}`;
    a.click();
    URL.revokeObjectURL(a.href);
}

function importFile(ev) {
    const f = ev.target.files?.[0];
    if (!f) return;
    const r = new FileReader();
    r.onload = () => {
        doc.value = String(r.result || '');
        currentId.value = null; errors.value = []; valid.value = null; saveMsg.value = '';
        const imp = parsed();
        if (imp.ok) applySuggest(imp.value);
    };
    r.readAsText(f);
    ev.target.value = '';
}

async function runBacktest() {
    if (!await ensureSaved()) return;
    showNudge.value = !!aiStatus.value?.nudge?.show;
    btBusy.value = true; btResult.value = null; errors.value = [];
    try {
        const products = btForm.value.products.split(',').map((s) => s.trim()).filter(Boolean);
        const res = await api.post(`/strategy-plugins/${currentId.value}/backtest`, {
            days: btForm.value.days, cash: btForm.value.cash, products: products.length ? products : undefined,
        });
        btResult.value = res;
    } catch (e) { errors.value = [e.message]; }
    btBusy.value = false;
}

async function sendChat() {
    const text = draft.value.trim();
    if (!text || chatBusy.value) return;
    if (!aiStatus.value.connected) { chat.value.push({ role: 'assistant', content: 'Connect your OpenRouter account first — the button above starts the sign-in.' }); return; }
    const model = orModel.value === '__custom__' ? customModel.value.trim() : orModel.value.trim();
    if (!model) { chat.value.push({ role: 'assistant', content: 'Pick a model or paste a custom model id first.' }); return; }
    localStorage.setItem('smx_or_model', model);
    chat.value.push({ role: 'user', content: text });
    draft.value = '';
    chatBusy.value = true;
    try {
        const res = await api.post('/strategy-assist', { messages: chat.value.slice(-20), model });
        chat.value.push({ role: 'assistant', content: res.content || '(empty reply)' });
        await loadAiUsage();
    } catch (e) { chat.value.push({ role: 'assistant', content: 'Error: ' + e.message }); }
    chatBusy.value = false;
}

function useJsonFromChat(content) {
    const m = content.match(/```json\s*([\s\S]*?)```/);
    if (m) {
        doc.value = m[1].trim(); errors.value = []; valid.value = null; saveMsg.value = '';
        const imp = parsed();
        if (imp.ok) { applySuggest(imp.value); save(); }
    }
}

onMounted(() => {
    // Keys are no longer pasted anywhere; drop any one an older build left behind.
    localStorage.removeItem('smx_or_key');
    loadSaved();
    loadModels();
    loadAiStatus();
    loadSyncStatus();
});

// Strategy Agent — walks Setup → Trigger → Entry → Management → Exit → Risk → Review,
// one phase at a time, via /agent/conversations. Independent of the SMX assist chat above.
const agentConvId = ref(null);
const agentMessages = ref([]);
const agentDraft = ref('');
const agentBusy = ref(false);
const agentPhase = ref(1);
const agentToolEvents = ref([]);
const agentShareCard = ref(null);
const publishBusy = ref(false);
const publishMsg = ref('');
const AGENT_PHASE_LABELS = ['Setup', 'Trigger', 'Entry', 'Management', 'Exit', 'Risk', 'Review'];

async function publishToHub() {
    if (!agentShareCard.value || publishBusy.value) return;
    publishBusy.value = true;
    publishMsg.value = '';
    try {
        const res = await api.post(`/strategy-plugins/${agentShareCard.value.plugin_id}/publish`, { version: agentShareCard.value.version });
        publishMsg.value = `Published v${res.version} to the hub.`;
        agentShareCard.value = null;
    } catch (e) {
        publishMsg.value = 'Error: ' + e.message;
    }
    publishBusy.value = false;
}

async function ensureAgentConversation() {
    if (agentConvId.value) return;
    const res = await api.post('/agent/conversations', currentId.value ? { plugin_id: currentId.value } : {});
    agentConvId.value = res.id;
    agentPhase.value = res.phase || 1;
}

async function sendAgentMessage() {
    const text = agentDraft.value.trim();
    if (!text || agentBusy.value) return;
    await ensureAgentConversation();
    agentMessages.value.push({ role: 'user', content: text });
    agentDraft.value = '';
    agentBusy.value = true;
    try {
        const res = await api.post(`/agent/conversations/${agentConvId.value}/turn`, { message: text });
        agentMessages.value.push({ role: 'assistant', content: res.content || '(no reply)' });
        agentPhase.value = res.phase || agentPhase.value;
        agentToolEvents.value = res.tool_events || [];
        const share = (res.tool_events || []).find((e) => e.tool === 'suggest_share');
        if (share) agentShareCard.value = share.result;
    } catch (e) {
        agentMessages.value.push({ role: 'assistant', content: 'Error: ' + e.message });
    }
    agentBusy.value = false;
}
</script>

<template>
    <div class="builder">
        <h1>Strategy Builder</h1>
        <p class="sub">
            Describe your edge in JSON. Validate it, save it, export it — and when it's a winner,
            open a PR against the public strategies repo. Full schema: <code>docs/STRATEGY_PLUGIN.md</code>.
        </p>
        <div class="grid">
            <section class="editor">
                <h2>Plugin JSON</h2>
                <textarea v-model="doc" spellcheck="false" rows="28"></textarea>
                <div class="row">
                    <button :disabled="busy" @click="validate">Validate</button>
                    <select v-model="bump" title="version bump">
                        <option value="patch">patch bump</option>
                        <option value="minor">minor bump</option>
                        <option value="major">major bump</option>
                    </select>
                    <input v-model="changelog" placeholder="changelog (optional)" style="flex: 1" />
                    <button :disabled="busy" @click="save">Save{{ currentVersion ? ` (v${currentVersion})` : '' }}</button>
                    <label class="file">Import .json<input type="file" accept=".json,application/json" @change="importFile" hidden /></label>
                    <span v-if="valid === true" class="ok">Valid</span>
                    <span v-if="valid === false" class="bad">Invalid</span>
                    <span v-if="saveMsg" class="ok">{{ saveMsg }}</span>
                </div>
                <div class="row">
                    <span>Export:</span>
                    <button @click="exportFmt('json')">JSON</button>
                    <button @click="exportFmt('pine')">Pine</button>
                    <button @click="exportFmt('md')">Markdown</button>
                </div>
                <p class="hint">Have Pine or Markdown? Paste it into the SMX chat and ask to convert it to plugin JSON.</p>
                <h2>Backtest this plugin</h2>
                <div class="row">
                    <input v-model.number="btForm.days" type="number" min="1" max="365" style="max-width: 5rem" title="days" />
                    <input v-model.number="btForm.cash" type="number" min="1" style="max-width: 6rem" title="cash" />
                    <input v-model="btForm.products" placeholder="BTC-USD,ETH-USD" style="flex: 2" />
                    <button :disabled="btBusy" @click="runBacktest">{{ btBusy ? 'Queued…' : 'Run backtest' }}</button>
                </div>
                <div v-if="showNudge" class="nudge">
                    {{ aiStatus.nudge.reason }}
                    <button @click="orModel = aiStatus.nudge.model; savePrefs(); showNudge = false">Switch to {{ aiStatus.nudge.model }}</button>
                    <button @click="showNudge = false">Stay on {{ aiStatus.default_model }}</button>
                </div>
                <div v-if="btResult" class="bt">
                    Backtest <router-link :to="`/backtests/${btResult.id}`">#{{ btResult.id }}</router-link> queued.
                    <router-link :to="`/chart/${(btResult.products || ['BTC-USD'])[0]}?backtest=${btResult.id}`">View fills on the TradingView chart</router-link>
                </div>
                <ul v-if="errors.length" class="errors">
                    <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
                </ul>
                <h2>Community strategies</h2>
                <div class="community">
                    <p v-if="communitySync" class="hint">
                        {{ communitySync.new.length }} new / {{ communitySync.updated.length }} updated available.
                        <button v-if="communitySync.new.length" :disabled="communityBusy" @click="importAllNew">Import all new</button>
                    </p>
                    <ul v-if="communitySync && (communitySync.new.length || communitySync.updated.length)" class="community-list">
                        <li v-for="e in [...communitySync.new, ...communitySync.updated]" :key="e.id">
                            <strong>{{ e.name }}</strong>
                            <span class="hint">v{{ e.version }} — {{ e.id }}</span>
                            <button :disabled="communityBusy" @click="importStrategy(e.id)">Import</button>
                        </li>
                    </ul>
                </div>
                <h2>Saved plugins</h2>
                <ul class="saved">
                    <li v-for="p in saved" :key="p.id">
                        <a href="#" @click.prevent="loadOne(p.id)"><strong>{{ p.key }}</strong> — {{ p.name }}</a>
                    </li>
                    <li v-if="!saved.length"><em>None yet.</em></li>
                </ul>
                <template v-if="currentId">
                    <h2>Versions</h2>
                    <ul class="versions">
                        <li v-for="v in versions" :key="v.id" :class="{ current: v.version === currentVersion }">
                            <strong>v{{ v.version }}</strong>
                            <span v-if="v.version === currentVersion" class="ok">current</span>
                            <span v-if="v.changelog" class="hint"> — {{ v.changelog }}</span>
                            <button :disabled="versionBusy || v.version === currentVersion" @click="restoreVersion(v.version)">Restore</button>
                        </li>
                        <li v-if="!versions.length"><em>No versions yet — save to create v1.0.0.</em></li>
                    </ul>
                    <div class="row">
                        <button @click="toggleAutoBacktest">{{ autoBacktest ? 'Stop the 24/7 worker' : 'Backtest this 24/7' }}</button>
                        <small class="hint">The worker rebacktests the current version every hour and files a verdict.</small>
                    </div>
                    <div v-if="reviews.length" class="review">
                        <strong>Latest agent review: {{ reviews[0].verdict }}</strong>
                        <span class="hint"> ({{ reviews[0].model }})</span>
                        <p v-if="reviews[0].notes">{{ reviews[0].notes }}</p>
                    </div>
                </template>
            </section>
            <section class="assist">
                <h2>SMX AI assist</h2>
                <div class="prefs">
                    <div v-if="!aiStatus.connected" class="row">
                        <button @click="connectOpenRouter">Connect OpenRouter</button>
                        <small>Sign in once. No key ever touches this browser.</small>
                    </div>
                    <div v-else class="row">
                        <small class="usage">
                            Connected{{ aiStatus.label ? ` as ${aiStatus.label}` : '' }}
                            <template v-if="aiUsage"> — {{ aiUsage.calls }} calls today · ${{ aiUsage.cost_usd.toFixed(4) }} today</template>
                        </small>
                    </div>
                    <select v-model="orModel" @change="useCustom = orModel === '__custom__'; savePrefs()">
                        <optgroup label="Recommended">
                            <option v-for="m in recommendedModels" :key="m.id" :value="m.id">{{ m.id }}</option>
                        </optgroup>
                        <optgroup label="Free">
                            <option v-for="m in freeModels" :key="m.id" :value="m.id">{{ m.id }}</option>
                        </optgroup>
                        <option value="__custom__">Custom model id…</option>
                    </select>
                    <input v-if="useCustom || orModel === '__custom__'" v-model="customModel" placeholder="paste any OpenRouter model id" @change="orModel = customModel; savePrefs()" />
                    <small>Both lists are live from OpenRouter.</small>
                </div>
                <div class="chat">
                    <div v-for="(m, i) in chat" :key="i" :class="['msg', m.role]">
                        <pre>{{ m.content }}</pre>
                        <button v-if="m.role === 'assistant' && m.content.includes('```json')" @click="useJsonFromChat(m.content)">Use this JSON</button>
                    </div>
                    <div v-if="!chat.length" class="empty"><em>Ask SMX to draft a strategy, e.g. “oversold H1 dip with volume surge, tight spread”.</em></div>
                </div>
                <div class="row">
                    <input v-model="draft" placeholder="Describe your strategy…" @keyup.enter="sendChat" />
                    <button :disabled="chatBusy" @click="sendChat">{{ chatBusy ? '…' : 'Send' }}</button>
                </div>
            </section>
        </div>
        <section class="agent">
            <h2>Strategy Agent</h2>
            <p class="hint">Walks you through Setup → Trigger → Entry → Management → Exit → Risk → Review, one phase at a time.</p>
            <div class="phase-track">
                <span v-for="(label, i) in AGENT_PHASE_LABELS" :key="i" :class="['phase', { current: agentPhase === i + 1, done: agentPhase > i + 1 }]">{{ i + 1 }}. {{ label }}</span>
            </div>
            <div class="chat">
                <div v-for="(m, i) in agentMessages" :key="i" :class="['msg', m.role]"><pre>{{ m.content }}</pre></div>
                <div v-if="!agentMessages.length" class="empty"><em>Describe your setup to get started.</em></div>
            </div>
            <div v-if="agentToolEvents.length" class="tool-events">
                <div v-for="(e, i) in agentToolEvents" :key="i" class="tool-card"><strong>{{ e.tool }}</strong> <span class="hint">{{ JSON.stringify(e.result) }}</span></div>
            </div>
            <div v-if="agentShareCard" class="share-card">
                {{ agentShareCard.message }}
                <button v-if="agentShareCard.action === 'publish'" :disabled="publishBusy" @click="publishToHub">{{ publishBusy ? '…' : 'Publish' }}</button>
            </div>
            <div v-if="publishMsg" class="hint">{{ publishMsg }}</div>
            <div class="row">
                <input v-model="agentDraft" placeholder="Describe your setup…" @keyup.enter="sendAgentMessage" />
                <button :disabled="agentBusy" @click="sendAgentMessage">{{ agentBusy ? '…' : 'Send' }}</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.builder { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
.sub { opacity: 0.8; }
.grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 1.5rem; }
@media (max-width: 1000px) { .grid { grid-template-columns: 1fr; } }
textarea { width: 100%; font-family: monospace; font-size: 0.85rem; }
.row { display: flex; gap: 0.5rem; margin: 0.5rem 0; align-items: center; }
.row input { flex: 1; }
.ok { color: green; } .bad { color: red; }
.errors { color: red; }
.chat { border: 1px solid #ccc; min-height: 300px; max-height: 60vh; overflow-y: auto; padding: 0.5rem; }
.msg { margin: 0.5rem 0; padding: 0.5rem; border-radius: 6px; }
.msg.user { background: #eef; }
.msg.assistant { background: #f5f5f5; }
.msg pre { white-space: pre-wrap; margin: 0; font-size: 0.85rem; }
.prefs { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.5rem; }
.prefs .row { margin: 0; }
.usage { opacity: 0.8; }
.nudge { border: 1px solid #ccc; border-left: 3px solid #888; padding: 0.5rem; margin: 0.5rem 0; font-size: 0.9rem; }
.nudge button { margin-left: 0.5rem; }
.saved { list-style: none; padding: 0; }
.community-list { list-style: none; padding: 0; }
.community-list li { display: flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0; }
.versions { list-style: none; padding: 0; }
.versions li { display: flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0; }
.versions li.current { font-weight: 600; }
.versions .hint { opacity: 0.8; }
.hint { opacity: 0.8; }
.review { border: 1px solid #ccc; border-left: 3px solid #888; padding: 0.5rem; margin: 0.5rem 0; font-size: 0.9rem; }
.review p { margin: 0.3rem 0 0; }
.agent { margin-top: 1.5rem; }
.phase-track { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.5rem 0; }
.phase { padding: 0.2rem 0.5rem; border-radius: 4px; background: #eee; font-size: 0.8rem; }
.phase.current { background: #d6e4ff; font-weight: 600; }
.phase.done { background: #dff5df; }
.tool-events { margin: 0.5rem 0; }
.tool-card { padding: 0.3rem 0.5rem; margin: 0.2rem 0; background: #f5f5f5; border-radius: 4px; font-size: 0.8rem; }
.share-card { padding: 0.5rem; margin: 0.5rem 0; background: #fff7e0; border-radius: 6px; }
.share-card button { margin-left: 0.5rem; }
</style>
