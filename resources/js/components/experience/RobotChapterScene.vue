<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { pngUrlFor } from "../../coinIcons.js";

const props = defineProps({
    active: Boolean,
    visible: Boolean,
    focused: Boolean,
    profile: { type: Object, required: true },
    pointer: { type: Object, required: true },
});
const host = ref(null);
const available = ref(false);
const objects = computed(() => props.profile.objects.map((item) => ({
    key: item,
    icon: item.startsWith("fa-") ? item : null,
    image: item.startsWith("fa-") ? null : pngUrlFor(item),
})));
let engine, pending = false, disposed = false;
async function initialize() {
    if (engine || pending || disposed || !props.visible || !host.value) return;
    pending = true;
    try {
        const { createChapterField } = await import("./chapterField.js");
        if (disposed || !host.value || !props.visible) return;
        engine = createChapterField(host.value, {
            profile: props.profile,
            pointer: props.pointer,
            active: props.active,
            visible: props.visible,
            onAvailability: (value) => { available.value = value; },
        });
    } catch {
        available.value = false;
        // CSS armillary, scanner, portrait and atmospheric grid remain visible.
    } finally { pending = false; }
}
watch(() => props.visible, (value) => {
    engine?.setVisible(value);
    if (value) initialize();
});
watch(() => props.active, (value) => engine?.setActive(value));
watch(() => props.profile, (value) => engine?.setProfile(value));
watch(() => props.focused, (value) => engine?.setFocus(value));
onMounted(initialize);
onUnmounted(() => { disposed = true; engine?.dispose(); });
</script>

<template>
    <div class="smx-chapter-scene" :data-renderer="available ? 'webgl' : 'css'" aria-hidden="true">
        <div class="smx-chapter-scene__aura"></div>
        <div class="smx-chapter-scene__armillary"><span></span><span></span><span></span></div>
        <div ref="host" class="smx-chapter-scene__gpu"></div>
        <div class="smx-chapter-scene__code"><i :class="['fa-solid', profile.icon]"></i></div>
        <svg class="smx-chapter-scene__circuit" viewBox="0 0 600 320" fill="none" preserveAspectRatio="none">
            <path d="M0 244H61L94 211V158L125 127H163M600 81H536L502 115V211L470 243H426M26 280H107L129 258H207M595 285H505L483 263H419" />
            <path class="smx-chapter-scene__flow" pathLength="100" d="M0 244H61L94 211V158L125 127H163M600 81H536L502 115V211L470 243H426M26 280H107L129 258H207M595 285H505L483 263H419" />
            <circle cx="163" cy="127" r="3" /><circle cx="426" cy="243" r="3" /><circle cx="207" cy="258" r="3" /><circle cx="419" cy="263" r="3" />
        </svg>
        <div class="smx-chapter-scene__portrait">
            <img :src="'/brand/shoegpt-robot-armor.webp'" alt="" width="1397" height="1126" decoding="async" fetchpriority="high" draggable="false" />
            <span class="smx-chapter-scene__eye"><span></span></span>
            <span class="smx-chapter-scene__visor"></span>
            <span class="smx-chapter-scene__scan"></span>
            <span class="smx-chapter-scene__chest"></span>
        </div>
        <svg class="smx-chapter-scene__beams" viewBox="0 0 600 320" fill="none" preserveAspectRatio="none">
            <path class="smx-chapter-scene__beam smx-chapter-scene__beam--1" d="M300 66 84 137 95 127Z" />
            <path class="smx-chapter-scene__beam smx-chapter-scene__beam--2" d="M300 66 516 64 514 78Z" />
            <path class="smx-chapter-scene__beam smx-chapter-scene__beam--3" d="M300 66 530 234 542 223Z" />
        </svg>
        <div class="smx-chapter-scene__objects">
            <span v-for="(object, index) in objects" :key="object.key" class="smx-chapter-scene__object" :class="`smx-chapter-scene__object--${index + 1}`" :style="{ '--object-phase': `${index * -3}s` }">
                <span class="smx-chapter-scene__object-ring"></span>
                <img v-if="object.image" :src="object.image" alt="" width="36" height="36" draggable="false" />
                <i v-else :class="['fa-solid', object.icon]"></i>
            </span>
        </div>
        <div class="smx-chapter-scene__horizon"></div>
        <div class="smx-chapter-scene__reticle"><span></span><span></span><span></span><span></span></div>
    </div>
</template>
