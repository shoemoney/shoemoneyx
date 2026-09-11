<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import BrandInsignia from "../landing/BrandInsignia.vue";
import { singularityDesigns } from "../landing/singularityDesigns";
import "../../css/singularity-index.css";

const robotUrl = "/brand/shoegpt-robot-armor.webp";
const query = ref("");
const heroScene = ref(null);
const motion = ref(true);
const reducedMotion = ref(false);
const pageVisible = ref(!document.hidden);
const heroVisible = ref(true);
const failedPreviews = ref(new Set());
const effectEnabled = computed(
    () =>
        motion.value &&
        !reducedMotion.value &&
        pageVisible.value &&
        heroVisible.value,
);
const visibleDesigns = computed(() => {
    const needle = query.value.trim().toLowerCase();
    return singularityDesigns.filter((design) =>
        `${design.number} ${design.name} ${design.signature} ${design.description}`
            .toLowerCase()
            .includes(needle),
    );
});
const editionLink = (id) => ({
    path: `/singularity/${id}`,
    query: { demo: "1" },
});
let media,
    observer,
    pointerFrame = 0,
    pointerX = 0,
    pointerY = 0;
function resetPointer() {
    cancelAnimationFrame(pointerFrame);
    pointerFrame = 0;
    heroScene.value?.style.setProperty("--hero-x", "0px");
    heroScene.value?.style.setProperty("--hero-y", "0px");
}
function movePointer(event) {
    if (!effectEnabled.value || event.pointerType === "touch") return;
    const rect = heroScene.value.getBoundingClientRect();
    pointerX = ((event.clientX - rect.left) / rect.width - 0.5) * 18;
    pointerY = ((event.clientY - rect.top) / rect.height - 0.5) * 12;
    if (!pointerFrame)
        pointerFrame = requestAnimationFrame(() => {
            pointerFrame = 0;
            if (!effectEnabled.value) return;
            heroScene.value?.style.setProperty("--hero-x", `${pointerX}px`);
            heroScene.value?.style.setProperty("--hero-y", `${pointerY}px`);
        });
}
function previewFailed(id) {
    failedPreviews.value = new Set([...failedPreviews.value, id]);
}
function updateVisibility() {
    pageVisible.value = !document.hidden;
}
function updatePreference() {
    reducedMotion.value = media.matches;
}
watch(effectEnabled, (enabled) => {
    if (!enabled) resetPointer();
});
onMounted(() => {
    document.title = "Singularity collection · ShoeMoneyX";
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    updatePreference();
    media.addEventListener("change", updatePreference);
    document.addEventListener("visibilitychange", updateVisibility);
    observer = new IntersectionObserver(([entry]) => {
        heroVisible.value = entry.isIntersecting;
    });
    observer.observe(heroScene.value);
});
onUnmounted(() => {
    resetPointer();
    observer?.disconnect();
    media?.removeEventListener("change", updatePreference);
    document.removeEventListener("visibilitychange", updateVisibility);
    document.title = "ShoeMoneyX — desk";
});
</script>

<template>
    <div id="singularity-top" class="singularity-index">
        <a class="sg-skip" href="#singularity-editions">Skip to the editions</a>
        <div class="sg-shell">
            <header class="sg-header">
                <router-link
                    to="/"
                    class="sg-brand"
                    aria-label="ShoeMoneyX all designs"
                >
                    <BrandInsignia
                        theme="horizon-blue"
                        placement="masthead"
                        :motion="motion && !reducedMotion"
                        class="sg-brand-seal"
                    />
                    <span
                        >SHOEMONEY<b>X</b
                        ><small>THE SINGULARITY COLLECTION</small></span
                    >
                </router-link>
                <router-link to="/designs" class="sg-all-designs"
                    ><span aria-hidden="true">←</span> All designs</router-link
                >
            </header>

            <section class="sg-hero" aria-labelledby="sg-title">
                <div class="sg-hero-copy">
                    <div class="sg-eyebrow">
                        <span aria-hidden="true"></span> SPECIAL COLLECTION
                        <b>01—10</b>
                    </div>
                    <h1 id="sg-title">
                        One guardian.<br /><em>Ten dimensions.</em>
                    </h1>
                    <p>
                        Explore ten interactive robot editions, from a
                        singularity eye to a magnetic star swarm.
                    </p>
                    <div class="sg-hero-actions">
                        <router-link :to="editionLink('eye')" class="sg-primary"
                            >Enter the first edition
                            <span aria-hidden="true">↗</span></router-link
                        >
                        <a href="#singularity-editions" class="sg-secondary"
                            >Explore all {{ singularityDesigns.length }}
                            <span aria-hidden="true">↓</span></a
                        >
                    </div>
                    <div class="sg-hero-footnote">
                        <span class="sg-demo-pip" aria-hidden="true"></span>
                        INTERACTIVE PREVIEWS <span>SIMULATED DATA</span>
                    </div>
                </div>

                <div
                    ref="heroScene"
                    class="sg-hero-scene"
                    :class="{ 'sg-art-paused': !effectEnabled }"
                    @pointermove="movePointer"
                    @pointerleave="resetPointer"
                >
                    <div class="sg-scene-grid" aria-hidden="true"></div>
                    <div class="sg-scene-aura" aria-hidden="true"></div>
                    <div class="sg-scene-orbits" aria-hidden="true">
                        <i></i><i></i><i></i>
                    </div>
                    <svg
                        class="sg-scene-schematic"
                        viewBox="0 0 600 500"
                        aria-hidden="true"
                    >
                        <path
                            d="M26 88H88L123 125H181M422 127H466L501 92H574M18 371H100L144 327H186M415 327H461L505 371H581"
                        />
                        <path
                            class="sg-schematic-dashes"
                            d="M85 58H45V105M515 58H555V105M45 395V442H92M555 395V442H508M278 22H322M278 479H322"
                        />
                        <circle cx="300" cy="251" r="214" />
                        <circle cx="300" cy="251" r="184" />
                    </svg>
                    <img
                        class="sg-hero-robot"
                        :src="robotUrl"
                        alt="The silver ShoeMoney robot with an electric blue eye and the company insignia on its chest"
                        width="1400"
                        height="1126"
                        fetchpriority="high"
                    />
                    <div class="sg-eye-light" aria-hidden="true"></div>
                    <div
                        class="sg-scene-label sg-scene-label-top"
                        aria-hidden="true"
                    >
                        <i></i> SINGULARITY / SHOE GPT
                    </div>
                    <div
                        class="sg-scene-label sg-scene-label-bottom"
                        aria-hidden="true"
                    >
                        ONE IDENTITY <span>TEN EFFECT WORLDS</span>
                    </div>
                    <span class="sg-scene-number" aria-hidden="true">S/10</span>
                </div>
            </section>

            <div class="sg-collection-heading">
                <div>
                    <span class="sg-section-marker">THE EDITIONS</span>
                    <h2>Choose your dimension.</h2>
                </div>
                <button
                    class="sg-effects-button"
                    :aria-pressed="!motion || reducedMotion"
                    :disabled="reducedMotion"
                    @click="motion = !motion"
                >
                    <span aria-hidden="true">{{
                        motion && !reducedMotion ? "Ⅱ" : "▷"
                    }}</span
                    >{{
                        reducedMotion
                            ? "Reduced motion"
                            : motion
                              ? "Pause effects"
                              : "Resume effects"
                    }}
                </button>
            </div>

            <div class="sg-toolbar">
                <p role="status">
                    <strong>{{
                        String(visibleDesigns.length).padStart(2, "0")
                    }}</strong>
                    /
                    {{ String(singularityDesigns.length).padStart(2, "0") }}
                    EDITIONS
                    <span>Every thumbnail opens an interactive preview.</span>
                </p>
                <div class="sg-search">
                    <span aria-hidden="true">⌕</span
                    ><input
                        v-model="query"
                        type="search"
                        placeholder="Find an effect"
                        aria-label="Find a Singularity effect"
                        @keydown.esc="query = ''"
                    /><button
                        v-if="query"
                        type="button"
                        @click="query = ''"
                        aria-label="Clear edition search"
                    >
                        ×
                    </button>
                </div>
            </div>

            <main
                id="singularity-editions"
                class="sg-grid"
                aria-label="Singularity robot editions"
                tabindex="-1"
            >
                <router-link
                    v-for="design in visibleDesigns"
                    :key="design.id"
                    :to="editionLink(design.id)"
                    class="sg-card"
                    :aria-label="`Open ${design.number} ${design.name}`"
                >
                    <div class="sg-thumbnail">
                        <img
                            v-if="!failedPreviews.has(design.id)"
                            :src="design.thumbnail"
                            alt=""
                            width="960"
                            height="640"
                            :loading="
                                Number(design.number) <= 4 ? 'eager' : 'lazy'
                            "
                            decoding="async"
                            @error="previewFailed(design.id)"
                        />
                        <div v-else class="sg-preview-pending">
                            <img
                                :src="robotUrl"
                                alt=""
                                width="1400"
                                height="1126"
                                loading="lazy"
                            /><span>Preview rendering</span>
                        </div>
                        <span class="sg-edition-number">{{
                            design.number
                        }}</span>
                        <span class="sg-thumbnail-signature">{{
                            design.signature
                        }}</span>
                        <span class="sg-card-open" aria-hidden="true"
                            >Explore edition <b>↗</b></span
                        >
                    </div>
                    <div class="sg-card-title">
                        <h3>{{ design.name }}</h3>
                        <span aria-hidden="true">↗</span>
                    </div>
                    <p class="sg-card-description">{{ design.description }}</p>
                    <p class="sg-card-hint">
                        <span aria-hidden="true">↳</span>{{ design.hint }}
                    </p>
                </router-link>
                <div v-if="!visibleDesigns.length" class="sg-empty">
                    <span aria-hidden="true">⌕</span>
                    <h3>No matching dimensions.</h3>
                    <p>Try “eye”, “plasma”, or “stars”.</p>
                    <button @click="query = ''">Show all ten editions</button>
                </div>
            </main>

            <footer class="sg-footer">
                <div>
                    SHOEMONEY<b>X</b><span>ONE GUARDIAN. TEN DIMENSIONS.</span>
                </div>
                <a href="#singularity-top"
                    >Back to top <span aria-hidden="true">↑</span></a
                >
            </footer>
        </div>
    </div>
</template>
