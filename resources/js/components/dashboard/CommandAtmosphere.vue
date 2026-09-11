<script setup>
import { ref, onMounted, onUnmounted, watch } from "vue";
import * as THREE from "three";

const props = defineProps({
    active: Boolean,
    pulse: { type: Number, default: 0 },
});
const host = ref(null);
let renderer, scene, camera, particles, rings, resizeObserver, observer;
let frame = 0,
    disposed = false,
    contextLost = false,
    visible = true,
    last = 0,
    clock = 0,
    surge = 0;
const pointer = { x: 0, y: 0 };
const eased = { x: 0, y: 0 };
const geometries = [],
    materials = [];

function draw(now = 0) {
    frame = 0;
    if (disposed || contextLost || !renderer) return;
    if (props.active && visible && !document.hidden) {
        const delta = Math.min(50, Math.max(0, now - last));
        clock += delta / 1000;
        surge *= 0.955;
        eased.x += (pointer.x - eased.x) * 0.035;
        eased.y += (pointer.y - eased.y) * 0.035;
        particles.rotation.z = clock * 0.011;
        particles.rotation.y = Math.sin(clock * 0.035) * 0.08 + eased.x * 0.05;
        rings.rotation.z = -clock * 0.014;
        camera.position.x = eased.x * 0.45;
        camera.position.y = eased.y * 0.2;
        particles.material.uniforms.time.value = clock;
        particles.material.uniforms.surge.value = surge;
    }
    last = now;
    renderer.render(scene, camera);
    if (props.active && visible && !document.hidden)
        frame = requestAnimationFrame(draw);
}
function sync() {
    cancelAnimationFrame(frame);
    frame = 0;
    last = performance.now();
    if (renderer && visible && !document.hidden) draw(last);
}
function loseContext(event) {
    event.preventDefault();
    contextLost = true;
    cancelAnimationFrame(frame);
    frame = 0;
    renderer.domElement.style.visibility = "hidden";
}
function restoreContext() {
    contextLost = false;
    renderer.domElement.style.visibility = "";
    sync();
}
function pointerMove(event) {
    const bounds = host.value?.parentElement?.getBoundingClientRect();
    if (!bounds || !props.active) return;
    pointer.x = ((event.clientX - bounds.left) / bounds.width - 0.5) * 2;
    pointer.y = -((event.clientY - bounds.top) / bounds.height - 0.5) * 2;
}
watch(() => props.active, sync);
watch(
    () => props.pulse,
    () => {
        if (props.active) surge = 1;
    },
);
onMounted(() => {
    try {
        renderer = new THREE.WebGLRenderer({
            alpha: true,
            antialias: false,
            powerPreference: "low-power",
        });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        renderer.setClearColor(0x000000, 0);
        renderer.domElement.setAttribute("aria-hidden", "true");
        renderer.domElement.addEventListener("webglcontextlost", loseContext);
        renderer.domElement.addEventListener(
            "webglcontextrestored",
            restoreContext,
        );
        host.value.appendChild(renderer.domElement);
        scene = new THREE.Scene();
        camera = new THREE.PerspectiveCamera(46, 1, 0.1, 50);
        camera.position.z = 9;
        const geometry = new THREE.BufferGeometry();
        const count = window.innerWidth < 700 ? 600 : 1600;
        const positions = new Float32Array(count * 3),
            seeds = new Float32Array(count);
        for (let i = 0; i < count; i++) {
            const angle = i * 2.39996323;
            const radius = 1.5 + Math.sqrt(i / count) * 7;
            positions[i * 3] = Math.cos(angle) * radius;
            positions[i * 3 + 1] = Math.sin(angle) * radius * 0.45;
            positions[i * 3 + 2] = Math.sin(i * 4.3) * 2;
            seeds[i] = (i * 0.618034) % 1;
        }
        geometry.setAttribute(
            "position",
            new THREE.BufferAttribute(positions, 3),
        );
        geometry.setAttribute("seed", new THREE.BufferAttribute(seeds, 1));
        const material = new THREE.ShaderMaterial({
            transparent: true,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            uniforms: { time: { value: 0 }, surge: { value: 0 } },
            vertexShader: `attribute float seed; uniform float time; uniform float surge; varying float light;
                void main(){vec3 p=position; p.z+=sin(time*.45+seed*14.)*.13;
                vec4 mv=modelViewMatrix*vec4(p,1.);gl_Position=projectionMatrix*mv;
                gl_PointSize=(1.5+seed*2.+surge*1.3)*min(1.8,8./-mv.z);
                light=.2+seed*.5+surge*.25;}`,
            fragmentShader: `varying float light;void main(){float r=length(gl_PointCoord-.5);float a=(1.-smoothstep(.05,.5,r))*light;gl_FragColor=vec4(.08,.52,1.,a);}`,
        });
        particles = new THREE.Points(geometry, material);
        scene.add(particles);
        geometries.push(geometry);
        materials.push(material);
        rings = new THREE.Group();
        for (let i = 0; i < 5; i++) {
            const points = Array.from({ length: 181 }, (_, n) => {
                const a = (n / 180) * Math.PI * 2;
                return new THREE.Vector3(
                    Math.cos(a) * (2.6 + i * 0.6),
                    Math.sin(a) * (1.2 + i * 0.35),
                    -1,
                );
            });
            const g = new THREE.BufferGeometry().setFromPoints(points);
            const m = new THREE.LineBasicMaterial({
                color: 0x189aff,
                transparent: true,
                opacity: 0.07 + i * 0.009,
            });
            rings.add(new THREE.Line(g, m));
            geometries.push(g);
            materials.push(m);
        }
        rings.position.x = 3;
        scene.add(rings);
        resizeObserver = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            if (!width || !height) return;
            renderer.setSize(width, height);
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            sync();
        });
        resizeObserver.observe(host.value);
        observer = new IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting;
            sync();
        });
        observer.observe(host.value);
        document.addEventListener("visibilitychange", sync);
        host.value.parentElement.addEventListener("pointermove", pointerMove, {
            passive: true,
        });
        sync();
    } catch {
        renderer?.dispose();
        renderer = null;
        // The CSS orbital field remains when WebGL is unavailable.
    }
});
onUnmounted(() => {
    disposed = true;
    cancelAnimationFrame(frame);
    resizeObserver?.disconnect();
    observer?.disconnect();
    document.removeEventListener("visibilitychange", sync);
    host.value?.parentElement?.removeEventListener("pointermove", pointerMove);
    geometries.forEach((geometry) => geometry.dispose());
    materials.forEach((material) => material.dispose());
    renderer?.dispose();
    renderer?.domElement.removeEventListener("webglcontextlost", loseContext);
    renderer?.domElement.removeEventListener(
        "webglcontextrestored",
        restoreContext,
    );
    renderer?.domElement.remove();
});
</script>

<template>
    <div ref="host" class="dc-atmosphere" aria-hidden="true"></div>
</template>
