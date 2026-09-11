<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import * as THREE from "three";
import { coinTextureSource } from "../coinIcons";

const props = defineProps({ coin: { type: String, required: true } });

const host = ref(null);
const rendererUnavailable = ref(false);
let disposed = false;
let inView = true;
let intersectionObserver = null;
let rendererCanvas = null;
const orbitRings = [];
let scannerDust = null;
let impulseAt = -100;
const MAX_ACTIVE = 6;
const MAX_QUEUED = 24;
const MAX_REPLAY_AGE_MS = 15000;
const FRAME_INTERVAL_MS = 1000 / 40;
let lastPaintAt = 0;
const ENTER_END = 1.1;
const CHARGE_END = 1.6;
const IMPACT_T = 2.0;
const WIN_FADE_START = 7.0;
const WIN_FADE_END = 7.6;
const LOSE_FADE_END = 2.9;
const STAGGER_DURATION = 0.5;
const TINT_DURATION = 0.2; // champion HIT amber tint — a flash, not the knockback envelope
const STRIKE_DURATION = 0.55;
const PROMOTE_WIN_DURATION = 1.5;
const PROMOTE_DURATION = 1.4;
const BREATHE_PERIOD = 2.0; // also the champion medallion's bob period
const HUD_INTERVAL = 0.12; // seconds between HUD/label refreshes — plenty smooth, far cheaper than every frame

// Champion stands right, the challenger lane runs through the left third, camera framed tight on both.
const CHAMPION_X = 1.7;
const CHAMPION_H = 2.6;
const CHALLENGER_H = 2.05;
const CAMERA_FOV = 38;
const CAMERA_BASE = new THREE.Vector3(0.5, 1.7, 6.1);
const CAMERA_LOOKAT = new THREE.Vector3(0.55, 1.25, 0);

const WHITE = new THREE.Color(0xffffff);
const AMBER = new THREE.Color(0x6acfff);
const ZINC = new THREE.Color(0x71717a);

const SPRITE_URLS = {
    champion: {
        idle: "/brand/shoegpt-robot-armor.webp",
        strike: "/brand/shoegpt-robot-armor.webp",
        win: "/brand/shoegpt-robot-armor.webp",
    },
    challenger: [
        "/brand/shoegpt-robot-armor.webp",
        "/brand/shoegpt-robot-armor.webp",
        "/brand/shoegpt-robot-armor.webp",
    ],
};

const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
const lerp = (a, b, t) => a + (b - a) * t;

const reduceMotionQuery = window.matchMedia?.(
    "(prefers-reduced-motion: reduce)",
);
let reduceMotion = reduceMotionQuery?.matches ?? false;
function onReduceMotionChange(e) {
    reduceMotion = e.matches;
}

const hud = ref({ active: 0, queued: 0, last: "—" });
const hudFlash = ref({}); // field -> { dir: 'up'|'down', tick } so :key can force the wash to restart on repeats
const championLabel = ref({ left: "50%", top: "80%", visible: false });
const footVec = new THREE.Vector3();

let renderer = null;
let scene = null;
let camera = null;
let clock = null;
let ground = null;
let championGroup = null;
let championStagger = null; // { start }
let championPose = null; // { start, duration, sprite } — strike/win texture holds, reverts to idle
let promotion = null; // { start, fallingGroup, growingGroup }
let active = []; // { id, group, spawnAt, outcome, resolved, consumed }
let queue = []; // pending challenger payloads, waiting for a free slot
let disposables = []; // geometries/materials/textures created outside groups, disposed on teardown
let laneTicks = [];
let resizeObserver = null;
let raf = 0;
let nextId = 1;
let challengerCycle = 0;
let hudClock = 0;
let spriteTex = { champion: {}, challenger: [] };
let spritesReady = false; // preloadSprites() hasn't resolved until this flips — spawns queue until then
let coinTex = null;
let shadowGeo = null;
let shadowTex = null;
const loader = new THREE.TextureLoader();

function track(x) {
    disposables.push(x);
    return x;
}

// Sprite textures and the shadow texture are shared across every fighter and disposed exactly
// once on unmount — disposeGroup must never touch a material's .map, only its own geometry and
// material instances, or the next spawn inherits a destroyed texture.
function disposeGroup(g) {
    g.traverse((o) => {
        if (o.geometry && o.geometry !== shadowGeo) o.geometry.dispose();
        if (o.material) {
            const mats = Array.isArray(o.material) ? o.material : [o.material];
            mats.forEach((m) => m.dispose());
        }
    });
    g.parent?.remove(g);
}

function setGroupOpacity(g, factor) {
    g.traverse((o) => {
        if (o.material) {
            const mats = Array.isArray(o.material) ? o.material : [o.material];
            mats.forEach((m) => {
                m.opacity = (m.userData.baseOpacity ?? 1) * factor;
            });
        }
    });
}

function loadTex(url) {
    return new Promise((resolve) => {
        loader.load(
            url,
            (tex) => {
                tex.colorSpace = THREE.SRGBColorSpace;
                tex.anisotropy = 4;
                tex.generateMipmaps = true;
                tex.minFilter = THREE.LinearMipmapLinearFilter;
                tex.magFilter = THREE.LinearFilter;
                tex.userData = { aspect: tex.image.width / tex.image.height };
                resolve(tex);
            },
            undefined,
            (err) => {
                console.error("[arena] sprite failed to load", url, err);
                const c = document.createElement("canvas");
                c.width = 64;
                c.height = 128;
                const ctx = c.getContext("2d");
                ctx.fillStyle = "rgba(120,120,130,0.85)";
                ctx.fillRect(0, 0, 64, 128);
                const fallback = new THREE.CanvasTexture(c);
                fallback.colorSpace = THREE.SRGBColorSpace;
                fallback.userData = { aspect: 64 / 128 };
                resolve(fallback);
            },
        );
    });
}

async function preloadSprites() {
    const robot = await loadTex(SPRITE_URLS.champion.idle);
    if (disposed) { robot.dispose(); return; }
    spriteTex = { champion: { idle: robot, strike: robot, win: robot }, challenger: [robot, robot, robot] };
    track(robot);
    spritesReady = true;
}

function buildCoinTexture(coin) {
    const src = coinTextureSource(coin);
    let tex;
    if (src.type === "canvas") {
        tex = new THREE.CanvasTexture(src.value);
    } else {
        tex = loader.load(src.value, (loaded) => {
            loaded.colorSpace = THREE.SRGBColorSpace;
            loaded.needsUpdate = true;
        });
    }
    tex.colorSpace = THREE.SRGBColorSpace;
    return tex;
}

function billboardGeometry(aspect, height) {
    const geo = new THREE.PlaneGeometry(height * aspect, height);
    geo.translate(0, height / 2, 0);
    return geo;
}

function billboardMaterial(map) {
    const mat = new THREE.MeshBasicMaterial({
        map,
        transparent: true,
        alphaTest: 0.08,
        depthWrite: true,
        toneMapped: false,
    });
    mat.userData.baseOpacity = 1;
    return mat;
}

function setSpriteTexture(sprite, tex) {
    if (sprite.material.map === tex) return;
    sprite.material.map = tex;
    sprite.material.needsUpdate = true;
    const old = sprite.geometry;
    const height = sprite.userData.height;
    sprite.geometry = billboardGeometry(tex.userData.aspect, height);
    old.dispose();
}

function buildContactShadow(sx, sy, opacity = 0.55) {
    const mat = new THREE.MeshBasicMaterial({
        map: shadowTex,
        transparent: true,
        depthWrite: false,
        opacity,
    });
    mat.userData.baseOpacity = opacity;
    const mesh = new THREE.Mesh(shadowGeo, mat);
    mesh.rotation.x = -Math.PI / 2;
    mesh.position.y = 0.006;
    mesh.scale.set(sx, sy, 1);
    return mesh;
}

function buildChampion(coin, pose = "idle") {
    const g = new THREE.Group();

    const tex = spriteTex.champion[pose] ?? spriteTex.champion.idle;
    const sprite = new THREE.Mesh(
        billboardGeometry(tex.userData.aspect, CHAMPION_H),
        billboardMaterial(tex),
    );
    sprite.userData.height = CHAMPION_H;
    g.add(sprite);

    const shadow = buildContactShadow(0.9, 0.3, 0.66);
    g.add(shadow);

    const ringMat = new THREE.MeshBasicMaterial({
        color: 0x66cfff,
        transparent: true,
        opacity: 0.35,
        side: THREE.DoubleSide,
    });
    ringMat.userData.baseOpacity = 0.35;
    const ring = new THREE.Mesh(
        new THREE.RingGeometry(0.62, 0.68, 48),
        ringMat,
    );
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0.008;
    g.add(ring);

    const medMat = new THREE.MeshBasicMaterial({
        map: coinTex,
        color: 0xd4d4d8,
        transparent: true,
        opacity: 0.9,
        toneMapped: false,
        side: THREE.DoubleSide,
    });
    medMat.userData.baseOpacity = 0.95;
    const medallion = new THREE.Mesh(
        new THREE.CircleGeometry(0.21, 40),
        medMat,
    );
    medallion.position.set(0, CHAMPION_H + 0.18, 0);
    g.add(medallion);

    g.userData = {
        sprite,
        shadow,
        ring,
        medallion,
        medallionY: CHAMPION_H + 0.18,
    };
    g.position.set(CHAMPION_X, 0, 0);
    return g;
}

function buildChallenger(coin, z) {
    const g = new THREE.Group();

    const tex = spriteTex.challenger[challengerCycle % 3];
    challengerCycle++;
    const sprite = new THREE.Mesh(
        billboardGeometry(tex.userData.aspect, CHALLENGER_H),
        billboardMaterial(tex),
    );
    sprite.userData.height = CHALLENGER_H;
    g.add(sprite);

    const shadow = buildContactShadow(0.7, 0.26, 0.77);
    g.add(shadow);

    const medMat = new THREE.MeshBasicMaterial({
        map: coinTex,
        transparent: true,
        toneMapped: false,
        side: THREE.DoubleSide,
    });
    medMat.userData.baseOpacity = 1;
    const medallionY = CHALLENGER_H + 0.18;
    const medallion = new THREE.Mesh(new THREE.CircleGeometry(0.1, 40), medMat);
    medallion.position.set(0, medallionY, 0);
    g.add(medallion);

    g.userData = { sprite, shadow, medallion, medallionY };
    g.position.set(-6, 0, z);
    return g;
}

function faceCamera(mesh, wx, wz) {
    mesh.rotation.y = Math.atan2(
        camera.position.x - wx,
        camera.position.z - wz,
    );
}

function faceGroupToCamera(g) {
    const u = g.userData;
    faceCamera(u.sprite, g.position.x, g.position.z);
    if (u.medallion)
        faceCamera(
            u.medallion,
            g.position.x + u.medallion.position.x,
            g.position.z + u.medallion.position.z,
        );
}

function challengerTransform(elapsed, outcome) {
    if (reduceMotion) {
        if (elapsed < IMPACT_T)
            return { x: -1.05, y: 0, scale: 1, opacity: 1, rotY: 0, tint: 0 };
        if (outcome === "win")
            return {
                x: -1.05,
                y: 0,
                scale: 1,
                opacity: elapsed < WIN_FADE_END ? 1 : 0,
                rotY: 0,
                tint: 0,
            };

        return {
            x: -1.05,
            y: elapsed < LOSE_FADE_END ? 0 : -0.25,
            scale: elapsed < LOSE_FADE_END ? 1 : 0.85,
            opacity: elapsed < LOSE_FADE_END ? 1 : 0,
            rotY: 0,
            tint: elapsed < LOSE_FADE_END ? 0 : 1,
        };
    }
    if (elapsed <= ENTER_END) {
        return {
            x: lerp(-6, -2.2, elapsed / ENTER_END),
            y: 0,
            scale: 1,
            opacity: 1,
            rotY: 0,
            tint: 0,
        };
    }
    if (elapsed <= CHARGE_END) {
        return {
            x: -2.2 + Math.sin((elapsed - ENTER_END) * 20) * 0.05,
            y: 0,
            scale: 1,
            opacity: 1,
            rotY: 0,
            tint: 0,
        };
    }
    if (elapsed <= IMPACT_T) {
        const t = (elapsed - CHARGE_END) / (IMPACT_T - CHARGE_END);

        return {
            x: lerp(-2.2, -1.05, t),
            y: 0,
            scale: 1,
            opacity: 1,
            rotY: 0,
            tint: 0,
        };
    }
    if (outcome === "win") {
        const o =
            elapsed < WIN_FADE_START
                ? 1
                : 1 -
                  clamp(
                      (elapsed - WIN_FADE_START) /
                          (WIN_FADE_END - WIN_FADE_START),
                      0,
                      1,
                  );

        return {
            x: -1.05,
            y: 0,
            scale: 1,
            opacity: o,
            rotY: Math.sin(elapsed * 2) * 0.12,
            tint: 0,
        };
    }
    const t = clamp((elapsed - IMPACT_T) / (LOSE_FADE_END - IMPACT_T), 0, 1);

    return {
        x: -1.05,
        y: lerp(0, -0.25, t),
        scale: lerp(1, 0.85, t),
        opacity: 1 - t,
        rotY: 0,
        tint: t,
    };
}

function triggerStagger() {
    if (!championGroup) return;
    championStagger = { start: clock.getElapsed() };
}

function triggerChampionStrike() {
    if (!championGroup) return;
    const sprite = championGroup.userData.sprite;
    setSpriteTexture(sprite, spriteTex.champion.strike);
    championPose = {
        start: clock.getElapsed(),
        duration: STRIKE_DURATION,
        sprite,
    };
}

const SLOT_Z = [-0.1, -0.7, -1.3];
const SLOT_X = [0, -0.55];

function freeSlot() {
    const used = new Set(active.map((a) => a.slot));
    for (let i = 0; i < MAX_ACTIVE; i++) if (!used.has(i)) return i;
    return active.length % MAX_ACTIVE;
}

function spawnNow(payload) {
    const slot = freeSlot();
    const z = SLOT_Z[slot % 3];
    const slotX = SLOT_X[Math.floor(slot / 3)] - (slot % 3) * 0.18;
    const group = buildChallenger(props.coin, z);
    scene.add(group);
    active.push({
        id: nextId++,
        group,
        payload,
        slot,
        slotX,
        spawnAt: clock.getElapsed(),
        outcome: payload.testRet == null || payload.championTest == null || !Number.isFinite(Number(payload.testRet)) || !Number.isFinite(Number(payload.championTest))
            ? "unknown"
            : Number(payload.testRet) > Number(payload.championTest) ? "win" : "lose",
        resolved: false,
        consumed: false,
    });
}

function spawnChallenger(payload) {
    if (disposed || rendererUnavailable.value) return;
    if (!spritesReady || !inView || document.hidden || active.length >= MAX_ACTIVE) {
        queue.push({ ...payload, queuedAt: Date.now() });
        const maxQueued = !inView || document.hidden ? MAX_ACTIVE : MAX_QUEUED;
        if (queue.length > maxQueued) queue.splice(0, queue.length - maxQueued);
        return;
    }
    spawnNow(payload);
}

function fallAndGrow() {
    impulseAt = clock?.getElapsed() ?? 0;
    if (!championGroup || promotion) return;
    const winner = [...active]
        .reverse()
        .find((a) => a.outcome === "win" && !a.consumed);
    if (winner) {
        winner.consumed = true;
        disposeGroup(winner.group);
        active = active.filter((a) => a.id !== winner.id);
    }
    const growing = buildChampion(props.coin, "win");
    growing.scale.setScalar(reduceMotion ? 1 : 0.05);
    scene.add(growing);
    promotion = {
        start: clock.getElapsed(),
        fallingGroup: championGroup,
        growingGroup: growing,
    };
    championGroup = null;
    championPose = {
        start: clock.getElapsed(),
        duration: PROMOTE_WIN_DURATION,
        sprite: growing.userData.sprite,
    };
    if (reduceMotion) {
        disposeGroup(promotion.fallingGroup);
        championGroup = promotion.growingGroup;
        promotion = null;
    }
}

// A strict handoff, never a cross-fade: phase A drops and dissolves the old champion (body,
// medallion, ring all the way to nothing) while the new one stays fully hidden; only once the
// old one is gone does phase B grow the new champion in. At no point are two bodies or two
// medallions visible together.
const PROMOTE_HANDOFF = 0.45;

function updatePromotion(t) {
    if (!promotion) return;
    const raw = clamp((t - promotion.start) / PROMOTE_DURATION, 0, 1);
    const fall = promotion.fallingGroup;
    const grow = promotion.growingGroup;
    const fu = fall.userData;
    const gu = grow.userData;

    if (raw <= PROMOTE_HANDOFF) {
        const pa = raw / PROMOTE_HANDOFF;
        fall.rotation.x = lerp(0, Math.PI / 2.2, pa);
        fall.position.y = lerp(0, -0.4, pa);
        const fadeOut = 1 - pa;
        fu.sprite.material.opacity =
            fu.sprite.material.userData.baseOpacity * fadeOut;
        fu.shadow.material.opacity =
            fu.shadow.material.userData.baseOpacity * fadeOut;
        fu.ring.material.opacity =
            fu.ring.material.userData.baseOpacity * fadeOut;
        fu.ring.scale.setScalar(fadeOut);
        fu.medallion.material.opacity =
            fu.medallion.material.userData.baseOpacity * fadeOut;
        fu.medallion.scale.setScalar(fadeOut);

        grow.scale.setScalar(1);
        gu.sprite.scale.setScalar(0.3);
        gu.sprite.material.opacity = 0;
        gu.shadow.material.opacity = 0;
        gu.ring.material.opacity = 0;
        gu.medallion.material.opacity = 0;
        gu.medallion.scale.setScalar(0);
    } else {
        const pb = (raw - PROMOTE_HANDOFF) / (1 - PROMOTE_HANDOFF);
        fu.sprite.material.opacity = 0;
        fu.shadow.material.opacity = 0;
        fu.ring.material.opacity = 0;
        fu.medallion.material.opacity = 0;

        grow.scale.setScalar(1);
        gu.sprite.scale.setScalar(lerp(0.3, 1, pb));
        gu.sprite.material.opacity = gu.sprite.material.userData.baseOpacity;
        gu.shadow.material.opacity = gu.shadow.material.userData.baseOpacity;
        gu.ring.material.opacity = lerp(
            0,
            gu.ring.material.userData.baseOpacity,
            pb,
        );
        gu.medallion.material.opacity =
            gu.medallion.material.userData.baseOpacity;
        gu.medallion.scale.setScalar(lerp(0, 1, pb));
        gu.medallion.position.y = gu.medallionY * lerp(0.3, 1, pb);
    }

    if (raw >= 1) {
        gu.sprite.scale.setScalar(1);
        disposeGroup(fall);
        championGroup = grow;
        promotion = null;
    }
}

function resetScene() {
    active.forEach((a) => disposeGroup(a.group));
    active = [];
    queue = [];
    if (promotion) {
        disposeGroup(promotion.fallingGroup);
        disposeGroup(promotion.growingGroup);
        promotion = null;
    }
    if (championGroup) disposeGroup(championGroup);
    championStagger = null;
    championPose = null;
    coinTex?.dispose();
    coinTex = buildCoinTexture(props.coin);
    championGroup = buildChampion(props.coin);
    scene.add(championGroup);
}

function bumpHud(field, dir) {
    const tick = (hudFlash.value[field]?.tick ?? 0) + 1;
    hudFlash.value = { ...hudFlash.value, [field]: { dir, tick } };
}

function updateHud(t) {
    if (t - hudClock < HUD_INTERVAL) return;
    hudClock = t;
    const prev = hud.value;
    const next = {
        active: active.length,
        queued: queue.length,
        last: prev.last,
    };
    if (next.active !== prev.active)
        bumpHud("active", next.active > prev.active ? "up" : "down");
    if (next.queued !== prev.queued)
        bumpHud("queued", next.queued > prev.queued ? "up" : "down");
    hud.value = next;
    const anchor = championGroup ?? promotion?.growingGroup ?? null;
    if (!anchor || !camera || !host.value) {
        championLabel.value = { ...championLabel.value, visible: false };
        return;
    }
    footVec.set(anchor.position.x, 0.04, anchor.position.z);
    footVec.project(camera);
    const w = host.value.clientWidth || 1;
    const h = host.value.clientHeight || 1;
    championLabel.value = {
        left: `${(footVec.x * 0.5 + 0.5) * w}px`,
        top: `${(-footVec.y * 0.5 + 0.5) * h}px`,
        visible: footVec.z < 1,
    };
}

function animate(timestamp = performance.now()) {
    raf = requestAnimationFrame(animate);
    const interval = reduceMotion ? 100 : FRAME_INTERVAL_MS;
    if (timestamp - lastPaintAt < interval) return;
    lastPaintAt = timestamp;
    clock.update(timestamp);
    const t = clock.getElapsed();
    const bob = reduceMotion
        ? 0
        : Math.sin((t / BREATHE_PERIOD) * Math.PI * 2) * 0.04;

    if (championPose && t - championPose.start >= championPose.duration) {
        setSpriteTexture(championPose.sprite, spriteTex.champion.idle);
        championPose = null;
    }

    for (const a of active) {
        const elapsed = t - a.spawnAt;
        const ending = a.outcome === "win" ? WIN_FADE_END : LOSE_FADE_END;
        if (elapsed >= ending) continue;
        if (elapsed >= IMPACT_T && !a.resolved) {
            a.resolved = true;
            impulseAt = t;
            hud.value = {
                ...hud.value,
                last: a.outcome === "win" ? "HIT" : a.outcome === "lose" ? "MISS" : "UNRATED",
            };
            bumpHud("last", a.outcome === "win" ? "up" : "down");
            if (a.outcome === "win") triggerStagger();
            else if (a.outcome === "lose") triggerChampionStrike();
        }
        const tr = challengerTransform(elapsed, a.outcome);
        a.group.position.x = tr.x + a.slotX;
        a.group.position.y = tr.y;
        a.group.scale.setScalar(Math.max(tr.scale, 0.001));
        a.group.rotation.y = tr.rotY;
        setGroupOpacity(a.group, tr.opacity);
        a.group.userData.sprite.material.color.set(0x79bded).lerp(ZINC, tr.tint);
        a.group.userData.medallion.position.y =
            a.group.userData.medallionY + bob;
    }
    const expired = active.filter((a) => {
        const elapsed = t - a.spawnAt;

        return a.outcome === "win"
            ? elapsed > WIN_FADE_END
            : elapsed > LOSE_FADE_END;
    });
    if (expired.length) {
        expired.forEach((a) => disposeGroup(a.group));
        active = active.filter((a) => !expired.includes(a));
    }
    while (spritesReady && active.length < MAX_ACTIVE && queue.length) {
        const next = queue.shift();
        if (Date.now() - next.queuedAt <= MAX_REPLAY_AGE_MS) spawnNow(next);
    }

    if (championGroup) {
        const breathe = reduceMotion
            ? 1
            : 1.01 + 0.01 * Math.sin((t / BREATHE_PERIOD) * Math.PI * 2);
        championGroup.scale.setScalar(breathe);
        let knockback = 0;
        if (championStagger) {
            const p = clamp(
                (t - championStagger.start) / STAGGER_DURATION,
                0,
                1,
            );
            knockback = (reduceMotion ? 0 : Math.sin(p * Math.PI)) * 0.22;
            const tp = clamp((t - championStagger.start) / TINT_DURATION, 0, 1);
            const tintAmt = reduceMotion
                ? tp < 1
                    ? 1
                    : 0
                : Math.sin(tp * Math.PI);
            championGroup.userData.sprite.material.color
                .copy(WHITE)
                .lerp(AMBER, tintAmt);
            if (p >= 1) championStagger = null;
        } else {
            championGroup.userData.sprite.material.color.copy(WHITE);
        }
        championGroup.position.x = CHAMPION_X - knockback;
        championGroup.userData.medallion.position.y =
            championGroup.userData.medallionY + bob;
    }
    updatePromotion(t);
    updateHud(t);

    camera.position.copy(CAMERA_BASE);
    camera.position.z = Math.max(CAMERA_BASE.z, 7.8 / (2 * Math.tan(THREE.MathUtils.degToRad(CAMERA_FOV / 2)) * camera.aspect));
    camera.lookAt(CAMERA_LOOKAT);

    if (championGroup) faceGroupToCamera(championGroup);
    for (const a of active) faceGroupToCamera(a.group);
    if (promotion) {
        faceGroupToCamera(promotion.fallingGroup);
        faceGroupToCamera(promotion.growingGroup);
    }

    const impact = reduceMotion ? 0 : Math.max(0, 1 - (t - impulseAt) / 1.4);
    orbitRings.forEach((ring, i) => {
        ring.rotation.z = reduceMotion ? i * .3 : t * (.09 + i * .017) * (i % 2 ? -1 : 1);
        ring.material.opacity = ring.userData.baseOpacity + impact * .32;
    });
    if (scannerDust && !reduceMotion) scannerDust.rotation.y = t * .025;
    if (laneTicks.length) laneTicks[0].material.opacity = .35 + impact * .6;
    renderer.render(scene, camera);
}

function pause() {
    if (raf) {
        cancelAnimationFrame(raf);
        raf = 0;
    }
}
function resume() {
    if (!raf && !disposed && !rendererUnavailable.value && inView && !document.hidden && spritesReady) animate();
}
function onVisibility() {
    if (document.hidden) pause();
    else resume();
}

function resize() {
    if (!host.value || !renderer) return;
    const w = host.value.clientWidth || 1;
    const h = host.value.clientHeight || 1;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function buildGroundAndLane() {
    ground = track(
        new THREE.Mesh(
            new THREE.PlaneGeometry(60, 60),
            new THREE.MeshStandardMaterial({
                color: 0x02080f,
                roughness: 0.95,
            }),
        ),
    );
    ground.rotation.x = -Math.PI / 2;
    scene.add(ground);

    const grid = track(new THREE.GridHelper(60, 90, 0x238ecd, 0x143e61));
    grid.position.y = 0.01;
    grid.material.transparent = true;
    grid.material.opacity = 0.45;
    scene.add(grid);

    const tickGeo = track(new THREE.BoxGeometry(0.04, 0.01, 0.5));
    const tickMat = track(
        new THREE.MeshBasicMaterial({
            color: 0x61c7ff,
            transparent: true,
            opacity: 0.5,
        }),
    );
    for (let x = -6; x <= -1; x += 1) {
        const tick = new THREE.Mesh(tickGeo, tickMat);
        tick.position.set(x, 0.015, 0);
        scene.add(tick);
        laneTicks.push(tick);
    }
}

onMounted(async () => {
    reduceMotionQuery?.addEventListener("change", onReduceMotionChange);

    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x000000);
    scene.fog = new THREE.Fog(0x000000, 9, 24);

    camera = new THREE.PerspectiveCamera(CAMERA_FOV, 1, 0.1, 100);
    camera.position.copy(CAMERA_BASE);
    camera.lookAt(CAMERA_LOOKAT);

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: "low-power" });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    } catch {
        rendererUnavailable.value = true;
        return;
    }
    rendererCanvas = renderer.domElement;
    rendererCanvas.addEventListener("webglcontextlost", onContextLost);
    renderer.shadowMap.enabled = false;
    host.value.appendChild(renderer.domElement);

    const ambient = new THREE.AmbientLight(0x8899aa, 0.8);
    scene.add(ambient);
    const key = new THREE.DirectionalLight(0xfff4e0, 0.5);
    key.position.set(4, 6, 4);
    scene.add(key);

    // Shared blob-shadow assets — one gradient texture and one unit circle, reused/scaled per fighter.
    const shadowCanvas = document.createElement("canvas");
    shadowCanvas.width = shadowCanvas.height = 128;
    const sctx = shadowCanvas.getContext("2d");
    const grad = sctx.createRadialGradient(64, 64, 0, 64, 64, 64);
    grad.addColorStop(0, "rgba(0,0,0,0.55)");
    grad.addColorStop(1, "rgba(0,0,0,0)");
    sctx.fillStyle = grad;
    sctx.fillRect(0, 0, 128, 128);
    shadowTex = track(new THREE.CanvasTexture(shadowCanvas));
    shadowTex.colorSpace = THREE.SRGBColorSpace;
    shadowGeo = track(new THREE.CircleGeometry(1, 32));

    buildGroundAndLane();
    // A dimensional scanner cage shares the fight renderer: no second canvas or idle GPU scene.
    for (let i = 0; i < 5; i++) {
        const material = track(new THREE.MeshBasicMaterial({ color: i % 2 ? 0x7bd5ff : 0x168bfa, transparent: true, opacity: .17 + i * .018, side: THREE.DoubleSide, depthWrite: false }));
        const ring = new THREE.Mesh(track(new THREE.RingGeometry(1.5 + i * .46, 1.512 + i * .46, 96, 1, i * .6, Math.PI * 1.65)), material);
        ring.rotation.x = -Math.PI / 2 + (i % 2) * .14;
        ring.position.set(.25, .02 + i * .035, -.35);
        ring.userData.baseOpacity = material.opacity;
        scene.add(ring); orbitRings.push(ring);
    }
    const dustPositions = new Float32Array(240 * 3);
    for (let i = 0; i < 240; i++) {
        const angle = i * 2.39996323;
        const radius = 2.8 + (i % 17) * .21;
        dustPositions[i * 3] = Math.cos(angle) * radius;
        dustPositions[i * 3 + 1] = .12 + (i % 29) * .14;
        dustPositions[i * 3 + 2] = -2.5 + Math.sin(angle) * radius * .55;
    }
    const dustGeometry = track(new THREE.BufferGeometry());
    dustGeometry.setAttribute("position", new THREE.BufferAttribute(dustPositions, 3));
    scannerDust = new THREE.Points(dustGeometry, track(new THREE.PointsMaterial({ color: 0x57bcff, size: .025, transparent: true, opacity: .65, depthWrite: false, sizeAttenuation: true })));
    scene.add(scannerDust);

    clock = new THREE.Timer();
    clock.connect(document);

    await preloadSprites();
    if (disposed) return;
    resetScene();
    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(host.value);
    document.addEventListener("visibilitychange", onVisibility);
    intersectionObserver = new IntersectionObserver(([entry]) => { inView = entry.isIntersecting; if (inView) resume(); else pause(); }, { rootMargin: "60px" });
    intersectionObserver.observe(host.value);
    resume();
});

function onContextLost(event) { event.preventDefault(); rendererUnavailable.value = true; pause(); }

onBeforeUnmount(() => {
    disposed = true;
    pause();
    intersectionObserver?.disconnect();
    rendererCanvas?.removeEventListener("webglcontextlost", onContextLost);
    reduceMotionQuery?.removeEventListener("change", onReduceMotionChange);
    document.removeEventListener("visibilitychange", onVisibility);
    resizeObserver?.disconnect();
    active.forEach((a) => disposeGroup(a.group));
    if (championGroup) disposeGroup(championGroup);
    if (promotion) {
        disposeGroup(promotion.fallingGroup);
        disposeGroup(promotion.growingGroup);
    }
    laneTicks.forEach((t) => scene.remove(t));
    coinTex?.dispose();
    disposables.forEach((d) => {
        d.geometry?.dispose?.();
        d.material?.dispose?.();
        d.dispose?.();
    });
    clock?.dispose();
    renderer?.dispose();
    host.value?.replaceChildren();
});

watch(
    () => props.coin,
    () => {
        if (scene && spritesReady) resetScene();
    },
);

const championLabelStyle = computed(() => ({
    left: championLabel.value.left,
    top: championLabel.value.top,
    opacity: championLabel.value.visible ? 1 : 0,
}));

const hudFlashCls = (field) =>
    hudFlash.value[field]?.dir === "up"
        ? "flash-up"
        : hudFlash.value[field]?.dir === "down"
          ? "flash-down"
          : "";
const hudFlashTick = (field) => hudFlash.value[field]?.tick ?? 0;
const lastBadgeCls = computed(() =>
    hud.value.last === "HIT"
        ? "bg-emerald-500/20 text-emerald-300"
        : hud.value.last === "MISS"
          ? "bg-red-500/20 text-red-300"
          : "bg-zinc-800 text-zinc-500",
);

defineExpose({ spawnChallenger, promote: fallAndGrow });
</script>

<template>
    <div class="relative h-full w-full">
        <div ref="host" class="absolute inset-0"></div>
        <div v-if="rendererUnavailable" class="arena-renderer-fallback"><img :src="'/brand/shoegpt-robot-armor.webp'" alt="ShoeMoney robot champion" /><div><strong>Strategy observer</strong><span>3D unavailable · live evidence remains below.</span></div></div>
        <div
            v-if="!rendererUnavailable"
            class="arena-combat-hud pointer-events-none absolute left-2 top-2 grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-0.5 font-mono text-sm uppercase tracking-widest text-zinc-500"
        >
            <div>on stage</div>
            <div
                :key="`active-${hudFlashTick('active')}`"
                class="num text-sm font-semibold text-zinc-100 tabular-nums"
                :class="hudFlashCls('active')"
            >
                {{ hud.active }}
            </div>
            <div>waiting</div>
            <div
                :key="`queued-${hudFlashTick('queued')}`"
                class="num text-sm font-semibold text-zinc-100 tabular-nums"
                :class="hudFlashCls('queued')"
            >
                {{ hud.queued }}
            </div>
            <div>last</div>
            <div
                :key="`last-${hudFlashTick('last')}`"
                class="badge justify-self-end"
                :class="lastBadgeCls"
            >
                {{ hud.last }}
            </div>
        </div>
        <div
            class="pointer-events-none absolute -translate-x-1/2 font-mono text-sm uppercase tracking-widest text-zinc-400"
            :style="championLabelStyle"
        >
            CHAMPION
        </div>
    </div>
</template>

<style scoped>
.arena-combat-hud{top:26px;left:30px;padding:12px 15px;background:#020a12bb;border:1px solid #21527688;border-radius:8px;color:#98bfdb;font-size:12px;backdrop-filter:blur(6px)}
.arena-renderer-fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse,#073b60aa,transparent 70%);overflow:hidden}.arena-renderer-fallback img{max-height:88%;max-width:80%;object-fit:contain;filter:drop-shadow(0 0 24px #0b7fe633)}.arena-renderer-fallback>div{position:absolute;bottom:20px;left:20px;right:20px;text-align:center;background:#000a;padding:13px;border:1px solid #21527688;border-radius:8px}.arena-renderer-fallback strong{display:block;font-size:17px;color:#d9f0ff}.arena-renderer-fallback span{display:block;font-size:13px;margin-top:5px;color:#a1c2db}
@media(max-width:600px){.arena-combat-hud{top:24px;left:24px;padding:8px 10px;font-size:10px}}
</style>
