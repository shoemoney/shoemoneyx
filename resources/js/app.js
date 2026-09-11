import { createApp, nextTick } from "vue";
import { createRouter, createWebHistory } from "vue-router";
import "./fontawesome";
import App from "./App.vue";
import { installSiteChrome } from "./siteShell";
import { updateSiteMetadata } from "./siteMetadata";
import Landing from "./pages/Landing.vue";
import { explosionDesigns } from "./landing/explosionDesigns";
import { singularityDesigns } from "./landing/singularityDesigns";

const Dashboard = () => import("./pages/Dashboard.vue");
const Chart = () => import("./pages/Chart.vue");
const Desk = () => import("./pages/Desk.vue");
const Positions = () => import("./pages/Positions.vue");
const Backtests = () => import("./pages/Backtests.vue");
const Settings = () => import("./pages/Settings.vue");
const Optimizer = () => import("./pages/Optimizer.vue");
const Arena = () => import("./pages/Arena.vue");
const DataExplosion = () => import("./pages/DataExplosion.vue");
const DesignIndex = () => import("./pages/DesignIndex.vue");
const StrategyBuilder = () => import("./pages/StrategyBuilder.vue");
const Archive = () => import("./pages/Archive.vue");
const Exchanges = () => import("./pages/Exchanges.vue");
const SingularityIndex = () => import("./pages/SingularityIndex.vue");

const router = createRouter({
    history: createWebHistory(),
    scrollBehavior: (to, from, saved) =>
        saved || (to.hash ? { el: to.hash } : { top: 0 }),
    routes: [
        {
            path: "/singularity",
            component: SingularityIndex,
            name: "singularity-index",
            meta: { landing: true },
        },
        {
            path: `/singularity/:effect(${singularityDesigns.map((d) => d.id).join("|")})`,
            component: DataExplosion,
            name: "singularity-effect",
            meta: { landing: true },
        },
        {
            path: "/",
            component: DataExplosion,
            name: "landing",
            meta: { landing: true, frontPage: true },
        },
        {
            path: "/designs",
            alias: ["/landing"],
            component: DesignIndex,
            name: "design-collection",
            meta: { landing: true },
        },
        {
            path: `/landing/:version(${explosionDesigns.map((d) => d.id).join("|")})`,
            component: DataExplosion,
            name: "data-explosion",
            meta: { landing: true },
        },
        {
            path: "/landing/:version",
            component: Landing,
            name: "landing-version",
            meta: { landing: true },
        },
        { path: "/dashboard", component: Dashboard, name: "dashboard" },
        {
            path: "/chart/:symbol?",
            component: Chart,
            name: "chart",
            props: true,
        },
        { path: "/desk/:run?", component: Desk, name: "desk", props: true },
        { path: "/positions", component: Positions, name: "positions" },
        {
            path: "/backtests/:id?",
            component: Backtests,
            name: "backtests",
            props: true,
        },
        { path: "/optimizer", component: Optimizer, name: "optimizer" },
        { path: "/arena", component: Arena, name: "arena" },
        { path: "/builder", component: StrategyBuilder, name: "builder" },
        { path: "/archive", component: Archive, name: "archive" },
        { path: "/exchanges", component: Exchanges, name: "exchanges" },
        { path: "/settings", component: Settings, name: "settings" },
    ],
});

const app = createApp(App).use(router);
router.afterEach(async (to, from, failure) => {
    if (!failure) {
        await nextTick();
        updateSiteMetadata(to.path);
    }
});
router.isReady().then(() => {
    const disposeChrome = installSiteChrome(router);
    app.mount("#app");
    nextTick(() => updateSiteMetadata(router.currentRoute.value.path));
    if (import.meta.hot) import.meta.hot.dispose(disposeChrome);
});
