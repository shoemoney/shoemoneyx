import * as THREE from "three";
import { createSceneSpectacle } from "./sceneSpectacle";
import { pngUrlFor } from "../coinIcons";
import { coinOrbit } from "./robotCoinScan";

const TAU = Math.PI * 2;
const modes = [
    "starburst",
    "grid",
    "reactor",
    "waves",
    "city",
    "tunnel",
    "neural",
    "spectrum",
    "blackhole",
    "matrix",
];

// All ten fields use one batched particle draw, with motion computed on the GPU.
const vertexShader = `
    uniform float uTime;
    uniform float uMode;
    uniform float uActivity;
    uniform float uPixelRatio;
    attribute float aPhase;
    attribute float aSize;
    varying float vGlow;
    varying float vMix;
    void main() {
        vec3 p = position;
        float t = uTime;
        if (uMode < 0.5) {
            p = normalize(p) * (0.55 + fract(aPhase + t * 0.085) * 6.8);
        } else if (uMode < 1.5) {
            p.y += sin(p.x * 0.7 + t * 0.6) * cos(p.z * 0.6 + t * 0.35) * 0.8;
        } else if (uMode < 2.5) {
            float a = t * 0.18;
            p.xz = mat2(cos(a), -sin(a), sin(a), cos(a)) * p.xz;
            p.y += sin(aPhase * 30.0 + t) * 0.12;
        } else if (uMode < 3.5) {
            p.y += sin(p.x * 0.68 + t * 0.9) * 0.85 + cos(p.z * 0.65 + t * 0.5) * 0.65;
        } else if (uMode < 4.5) {
            p.y += fract(t * 0.16 + aPhase) * 1.5;
        } else if (uMode < 5.5) {
            p.z = 6.0 - fract(aPhase + t * 0.12) * 34.0;
        } else if (uMode < 6.5) {
            p *= 1.0 + sin(t * 0.6 + aPhase * 4.0) * 0.05;
            p.y += sin(t * 0.3 + p.x) * 0.12;
        } else if (uMode < 7.5) {
            p.y += sin(p.x * 0.65 + t * 0.8) * 0.9;
            p.z += cos(p.x * 0.6 + t * 0.5) * 0.6;
        } else if (uMode < 8.5) {
            float a = t * (0.1 + 0.4 / max(length(p.xz), 0.4));
            p.xz = mat2(cos(a), -sin(a), sin(a), cos(a)) * p.xz;
        } else {
            p.y += sin(p.x * 0.8 + t) * cos(p.z * 0.8 - t * 0.4) * 0.4;
        }
        vec4 mv = modelViewMatrix * vec4(p, 1.0);
        gl_Position = projectionMatrix * mv;
        gl_PointSize = min(12.0, aSize * uPixelRatio * 23.0 / max(-mv.z, 0.5));
        vGlow = 0.45 + 0.4 * sin(aPhase * 65.0 + t * (0.7 + uActivity * 0.6));
        vMix = aPhase;
    }
`;
const fragmentShader = `
    uniform vec3 uColor;
    uniform vec3 uSecond;
    varying float vGlow;
    varying float vMix;
    void main() {
        float d = length(gl_PointCoord - vec2(0.5));
        if (d > 0.5) discard;
        float glow = pow(1.0 - d * 2.0, 1.4);
        gl_FragColor = vec4(mix(uColor, uSecond, vMix), glow * (0.55 + vGlow));
    }
`;

export function createDataScene(
    host,
    design,
    onSelect,
    onFailure,
    options = {},
) {
    const coinNodes = options.coinNodes === true;
    const initializationCleanups = [];
    try {
        const renderer = new THREE.WebGLRenderer({
            alpha: true,
            antialias: true,
            powerPreference: "high-performance",
        });
        initializationCleanups.push(() => {
            renderer.dispose();
            renderer.forceContextLoss();
            renderer.domElement.remove();
        });
        renderer.setClearColor(0x000000, 0);
        renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.domElement.setAttribute("aria-hidden", "true");
        host.appendChild(renderer.domElement);
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(46, 1, 0.1, 100);
        const color = new THREE.Color(design.accent),
            second = new THREE.Color(design.second),
            negativeColor = new THREE.Color(
                design.colorway === "blue" ? "#9398ff" : "#ff6376",
            );
        const mode = modes.indexOf(design.scene);
        const field = new THREE.Group(),
            core = new THREE.Group(),
            links = new THREE.Group();
        scene.add(field, core, links);
        const objects = new Set(),
            materials = new Set();
        initializationCleanups.push(() => {
            objects.forEach((g) => g.dispose());
            materials.forEach((m) => m.dispose());
        });
        const geo = (g) => {
            objects.add(g);
            return g;
        };
        const mat = (m) => {
            materials.add(m);
            return m;
        };
        let seed = 37;
        const random = () => {
            seed = (seed * 16807) % 2147483647;
            return (seed - 1) / 2147483646;
        };
        const count = host.clientWidth < 600 ? 4400 : 8200;
        const positions = new Float32Array(count * 3),
            phases = new Float32Array(count),
            sizes = new Float32Array(count);
        for (let i = 0; i < count; i++) {
            let x, y, z;
            const a = random() * TAU,
                r = 1.1 + Math.pow(random(), 0.7) * 5.5;
            if (mode === 0 || mode === 6) {
                const v = random() * 2 - 1,
                    radius = mode === 6 ? 1.6 + random() * 2.5 : r;
                x = Math.cos(a) * Math.sqrt(1 - v * v) * radius;
                y = Math.sin(a) * Math.sqrt(1 - v * v) * radius;
                z = v * radius;
            } else if ([1, 3, 9].includes(mode)) {
                const side = Math.ceil(Math.sqrt(count));
                x = ((i % side) / side - 0.5) * 16;
                z = (Math.floor(i / side) / side - 0.5) * 12;
                y = mode === 9 ? (i % 7) * 0.15 - 1 : -1.2;
            } else if (mode === 2) {
                const b = random() * TAU,
                    rr = 2.75 + Math.cos(b) * 0.6;
                x = Math.cos(a) * rr;
                z = Math.sin(a) * rr;
                y = Math.sin(b) * 0.6;
            } else if (mode === 4) {
                x = (random() - 0.5) * 11;
                z = (random() - 0.5) * 8;
                y = -1.5 + random() * 0.15;
            } else if (mode === 5) {
                const radius = 1.8 + random() * 4.5;
                x = Math.cos(a) * radius;
                y = Math.sin(a) * radius;
                z = random() * -28;
            } else if (mode === 7) {
                x = (random() - 0.5) * 13;
                y = Math.cos(a) * (0.8 + random() * 0.4);
                z = Math.sin(a) * 1.7;
            } else {
                x = Math.cos(a) * r;
                z = Math.sin(a) * r;
                y = (random() - 0.5) * (0.1 + 1 / r);
            }
            positions.set([x, y, z], i * 3);
            phases[i] = random();
            sizes[i] = random() * 1.6 + 0.5;
        }
        const particleGeo = geo(new THREE.BufferGeometry());
        particleGeo.setAttribute(
            "position",
            new THREE.BufferAttribute(positions, 3),
        );
        particleGeo.setAttribute(
            "aPhase",
            new THREE.BufferAttribute(phases, 1),
        );
        particleGeo.setAttribute("aSize", new THREE.BufferAttribute(sizes, 1));
        const uniforms = {
            uTime: { value: 0 },
            uMode: { value: mode },
            uActivity: { value: 0 },
            uPixelRatio: { value: renderer.getPixelRatio() },
            uColor: { value: color },
            uSecond: { value: second },
        };
        field.add(
            new THREE.Points(
                particleGeo,
                mat(
                    new THREE.ShaderMaterial({
                        uniforms,
                        vertexShader,
                        fragmentShader,
                        transparent: true,
                        depthWrite: false,
                        blending: design.light
                            ? THREE.NormalBlending
                            : THREE.AdditiveBlending,
                    }),
                ),
            ),
        );

        const lineMaterial = mat(
            new THREE.LineBasicMaterial({
                color,
                transparent: true,
                opacity: design.light ? 0.25 : 0.28,
            }),
        );
        const brightMaterial = mat(
            new THREE.MeshBasicMaterial({
                color,
                transparent: true,
                opacity: 0.5,
                wireframe: true,
            }),
        );
        const ring = (radius, rotation, opacity = 0.24) => {
            const mesh = new THREE.Mesh(
                geo(new THREE.TorusGeometry(radius, 0.008, 4, 180)),
                mat(
                    new THREE.MeshBasicMaterial({
                        color,
                        transparent: true,
                        opacity,
                    }),
                ),
            );
            mesh.rotation.set(...rotation);
            core.add(mesh);
            return mesh;
        };
        if (mode === 4) {
            camera.position.set(7.5, 7.2, 8.5);
            camera.lookAt(0, 0, 0);
            const grid = new THREE.GridHelper(13, 26, color, color);
            grid.position.y = -1.5;
            grid.material.transparent = true;
            grid.material.opacity = 0.18;
            objects.add(grid.geometry);
            materials.add(grid.material);
            scene.add(grid);
        } else if (mode === 5) {
            camera.position.set(0, 0.4, 8);
            camera.lookAt(0, 0, -7);
            for (let j = 0; j < 12; j++) {
                const r = ring(3.4, [0, 0, Math.PI / 4], 0.24 + j * 0.012);
                r.position.z = -j * 2;
            }
            const wire = new THREE.Mesh(
                geo(new THREE.OctahedronGeometry(0.65, 0)),
                brightMaterial,
            );
            core.add(wire);
        } else if ([1, 3, 9].includes(mode)) {
            camera.position.set(0, 4.2, 8.5);
            camera.lookAt(0, -0.3, 0);
            if (mode !== 3) {
                const grid = new THREE.GridHelper(16, 32, color, second);
                grid.position.y = -1.25;
                grid.material.transparent = true;
                grid.material.opacity = 0.14;
                objects.add(grid.geometry);
                materials.add(grid.material);
                scene.add(grid);
            }
            core.add(
                new THREE.Mesh(
                    geo(
                        new THREE.IcosahedronGeometry(
                            mode === 3 ? 0.55 : 0.9,
                            1,
                        ),
                    ),
                    brightMaterial,
                ),
            );
            ring(1.6, [Math.PI / 2, 0, 0]);
        } else if (mode === 8) {
            camera.position.set(0, 3.1, 9);
            camera.lookAt(0, 0, 0);
            const sphere = new THREE.Mesh(
                geo(new THREE.SphereGeometry(1.1, 40, 24)),
                mat(new THREE.MeshBasicMaterial({ color: "#060606" })),
            );
            core.add(sphere);
            ring(1.15, [Math.PI / 2, 0, 0], 0.95);
            ring(1.23, [Math.PI / 2, 0, 0], 0.5);
            ring(1.18, [0.3, 0, 0], 0.8);
        } else if (mode === 7) {
            camera.position.set(0, 2, 9);
            camera.lookAt(0, 0, 0);
            const shape = new THREE.Mesh(
                geo(new THREE.TorusKnotGeometry(0.8, 0.23, 100, 8)),
                brightMaterial,
            );
            core.add(shape);
        } else {
            camera.position.set(
                0,
                mode === 2 ? 3.8 : 0.7,
                mode === 2 ? 8.8 : 10.8,
            );
            camera.lookAt(0, 0, 0);
            core.add(
                new THREE.Mesh(
                    geo(
                        new THREE.IcosahedronGeometry(
                            mode === 2 ? 1.1 : 0.85,
                            2,
                        ),
                    ),
                    brightMaterial,
                ),
            );
            ring(mode === 2 ? 2.3 : 1.35, [Math.PI / 2.4, 0.2, 0]);
            ring(mode === 2 ? 3.6 : 1.65, [0.1, Math.PI / 3, 0.3]);
            ring(mode === 2 ? 3.8 : 1.8, [0.2, 0, Math.PI / 6], 0.13);
        }
        if (mode === 6) {
            const cloud = [];
            for (let i = 0; i < 160; i++)
                cloud.push(
                    new THREE.Vector3(
                        positions[i * 3],
                        positions[i * 3 + 1],
                        positions[i * 3 + 2],
                    ),
                );
            const segments = [];
            for (let i = 0; i < cloud.length; i++)
                for (let j = i + 1; j < cloud.length; j++)
                    if (cloud[i].distanceTo(cloud[j]) < 1.15)
                        segments.push(
                            ...cloud[i].toArray(),
                            ...cloud[j].toArray(),
                        );
            const network = geo(new THREE.BufferGeometry());
            network.setAttribute(
                "position",
                new THREE.Float32BufferAttribute(segments, 3),
            );
            field.add(new THREE.LineSegments(network, lineMaterial));
        }

        const spectacle = createSceneSpectacle({ scene, camera, design, mode });
        initializationCleanups.push(() => spectacle.dispose());

        // Market nodes and all pipes are rebuilt only when the pair universe changes.
        const nodeGeometry = geo(new THREE.IcosahedronGeometry(0.085, 1));
        const nodeMaterial = mat(
            new THREE.MeshBasicMaterial({ color: second }),
        );
        const coinTextures = new Set();
        let coinAssetsDisposed = false;
        const disposeCoinTextures = () => {
            coinAssetsDisposed = true;
            coinTextures.forEach((texture) => texture.dispose());
            coinTextures.clear();
        };
        initializationCleanups.push(disposeCoinTextures);
        function canvasTexture(paint) {
            const canvas = document.createElement("canvas");
            canvas.width = canvas.height = 128;
            const context = canvas.getContext("2d");
            if (!context) throw new Error("Coin icon canvas is unavailable");
            paint(context);
            const texture = new THREE.CanvasTexture(canvas);
            texture.colorSpace = THREE.SRGBColorSpace;
            coinTextures.add(texture);
            return texture;
        }
        const haloTexture = coinNodes
            ? canvasTexture((context) => {
                  const glow = context.createRadialGradient(
                      64,
                      64,
                      38,
                      64,
                      64,
                      64,
                  );
                  glow.addColorStop(0, "#1e82ff00");
                  glow.addColorStop(0.28, "#2ba9ff88");
                  glow.addColorStop(0.55, "#137dff36");
                  glow.addColorStop(1, "#137dff00");
                  context.fillStyle = glow;
                  context.fillRect(0, 0, 128, 128);
                  context.strokeStyle = "#68ccffdd";
                  context.lineWidth = 1.8;
                  context.beginPath();
                  context.arc(64, 64, 45, 0, TAU);
                  context.stroke();
              })
            : null;
        function coinBadge(id) {
            return canvasTexture((context) => {
                context.fillStyle = "#06172c";
                context.beginPath();
                context.arc(64, 64, 61, 0, TAU);
                context.fill();
                context.strokeStyle = "#45b9ff";
                context.lineWidth = 3;
                context.stroke();
                const ticker = String(id).split("-")[0].slice(0, 5);
                context.font = `700 ${ticker.length > 3 ? 24 : 33}px sans-serif`;
                context.textAlign = "center";
                context.textBaseline = "middle";
                context.fillStyle = "#bbebff";
                context.fillText(ticker, 64, 66);
            });
        }
        function reportCoinTextures() {
            if (!coinNodes || coinAssetsDisposed) return;
            host.dataset.coinTexturesLoaded = String(
                nodes.filter((node) => node.userData.coinState === "loaded")
                    .length,
            );
            host.dataset.coinTexturesFallback = String(
                nodes.filter((node) => node.userData.coinState === "fallback")
                    .length,
            );
        }
        let whiteCoinBackingTexture;
        function makeCoinNode(pair) {
            const badge = coinBadge(pair.id);
            const iconMaterial = mat(
                new THREE.SpriteMaterial({
                    map: badge,
                    color: 0xffffff,
                    transparent: true,
                    depthTest: false,
                    depthWrite: false,
                    toneMapped: false,
                    sizeAttenuation: false,
                }),
            );
            const haloMaterial = mat(
                new THREE.SpriteMaterial({
                    map: haloTexture,
                    color: 0xffffff,
                    transparent: true,
                    depthTest: false,
                    depthWrite: false,
                    toneMapped: false,
                    sizeAttenuation: false,
                    opacity: 0.65,
                }),
            );
            const node = new THREE.Sprite(iconMaterial);
            const halo = new THREE.Sprite(haloMaterial);
            let backing = null;
            // ONDO supplies a black transparent mark. Keep its source pixels
            // untouched and give it the same neutral disk used by DOM icons.
            if (String(pair.id).split("-")[0].toUpperCase() === "ONDO") {
                whiteCoinBackingTexture ||= canvasTexture((context) => {
                    context.fillStyle = "#ffffff";
                    context.beginPath();
                    context.arc(64, 64, 63.5, 0, TAU);
                    context.fill();
                });
                backing = new THREE.Sprite(
                    mat(
                        new THREE.SpriteMaterial({
                            map: whiteCoinBackingTexture,
                            color: 0xffffff,
                            transparent: true,
                            depthTest: false,
                            depthWrite: false,
                            toneMapped: false,
                            sizeAttenuation: false,
                        }),
                    ),
                );
                backing.renderOrder = 21.5;
            }
            node.renderOrder = 22;
            halo.renderOrder = 21;
            const assets = {
                active: true,
                halo,
                backing,
                materials: [
                    iconMaterial,
                    haloMaterial,
                    ...(backing ? [backing.material] : []),
                ],
                textures: new Set([badge]),
            };
            node.userData.coinAssets = assets;
            node.userData.coinState = "pending";
            const url = pngUrlFor(pair.id);
            if (!url) node.userData.coinState = "fallback";
            else {
                const texture = new THREE.TextureLoader().load(
                    url,
                    (loaded) => {
                        if (coinAssetsDisposed || !assets.active) {
                            loaded.dispose();
                            return;
                        }
                        loaded.colorSpace = THREE.SRGBColorSpace;
                        iconMaterial.map = loaded;
                        iconMaterial.needsUpdate = true;
                        node.userData.coinState = "loaded";
                        badge.dispose();
                        coinTextures.delete(badge);
                        assets.textures.delete(badge);
                        reportCoinTextures();
                        draw();
                    },
                    undefined,
                    () => {
                        if (coinAssetsDisposed || !assets.active) return;
                        node.userData.coinState = "fallback";
                        reportCoinTextures();
                        draw();
                    },
                );
                texture.colorSpace = THREE.SRGBColorSpace;
                coinTextures.add(texture);
                assets.textures.add(texture);
            }
            return node;
        }
        function releaseCoinNode(node) {
            const assets = node.userData.coinAssets;
            if (!assets) return;
            assets.active = false;
            links.remove(assets.halo);
            if (assets.backing) links.remove(assets.backing);
            assets.materials.forEach((material) => {
                material.dispose();
                materials.delete(material);
            });
            assets.textures.forEach((texture) => {
                texture.dispose();
                coinTextures.delete(texture);
            });
            assets.textures.clear();
        }
        const pulseGeo = geo(new THREE.BufferGeometry());
        const pulseMaterial = mat(
            new THREE.PointsMaterial({
                color: design.light ? color : second,
                size: 0.055,
                transparent: true,
                opacity: 0.9,
                depthWrite: false,
            }),
        );
        const pulses = new THREE.Points(pulseGeo, pulseMaterial);
        links.add(pulses);
        const barGeometry = geo(new THREE.BoxGeometry(0.46, 1, 0.46));
        const barMaterial = mat(
            new THREE.ShaderMaterial({
                vertexShader: `
            varying vec2 vWindow;
            varying vec3 vTint;
            varying vec3 vFace;
            void main() {
                vWindow = uv; vFace = normal;
                vTint = instanceColor;
                gl_Position = projectionMatrix * modelViewMatrix * instanceMatrix * vec4(position, 1.0);
            }
        `,
                fragmentShader: `
            varying vec2 vWindow;
            varying vec3 vTint;
            varying vec3 vFace;
            void main() {
                float windows = step(0.2, fract(vWindow.x * 4.0)) * step(0.3, fract(vWindow.y * 22.0));
                float shade = 0.35 + 0.45 * max(0.0, dot(normalize(vFace), normalize(vec3(1.0, 1.0, 2.0))));
                vec3 wall = vTint * shade * 0.45;
                vec3 glow = vTint * (0.5 + 0.4 * fract(floor(vWindow.y * 22.0) * 0.618));
                gl_FragColor = vec4(mix(wall, glow, windows), 1.0);
            }
        `,
            }),
        );
        let bars,
            nodes = [],
            curves = [],
            signature = "",
            activity = 0,
            pairs = [],
            pipeGeometry;
        initializationCleanups.push(() => bars?.dispose());
        const matrix = new THREE.Object3D();
        function setPairs(next = [], rate = 0) {
            if (disposed || contextUnavailable) return;
            pairs = next;
            activity = Math.min(1, rate / 35);
            const nextSignature = next.map((p) => p.id).join(",");
            if (nextSignature !== signature) {
                signature = nextSignature;
                nodes.forEach((n) => {
                    releaseCoinNode(n);
                    links.remove(n);
                });
                nodes = [];
                curves = [];
                if (pipeGeometry) {
                    links.remove(pipeGeometry.line);
                    pipeGeometry.geometry.dispose();
                    objects.delete(pipeGeometry.geometry);
                }
                if (bars) {
                    links.remove(bars);
                    bars.dispose();
                    bars = null;
                }
                const linePositions = [];
                next.forEach((pair, i) => {
                    const orbit = coinOrbit(i, next.length);
                    let endpoint;
                    if (mode === 4)
                        endpoint = new THREE.Vector3(
                            ((i % 6) - 2.5) * 1.25,
                            -1.4,
                            (Math.floor(i / 6) - Math.floor(next.length / 12)) *
                                1.5,
                        );
                    else if ([1, 3, 7, 9].includes(mode))
                        endpoint = new THREE.Vector3(
                            (i / Math.max(1, next.length - 1) - 0.5) * 12,
                            -0.8,
                            i % 2 ? 1.5 : -1.5,
                        );
                    else
                        endpoint = new THREE.Vector3(
                            orbit.x * 3.7,
                            orbit.y * 2.55,
                            orbit.z,
                        );
                    const node = coinNodes
                        ? makeCoinNode(pair)
                        : new THREE.Mesh(nodeGeometry, nodeMaterial);
                    node.position.copy(endpoint);
                    node.userData.id = pair.id;
                    links.add(node);
                    nodes.push(node);
                    if (coinNodes) {
                        const halo = node.userData.coinAssets.halo;
                        halo.position.copy(endpoint);
                        links.add(halo);
                        const backing = node.userData.coinAssets.backing;
                        if (backing) {
                            backing.position.copy(endpoint);
                            links.add(backing);
                        }
                    }
                    const bend = endpoint.clone().multiplyScalar(0.52);
                    bend.z += mode === 4 ? 0.4 : 1.3;
                    bend.y += 0.5;
                    const curve = new THREE.QuadraticBezierCurve3(
                        new THREE.Vector3(0, 0, 0),
                        bend,
                        endpoint,
                    );
                    curves.push(curve);
                    const path = curve.getPoints(36);
                    path.slice(1).forEach((p, j) =>
                        linePositions.push(
                            ...path[j].toArray(),
                            ...p.toArray(),
                        ),
                    );
                });
                const geometry = geo(new THREE.BufferGeometry());
                geometry.setAttribute(
                    "position",
                    new THREE.Float32BufferAttribute(linePositions, 3),
                );
                const line = new THREE.LineSegments(geometry, lineMaterial);
                links.add(line);
                pipeGeometry = { geometry, line };
                pulseGeo.setAttribute(
                    "position",
                    new THREE.BufferAttribute(
                        new Float32Array(next.length * 8 * 3),
                        3,
                    ),
                );
                pulses.frustumCulled = false;
                if (mode === 4 && next.length) {
                    bars = new THREE.InstancedMesh(
                        barGeometry,
                        barMaterial,
                        next.length,
                    );
                    bars.frustumCulled = false;
                    links.add(bars);
                }
            }
            if (bars) {
                const max = Math.max(1, ...next.map((p) => p.notional || 0));
                next.forEach((p, i) => {
                    const height = 0.15 + ((p.notional || 0) / max) * 3.7;
                    matrix.position.copy(nodes[i].position);
                    matrix.position.y += height / 2;
                    matrix.scale.set(1, height, 1);
                    matrix.updateMatrix();
                    bars.setMatrixAt(i, matrix.matrix);
                    bars.setColorAt(
                        i,
                        p.pnl < 0
                            ? negativeColor
                            : color.clone().lerp(second, i / next.length),
                    );
                });
                bars.instanceMatrix.needsUpdate = true;
                if (bars.instanceColor) bars.instanceColor.needsUpdate = true;
            }
            reportCoinTextures();
            spectacle.setPairs(next, nodes);
            draw();
        }
        const basePosition = camera.position.clone();
        let raf = 0,
            time = 0,
            lastFrame = 0,
            enabled = true,
            disposed = false,
            contextUnavailable = false,
            hidden = document.hidden,
            onScreen = true;
        initializationCleanups.push(() => {
            disposed = true;
            cancelAnimationFrame(raf);
        });
        let focusedId = null,
            scannedId = null,
            hoveredCoinId = null;
        const pointer = new THREE.Vector2(),
            raycaster = new THREE.Raycaster();
        function draw() {
            if (disposed || contextUnavailable || hidden || !onScreen) return;
            uniforms.uTime.value = time;
            uniforms.uActivity.value = activity;
            if (![4, 8].includes(mode)) {
                core.rotation.y = time * 0.12;
                if (mode === 0 || mode === 6)
                    core.rotation.z = Math.sin(time * 0.1) * 0.1;
            }
            if (mode === 6) field.rotation.y = time * 0.018;
            const attr = pulseGeo.getAttribute("position");
            if (attr) {
                curves.forEach((curve, i) => {
                    for (let j = 0; j < 8; j++) {
                        const t =
                            (j / 8 +
                                time * (0.15 + activity * 0.08) +
                                i * 0.03) %
                            1;
                        const p = curve.getPoint(1 - t);
                        attr.setXYZ(i * 8 + j, p.x, p.y, p.z);
                    }
                });
                attr.needsUpdate = true;
            }
            camera.position.x =
                basePosition.x + (enabled ? pointer.x * 0.18 : 0);
            camera.position.y =
                basePosition.y + (enabled ? pointer.y * 0.1 : 0);
            spectacle.update(time, activity);
            const iconPixelScale =
                (2 * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2))) /
                Math.max(1, host.clientHeight);
            nodes.forEach((node, index) => {
                const focused =
                    node.userData.id === focusedId ||
                    node.userData.id === scannedId ||
                    node.userData.id === hoveredCoinId;
                if (!coinNodes) {
                    node.scale.setScalar(focused ? 2.4 : 1);
                    return;
                }
                const breath = enabled
                    ? Math.sin(time * 1.7 - index * 0.48)
                    : 0;
                const pixels =
                    (host.clientWidth < 600 ? 28 : 32) *
                    (1 + breath * 0.035) *
                    (focused ? 1.18 : 1);
                node.scale.setScalar(iconPixelScale * pixels);
                const halo = node.userData.coinAssets.halo;
                node.userData.coinAssets.backing?.scale.copy(node.scale);
                halo.scale.setScalar(
                    iconPixelScale * pixels * (1.52 + breath * 0.1),
                );
                halo.material.opacity = focused ? 0.95 : 0.62 + breath * 0.17;
            });
            renderer.render(scene, camera);
        }
        function tick(stamp) {
            if (
                disposed ||
                contextUnavailable ||
                !enabled ||
                hidden ||
                !onScreen
            ) {
                raf = 0;
                return;
            }
            const delta = lastFrame
                ? Math.min((stamp - lastFrame) / 1000, 0.05)
                : 0;
            lastFrame = stamp;
            time += delta;
            draw();
            raf = requestAnimationFrame(tick);
        }
        function resume() {
            if (
                enabled &&
                !hidden &&
                onScreen &&
                !raf &&
                !disposed &&
                !contextUnavailable
            ) {
                lastFrame = 0;
                raf = requestAnimationFrame(tick);
            }
        }
        function setMotion(value) {
            enabled = value && !contextUnavailable;
            spectacle.setMotion(enabled);
            if (!enabled) {
                cancelAnimationFrame(raf);
                raf = 0;
                draw();
            } else resume();
        }
        function resize() {
            const w = Math.max(1, host.clientWidth),
                h = Math.max(1, host.clientHeight);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            renderer.setSize(w, h);
            draw();
        }
        function move(event) {
            const rect = host.getBoundingClientRect();
            pointer.set(
                ((event.clientX - rect.left) / rect.width) * 2 - 1,
                -(((event.clientY - rect.top) / rect.height) * 2 - 1),
            );
            spectacle.setPointer(pointer);
            if (coinNodes) {
                raycaster.setFromCamera(pointer, camera);
                hoveredCoinId =
                    raycaster.intersectObjects(nodes)[0]?.object.userData.id ||
                    null;
                host.style.cursor = hoveredCoinId ? "pointer" : "";
            }
            if (!enabled) draw();
        }
        function click(event) {
            move(event);
            raycaster.setFromCamera(pointer, camera);
            const hit = raycaster.intersectObjects(nodes)[0];
            spectacle.activate();
            if (hit) onSelect(hit.object.userData.id);
        }
        const leave = () => {
            pointer.set(0, 0);
            hoveredCoinId = null;
            if (coinNodes) host.style.cursor = "";
            spectacle.setPointer(pointer, false);
            if (!enabled) draw();
        };
        const visibility = () => {
            hidden = document.hidden;
            if (hidden) {
                cancelAnimationFrame(raf);
                raf = 0;
            } else {
                draw();
                resume();
            }
        };
        const contextLost = (event) => {
            event.preventDefault();
            contextUnavailable = true;
            setMotion(false);
            onFailure();
        };
        const observer = new ResizeObserver(resize);
        initializationCleanups.push(() => observer.disconnect());
        observer.observe(host);
        const intersection = new IntersectionObserver(([entry]) => {
            onScreen = entry.isIntersecting;
            if (onScreen) {
                draw();
                resume();
            }
        });
        initializationCleanups.push(() => intersection.disconnect());
        intersection.observe(host);
        host.addEventListener("pointermove", move);
        host.addEventListener("pointerleave", leave);
        host.addEventListener("click", click);
        renderer.domElement.addEventListener("webglcontextlost", contextLost);
        document.addEventListener("visibilitychange", visibility);
        initializationCleanups.push(() => {
            host.removeEventListener("pointermove", move);
            host.removeEventListener("pointerleave", leave);
            host.removeEventListener("click", click);
            renderer.domElement.removeEventListener(
                "webglcontextlost",
                contextLost,
            );
            document.removeEventListener("visibilitychange", visibility);
        });
        resize();
        resume();
        return {
            setPairs,
            setMotion,
            getCoinPoint(id) {
                const node = nodes.find((item) => item.userData.id === id);
                if (!node || disposed || contextUnavailable) return null;
                const point = node.position.clone().project(camera);
                return { x: (point.x + 1) / 2, y: (1 - point.y) / 2 };
            },
            setScan(id) {
                scannedId = id || null;
                if (!enabled) draw();
            },
            setFocus(id) {
                focusedId = id || null;
                spectacle.setFocus(focusedId);
                if (!enabled) draw();
            },
            dispose() {
                disposed = true;
                cancelAnimationFrame(raf);
                observer.disconnect();
                intersection.disconnect();
                host.removeEventListener("pointermove", move);
                host.removeEventListener("pointerleave", leave);
                host.removeEventListener("click", click);
                document.removeEventListener("visibilitychange", visibility);
                renderer.domElement.removeEventListener(
                    "webglcontextlost",
                    contextLost,
                );
                bars?.dispose();
                disposeCoinTextures();
                if (coinNodes) host.style.cursor = "";
                spectacle.dispose();
                objects.forEach((g) => g.dispose());
                materials.forEach((m) => m.dispose());
                renderer.dispose();
                renderer.forceContextLoss();
                renderer.domElement.remove();
            },
        };
    } catch (error) {
        for (const cleanup of initializationCleanups.reverse()) {
            try {
                cleanup();
            } catch {
                /* Continue releasing remaining resources. */
            }
        }
        throw error;
    }
}
