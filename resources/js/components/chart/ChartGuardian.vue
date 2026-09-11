<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { pngUrlFor } from '../../coinIcons';
import { fmt } from '../../api';
import { chapterFor } from '../experience/chapters';

const props = defineProps({ symbol: String, active: Boolean, quote: Object });
const root = ref(null), gpu = ref(null), portrait = ref(null), beam = ref(null), ray = ref(null), reticle = ref(null);
const available = ref(false), visible = ref(true), tracking = ref(false), cursor = ref(null), pulse = ref(0);
const pointer = { x: 0, y: 0 };
const running = computed(() => props.active && visible.value);
let engine, observer, resize, frame = 0, disposed = false, pending = false, lastReadout = 0;
let aimPoint = { x: .25, y: .24 }, bounds = { width: 1000, height: 600 };
function drawAim() {
    frame = 0;
    if (!root.value || !portrait.value) return;
    const host = root.value.getBoundingClientRect(), robot = portrait.value.getBoundingClientRect();
    const eyeX = robot.left - host.left + robot.width * .5, eyeY = robot.top - host.top + robot.height * .17;
    const x = Number.isFinite(aimPoint.pixelX) ? aimPoint.pixelX : 56 + aimPoint.x * Math.max(1, bounds.width - 136);
    const y = Number.isFinite(aimPoint.pixelY) ? aimPoint.pixelY : 42 + aimPoint.y * (aimPoint.paneHeight || Math.max(1, bounds.height - 80));
    beam.value?.setAttribute('points', `${eyeX},${eyeY} ${x},${y - 12} ${x},${y + 12}`);
    ray.value?.setAttribute('d', `M${eyeX} ${eyeY} Q${(eyeX + x) / 2} ${y - 28} ${x} ${y}`);
    reticle.value?.style.setProperty('transform', `translate(${x}px,${y}px)`);
    root.value.style.setProperty('--guardian-yaw', `${pointer.x * 9}deg`);
    root.value.style.setProperty('--guardian-pitch', `${pointer.y * -4}deg`);
}
function aim(target) {
    if (!target || !running.value) return;
    aimPoint = target; pointer.x = target.x * 2 - 1; pointer.y = target.y * 2 - 1;
    tracking.value = true; engine?.setFocus(true);
    if (performance.now() - lastReadout > 90) { cursor.value = target; lastReadout = performance.now(); }
    if (!frame) frame = requestAnimationFrame(drawAim);
}
function leave() {
    tracking.value = false; cursor.value = null; pointer.x = pointer.y = 0;
    engine?.setFocus(false); aimPoint = { x: .25, y: .24 };
    cancelAnimationFrame(frame); frame = requestAnimationFrame(drawAim);
}
defineExpose({ aim, leave });
async function initialize() {
    if (disposed || pending || engine || !visible.value || !gpu.value) return;
    pending = true;
    try {
        const { createChapterField } = await import('../experience/chapterField');
        if (disposed || !visible.value) return;
        engine = createChapterField(gpu.value, { profile: { ...chapterFor('chart'), speed: .19 }, pointer,
            active: running.value, visible: visible.value, onAvailability: value => { available.value = value; } });
    } catch { available.value = false; } finally { pending = false; }
}
watch(running, value => { engine?.setActive(value); if (!value) leave(); });
watch(() => props.symbol, () => { pulse.value++; leave(); });
watch(() => props.quote?.price, (value, old) => { if (value && old && value !== old) pulse.value++; });
onMounted(() => {
    observer = new IntersectionObserver(([entry]) => {
        visible.value = entry.isIntersecting; engine?.setVisible(visible.value); if (visible.value) initialize();
    }); observer.observe(root.value);
    resize = new ResizeObserver(([entry]) => { bounds = entry.contentRect; drawAim(); }); resize.observe(root.value);
    initialize();
});
onUnmounted(() => { disposed = true; cancelAnimationFrame(frame); observer?.disconnect(); resize?.disconnect(); engine?.dispose(); });
</script>

<template>
    <div ref="root" class="cl-guardian" :class="{ 'is-tracking': tracking, 'is-still': !running }" :data-renderer="available ? 'webgl' : 'css'" aria-hidden="true">
        <div class="cl-guardian-grid"></div>
        <div ref="gpu" class="cl-guardian-gpu"></div>
        <div class="cl-holo-orbits"><i></i><i></i><i></i></div>
        <svg class="cl-eye-beam"><polygon ref="beam"/><path ref="ray"/></svg>
        <div class="cl-robot-hologram" :key="symbol">
            <div ref="portrait" class="cl-robot-body">
                <img :src="'/brand/shoegpt-robot-armor.webp'" alt="" width="1397" height="1126" fetchpriority="high" draggable="false" @load="drawAim" />
                <span class="cl-robot-scan"></span><span class="cl-robot-eye"></span><span class="cl-robot-eye-flare"></span>
            </div>
            <div class="cl-robot-plinth"><i></i><i></i><i></i></div>
            <div class="cl-guardian-label"><span></span>SHOEMONEY AI <b>V3.8</b></div>
        </div>
        <div class="cl-guardian-coin"><span></span><img v-if="pngUrlFor(symbol)" :src="pngUrlFor(symbol)" alt=""/><b>{{ symbol?.split('-')[0] }}</b></div>
        <div ref="reticle" class="cl-guardian-target"><span></span><i></i><b></b></div>
        <div class="cl-guardian-readout"><span>{{ tracking ? 'CURSOR LOCK' : 'MARKET IN FOCUS' }}</span><strong>{{ tracking && cursor ? fmt.px(cursor.price) : symbol }}</strong><small>{{ tracking && cursor ? new Date(cursor.time * 1000).toLocaleString() : 'Move across the candles to aim the guardian' }}</small></div>
        <div class="cl-reaction-wave" :key="pulse"></div>
        <div class="cl-corner cl-corner--tl"></div><div class="cl-corner cl-corner--tr"></div><div class="cl-corner cl-corner--bl"></div><div class="cl-corner cl-corner--br"></div>
    </div>
</template>
