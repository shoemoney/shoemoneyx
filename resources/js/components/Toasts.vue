<script setup>
import { toasts } from '../toasts';
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-1.5">
        <TransitionGroup name="toast">
            <div v-for="t in toasts.list" :key="t.id" class="flex items-start gap-2 rounded-md border bg-zinc-950/95 px-3 py-2 text-xs shadow-lg backdrop-blur" :class="t.cls">
                <span class="mt-0.5 w-4 shrink-0 text-center"><i :class="t.icon"></i><span class="fa-fallback">{{ t.glyph }}</span></span>
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ t.title }}</div>
                    <div v-if="t.detail" class="truncate font-mono text-[10px] opacity-80">{{ t.detail }}</div>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active { transition: all 120ms ease-out; }
.toast-leave-active { transition: all 220ms ease-in; }
.toast-enter-from { opacity: 0; transform: translateX(16px); }
.toast-leave-to { opacity: 0; transform: translateY(6px); }
/* Font Awesome is bundled (resources/js/fontawesome.js); the glyph is a screen-reader/no-JS fallback only. */
.fa-fallback { display: none; }
</style>
