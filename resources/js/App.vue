<script setup>
import { ref, onMounted, onUnmounted, provide, watch } from "vue";
import { api } from "./api";
import Toasts from "./components/Toasts.vue";
import { useRoute } from "vue-router";
import { publishDeskStatus } from "./siteShell";

const route = useRoute();
const status = ref(null);
const error = ref("");
let timer;
let requestId = 0;
async function refresh() {
    if (route.meta.landing) return;
    const id = ++requestId;
    try {
        const response = await api.get("/status");
        if (id !== requestId || route.meta.landing) return;
        status.value = response;
        error.value = "";
    } catch (e) {
        if (id !== requestId || route.meta.landing) return;
        error.value = e.message;
    }
}
provide("status", status);
provide("statusError", error);
provide("refreshStatus", refresh);
onMounted(() => {
    refresh();
    timer = setInterval(refresh, 10000);
});
onUnmounted(() => {
    clearInterval(timer);
    requestId++;
});
watch(
    () => route.meta.landing,
    (isLanding) => {
        if (!isLanding) refresh();
        else requestId++;
    },
);
watch(
    [status, error, () => route.path],
    () => publishDeskStatus(status.value, error.value),
    { flush: "post", immediate: true },
);
</script>

<template>
    <Toasts />
    <router-view
        v-if="route.meta.landing"
        :key="
            route.meta.frontPage
                ? 'live-front-page'
                : route.query.demo === '1'
                  ? 'landing-demo'
                  : 'landing-live'
        "
    />
    <div v-else class="smx-workspace">
        <router-view />
    </div>
</template>
