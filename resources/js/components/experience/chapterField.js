import * as THREE from "three";

// A bounded sculptural field. These are artistic particles, never simulated trades.
export function createChapterField(host, options) {
    let renderer, canvas, scene, resize, frame = 0, elapsed = 0, previous = 0, active = options.active;
    let destroyed = false, contextLost = false, focus = 0, targetFocus = 0;
    let visible = options.visible !== false, shaderFailed = false, reported = false;
    let profile = options.profile;
    const geometries = [], materials = [], cleanups = [];
    const eye = { x: 0, y: 0 };
    const noop = { setActive() {}, setVisible() {}, setProfile() {}, setFocus() {}, dispose() {} };
    function report(value) {
        if (reported === value) return;
        reported = value;
        options.onAvailability(value);
    }
    function dispose() {
        if (destroyed) return;
        destroyed = true;
        cancelAnimationFrame(frame);
        frame = 0;
        resize?.disconnect();
        cleanups.forEach((cleanup) => cleanup());
        geometries.forEach((g) => g.dispose());
        materials.forEach((m) => m.dispose());
        scene?.clear();
        renderer?.dispose();
        renderer?.forceContextLoss();
        canvas?.remove();
    }
    function fail() {
        report(false);
        dispose();
    }
    try {
        renderer = new THREE.WebGLRenderer({ alpha: true, antialias: false, powerPreference: "low-power" });
        renderer.debug.onShaderError = () => { shaderFailed = true; };
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        renderer.setClearColor(0x000000, 0);
        canvas = renderer.domElement;
        canvas.setAttribute("aria-hidden", "true");
        host.appendChild(canvas);
        scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(44, 1, 0.1, 50);
        camera.position.z = 7.5;
        const sculpture = new THREE.Group();
        sculpture.position.y = -0.2;
        scene.add(sculpture);

        const count = window.innerWidth < 700 ? 520 : 1150;
        const position = new Float32Array(count * 3), seeds = new Float32Array(count);
        for (let index = 0; index < count; index++) {
            const a = index * 2.39996323;
            const radius = 0.95 + Math.sqrt(index / count) * 3.8;
            position[index * 3] = Math.cos(a) * radius;
            position[index * 3 + 1] = Math.sin(a) * radius * 0.75;
            position[index * 3 + 2] = Math.sin(index * 7.11) * 1.2;
            seeds[index] = (index * 0.618034) % 1;
        }
        const particleGeometry = new THREE.BufferGeometry();
        particleGeometry.setAttribute("position", new THREE.BufferAttribute(position, 3));
        particleGeometry.setAttribute("seed", new THREE.BufferAttribute(seeds, 1));
        const particleMaterial = new THREE.ShaderMaterial({
            transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
            uniforms: { time: { value: 0 }, energy: { value: 0 }, tint: { value: new THREE.Color(0x299dff) } },
            vertexShader: `attribute float seed; uniform float time; uniform float energy; varying float light;
            void main(){vec3 p=position;float wave=sin(time*.5+seed*16.);p.z+=wave*.25;
            p.xy*=1.+energy*.035;vec4 mv=modelViewMatrix*vec4(p,1.);gl_Position=projectionMatrix*mv;
            gl_PointSize=(1.4+seed*2.2+energy)*min(1.5,8./-mv.z);light=.23+seed*.45+energy*.25;}`,
            fragmentShader: `uniform vec3 tint;varying float light;void main(){float r=length(gl_PointCoord-.5);
            float alpha=(1.-smoothstep(.04,.5,r))*light;gl_FragColor=vec4(tint,alpha);}`,
        });
        const particles = new THREE.Points(particleGeometry, particleMaterial);
        sculpture.add(particles);
        geometries.push(particleGeometry); materials.push(particleMaterial);

        const orbital = new THREE.Group();
        const tracers = [];
        for (let ring = 0; ring < 5; ring++) {
            const radius = 1.65 + ring * 0.43;
            const points = [];
            for (let index = 0; index <= 160; index++) {
                const a = index / 160 * Math.PI * 2;
                points.push(new THREE.Vector3(Math.cos(a) * radius, Math.sin(a) * radius, 0));
            }
            const g = new THREE.BufferGeometry().setFromPoints(points);
            const m = new THREE.LineBasicMaterial({ color: ring % 2 ? 0x78ceff : 0x208fff, transparent: true, opacity: 0.11 + ring * 0.027 });
            const group = new THREE.Group();
            group.rotation.x = 0.7 + ring * 0.17;
            group.rotation.y = ring * 0.32;
            group.add(new THREE.Line(g, m));
            geometries.push(g); materials.push(m);
            const trailPoints = [];
            for (let index = 0; index < 24; index++) {
                const a = index / 160 * Math.PI * 2;
                trailPoints.push(new THREE.Vector3(Math.cos(a) * radius, Math.sin(a) * radius, 0));
            }
            const tg = new THREE.BufferGeometry().setFromPoints(trailPoints);
            const tm = new THREE.LineBasicMaterial({ color: 0x97ddff, transparent: true, opacity: 0.74 });
            const trail = new THREE.Line(tg, tm);
            trail.rotation.z = ring * 1.9;
            group.add(trail); tracers.push(trail);
            geometries.push(tg); materials.push(tm);
            orbital.add(group);
        }
        sculpture.add(orbital);

        // A second geometric vocabulary: a titanium-blue wire cage, viewed in perspective.
        const cageSource = new THREE.IcosahedronGeometry(2.32, 0);
        const cageGeometry = new THREE.EdgesGeometry(cageSource);
        cageSource.dispose();
        const cageMaterial = new THREE.LineBasicMaterial({ color: 0x409bea, transparent: true, opacity: 0.12 });
        const cage = new THREE.LineSegments(cageGeometry, cageMaterial);
        sculpture.add(cage);
        geometries.push(cageGeometry); materials.push(cageMaterial);

        function draw(now) {
            frame = 0;
            if (destroyed || contextLost || !visible || document.hidden) return;
            const delta = Math.max(0, Math.min((now - previous) / 1000, 0.045));
            previous = now;
            if (active && !document.hidden) {
                elapsed += delta;
                eye.x += (options.pointer.x - eye.x) * 0.04;
                eye.y += (options.pointer.y - eye.y) * 0.04;
                focus += (targetFocus - focus) * 0.05;
            }
            particleMaterial.uniforms.time.value = elapsed;
            particleMaterial.uniforms.energy.value = focus;
            particles.rotation.z = elapsed * profile.speed * 0.22;
            orbital.rotation.z = profile.tilt + elapsed * profile.speed * 0.35;
            orbital.rotation.y = Math.sin(elapsed * 0.09) * 0.18 + eye.x * 0.11;
            orbital.rotation.x = eye.y * 0.08;
            cage.rotation.set(elapsed * 0.022 + profile.tilt, elapsed * 0.035, profile.tilt * 0.3);
            cageMaterial.opacity = profile.key === "settings" || profile.key === "arena" ? 0.25 : 0.1;
            tracers.forEach((trail, index) => { trail.rotation.z = index * 1.9 + elapsed * profile.speed * (index % 2 ? -1.5 : 2); });
            camera.position.x = eye.x * 0.15;
            camera.position.y = -eye.y * 0.1;
            camera.lookAt(0, 0, 0);
            try {
                renderer.render(scene, camera);
                if (shaderFailed) { fail(); return; }
                report(true);
            } catch { fail(); return; }
            if (active && !document.hidden) frame = requestAnimationFrame(draw);
        }
        function sync() {
            cancelAnimationFrame(frame); frame = 0;
            if (destroyed || contextLost || !visible || document.hidden) return;
            previous = performance.now(); draw(previous);
        }
        function lost(event) {
            event.preventDefault(); contextLost = true;
            cancelAnimationFrame(frame); frame = 0;
            canvas.style.visibility = "hidden";
            report(false);
        }
        function restored() {
            contextLost = false; canvas.style.visibility = "";
            sync();
        }
        canvas.addEventListener("webglcontextlost", lost);
        cleanups.push(() => canvas.removeEventListener("webglcontextlost", lost));
        canvas.addEventListener("webglcontextrestored", restored);
        cleanups.push(() => canvas.removeEventListener("webglcontextrestored", restored));
        document.addEventListener("visibilitychange", sync);
        cleanups.push(() => document.removeEventListener("visibilitychange", sync));
        resize = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            if (!width || !height || destroyed) return;
            try {
                renderer.setSize(width, height);
                camera.aspect = width / height; camera.updateProjectionMatrix();
                sync();
            } catch { fail(); }
        });
        resize.observe(host);
        return {
            setActive(value) {
                active = value;
                if (!value) { eye.x = eye.y = focus = 0; }
                sync();
            },
            setVisible(value) { visible = value; sync(); },
            setProfile(value) { profile = value; sync(); },
            setFocus(value) { targetFocus = value ? 1 : 0; },
            dispose,
        };
    } catch {
        fail();
        return noop;
    }
}
