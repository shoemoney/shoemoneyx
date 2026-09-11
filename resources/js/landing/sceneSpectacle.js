import * as THREE from "three";

const TAU = Math.PI * 2;
const impactSlots = 18;
const particlesPerImpact = 72;

// Three deliberately different layers: the world's sculpture, bounded impacts
// from changed market observations, and an interruption-safe interaction lens.
// None of the decorative dimensions purport to be a measured trading statistic.
export function createSceneSpectacle({ scene, camera, design, mode }) {
    const geometries = new Set();
    const materials = new Set();
    const root = new THREE.Group();
    scene.add(root);
    try {
        const color = new THREE.Color(design.accent);
        const second = new THREE.Color(design.second);
        // Keep the negative-change accent distinct without introducing a warm
        // hue into the blue editions. Numeric signs and observation data stay intact.
        const negative = new THREE.Color(
            design.colorway === "blue" ? "#9398ff" : "#ff557d",
        );
        const geometry = (g) => (geometries.add(g), g);
        const material = (m) => (materials.add(m), m);
        const blending = design.light
            ? THREE.NormalBlending
            : THREE.AdditiveBlending;
        const uniforms = {
            uTime: { value: 0 },
            uMode: { value: mode },
            uActivity: { value: 0 },
            uColor: { value: color },
            uSecond: { value: second },
            uNegative: { value: negative },
            uFocus: { value: new THREE.Vector3() },
            uEngaged: { value: 0 },
            uLight: { value: design.light ? 1 : 0 },
        };
        const vertices = [],
            uvs = [],
            layers = [];
        const pushVertex = (point, u, v, layer) => {
            vertices.push(point.x, point.y, point.z);
            uvs.push(u, v);
            layers.push(layer);
        };
        function ribbon(sample, width, layer, steps = 160, upright = false) {
            const up = new THREE.Vector3(0, 1, 0);
            const side = new THREE.Vector3();
            const tangent = new THREE.Vector3();
            const points = Array.from({ length: steps + 1 }, (_, i) =>
                sample(i / steps),
            );
            const edges = points.map((point, i) => {
                tangent
                    .subVectors(
                        points[Math.min(steps, i + 1)],
                        points[Math.max(0, i - 1)],
                    )
                    .normalize();
                if (upright) side.copy(up);
                else side.crossVectors(tangent, up).normalize();
                if (side.lengthSq() < 0.001) side.set(1, 0, 0);
                const w =
                    width *
                    (0.25 + 0.75 * Math.sin((i / steps) * Math.PI) ** 0.35);
                return [
                    point.clone().addScaledVector(side, -w),
                    point.clone().addScaledVector(side, w),
                ];
            });
            for (let i = 0; i < steps; i++) {
                for (const [j, edge] of [
                    [i, 0],
                    [i, 1],
                    [i + 1, 0],
                    [i, 1],
                    [i + 1, 1],
                    [i + 1, 0],
                ])
                    pushVertex(edges[j][edge], j / steps, edge, layer);
            }
        }
        function arc(radius, start, end, y = 0, tilt = 0) {
            return (t) => {
                const angle = start + (end - start) * t;
                return new THREE.Vector3(
                    Math.cos(angle) * radius,
                    y + Math.sin(angle) * radius * Math.sin(tilt),
                    Math.sin(angle) * radius * Math.cos(tilt),
                );
            };
        }

        if (mode === 0) {
            // Solar prominences curl out of the core and reconnect in tilted loops.
            for (let i = 0; i < 7; i++) {
                const rotation = new THREE.Matrix4().makeRotationZ(
                    (i / 7) * TAU,
                );
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            0.7 + Math.sin(t * Math.PI) * (2.7 + (i % 2) * 0.6),
                            Math.sin(t * TAU) * 0.9,
                            Math.cos(t * TAU) * 0.7,
                        ).applyMatrix4(rotation),
                    0.065,
                    i / 7,
                    120,
                    true,
                );
            }
        } else if (mode === 1) {
            // Neon scan walls cut across the horizontal lattice in opposing planes.
            for (let i = 0; i < 7; i++) {
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            (t - 0.5) * 14,
                            0.35 + Math.sin(t * Math.PI) * 0.35,
                            (i - 3) * 1.25,
                        ),
                    0.95,
                    i / 7,
                    80,
                    true,
                );
            }
            ribbon(arc(2.3, 0, TAU, 0.05, Math.PI / 2), 0.045, 0.65);
        } else if (mode === 2) {
            // Machined turbine vanes form a segmented, counter-rotating iris.
            for (let i = 0; i < 28; i++) {
                ribbon(
                    (t) => {
                        const angle = (i / 28) * TAU + t * 0.24;
                        const radius = 2.65 + t * 1.8;
                        return new THREE.Vector3(
                            Math.cos(angle) * radius,
                            0.12 + Math.sin(t * Math.PI) * 0.65,
                            Math.sin(angle) * radius,
                        );
                    },
                    0.14,
                    i / 28,
                    24,
                );
            }
        } else if (mode === 3) {
            // Wide translucent sheets give the liquid field a continuous surface.
            for (let j = 0; j < 36; j++) {
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            (t - 0.5) * 15,
                            -0.75,
                            (j / 35 - 0.5) * 10,
                        ),
                    0.18,
                    j / 35,
                    100,
                );
            }
        } else if (mode === 4) {
            // Elevated skybridges stitch the capital skyline into an illuminated city.
            for (let i = 0; i < 6; i++) {
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            (t - 0.5) * 9,
                            -0.6 + Math.sin(t * Math.PI) * (3.0 + i * 0.2),
                            (i - 2.5) * 1.25,
                        ),
                    0.06,
                    i / 6,
                    100,
                    true,
                );
            }
            for (let i = 0; i < 4; i++) {
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            (i - 1.5) * 2.4,
                            -0.6 + Math.sin(t * Math.PI) * 3.7,
                            (t - 0.5) * 8,
                        ),
                    0.052,
                    0.4 + i / 7,
                    90,
                    true,
                );
            }
        } else if (mode === 5) {
            // Velocity rails taper through the entire depth of the racing tunnel.
            for (let i = 0; i < 16; i++) {
                const angle = (i / 16) * TAU;
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            Math.cos(angle) * 2.6,
                            Math.sin(angle) * 2.6,
                            5 - t * 34,
                        ),
                    i % 4 ? 0.035 : 0.11,
                    i / 16,
                    120,
                    i % 4 !== 0,
                );
            }
        } else if (mode === 6) {
            // Axon helices braid around the brain instead of adding another sphere.
            for (let i = 0; i < 6; i++) {
                const rotation = new THREE.Matrix4().makeRotationZ(
                    (i / 6) * Math.PI,
                );
                ribbon(
                    (t) => {
                        const angle = t * TAU * 2.5 + (i % 2) * Math.PI;
                        const radius = 0.4 + Math.sin(t * Math.PI) * 0.7;
                        return new THREE.Vector3(
                            (t - 0.5) * 8,
                            Math.cos(angle) * radius,
                            Math.sin(angle) * radius,
                        ).applyMatrix4(rotation);
                    },
                    0.026,
                    i / 6,
                    180,
                    true,
                );
            }
        } else if (mode === 7) {
            // Satin ribbons split into different colors as they fold through space.
            for (let i = 0; i < 8; i++) {
                ribbon(
                    (t) =>
                        new THREE.Vector3(
                            (t - 0.5) * 14,
                            Math.sin(t * TAU + i * 0.4) * (0.5 + i * 0.09),
                            Math.cos(t * TAU * 1.5 + i * 0.4) * 1.5,
                        ),
                    0.085,
                    i / 8,
                    160,
                    true,
                );
            }
        } else if (mode === 8) {
            // Multiple photon arcs curve over and around the dark central silhouette.
            for (let i = 0; i < 6; i++) {
                ribbon(
                    arc(1.4 + i * 0.105, 0, TAU, 0, Math.PI / 2 - i * 0.055),
                    0.021 + i * 0.003,
                    i / 6,
                    220,
                );
            }
            for (let i = 0; i < 4; i++)
                ribbon(
                    arc(2 + i * 0.6, 0, TAU, -0.05, 0.07),
                    0.055,
                    i / 4,
                    220,
                );
        } else {
            // Stacked circuit raceways make packets travel along visible right angles.
            for (let layer = 0; layer < 3; layer++) {
                for (let j = 0; j < 9; j++) {
                    ribbon(
                        (t) => {
                            const x = (t - 0.5) * 14;
                            const z =
                                (j - 4) * 0.7 +
                                (t > 0.38 && t < 0.62 ? 0.4 : 0);
                            return new THREE.Vector3(x, -0.9 + layer * 0.42, z);
                        },
                        0.016 + layer * 0.005,
                        (j + layer) / 12,
                        100,
                    );
                }
            }
        }
        const sculptureGeometry = geometry(new THREE.BufferGeometry());
        sculptureGeometry.setAttribute(
            "position",
            new THREE.Float32BufferAttribute(vertices, 3),
        );
        sculptureGeometry.setAttribute(
            "uv",
            new THREE.Float32BufferAttribute(uvs, 2),
        );
        sculptureGeometry.setAttribute(
            "aLayer",
            new THREE.Float32BufferAttribute(layers, 1),
        );
        const sculpture = new THREE.Mesh(
            sculptureGeometry,
            material(
                new THREE.ShaderMaterial({
                    uniforms,
                    transparent: true,
                    depthWrite: false,
                    side: THREE.DoubleSide,
                    blending,
                    vertexShader: `
            uniform float uTime, uMode, uActivity, uEngaged;
            uniform vec3 uFocus;
            attribute float aLayer;
            varying vec2 vUv;
            varying float vLayer;
            void main() {
                vec3 p = position;
                float t = uTime;
                if (uMode < 0.5) {
                    p *= 1.0 + sin(t * 0.9 + aLayer * 9.0) * 0.065;
                } else if (uMode < 1.5) {
                    p.y += sin(t * 0.65 + aLayer * 6.0) * 0.2;
                } else if (uMode < 2.5) {
                    float a = t * 0.19;
                    p.xz = mat2(cos(a), -sin(a), sin(a), cos(a)) * p.xz;
                } else if (uMode < 3.5) {
                    p.y += sin(p.x * 0.7 + t * 0.9) * 0.55 + cos(p.z * 0.7 + t * 0.5) * 0.45;
                    float d = length(p.xz - uFocus.xz);
                    p.y += sin(d * 3.5 - t * 2.5) * exp(-d * 0.6) * uEngaged * 0.5;
                } else if (uMode > 5.5 && uMode < 6.5) {
                    p.z += sin(t * 0.65 + p.x) * 0.14;
                } else if (uMode > 6.5 && uMode < 7.5) {
                    p.y += sin(t * 0.7 + p.x * 0.5 + aLayer * 4.0) * 0.5;
                } else if (uMode > 7.5 && uMode < 8.5) {
                    p.y += sin(t * 0.4 + aLayer * 6.0) * 0.018;
                }
                vUv = uv; vLayer = aLayer;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
            }
        `,
                    fragmentShader: `
            uniform float uTime, uMode, uActivity, uLight;
            uniform vec3 uColor, uSecond;
            varying vec2 vUv;
            varying float vLayer;
            void main() {
                float edge = pow(max(0.0, 1.0 - abs(vUv.y - 0.5) * 2.0), 0.65);
                float packet = pow(0.5 + 0.5 * sin(vUv.x * 30.0 - uTime * (2.3 + uActivity) + vLayer * 8.0), 9.0);
                float ends = smoothstep(0.0, 0.06, vUv.x) * smoothstep(0.0, 0.06, 1.0 - vUv.x);
                vec3 tint = mix(uColor, uSecond, 0.2 + vLayer * 0.8);
                float alpha = edge * ends * (0.24 + packet * 0.56);
                if (uMode > 0.5 && uMode < 1.5) {
                    float scans = pow(0.5 + 0.5 * sin(vUv.y * 85.0 + uTime), 12.0);
                    float rim = pow(abs(vUv.y - 0.5) * 2.0, 16.0);
                    alpha = ends * (0.06 + edge * 0.24 + scans * 0.28 + rim * 0.28) * (0.7 + packet * 0.6);
                }
                if (uMode > 2.5 && uMode < 3.5) {
                    float caustic = pow(0.5 + 0.5 * sin(vUv.x * 19.0 + vLayer * 15.0 - uTime * 0.7), 14.0);
                    tint = mix(tint, vec3(0.65, 1.0, 0.92), caustic * 0.65);
                    alpha = ends * (0.12 + edge * 0.28 + caustic * 0.26);
                }
                if (uMode > 1.5 && uMode < 2.5) {
                    tint = mix(tint, vec3(1.0, 0.94, 0.65), 0.3);
                    alpha = edge * ends * (0.56 + packet * 0.36);
                }
                if (uMode > 3.5 && uMode < 4.5) {
                    tint = mix(tint, vec3(0.7, 1.0, 0.86), 0.35);
                    alpha = edge * ends * (0.62 + packet * 0.32);
                }
                if (uMode > 4.5 && uMode < 5.5) alpha *= 0.2 + step(0.42, fract(vUv.x * 18.0 - uTime * 0.6));
                if (uMode > 6.5 && uMode < 7.5) {
                    tint = 0.5 + 0.45 * cos(6.28318 * (vLayer + vUv.x * 0.25 + vec3(0.0, 0.33, 0.67)));
                    alpha = edge * ends * (0.36 + packet * 0.2);
                }
                gl_FragColor = vec4(tint * (1.0 + packet * 0.3 * (1.0 - uLight)), alpha);
            }
        `,
                }),
            ),
        );
        root.add(sculpture);

        const count = impactSlots * particlesPerImpact;
        const origins = new Float32Array(count * 3);
        const birth = new Float32Array(count).fill(-100);
        const seeds = new Float32Array(count * 3);
        const signs = new Float32Array(count);
        for (let i = 0; i < count; i++) {
            seeds[i * 3] = (i % particlesPerImpact) / particlesPerImpact;
            seeds[i * 3 + 1] = ((i * 37) % 101) / 101;
            seeds[i * 3 + 2] = ((i * 61) % 103) / 103;
        }
        const impactGeometry = geometry(new THREE.BufferGeometry());
        impactGeometry.setAttribute(
            "position",
            new THREE.BufferAttribute(origins, 3).setUsage(
                THREE.DynamicDrawUsage,
            ),
        );
        impactGeometry.setAttribute(
            "aBirth",
            new THREE.BufferAttribute(birth, 1).setUsage(
                THREE.DynamicDrawUsage,
            ),
        );
        impactGeometry.setAttribute(
            "aSeed",
            new THREE.BufferAttribute(seeds, 3),
        );
        impactGeometry.setAttribute(
            "aSign",
            new THREE.BufferAttribute(signs, 1).setUsage(
                THREE.DynamicDrawUsage,
            ),
        );
        const impacts = new THREE.Points(
            impactGeometry,
            material(
                new THREE.ShaderMaterial({
                    uniforms,
                    transparent: true,
                    depthWrite: false,
                    blending,
                    vertexShader: `
            uniform float uTime, uMode;
            attribute float aBirth, aSign;
            attribute vec3 aSeed;
            varying float vAlpha, vSign, vSeed;
            void main() {
                float age = uTime - aBirth;
                float life = clamp(age / 1.8, 0.0, 1.0);
                float phase = aSeed.x * 6.28318;
                vec3 p = position;
                if (uMode < 0.5) {
                    p += vec3(cos(phase), sin(phase), (aSeed.y - 0.5)) * life * (1.5 + aSeed.z * 2.0);
                } else if (uMode < 1.5) {
                    p += vec3((aSeed.x - 0.5) * 0.6, life * (1.5 + aSeed.y * 2.5), (aSeed.z - 0.5) * 0.4);
                } else if (uMode < 2.5) {
                    p += vec3(cos(phase) * life * 1.5, aSeed.y * 0.2, sin(phase) * life * 1.5);
                } else if (uMode < 3.5) {
                    float radius = life * (0.5 + aSeed.y);
                    p += vec3(cos(phase) * radius, sin(life * 3.14159) * (0.3 + aSeed.z * 1.4), sin(phase) * radius);
                } else if (uMode < 4.5) {
                    p += vec3(cos(phase) * 0.08, life * 5.0 * aSeed.y, sin(phase) * 0.08);
                } else if (uMode < 5.5) {
                    p += vec3((aSeed.x - 0.5) * 0.2, (aSeed.y - 0.5) * 0.2, life * (6.0 + aSeed.z * 6.0));
                } else if (uMode < 6.5) {
                    float travel = clamp(life * 1.2 + aSeed.x * 0.35, 0.0, 1.0);
                    p *= 1.0 - travel;
                    p += vec3(sin(travel * 30.0 + aSeed.z * 2.0), cos(travel * 24.0 + aSeed.y), sin(travel * 18.0)) * sin(travel * 3.14159) * 0.28;
                } else if (uMode < 7.5) {
                    p += vec3(cos(phase * 0.5) * life * 2.0, sin(phase * 0.5) * life * 1.4, (aSeed.y - 0.5) * life * 2.0);
                } else if (uMode < 8.5) {
                    float angle = life * 4.0;
                    p.xz = mat2(cos(angle), -sin(angle), sin(angle), cos(angle)) * p.xz;
                    p *= 1.0 - life * 0.9;
                    p.y += sin(life * 3.14159) * (aSeed.y - 0.5) * 0.6;
                } else {
                    p.x *= 1.0 - min(1.0, life * 2.0);
                    p.z *= 1.0 - max(0.0, life * 2.0 - 1.0);
                    p.y += aSeed.y * life * 1.8;
                }
                vec4 mv = modelViewMatrix * vec4(p, 1.0);
                gl_Position = projectionMatrix * mv;
                gl_PointSize = clamp((18.0 + aSeed.y * 16.0) / max(0.5, -mv.z), 1.0, 10.0);
                vAlpha = step(0.0, age) * (1.0 - smoothstep(0.35, 1.0, life));
                vSign = aSign; vSeed = aSeed.x;
            }
        `,
                    fragmentShader: `
            uniform vec3 uColor, uSecond, uNegative;
            uniform float uMode;
            varying float vAlpha, vSign, vSeed;
            void main() {
                vec2 coord = gl_PointCoord - 0.5;
                float shape = 1.0 - smoothstep(0.05, 0.5, length(coord));
                if (uMode > 0.5 && uMode < 1.5 || uMode > 8.5) shape = 1.0 - smoothstep(0.25, 0.48, max(abs(coord.x), abs(coord.y)));
                vec3 tint = vSign < -0.5 ? uNegative : mix(uColor, uSecond, vSeed);
                if (uMode > 6.5 && uMode < 7.5) tint = 0.5 + 0.45 * cos(6.28318 * (vSeed + vec3(0.0, 0.33, 0.67)));
                gl_FragColor = vec4(tint, shape * vAlpha * 0.9);
            }
        `,
                }),
            ),
        );
        impacts.frustumCulled = false;
        root.add(impacts);

        const lens = new THREE.Group();
        root.add(lens);
        const lensPositions = [],
            lensColors = [];
        function stroke(points, phase = 0) {
            const tint = color.clone().lerp(second, phase);
            for (let i = 1; i < points.length; i++) {
                lensPositions.push(...points[i - 1], ...points[i]);
                lensColors.push(...tint.toArray(), ...tint.toArray());
            }
        }
        const polygon = (sides, radius, rotation = 0, sy = 1) =>
            Array.from({ length: sides + 1 }, (_, i) => {
                const angle = (i / sides) * TAU + rotation;
                return [
                    Math.cos(angle) * radius,
                    Math.sin(angle) * radius * sy,
                    0,
                ];
            });
        if (mode === 0) {
            stroke(polygon(90, 0.65), 0.5);
            for (let i = 0; i < 16; i++) {
                const angle = (i / 16) * TAU;
                stroke(
                    [
                        [Math.cos(angle) * 0.76, Math.sin(angle) * 0.76, 0],
                        [
                            Math.cos(angle + 0.06) * 1.1,
                            Math.sin(angle + 0.06) * 1.1,
                            0,
                        ],
                    ],
                    i / 16,
                );
            }
        } else if (mode === 1) {
            for (let i = 0; i < 3; i++)
                stroke(polygon(6, 0.65 + i * 0.19, i * 0.2), i / 3);
        } else if (mode === 2) {
            const gear = Array.from({ length: 97 }, (_, i) => {
                const radius = i % 4 < 2 ? 0.95 : 0.83;
                return [
                    Math.cos((i / 96) * TAU) * radius,
                    Math.sin((i / 96) * TAU) * radius,
                    0,
                ];
            });
            stroke(gear, 0.4);
            stroke(polygon(64, 0.62), 0.7);
        } else if (mode === 3) {
            for (let i = 0; i < 5; i++)
                stroke(polygon(80, 0.42 + i * 0.16, i * 0.2, 0.45), i / 5);
        } else if (mode === 4) {
            stroke(polygon(4, 1.05, Math.PI / 4), 0.5);
            stroke(polygon(4, 0.8, Math.PI / 4), 0.2);
            for (let i = -1; i <= 1; i += 2) {
                stroke(
                    [
                        [i, -0.45, 0],
                        [i, 0.45, 0],
                        [i * 0.45, 0.45, 0],
                    ],
                    0.8,
                );
                stroke(
                    [
                        [-0.45, i, 0],
                        [0.45, i, 0],
                    ],
                    0.3,
                );
            }
        } else if (mode === 5) {
            stroke(
                [
                    [-1.2, -0.8, 0],
                    [-0.8, 0.8, 0],
                    [0.8, 0.8, 0],
                    [1.2, -0.8, 0],
                ],
                0.65,
            );
            for (let i = 0; i < 5; i++)
                stroke(
                    [
                        [-1 + i * 0.5, 0.9, 0],
                        [-0.85 + i * 0.5, 1.08, 0],
                    ],
                    i / 5,
                );
        } else if (mode === 6) {
            for (let i = 0; i < 12; i++) {
                const angle = (i / 12) * TAU;
                const x = Math.cos(angle),
                    y = Math.sin(angle);
                stroke(
                    [
                        [x * 0.35, y * 0.35, 0],
                        [x * 0.7, y * 0.7, 0],
                        [x + y * 0.14, y - x * 0.14, 0],
                    ],
                    i / 12,
                );
                stroke(
                    [
                        [x * 0.7, y * 0.7, 0],
                        [x - y * 0.17, y + x * 0.17, 0],
                    ],
                    0.8,
                );
            }
        } else if (mode === 7) {
            for (let i = 0; i < 4; i++)
                stroke(polygon(3, 0.82 + i * 0.08, (i * Math.PI) / 6), i / 4);
        } else if (mode === 8) {
            for (let i = 0; i < 4; i++)
                stroke(
                    polygon(100, 0.7 + i * 0.16, i * 0.1, 0.45 + i * 0.14),
                    i / 4,
                );
        } else {
            for (let x = -1; x <= 1; x += 2)
                for (let y = -1; y <= 1; y += 2)
                    stroke(
                        [
                            [x * 0.55, y, 0],
                            [x, y, 0],
                            [x, y * 0.55, 0],
                        ],
                        0.6,
                    );
            stroke(
                [
                    [-0.3, 0, 0],
                    [0.3, 0, 0],
                ],
                0.1,
            );
            stroke(
                [
                    [0, -0.3, 0],
                    [0, 0.3, 0],
                ],
                0.1,
            );
        }
        const lensGeometry = geometry(new THREE.BufferGeometry());
        lensGeometry.setAttribute(
            "position",
            new THREE.Float32BufferAttribute(lensPositions, 3),
        );
        lensGeometry.setAttribute(
            "color",
            new THREE.Float32BufferAttribute(lensColors, 3),
        );
        const lensMaterial = material(
            new THREE.LineBasicMaterial({
                vertexColors: true,
                transparent: true,
                opacity: 0.42,
                depthWrite: false,
                depthTest: false,
                blending,
            }),
        );
        lens.add(new THREE.LineSegments(lensGeometry, lensMaterial));
        const destination = new THREE.Vector3(0, 0, 0.4);
        const projected = new THREE.Vector3();
        const focusPlane = new THREE.Plane(new THREE.Vector3(0, 0, 1), 0);
        const ray = new THREE.Raycaster();
        let targets = new Map(),
            lastValues = new Map(),
            focusId = null;
        let time = 0,
            lastTime = 0,
            slot = 0,
            enabled = true,
            isPointerInside = false;
        let kick = 0,
            disposed = false;
        const pointer = new THREE.Vector2();

        function emitImpact(point, sign = 0) {
            if (!enabled) return;
            const offset = (slot++ % impactSlots) * particlesPerImpact;
            for (let i = offset; i < offset + particlesPerImpact; i++) {
                origins.set([point.x, point.y, point.z], i * 3);
                birth[i] = time;
                signs[i] = sign;
            }
            impactGeometry.attributes.position.needsUpdate = true;
            impactGeometry.attributes.aBirth.needsUpdate = true;
            impactGeometry.attributes.aSign.needsUpdate = true;
        }
        return {
            setPairs(pairs, nodes) {
                if (disposed) return;
                targets = new Map(
                    nodes.map((node) => [
                        node.userData.id,
                        node.position.clone(),
                    ]),
                );
                const changed = [];
                const nextValues = new Map();
                for (const pair of pairs) {
                    const current = { price: pair.price, pnl: pair.pnl };
                    const previous = lastValues.get(pair.id);
                    nextValues.set(pair.id, current);
                    if (
                        previous &&
                        (previous.price !== current.price ||
                            previous.pnl !== current.pnl)
                    ) {
                        const delta =
                            Number.isFinite(current.pnl) &&
                            Number.isFinite(previous.pnl)
                                ? current.pnl - previous.pnl
                                : 0;
                        changed.push({
                            id: pair.id,
                            delta,
                            magnitude: Math.abs(delta),
                        });
                    }
                }
                lastValues = nextValues;
                // At most three launches per observed snapshot; no queues or unbounded objects.
                for (const update of changed
                    .sort((a, b) => b.magnitude - a.magnitude)
                    .slice(0, 3)) {
                    const point = targets.get(update.id);
                    if (point) emitImpact(point, Math.sign(update.delta));
                }
            },
            setFocus(id) {
                focusId = id || null;
            },
            setPointer(point, inside = true) {
                pointer.copy(point);
                isPointerInside = inside;
            },
            activate() {
                if (enabled) kick = 1;
            },
            setMotion(value) {
                enabled = value;
                if (!value) {
                    kick = 0;
                    birth.fill(-100);
                    impactGeometry.attributes.aBirth.needsUpdate = true;
                }
            },
            update(nextTime, activity) {
                if (disposed) return;
                time = nextTime;
                const delta = Math.min(0.05, Math.max(0, time - lastTime));
                lastTime = time;
                const target = targets.get(focusId);
                const engaged = !!target || isPointerInside;
                if (target) destination.copy(target);
                else if (isPointerInside) {
                    ray.setFromCamera(pointer, camera);
                    if (ray.ray.intersectPlane(focusPlane, projected)) {
                        destination.copy(projected);
                        destination.x = THREE.MathUtils.clamp(
                            destination.x,
                            -5.5,
                            5.5,
                        );
                        destination.y = THREE.MathUtils.clamp(
                            destination.y,
                            -3.2,
                            3.2,
                        );
                    }
                } else destination.set(0, 0, 0.4);
                if (enabled)
                    lens.position.lerp(destination, 1 - Math.exp(-delta * 10));
                else lens.position.copy(destination);
                lens.quaternion.copy(camera.quaternion);
                if (![4, 5, 9].includes(mode))
                    lens.rotateZ(time * (mode === 2 ? -0.25 : 0.065));
                kick *= Math.exp(-delta * 4.5);
                const scale =
                    (engaged ? 0.7 : mode === 8 ? 1.8 : 1.5) * (1 + kick * 0.5);
                lens.scale.setScalar(scale);
                lensMaterial.opacity =
                    (engaged ? 0.8 : design.light ? 0.34 : 0.43) + kick * 0.1;
                uniforms.uTime.value = time;
                uniforms.uActivity.value = activity;
                uniforms.uFocus.value.copy(lens.position);
                uniforms.uEngaged.value = engaged ? 1 : 0;
            },
            dispose() {
                disposed = true;
                scene.remove(root);
                geometries.forEach((g) => g.dispose());
                materials.forEach((m) => m.dispose());
                targets.clear();
                lastValues.clear();
            },
        };
    } catch (error) {
        scene.remove(root);
        geometries.forEach((g) => g.dispose());
        materials.forEach((m) => m.dispose());
        throw error;
    }
}
