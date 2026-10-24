<script setup>
import { ref, computed, nextTick, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import PageHeading from "../components/PageHeading.vue";
import { deskFetch } from "../api.js";

const router = useRouter();

const STEP_LABELS = {
    "master-password": "Master password",
    openrouter: "Connect an AI model",
    exchange: "Pick an exchange",
    "strategy-import": "Import a strategy",
    launch: "Launch",
};

const loading = ref(true);
const error = ref("");
const steps = ref([]);
const currentStep = ref(null);
const busy = ref(false);

// Failures carry step data in the JSON body (ApiError.data). Setting the master password marks this
// browser's session as logged in.
const call = (method, url, body) => deskFetch("/api" + url, { method, body });

const bootstrapPassword = ref(false);
const currentPasswordRequired = ref(false);

async function refresh() {
    const state = await call("GET", "/onboarding");
    steps.value = state.steps;
    currentStep.value = state.next_step;
    bootstrapPassword.value = !!state.bootstrap;
    currentPasswordRequired.value = !!state.current_password_required;
    if (state.completed) router.replace("/dashboard");
    return state;
}

async function load() {
    loading.value = true;
    error.value = "";
    try {
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

const stepIndex = computed(() => steps.value.findIndex((s) => s.key === currentStep.value));

// Step 1 — master password.
const password = ref("");
const passwordConfirmation = ref("");
const currentPassword = ref("");
const MIN_PASSWORD = 12;
const MAX_PASSWORD_BYTES = 72;
const passwordBytes = computed(() => new TextEncoder().encode(password.value).length);
const passwordMismatch = computed(
    () => passwordConfirmation.value !== "" && password.value !== passwordConfirmation.value,
);
const passwordTooShort = computed(() => password.value !== "" && password.value.length < MIN_PASSWORD);
const passwordTooLong = computed(() => passwordBytes.value > MAX_PASSWORD_BYTES);
const canSubmitPassword = computed(
    () =>
        password.value.length >= MIN_PASSWORD &&
        !passwordTooLong.value &&
        password.value === passwordConfirmation.value &&
        (!currentPasswordRequired.value || currentPassword.value !== ""),
);
async function submitPassword() {
    if (!canSubmitPassword.value) return;
    busy.value = true;
    error.value = "";
    try {
        const body = { password: password.value, password_confirmation: passwordConfirmation.value };
        if (currentPasswordRequired.value) body.current_password = currentPassword.value;
        const res = await call("POST", "/onboarding/master-password", body);
        // Logging in rotates the session's CSRF token; later steps must send the new one.
        document.querySelector('meta[name="csrf-token"]')?.setAttribute("content", res.csrf_token);
        password.value = "";
        passwordConfirmation.value = "";
        currentPassword.value = "";
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Step 2 — OpenRouter: paste a key (primary path) or use the existing OAuth connect link.
const apiKey = ref("");
const openRouterLabel = ref("");
async function submitOpenRouter() {
    busy.value = true;
    error.value = "";
    try {
        const res = await call("POST", "/onboarding/openrouter", { api_key: apiKey.value });
        openRouterLabel.value = res.label || "";
        apiKey.value = "";
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Step 3 — exchange.
const exchanges = ref([]);
const exchangesLoaded = ref(false);
const selectedExchange = ref("coinbase");
async function loadExchanges() {
    try {
        const res = await call("GET", "/exchanges");
        exchanges.value = res.data || [];
    } catch (e) {
        error.value = e.message;
    } finally {
        exchangesLoaded.value = true;
    }
}
async function submitExchange() {
    busy.value = true;
    error.value = "";
    try {
        await call("POST", "/onboarding/exchange", { exchange: selectedExchange.value });
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Step 4 — community strategy import (skippable).
const syncStatus = ref(null);
const syncLoaded = ref(false);
const selectedRemoteId = ref("");
async function loadSync() {
    try {
        syncStatus.value = await call("GET", "/strategies/sync/status");
        selectedRemoteId.value = syncStatus.value?.new?.[0]?.id || "";
    } catch (e) {
        // The community source can be unreachable without blocking onboarding — skip stays available.
        syncStatus.value = null;
    } finally {
        syncLoaded.value = true;
    }
}
async function submitStrategyImport() {
    if (!selectedRemoteId.value) return;
    busy.value = true;
    error.value = "";
    try {
        await call("POST", "/onboarding/strategy-import", { remote_id: selectedRemoteId.value });
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function skipStrategyImport() {
    busy.value = true;
    error.value = "";
    try {
        await call("POST", "/onboarding/strategy-import", { skip: true });
        await refresh();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Step 5 — launch, with an optional (skippable) hub registration first.
const wantsHub = ref(false);
const hub = ref({ email: "", handle: "", password: "", desk_name: "" });
const hubConnected = ref(false);
async function registerHub() {
    busy.value = true;
    error.value = "";
    try {
        await call("POST", "/hub/register", hub.value);
        hubConnected.value = true;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function launch() {
    busy.value = true;
    error.value = "";
    try {
        await call("POST", "/onboarding/launch", {});
        router.push("/dashboard");
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function onStepChange(step) {
    if (step === "exchange" && !exchangesLoaded.value) loadExchanges();
    if (step === "strategy-import" && !syncLoaded.value) loadSync();
}

const stepPanel = ref(null);
// The panel swaps in place, so move focus to its heading or keyboard and screen-reader users stay on a removed button.
watch(currentStep, async (step, previous) => {
    onStepChange(step);
    if (!previous) return;
    await nextTick();
    stepPanel.value?.querySelector("h2")?.focus();
});

onMounted(async () => {
    await load();
    onStepChange(currentStep.value);
});
</script>

<template>
    <div class="smx-page space-y-4 onboarding-page">
        <PageHeading
            title="Set up the desk"
            eyebrow="First run"
            description="Five short steps from no key to paper trading. Progress is saved after every step — close this and come back any time."
        />

        <div v-if="error" class="mw-error" role="alert">{{ error }}</div>

        <div v-if="loading" class="card">Loading…</div>

        <template v-else>
            <div class="card onboarding-steps">
                <ol class="onboarding-stepper">
                    <li v-for="(s, i) in steps" :key="s.key" :class="{ done: s.status !== 'pending', current: i === stepIndex }" :aria-current="i === stepIndex ? 'step' : undefined">
                        <span class="badge" :class="s.status === 'pending' ? 'bg-zinc-800 text-zinc-400' : 'bg-emerald-500/20 text-emerald-300'">{{ i + 1 }}</span>
                        {{ STEP_LABELS[s.key] }}
                        <span v-if="s.status === 'skipped'" class="text-xs text-zinc-500">(skipped)</span>
                    </li>
                </ol>
            </div>

            <div v-if="currentStep === 'master-password'" :ref="(el) => (stepPanel = el)" class="card space-y-3">
                <div class="mw-section-title">
                    <div>
                        <span class="mw-eyebrow">STEP 1 OF 5</span>
                        <h2 tabindex="-1">Set a master password</h2>
                    </div>
                </div>
                <p class="text-sm text-zinc-400">
                    Choose the password that protects this desk. You'll type it once to sign in, and this
                    browser stays signed in for about a year. At least {{ MIN_PASSWORD }} characters,
                    up to {{ MAX_PASSWORD_BYTES }} bytes. Some characters use more than one byte.
                </p>
                <p v-if="bootstrapPassword" class="text-sm text-zinc-400">
                    Your first-login key only works once: after you set a password it stops opening anything.
                </p>
                <input
                    v-if="currentPasswordRequired"
                    v-model="currentPassword"
                    type="password"
                    autocomplete="current-password"
                    placeholder="current password"
                    aria-label="Current password"
                    :disabled="busy"
                    @keyup.enter="submitPassword"
                />
                <input
                    v-model="password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="`new password (${MIN_PASSWORD}+ characters)`"
                    aria-label="New password"
                    :disabled="busy"
                    @keyup.enter="submitPassword"
                />
                <p v-if="passwordTooShort" class="text-sm text-red-400" role="alert">
                    Use at least {{ MIN_PASSWORD }} characters.
                </p>
                <p v-if="passwordTooLong" class="text-sm text-red-400" role="alert">
                    Use at most {{ MAX_PASSWORD_BYTES }} bytes. This password uses {{ passwordBytes }} bytes.
                </p>
                <input
                    v-model="passwordConfirmation"
                    type="password"
                    autocomplete="new-password"
                    placeholder="confirm new password"
                    aria-label="Confirm new password"
                    :disabled="busy"
                    @keyup.enter="submitPassword"
                />
                <p v-if="passwordMismatch" class="text-sm text-red-400" role="alert">
                    The two passwords do not match.
                </p>
                <button class="btn btn-primary" :disabled="busy || !canSubmitPassword" @click="submitPassword">Continue</button>
            </div>

            <div v-else-if="currentStep === 'openrouter'" :ref="(el) => (stepPanel = el)" class="card space-y-3">
                <div class="mw-section-title">
                    <div>
                        <span class="mw-eyebrow">STEP 2 OF 5</span>
                        <h2 tabindex="-1">Connect an AI model</h2>
                    </div>
                </div>
                <p class="text-sm text-zinc-400">
                    Paste an OpenRouter API key — it's stored encrypted, never written to <code>.env</code>. Or
                    <a href="/ai/openrouter/connect">connect via OpenRouter's own sign-in</a> (that path lands you
                    on the strategy builder afterward instead of back here).
                </p>
                <input v-model="apiKey" type="password" placeholder="sk-or-v1-…" aria-label="OpenRouter API key" :disabled="busy" @keyup.enter="apiKey && submitOpenRouter()" />
                <button class="btn btn-primary" :disabled="busy || !apiKey" @click="submitOpenRouter">Continue</button>
            </div>

            <div v-else-if="currentStep === 'exchange'" :ref="(el) => (stepPanel = el)" class="card space-y-3">
                <div class="mw-section-title">
                    <div>
                        <span class="mw-eyebrow">STEP 3 OF 5</span>
                        <h2 tabindex="-1">Pick an exchange</h2>
                    </div>
                </div>
                <p class="text-sm text-zinc-400">Coinbase, paper mode, is the default — good for a first run.</p>
                <div v-if="!exchangesLoaded">Loading exchanges…</div>
                <div v-else class="onboarding-exchange-list">
                    <label v-for="x in exchanges" :key="x.id" class="onboarding-radio">
                        <input type="radio" :value="x.id" v-model="selectedExchange" />
                        {{ x.name }}
                        <span class="badge" :class="x.kind === 'native' ? 'bg-sky-500/20 text-sky-300' : 'bg-zinc-800 text-zinc-300'">{{ x.kind }}</span>
                    </label>
                </div>
                <button class="btn btn-primary" :disabled="busy || !selectedExchange" @click="submitExchange">Continue with {{ selectedExchange }}, paper</button>
            </div>

            <div v-else-if="currentStep === 'strategy-import'" :ref="(el) => (stepPanel = el)" class="card space-y-3">
                <div class="mw-section-title">
                    <div>
                        <span class="mw-eyebrow">STEP 4 OF 5 · OPTIONAL</span>
                        <h2 tabindex="-1">Import a community strategy</h2>
                    </div>
                </div>
                <div v-if="!syncLoaded">Checking the community archive…</div>
                <template v-else-if="syncStatus && syncStatus.new && syncStatus.new.length">
                    <div class="onboarding-exchange-list">
                        <label v-for="entry in syncStatus.new" :key="entry.id" class="onboarding-radio">
                            <input type="radio" :value="entry.id" v-model="selectedRemoteId" />
                            {{ entry.name }}
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn btn-primary" :disabled="busy || !selectedRemoteId" @click="submitStrategyImport">Import</button>
                        <button class="btn" :disabled="busy" @click="skipStrategyImport">Skip for now</button>
                    </div>
                </template>
                <template v-else>
                    <p class="text-sm text-zinc-400">No new community strategies to import right now.</p>
                    <button class="btn btn-primary" :disabled="busy" @click="skipStrategyImport">Continue</button>
                </template>
            </div>

            <div v-else-if="currentStep === 'launch'" :ref="(el) => (stepPanel = el)" class="card space-y-3">
                <div class="mw-section-title">
                    <div>
                        <span class="mw-eyebrow">STEP 5 OF 5</span>
                        <h2 tabindex="-1">Launch, in paper mode</h2>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="onboarding-radio">
                        <input type="checkbox" v-model="wantsHub" :disabled="hubConnected" />
                        Register with the community hub (optional — leaderboards, contests, shared archive)
                    </label>
                    <template v-if="wantsHub && !hubConnected">
                        <input v-model="hub.email" placeholder="email" aria-label="Hub email" :disabled="busy" />
                        <input v-model="hub.handle" placeholder="handle" aria-label="Hub handle" :disabled="busy" />
                        <input v-model="hub.password" type="password" placeholder="password (min 8 characters)" aria-label="Hub password" :disabled="busy" @keyup.enter="registerHub" />
                        <button class="btn" :disabled="busy" @click="registerHub">Register</button>
                    </template>
                    <p v-if="hubConnected" class="text-sm text-emerald-300">Hub account connected.</p>
                </div>

                <button class="btn btn-ok" :disabled="busy" @click="launch">Start paper trading</button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.onboarding-page {
    color-scheme: dark;
    color: #e4f4ff;
    background: #02080d;
    font-size: 20px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.onboarding-page .card {
    color: #e4f4ff;
    background: #07111b;
    border-color: #365772;
}
.onboarding-page :is(.text-zinc-400, .text-zinc-500, .text-zinc-600) {
    color: #b5cbdc;
}
.onboarding-page a {
    color: #9dddff;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.onboarding-page p,
.onboarding-page label,
.onboarding-page .btn {
    font-size: 20px;
    line-height: 1.6;
}
.onboarding-page :is(input, select, textarea) {
    /* Keep the form readable despite the shared workspace's smaller mobile controls. */
    font-size: 20px !important;
    line-height: 1.5;
    color: #f3f8ff;
    caret-color: #f3f8ff;
    background: #02080d;
    border: 1px solid #52728b;
}
.onboarding-page :is(input:not([type="radio"]):not([type="checkbox"]), select, textarea) {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: 100%;
}
.onboarding-page input::placeholder,
.onboarding-page textarea::placeholder {
    color: #c2d2df;
    opacity: 1;
}
.onboarding-page :is(input, select, textarea):disabled {
    color: #b9cad8;
    background: #122332;
    opacity: 1;
}
.onboarding-page .btn {
    color: #e9f6ff;
    background: #153f5b;
    border-color: #4c99c8;
}
.onboarding-page .btn-ok {
    color: #d0f6e7;
    background: #10372c;
    border-color: #4b9d83;
}
.onboarding-page .btn:disabled {
    color: #b9cad8;
    background: #1a2937;
    border-color: #486174;
    opacity: 1;
}
.onboarding-page :is(.btn, input, select, textarea):focus-visible {
    outline: 2px solid #9dddff;
    outline-offset: 3px;
}
.onboarding-page :is(.badge, .text-xs, .mw-eyebrow, .mw-error) {
    font-size: 18px;
    line-height: 1.5;
    letter-spacing: normal;
}
.onboarding-page .mw-section-title h2 {
    color: #f3f8ff;
    font-size: clamp(26px, 3vw, 34px);
    line-height: 1.3;
}
.onboarding-page .mw-eyebrow {
    color: #9dddff;
}
.onboarding-page .badge {
    color: #e7f4ff;
    background: #243c4e;
}
.onboarding-page .text-red-400,
.onboarding-page .mw-error {
    color: #ffb1bd;
}
.onboarding-page :deep(.smx-experience.smx-page-heading) {
    padding-bottom: 80px;
}
.onboarding-page :deep(.smx-experience .smx-experience__description) {
    font-size: 20px;
    line-height: 1.6;
}
.onboarding-page :deep(.smx-experience__eyebrow),
.onboarding-page :deep(.smx-experience__signature),
.onboarding-page :deep(.smx-experience__signals),
.onboarding-page :deep(.smx-experience__motion) {
    font-size: 18px;
    line-height: 1.5;
    letter-spacing: normal;
}
.onboarding-page :deep(.smx-experience__chapter) {
    white-space: normal;
}
.onboarding-stepper {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    list-style: none;
    padding: 0;
    margin: 0;
}
.onboarding-stepper li {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem;
    color: #bdd0e0;
    font-size: 18px;
}
.onboarding-stepper li.done,
.onboarding-stepper li.current {
    color: inherit;
    font-weight: 600;
}
.onboarding-exchange-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.onboarding-radio {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
@media (max-width: 700px) {
    .onboarding-page :deep(.smx-experience__copy) {
        max-width: 100%;
    }
    .onboarding-page :deep(.smx-chapter-scene) {
        opacity: 0.18;
    }
    .onboarding-radio {
        flex-wrap: wrap;
    }
    .onboarding-page .btn {
        max-width: 100%;
        white-space: normal;
        text-align: center;
    }
}
</style>
