<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { designCatalog } from "../landing/designCatalog";
import { brandTreatments } from "../landing/brandTreatments";
import BrandInsignia from "../landing/BrandInsignia.vue";
import "../../css/design-index.css";

const filter = ref("all");
const showEnhancements = ref(false);
const filters = [
    { id: "all", name: "All designs" },
    { id: "original", name: "Signature layouts" },
    { id: "explosion", name: "Data explosion" },
].map((item) => ({
    ...item,
    count: designCatalog.filter(
        (design) => item.id === "all" || design.series === item.id,
    ).length,
}));
const visibleDesigns = computed(() =>
    designCatalog.filter(
        (design) => filter.value === "all" || design.series === filter.value,
    ),
);
onMounted(() => {
    document.title = "Design collection · ShoeMoneyX";
});
onUnmounted(() => {
    document.title = "ShoeMoneyX — desk";
});
</script>

<template>
    <div id="gallery-top" class="design-index">
        <a class="di-skip" href="#design-collection">Skip to the designs</a>
        <div class="di-shell">
            <header class="di-header">
                <router-link
                    to="/"
                    class="di-brand"
                    aria-label="ShoeMoneyX design collection"
                    ><BrandInsignia
                        theme="gallery"
                        placement="masthead"
                        class="di-brand-insignia"
                    />
                    <strong>SHOEMONEY<span>X</span></strong
                    ><small>DESIGN COLLECTION</small></router-link
                >
                <router-link to="/dashboard" class="di-desk-link"
                    >Open trading desk
                    <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i
                ></router-link>
            </header>
            <section class="di-intro" aria-labelledby="di-title">
                <div>
                    <span class="di-eyebrow"
                        ><i></i> ONE INTELLIGENCE.
                        {{ designCatalog.length }} PERSPECTIVES.</span
                    >
                    <h1 id="di-title">Pick your <em>perspective.</em></h1>
                    <p>
                        One unmistakable identity. {{ designCatalog.length }}
                        ways to electrify it.
                    </p>
                </div>
                <div class="di-collection-mark" aria-hidden="true">
                    <BrandInsignia
                        theme="gallery"
                        placement="gallery"
                        class="di-hero-insignia"
                    />
                    <div class="di-collection-count">
                        <span>THE COMPLETE COLLECTION</span
                        ><strong
                            >01<span>—</span>{{ designCatalog.length }}</strong
                        ><small>PICASO EDITION · INTELLIGENCE IN MOTION</small>
                    </div>
                </div>
            </section>
            <div class="di-toolbar">
                <nav aria-label="Filter design collection">
                    <button
                        v-for="item in filters"
                        :key="item.id"
                        :aria-pressed="filter === item.id"
                        @click="filter = item.id"
                    >
                        {{ item.name
                        }}<span>{{ String(item.count).padStart(2, "0") }}</span>
                    </button>
                </nav>
                <span class="di-demo-note"
                    ><i class="fa-solid fa-circle-nodes" aria-hidden="true"></i>
                    PREVIEWS USE SIMULATED DATA</span
                >
            </div>
            <div class="di-edition-bar">
                <div class="di-edition-links">
                    <router-link class="di-new-design" to="/singularity">
                        <span>10 NEW</span> THE SINGULARITY / ROBOT EFFECTS
                        <i
                            class="fa-solid fa-arrow-up-right-from-square"
                            aria-hidden="true"
                        ></i>
                    </router-link>
                    <router-link
                        class="di-new-design"
                        to="/landing/horizon-blue?demo=1"
                    >
                        <span>NEW</span> EVENT HORIZON BLUE
                        <i
                            class="fa-solid fa-arrow-up-right-from-square"
                            aria-hidden="true"
                        ></i>
                    </router-link>
                    <router-link
                        class="di-new-design"
                        to="/landing/supernova-blue?demo=1"
                    >
                        <span>NEW</span> SUPERNOVA BLUE
                        <i
                            class="fa-solid fa-arrow-up-right-from-square"
                            aria-hidden="true"
                        ></i>
                    </router-link>
                </div>
                <button
                    :aria-pressed="showEnhancements"
                    @click="showEnhancements = !showEnhancements"
                >
                    {{
                        showEnhancements
                            ? "Hide enhancements"
                            : "Show design enhancements"
                    }}
                    <span aria-hidden="true">{{
                        showEnhancements ? "−" : "+"
                    }}</span>
                </button>
            </div>
            <main
                id="design-collection"
                class="di-grid"
                aria-label="Trading landing page designs"
            >
                <router-link
                    v-for="(design, index) in visibleDesigns"
                    :key="design.id"
                    class="di-card"
                    :to="design.preview"
                    :style="{
                        '--card-accent': design.accent,
                        '--card-order': index,
                    }"
                    :aria-label="`Open ${design.number} ${design.name} design`"
                >
                    <div class="di-thumbnail">
                        <img
                            :src="design.thumbnail"
                            alt=""
                            width="800"
                            height="500"
                            :loading="index < 6 ? 'eager' : 'lazy'"
                            decoding="async"
                        /><span class="di-preview-badge"
                            ><i class="fa-solid fa-play" aria-hidden="true"></i>
                            INTERACTIVE PREVIEW</span
                        ><span class="di-open-overlay"
                            >Explore {{ design.name }}
                            <i
                                class="fa-solid fa-arrow-up-right-from-square"
                                aria-hidden="true"
                            ></i
                        ></span>
                    </div>
                    <div class="di-card-info">
                        <span class="di-number">{{ design.number }}</span>
                        <div>
                            <h2>{{ design.name }}</h2>
                            <p>{{ design.feature }}</p>
                        </div>
                        <span class="di-card-arrow"
                            ><i
                                class="fa-solid fa-arrow-up-right-from-square"
                                aria-hidden="true"
                            ></i
                        ></span>
                    </div>
                    <p class="di-description">{{ design.description }}</p>
                    <p class="di-brand-treatment">
                        <span>SIGNATURE</span> {{ brandTreatments[design.id] }}
                    </p>
                    <ol
                        v-if="showEnhancements"
                        class="di-enhancements"
                        :aria-label="`${design.name} enhancements`"
                    >
                        <li
                            v-for="enhancement in design.enhancements"
                            :key="enhancement"
                        >
                            {{ enhancement }}
                        </li>
                    </ol>
                </router-link>
            </main>
            <p class="di-result-count" role="status">
                {{ visibleDesigns.length }} of
                {{ designCatalog.length }} designs
            </p>
            <footer class="di-footer">
                <span
                    ><b>SHOEMONEY<span class="di-brand-x">X</span></b>
                    <span>ONE DESK. EVERY PERSPECTIVE.</span></span
                ><a href="#gallery-top"
                    >Back to top
                    <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i
                ></a>
            </footer>
        </div>
    </div>
</template>
