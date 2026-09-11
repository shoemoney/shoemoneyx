import {
    robotComputeWGSL,
    robotRenderWGSL,
    robotVertexGLSL,
    robotFragmentGLSL,
} from "./robotParticleShaders";

const imageAspect = 1397 / 1126;
const effectModes = { nanites: 0, plasma: 1, swarm: 2 };
const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
const abortError = () =>
    new DOMException("Robot field was disposed", "AbortError");

// Sample the same armor artwork used by the portrait and its animation layers.
async function sampleRobot(count, signal, effect) {
    const image = new Image();
    image.src =
        effect === "nanites"
            ? "/brand/shoegpt-robot-typing-wide.webp"
            : "/brand/shoegpt-robot-armor.webp";
    await image.decode();
    if (signal.aborted) throw abortError();
    const canvas = document.createElement("canvas");
    canvas.width = 320;
    canvas.height = Math.round(320 / imageAspect);
    const context = canvas.getContext("2d", { willReadFrequently: true });
    if (!context) throw new Error("Robot silhouette sampling is unavailable");
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    const occupied = [];
    for (let i = 0; i < pixels.length; i += 4)
        if (pixels[i + 3] > 90) occupied.push(i / 4);
    if (!occupied.length)
        throw new Error("Robot artwork contains no visible samples");
    let seed = 6719;
    const random = () =>
        ((seed = (seed * 16807) % 2147483647) - 1) / 2147483646;
    const homes = new Float32Array(count * 4);
    const tints = new Float32Array(count * 4);
    const packed = new Float32Array(count * 8);
    const initial = new Float32Array(count * 8);
    for (let i = 0; i < count; i++) {
        const pixel = occupied[Math.floor(random() * occupied.length)];
        const x = ((pixel % canvas.width) + random()) / canvas.width;
        const y = (Math.floor(pixel / canvas.width) + random()) / canvas.height;
        const luminance =
            (pixels[pixel * 4] +
                pixels[pixel * 4 + 1] +
                pixels[pixel * 4 + 2]) /
            765;
        const phase = random(),
            variation = random();
        homes.set(
            [x * 2 - 1, 1 - y * 2, (luminance - 0.5) * 0.12, phase],
            i * 4,
        );
        tints.set(
            [0.18 + luminance * 0.46, 0.49 + luminance * 0.38, 1, variation],
            i * 4,
        );
        packed.set(homes.subarray(i * 4, i * 4 + 4), i * 8);
        packed.set(tints.subarray(i * 4, i * 4 + 4), i * 8 + 4);
        initial.set(
            [
                Math.cos(phase * Math.PI * 2) * (1 + variation),
                Math.sin(phase * Math.PI * 2) * (0.8 + variation),
                variation - 0.5,
                0,
            ],
            i * 8,
        );
    }
    canvas.width = canvas.height = 1;
    return { count, homes, tints, packed, initial };
}

async function createWebGPU(canvas, samples, failure, signal) {
    let device,
        context,
        disposed = false,
        ready = false,
        lost = false;
    const buffers = [];
    const fail = (error) => {
        if (disposed) return;
        lost = true;
        if (ready) failure(error);
    };
    const uncaptured = (event) => fail(event.error);
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        device?.removeEventListener("uncapturederror", uncaptured);
        buffers.forEach((buffer) => buffer.destroy());
        context?.unconfigure();
        device?.destroy();
        canvas.remove();
    };
    try {
        const adapter = await navigator.gpu.requestAdapter({
            powerPreference: "high-performance",
        });
        if (!adapter || signal.aborted)
            throw signal.aborted
                ? abortError()
                : new Error("No WebGPU adapter");
        device = await adapter.requestDevice();
        if (signal.aborted) throw abortError();
        device.addEventListener("uncapturederror", uncaptured);
        void device.lost.then((info) => {
            if (!disposed && info.reason !== "destroyed")
                fail(new Error(`Robot GPU device lost: ${info.message}`));
        });
        context = canvas.getContext("webgpu");
        if (!context) throw new Error("WebGPU canvas is unavailable");
        const format = navigator.gpu.getPreferredCanvasFormat();
        context.configure({ device, format, alphaMode: "premultiplied" });
        const module = async (code, label) => {
            const shader = device.createShaderModule({ code, label });
            const messages = (
                await shader.getCompilationInfo()
            ).messages.filter((message) => message.type === "error");
            if (messages.length)
                throw new Error(
                    messages
                        .map(
                            (message) =>
                                `${label}:${message.lineNum}:${message.linePos} ${message.message}`,
                        )
                        .join("\n"),
                );
            return shader;
        };
        const [computeModule, renderModule] = await Promise.all([
            module(robotComputeWGSL, "Robot particle compute"),
            module(robotRenderWGSL, "Robot particle display"),
        ]);
        if (signal.aborted || lost)
            throw signal.aborted
                ? abortError()
                : new Error("GPU lost during initialization");
        const [computePipeline, renderPipeline] = await Promise.all([
            device.createComputePipelineAsync({
                label: "Robot independent particle simulation",
                layout: "auto",
                compute: { module: computeModule, entryPoint: "step" },
            }),
            device.createRenderPipelineAsync({
                label: "Robot instanced luminous particles",
                layout: "auto",
                vertex: { module: renderModule, entryPoint: "vertexMain" },
                fragment: {
                    module: renderModule,
                    entryPoint: "fragmentMain",
                    targets: [
                        {
                            format,
                            blend: {
                                color: {
                                    srcFactor: "one",
                                    dstFactor: "one-minus-src-alpha",
                                    operation: "add",
                                },
                                alpha: {
                                    srcFactor: "one",
                                    dstFactor: "one-minus-src-alpha",
                                    operation: "add",
                                },
                            },
                        },
                    ],
                },
                primitive: { topology: "triangle-list" },
            }),
        ]);
        if (signal.aborted || lost)
            throw signal.aborted
                ? abortError()
                : new Error("GPU lost during initialization");
        device.pushErrorScope("validation");
        device.pushErrorScope("out-of-memory");
        const makeBuffer = (label, data, usage) => {
            const buffer = device.createBuffer({
                label,
                size: data.byteLength,
                usage: usage | GPUBufferUsage.COPY_DST,
            });
            buffers.push(buffer);
            device.queue.writeBuffer(buffer, 0, data);
            return buffer;
        };
        const state = makeBuffer(
            "Robot state / 32 byte particle",
            samples.initial,
            GPUBufferUsage.STORAGE,
        );
        const seeds = makeBuffer(
            "Robot seed / 32 byte sample",
            samples.packed,
            GPUBufferUsage.STORAGE,
        );
        const params = makeBuffer(
            "Robot uniforms / four vec4 / 64 bytes",
            new Float32Array(16),
            GPUBufferUsage.UNIFORM,
        );
        const entries = [
            { binding: 0, resource: { buffer: state } },
            { binding: 1, resource: { buffer: seeds } },
            { binding: 2, resource: { buffer: params } },
        ];
        const computeBindings = device.createBindGroup({
            label: "Robot compute state",
            layout: computePipeline.getBindGroupLayout(0),
            entries,
        });
        const renderBindings = device.createBindGroup({
            label: "Robot read-only render state",
            layout: renderPipeline.getBindGroupLayout(0),
            entries,
        });
        const allocationError = await device.popErrorScope();
        const validationError = await device.popErrorScope();
        if (allocationError || validationError)
            throw new Error((allocationError || validationError).message);
        if (signal.aborted || lost)
            throw signal.aborted
                ? abortError()
                : new Error("GPU lost during initialization");
        ready = true;
        return {
            name: "WebGPU compute",
            maxSize: device.limits.maxTextureDimension2D,
            reset() {
                if (!disposed && !lost)
                    device.queue.writeBuffer(state, 0, samples.initial);
            },
            resize(width, height) {
                if (canvas.width !== width) canvas.width = width;
                if (canvas.height !== height) canvas.height = height;
            },
            render(values, steps) {
                if (disposed || lost) return;
                device.queue.writeBuffer(params, 0, values);
                const encoder = device.createCommandEncoder({
                    label: "Robot simulation and presentation",
                });
                // Each fixed substep is a pass boundary; no cross-particle reads.
                for (let step = 0; step < steps; step++) {
                    const pass = encoder.beginComputePass({
                        label: "Robot motion step",
                    });
                    pass.setPipeline(computePipeline);
                    pass.setBindGroup(0, computeBindings);
                    pass.dispatchWorkgroups(Math.ceil(samples.count / 64));
                    pass.end();
                }
                const pass = encoder.beginRenderPass({
                    colorAttachments: [
                        {
                            view: context.getCurrentTexture().createView(),
                            clearValue: { r: 0, g: 0, b: 0, a: 0 },
                            loadOp: "clear",
                            storeOp: "store",
                        },
                    ],
                });
                pass.setPipeline(renderPipeline);
                pass.setBindGroup(0, renderBindings);
                pass.draw(6, samples.count);
                pass.end();
                device.queue.submit([encoder.finish()]);
            },
            dispose,
        };
    } catch (error) {
        dispose();
        throw error;
    }
}

async function createWebGL(canvas, samples, failure, signal) {
    const THREE = await import("three");
    if (signal.aborted) throw abortError();
    let renderer,
        geometry,
        material,
        disposed = false,
        lost = false;
    const contextLost = (event) => {
        event.preventDefault();
        if (!disposed) {
            lost = true;
            failure(new Error("Robot WebGL context lost"));
        }
    };
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        canvas.removeEventListener("webglcontextlost", contextLost);
        geometry?.dispose();
        material?.dispose();
        renderer?.dispose();
        renderer?.forceContextLoss();
        canvas.remove();
    };
    try {
        renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: false,
            powerPreference: "high-performance",
        });
        renderer.setClearColor(0, 0);
        renderer.setPixelRatio(1);
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        const scene = new THREE.Scene();
        const camera = new THREE.Camera();
        geometry = new THREE.InstancedBufferGeometry();
        geometry.setAttribute(
            "position",
            new THREE.Float32BufferAttribute(
                [-1, -1, 0, 1, -1, 0, -1, 1, 0, -1, 1, 0, 1, -1, 0, 1, 1, 0],
                3,
            ),
        );
        geometry.setAttribute(
            "aHome",
            new THREE.InstancedBufferAttribute(samples.homes, 4),
        );
        geometry.setAttribute(
            "aTint",
            new THREE.InstancedBufferAttribute(samples.tints, 4),
        );
        geometry.instanceCount = samples.count;
        const uniforms = {
            uViewport: { value: new THREE.Vector4() },
            uControl: { value: new THREE.Vector4() },
            uPointer: { value: new THREE.Vector4() },
            uProjection: { value: new THREE.Vector4() },
        };
        material = new THREE.ShaderMaterial({
            uniforms,
            vertexShader: robotVertexGLSL,
            fragmentShader: robotFragmentGLSL,
            transparent: true,
            depthTest: false,
            depthWrite: false,
            blending: THREE.NormalBlending,
        });
        const field = new THREE.Mesh(geometry, material);
        field.frustumCulled = false;
        scene.add(field);
        let shaderError;
        renderer.debug.onShaderError = () => {
            shaderError = new Error("Robot WebGL shader did not compile");
        };
        renderer.compile(scene, camera);
        if (shaderError) throw shaderError;
        canvas.addEventListener("webglcontextlost", contextLost);
        return {
            name: "WebGL",
            maxSize: renderer.capabilities.maxTextureSize,
            reset() {},
            resize(width, height) {
                renderer.setSize(width, height, false);
            },
            render(values) {
                if (disposed || lost) return;
                uniforms.uViewport.value.fromArray(values, 0);
                uniforms.uControl.value.fromArray(values, 4);
                uniforms.uPointer.value.fromArray(values, 8);
                uniforms.uProjection.value.fromArray(values, 12);
                renderer.render(scene, camera);
                if (shaderError) {
                    lost = true;
                    failure(shaderError);
                }
            },
            dispose,
        };
    } catch (error) {
        dispose();
        throw error;
    }
}

export async function createRobotParticleScene(host, options) {
    const signal = options.signal;
    const mode = effectModes[options.effect] ?? 0;
    const forced =
        new URLSearchParams(location.search).get("robotRenderer") ||
        new URLSearchParams(location.search).get("renderer");
    let engine, canvas, observer, intersection;
    let disposed = false,
        enabled = options.motion !== false,
        visible = true,
        hidden = document.hidden;
    let frame = 0,
        lastFrame = 0,
        elapsed = 0,
        accumulator = 0,
        pulseEnergy = 0,
        assembled = true,
        entranceStarted = false,
        needsSettle = true,
        switching = false;
    let rate = options.rate || 0,
        previousPulse = options.pulse,
        target = options.target || { x: 0.5, y: 0.17 };
    let width = 0,
        height = 0,
        dpr = 1,
        scaleX = 1,
        scaleY = 1,
        contentScaleX = 1,
        contentScaleY = 1;
    const values = new Float32Array(16);
    const finishAssembly = (value) => {
        if (assembled === value && value !== true) return;
        assembled = value;
        options.onAssembled?.(value);
    };
    const stop = () => {
        cancelAnimationFrame(frame);
        frame = 0;
        lastFrame = 0;
        accumulator = 0;
    };
    const createCanvas = () => {
        const next = document.createElement("canvas");
        next.setAttribute("aria-hidden", "true");
        Object.assign(next.style, {
            width: "100%",
            height: "100%",
            display: "block",
            pointerEvents: "none",
        });
        return next;
    };
    const css = () => {
        stop();
        engine?.dispose();
        engine = null;
        canvas?.remove();
        finishAssembly(true);
        options.onRenderer?.("CSS");
    };
    let samples;
    async function fallback(error) {
        if (disposed || switching) return;
        switching = true;
        stop();
        const previousBackend = engine?.name;
        engine?.dispose();
        engine = null;
        canvas?.remove();
        options.onDiagnostic?.(String(error?.message || error));
        if (previousBackend === "WebGL" || !samples || signal.aborted) {
            css();
            switching = false;
            return;
        }
        try {
            canvas = createCanvas();
            engine = await createWebGL(canvas, samples, fallback, signal);
            if (disposed || signal.aborted) {
                engine.dispose();
                return;
            }
            host.appendChild(canvas);
            width = height = 0;
            needsSettle = true;
            options.onRenderer?.(engine.name);
            // A failed entrance reveals the source art rather than replaying surprise motion.
            if (mode === 0) {
                entranceStarted = true;
                elapsed = 4.4;
                finishAssembly(true);
            }
            resize();
        } catch (nextError) {
            options.onDiagnostic?.(String(nextError?.message || nextError));
            css();
        } finally {
            switching = false;
            draw(0);
            resume();
        }
    }
    function resize() {
        if (disposed || !engine) return;
        const box = host.getBoundingClientRect();
        dpr = Math.min(window.devicePixelRatio || 1, 1.5);
        const nextWidth = clamp(Math.round(box.width * dpr), 1, engine.maxSize);
        const nextHeight = clamp(
            Math.round(box.height * dpr),
            1,
            engine.maxSize,
        );
        if (nextWidth !== width || nextHeight !== height) {
            width = nextWidth;
            height = nextHeight;
            engine.resize(width, height);
        }
        // The particle canvas overflows the image box; project against the
        // original box so shoulders and silhouette retain their exact alignment.
        const content = host.parentElement?.getBoundingClientRect() || box;
        const contentWidth = Math.max(1, content.width);
        const contentHeight = Math.max(1, content.height);
        const ratio = contentWidth / contentHeight;
        contentScaleX = contentWidth / Math.max(1, box.width);
        contentScaleY = contentHeight / Math.max(1, box.height);
        scaleX = Math.min(1, imageAspect / ratio) * contentScaleX;
        scaleY = Math.min(1, ratio / imageAspect) * contentScaleY;
        draw(0);
    }
    function draw(steps) {
        if (!engine || disposed || hidden || !visible || switching) return;
        const settle = needsSettle || !enabled;
        const progress = clamp(elapsed / 2.8, 0, 1);
        const fade = mode === 0 ? 1 - clamp((elapsed - 3) / 1.15, 0, 1) : 1;
        values.set([
            width,
            height,
            elapsed,
            1 / 120,
            mode,
            progress,
            clamp(rate / 35, 0, 1),
            fade,
            (clamp(Number.isFinite(target?.x) ? target.x : 0.5, 0, 1) - 0.5) *
                contentScaleX +
                0.5,
            (clamp(Number.isFinite(target?.y) ? target.y : 0.17, 0, 1) - 0.5) *
                contentScaleY +
                0.5,
            pulseEnergy,
            0,
            scaleX,
            scaleY,
            dpr,
            settle ? 1 : 0,
        ]);
        try {
            engine.render(values, settle ? 1 : steps);
            needsSettle = false;
        } catch (error) {
            void fallback(error);
        }
    }
    function tick(stamp) {
        frame = 0;
        if (disposed || !enabled || !visible || hidden || !engine || switching)
            return;
        const delta = lastFrame
            ? Math.min((stamp - lastFrame) / 1000, 0.05)
            : 1 / 60;
        lastFrame = stamp;
        elapsed += delta;
        accumulator += delta;
        const steps = Math.min(6, Math.floor(accumulator / (1 / 120)));
        accumulator -= steps / 120;
        pulseEnergy *= Math.exp(-delta * 2.4);
        draw(steps);
        if (mode === 0 && elapsed >= 2.8 && !assembled) finishAssembly(true);
        if (mode !== 0 || elapsed < 4.2) resume();
    }
    function resume() {
        if (
            !disposed &&
            !switching &&
            enabled &&
            visible &&
            !hidden &&
            engine &&
            !frame &&
            (mode !== 0 || elapsed < 4.2)
        )
            frame = requestAnimationFrame(tick);
    }
    function startEntrance() {
        entranceStarted = true;
        elapsed = 0;
        needsSettle = true;
        engine.reset();
        finishAssembly(false);
    }
    function setMotion(value) {
        enabled = !!value;
        if (!enabled) {
            stop();
            if (mode === 0) {
                elapsed = 4.4;
                finishAssembly(true);
            }
            draw(0);
        } else {
            if (mode === 0 && engine && !entranceStarted) startEntrance();
            resume();
        }
    }
    const visibility = () => {
        hidden = document.hidden;
        if (hidden) stop();
        else {
            draw(0);
            resume();
        }
    };
    function dispose() {
        if (disposed) return;
        disposed = true;
        stop();
        observer?.disconnect();
        intersection?.disconnect();
        document.removeEventListener("visibilitychange", visibility);
        signal.removeEventListener("abort", dispose);
        engine?.dispose();
        canvas?.remove();
    }
    signal.addEventListener("abort", dispose, { once: true });
    const control = {
        setMotion,
        setInputs(next) {
            rate = Number.isFinite(next.rate) ? Math.max(0, next.rate) : 0;
            target = next.target || target;
            if (next.pulse !== previousPulse) {
                previousPulse = next.pulse;
                if (enabled) pulseEnergy = 1;
            }
            if (!enabled) draw(0);
        },
        replay() {
            if (disposed || !engine || !enabled) {
                finishAssembly(true);
                return;
            }
            stop();
            elapsed = 0;
            engine.reset();
            pulseEnergy = 1;
            needsSettle = true;
            if (mode === 0) {
                entranceStarted = true;
                finishAssembly(false);
            }
            resume();
        },
        dispose,
    };
    if (forced === "2d") {
        css();
        return control;
    }
    try {
        const mobile = window.innerWidth < 760;
        const count =
            mode === 0
                ? mobile
                    ? 14000
                    : 26000
                : mode === 1
                  ? mobile
                      ? 7000
                      : 14000
                  : mobile
                    ? 10000
                    : 20000;
        samples = await sampleRobot(count, signal, options.effect);
        if (disposed || signal.aborted) throw abortError();
        if (navigator.gpu && window.isSecureContext && forced !== "webgl") {
            try {
                canvas = createCanvas();
                engine = await createWebGPU(canvas, samples, fallback, signal);
            } catch (error) {
                options.onDiagnostic?.(String(error?.message || error));
            }
        }
        if (disposed || signal.aborted) throw abortError();
        if (!engine) {
            canvas = createCanvas();
            engine = await createWebGL(canvas, samples, fallback, signal);
        }
        if (disposed || signal.aborted) {
            engine.dispose();
            throw abortError();
        }
        host.appendChild(canvas);
        options.onRenderer?.(engine.name);
        observer = new ResizeObserver(resize);
        observer.observe(host);
        intersection = new IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting;
            if (visible) {
                draw(0);
                resume();
            } else stop();
        });
        intersection.observe(host);
        document.addEventListener("visibilitychange", visibility);
        if (mode === 0 && enabled) startEntrance();
        else if (mode === 0) elapsed = 4.4;
        resize();
        resume();
        return control;
    } catch (error) {
        if (disposed || signal.aborted) {
            dispose();
            throw abortError();
        }
        options.onDiagnostic?.(String(error?.message || error));
        css();
        return control;
    }
}
