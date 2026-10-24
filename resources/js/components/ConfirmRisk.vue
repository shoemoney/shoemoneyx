<script setup>
import { ref, watch, nextTick, computed } from "vue";
import { trapTab } from "../riskConfirm";

// Reusable confirmation for risky or destructive changes. Shows what will happen in plain words, the
// old -> new values, optional scope choices and a cancel. The parent owns the request (see
// useConfirmRisk in riskConfirm.js) and reacts to `confirm` (with the picked choice) or `cancel`.
const props = defineProps({
    request: { type: Object, default: null },
});
const emit = defineEmits(["confirm", "cancel"]);

const uid = Math.random().toString(36).slice(2, 8);
const titleId = `confirm-risk-title-${uid}`;
const bodyId = `confirm-risk-body-${uid}`;
const dialog = ref(null);
const cancelButton = ref(null);
const picked = ref(null);
let returnFocus = null;

const choices = computed(() => props.request?.choices ?? []);

watch(
    () => props.request,
    async (now, before) => {
        if (now && !before) {
            returnFocus = globalThis.document?.activeElement ?? null;
            picked.value = now.choices?.[0]?.value ?? null;
            await nextTick();
            const first = dialog.value?.querySelector("input[type=radio]:checked") ?? cancelButton.value;
            first?.focus();
        } else if (!now && before) {
            const target = returnFocus;
            returnFocus = null;
            await nextTick();
            target?.focus?.();
        }
    },
);

function onKeydown(event) {
    if (event.key === "Escape") {
        event.stopPropagation();
        emit("cancel");
        return;
    }
    trapTab(event, dialog.value);
}

function confirm() {
    emit("confirm", choices.value.length ? picked.value : null);
}
</script>

<template>
    <div
        v-if="request"
        class="risk-confirm fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
        @click.self="emit('cancel')"
    >
        <div
            ref="dialog"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            :aria-describedby="bodyId"
            class="risk-confirm__dialog w-full max-w-lg rounded-lg border bg-zinc-900 p-5 shadow-2xl"
            :class="request.danger ? 'border-red-500/50' : 'border-zinc-700'"
            @keydown="onKeydown"
        >
            <h2 :id="titleId" class="risk-confirm__title font-semibold text-zinc-100">
                {{ request.title }}
            </h2>
            <div :id="bodyId" class="mt-2 space-y-2 text-zinc-300">
                <p v-for="(line, i) in request.body ?? []" :key="i">{{ line }}</p>
            </div>

            <dl
                v-if="request.changes?.length"
                class="mt-3 divide-y divide-zinc-800 rounded-md border border-zinc-800"
                aria-label="What will change"
            >
                <div
                    v-for="change in request.changes"
                    :key="change.label"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                >
                    <dt class="risk-confirm__label w-full font-medium text-zinc-300">{{ change.label }}</dt>
                    <dd class="risk-confirm__values w-full">
                        <span>
                            <span class="risk-confirm__label block text-zinc-300">Current</span>
                            <code class="text-zinc-300">{{ change.from }}</code>
                        </span>
                        <span>
                            <span class="risk-confirm__label block text-zinc-300">New</span>
                            <code class="font-semibold text-zinc-100">{{ change.to }}</code>
                        </span>
                    </dd>
                </div>
            </dl>

            <fieldset v-if="choices.length" class="mt-3 space-y-2">
                <legend class="risk-confirm__label mb-1 font-medium text-zinc-300">Choose how far this goes</legend>
                <label
                    v-for="choice in choices"
                    :key="choice.value"
                    class="risk-confirm__choice flex cursor-pointer items-start gap-2 rounded-md border border-zinc-800 p-2"
                    :class="picked === choice.value ? 'border-zinc-500 bg-zinc-800/60' : ''"
                >
                    <input
                        v-model="picked"
                        type="radio"
                        :name="`confirm-risk-scope-${uid}`"
                        :value="choice.value"
                        class="mt-1"
                    />
                    <span>
                        <strong class="block text-zinc-100">{{ choice.label }}</strong>
                        <span v-if="choice.description" class="risk-confirm__label block text-zinc-300">{{ choice.description }}</span>
                    </span>
                </label>
            </fieldset>

            <div class="risk-confirm__actions mt-4 flex flex-wrap justify-end gap-2">
                <button ref="cancelButton" type="button" class="btn" @click="emit('cancel')">
                    {{ request.cancelLabel ?? "Cancel" }}
                </button>
                <button
                    type="button"
                    class="btn"
                    :class="request.danger ? 'btn-danger' : 'btn-primary'"
                    @click="confirm"
                >
                    {{ request.confirmLabel ?? "Confirm" }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.risk-confirm__dialog {
    max-height: calc(100vh - 32px);
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    font-size: 20px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.risk-confirm__title {
    font-size: 24px;
    line-height: 1.5;
}

.risk-confirm__label {
    font-size: 18px;
    line-height: 1.5;
}

.risk-confirm__values {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.risk-confirm__values > span,
.risk-confirm__choice > span {
    min-width: 0;
}

.risk-confirm__values code {
    font-size: 20px;
    line-height: 1.5;
    white-space: pre-wrap;
}

.risk-confirm__choice input {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
}

.risk-confirm__actions .btn {
    min-width: 0;
    max-width: 100%;
    min-height: 48px;
    padding: 8px 16px;
    font-size: 20px;
    line-height: 1.5;
    white-space: normal;
}

.risk-confirm__dialog :is(button, input):focus-visible {
    outline: 3px solid #fbbf24;
    outline-offset: 3px;
}
</style>
