// Native WebGPU contracts: each Particle and Seed is 32 bytes; Params is 64.
// Independent particles read/write their own record only. The render pass reads
// the completed compute buffer through a separate read-only binding.
const structs = `
struct Particle { position: vec4f, velocity: vec4f }
struct Seed { home: vec4f, tint: vec4f }
struct Params { viewport: vec4f, control: vec4f, pointer: vec4f, projection: vec4f }
`;
export const robotComputeWGSL = `${structs}
@group(0) @binding(0) var<storage, read_write> particles: array<Particle>;
@group(0) @binding(1) var<storage, read> seeds: array<Seed>;
@group(0) @binding(2) var<uniform> params: Params;

@compute @workgroup_size(64)
fn step(@builtin(global_invocation_id) id: vec3u) {
    let i = id.x;
    if (i >= arrayLength(&particles)) { return; }
    let seed = seeds[i];
    let s = seed.home.w;
    let r = seed.tint.w;
    let t = params.viewport.z;
    let dt = params.viewport.w;
    let mode = params.control.x;
    let activity = params.control.z;
    let pulse = params.pointer.z;
    var desired = seed.home.xyz;
    var phase = 0.0;
    if (mode < 0.5) {
        let progress = smoothstep(0.08, 0.93, params.control.y);
        let angle = s * 6.283185 + t * (0.4 + r * 0.4);
        let scattered = vec3f(cos(angle) * (0.4 + r * 1.1), sin(angle) * (0.35 + r * 1.05), r - 0.5);
        desired = mix(scattered, seed.home.xyz, progress);
        desired.z += sin(s * 37.0 + t * 3.0) * (1.0 - progress) * 0.15;
    } else if (mode < 1.5) {
        phase = fract(s + t * (0.145 + activity * 0.055));
        let side = select(-1.0, 1.0, r > 0.5);
        let thread = fract(r * 23.0);
        let twist = phase * 18.0 + t * 1.6 + thread * 6.283185;
        let curl = 0.025 + sin(phase * 3.14159) * 0.075;
        desired = vec3f(
            side * (0.51 + sin(phase * 3.14159) * (0.35 + thread * 0.12)) + cos(twist) * curl,
            0.015 + phase * 1.15 + sin(twist) * curl * 0.8,
            sin(twist) * 0.25
        );
        desired.x += side * pulse * 0.06 * sin(phase * 3.14159);
    } else {
        let angle = s * 6.283185 + t * (0.24 + r * 0.17);
        let radius = 0.71 + fract(r * 13.0) * 0.29;
        desired = vec3f(cos(angle) * radius, sin(angle) * (0.81 + r * 0.23) + 0.08, sin(angle * 2.0 + t * 0.3) * 0.25);
        desired = vec3f(desired.xy + vec2f(sin(angle * 4.0 + t), cos(angle * 3.0 - t)) * (0.03 + pulse * 0.035), desired.z);
        if (r > 0.77) {
            phase = fract(s + t * (0.2 + activity * 0.04));
            let streamTarget = vec3f((params.pointer.x * 2.0 - 1.0) / params.projection.x, (1.0 - params.pointer.y * 2.0) / params.projection.y, 0.15);
            let bend = vec3f(sin(s * 6.283185) * 0.14, 0.84 + cos(s * 6.283185) * 0.08, 0.15);
            let q = phase;
            desired = (1.0 - q) * (1.0 - q) * desired + 2.0 * (1.0 - q) * q * bend + q * q * streamTarget;
        }
    }
    var particle = particles[i];
    // A static first frame is settled once, without an invisible animation loop.
    if (params.projection.w > 0.5 || (mode > 0.5 && phase < particle.position.w)) {
        particle.position = vec4f(desired, phase);
        particle.velocity = vec4f(0.0);
    }
    let stiffness = select(75.0, 120.0, mode < 0.5);
    let acceleration = (desired - particle.position.xyz) * stiffness;
    let velocity = (particle.velocity.xyz + acceleration * dt) * exp(-dt * 13.0);
    particle.position = vec4f(particle.position.xyz + velocity * dt, phase);
    particle.velocity = vec4f(velocity, 0.0);
    if (mode < 0.5 && params.control.y >= 0.995) {
        particle.position = vec4f(seed.home.xyz, 0.0);
    }
    particles[i] = particle;
}
`;

export const robotRenderWGSL = `${structs}
@group(0) @binding(0) var<storage, read> particles: array<Particle>;
@group(0) @binding(1) var<storage, read> seeds: array<Seed>;
@group(0) @binding(2) var<uniform> params: Params;
struct VertexOutput {
    @builtin(position) position: vec4f,
    @location(0) uv: vec2f,
    @location(1) tint: vec3f,
    @location(2) opacity: f32,
}
@vertex fn vertexMain(@builtin(vertex_index) vertex: u32, @builtin(instance_index) instance: u32) -> VertexOutput {
    let corners = array<vec2f, 6>(vec2f(-1,-1), vec2f(1,-1), vec2f(-1,1), vec2f(-1,1), vec2f(1,-1), vec2f(1,1));
    let q = corners[vertex];
    let particle = particles[instance];
    let seed = seeds[instance];
    let mode = params.control.x;
    let phase = particle.position.w;
    let size = select(select(1.5 + seed.tint.w, 2.6 + seed.tint.w * 1.2, mode < 1.5), 1.1 + seed.tint.w * 0.65, mode < 0.5) * params.projection.z;
    let direction = normalize(particle.velocity.xy + vec2f(0.0001, 0.0002));
    let perpendicular = vec2f(-direction.y, direction.x);
    let stretch = select(1.0, 2.6, mode > 0.5 && mode < 1.5);
    let offset = (perpendicular * q.x + direction * q.y * stretch) * size * 2.0 / params.viewport.xy;
    var opacity = 0.44;
    var tint = seed.tint.rgb;
    if (mode > 0.5 && mode < 1.5) {
        opacity = (0.085 + params.control.z * 0.055) * sin(phase * 3.14159);
        tint = mix(vec3f(0.08, 0.3, 1.0), vec3f(0.35, 0.88, 1.0), seed.tint.w);
    } else if (mode > 1.5) {
        let streamOpacity = (0.13 + 0.16 * sin(phase * 3.14159)) * (1.0 - smoothstep(0.88, 1.0, phase));
        opacity = select(0.17, streamOpacity, seed.tint.w > 0.77);
        tint = mix(vec3f(0.1, 0.42, 1.0), vec3f(0.6, 0.92, 1.0), seed.tint.w);
    } else {
        opacity *= params.control.w;
    }
    var out: VertexOutput;
    out.position = vec4f(particle.position.xy * params.projection.xy + offset, 0.0, 1.0);
    out.uv = q;
    out.tint = tint;
    out.opacity = opacity;
    return out;
}
@fragment fn fragmentMain(input: VertexOutput) -> @location(0) vec4f {
    let d = length(input.uv);
    let alpha = pow(max(0.0, 1.0 - d), 1.5) * input.opacity;
    return vec4f(input.tint * alpha, alpha);
}
`;

// Same visual worlds in the portable WebGL fallback. Motion is evaluated by a
// vertex shader here; the native WebGPU path above maintains simulated velocity.
export const robotVertexGLSL = `
    uniform vec4 uViewport, uControl, uPointer, uProjection;
    attribute vec4 aHome, aTint;
    varying vec2 vUv;
    varying vec3 vTint;
    varying float vOpacity;
    void main() {
        float s = aHome.w, r = aTint.w, t = uViewport.z, mode = uControl.x;
        vec3 p = aHome.xyz;
        float phase = 0.0;
        if (mode < 0.5) {
            float progress = smoothstep(0.08, 0.93, uControl.y);
            float angle = s * 6.283185 + t * (0.4 + r * 0.4);
            vec3 scattered = vec3(cos(angle) * (0.4 + r * 1.1), sin(angle) * (0.35 + r * 1.05), r - 0.5);
            p = mix(scattered, aHome.xyz, progress);
        } else if (mode < 1.5) {
            phase = fract(s + t * (0.145 + uControl.z * 0.055));
            float side = r > 0.5 ? 1.0 : -1.0;
            float thread = fract(r * 23.0);
            float twist = phase * 18.0 + t * 1.6 + thread * 6.283185;
            float curl = 0.025 + sin(phase * 3.14159) * 0.075;
            p = vec3(side * (0.51 + sin(phase * 3.14159) * (0.35 + thread * 0.12)) + cos(twist) * curl,
                0.015 + phase * 1.15 + sin(twist) * curl * 0.8, sin(twist) * 0.25);
            p.x += side * uPointer.z * 0.06 * sin(phase * 3.14159);
        } else {
            float angle = s * 6.283185 + t * (0.24 + r * 0.17);
            float radius = 0.71 + fract(r * 13.0) * 0.29;
            p = vec3(cos(angle) * radius, sin(angle) * (0.81 + r * 0.23) + 0.08, sin(angle * 2.0 + t * 0.3) * 0.25);
            p.xy += vec2(sin(angle * 4.0 + t), cos(angle * 3.0 - t)) * (0.03 + uPointer.z * 0.035);
            if (r > 0.77) {
                phase = fract(s + t * (0.2 + uControl.z * 0.04));
                vec3 streamTarget = vec3((uPointer.x * 2.0 - 1.0) / uProjection.x, (1.0 - uPointer.y * 2.0) / uProjection.y, 0.15);
                vec3 bend = vec3(sin(s * 6.283185) * .14, .84 + cos(s * 6.283185) * .08, .15);
                p = pow(1.0-phase, 2.0) * p + 2.0 * (1.0-phase) * phase * bend + phase * phase * streamTarget;
            }
        }
        float size = (mode < .5 ? 1.1 + r * .65 : mode < 1.5 ? 2.6 + r * 1.2 : 1.5 + r) * uProjection.z;
        vec2 offset = position.xy * size * 2.0 / uViewport.xy;
        if (mode > .5 && mode < 1.5) offset.y *= 2.6;
        gl_Position = vec4(p.xy * uProjection.xy + offset, 0.0, 1.0);
        vUv = position.xy;
        vOpacity = .44 * uControl.w;
        vTint = aTint.rgb;
        if (mode > .5 && mode < 1.5) {
            vOpacity = (.085 + uControl.z * .055) * sin(phase * 3.14159);
            vTint = mix(vec3(.08,.3,1.), vec3(.35,.88,1.), r);
        } else if (mode > 1.5) {
            float streamOpacity = (.13 + .16 * sin(phase * 3.14159)) * (1.0 - smoothstep(.88, 1.0, phase));
            vOpacity = r > .77 ? streamOpacity : .17;
            vTint = mix(vec3(.1,.42,1.), vec3(.6,.92,1.), r);
        }
    }
`;
export const robotFragmentGLSL = `
    varying vec2 vUv;
    varying vec3 vTint;
    varying float vOpacity;
    void main() {
        float alpha = pow(max(0.0, 1.0 - length(vUv)), 1.5) * vOpacity;
        gl_FragColor = vec4(vTint, alpha);
    }
`;
