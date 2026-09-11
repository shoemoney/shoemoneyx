import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
let state;
class Events {
    listeners = new Map(); style = {};
    addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name).add(fn); }
    removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
    emit(name, event = {}) { for (const fn of this.listeners.get(name) || []) fn(event); }
    setAttribute() {}
    remove() { this.removed = true; }
}
class Geometry {
    constructor() { state.resources.push(this); }
    setAttribute() { return this; }
    setFromPoints() { return this; }
    dispose() { this.disposed = (this.disposed || 0) + 1; }
}
class Material extends Geometry { constructor(options) { super(); Object.assign(this, options); } }
class Graph {
    constructor(geometry, material) { this.geometry = geometry; this.material = material; this.position = {}; this.rotation = {set() {}}; this.children = []; }
    add(node) { this.children.push(node); }
    clear() { this.children = []; }
    lookAt() {}
    updateProjectionMatrix() {}
}
class Renderer {
    constructor() { if (state.fail === 'constructor') throw Error('No adapter'); this.domElement = new Events(); this.debug = {}; state.renderer = this; }
    setPixelRatio() {}
    setClearColor() { if (state.fail === 'setup') throw Error('Setup failed'); }
    setSize() { if (state.fail === 'resize') throw Error('Resize failed'); }
    render() { if (state.fail === 'render') throw Error('Draw failed'); if (state.fail === 'shader') this.debug.onShaderError(); state.draws++; }
    dispose() { this.disposed = true; }
    forceContextLoss() { this.lost = true; }
}
globalThis.__chapterTestThree = {
    WebGLRenderer: Renderer, Scene: Graph, PerspectiveCamera: Graph, Group: Graph,
    BufferGeometry: Geometry, BufferAttribute: class {}, ShaderMaterial: Material,
    Points: Graph, Vector3: class {}, LineBasicMaterial: Material, Line: Graph,
    IcosahedronGeometry: Geometry, EdgesGeometry: Geometry, LineSegments: Graph,
    Color: class {}, AdditiveBlending: 2,
};
globalThis.requestAnimationFrame = (fn) => { const id = ++state.next; state.frames.set(id, fn); return id; };
globalThis.cancelAnimationFrame = (id) => state.frames.delete(id);
globalThis.ResizeObserver = class {
    constructor(fn) { this.fn = fn; state.resize = this; }
    observe() {}
    disconnect() { this.disconnected = true; }
    fire() { this.fn([{contentRect:{width:600,height:360}}]); }
};
globalThis.window = {innerWidth:1200,devicePixelRatio:2};
const fieldUrl = new URL('../../../resources/js/components/experience/chapterField.js', import.meta.url);
const source = (await fs.readFile(fieldUrl, 'utf8')).replace(
    /^import\s+\*\s+as\s+THREE\s+from\s+['"]three['"];?\s*$/m,
    'const THREE = globalThis.__chapterTestThree;',
);
const {createChapterField} = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
function setup(fail = '', active = false) {
    state = {fail,resources:[],draws:0,frames:new Map(),next:0,reported:[]};
    globalThis.document = new Events(); document.hidden = false;
    const host = {appendChild(node) { state.canvas = node; }};
    return createChapterField(host, {active,visible:true,pointer:{x:.5,y:.5},profile:{key:'settings',speed:.1,tilt:.2},onAvailability:(value)=>state.reported.push(value)});
}
function noLeaks() {
    assert.equal(state.frames.size,0);
    assert.equal(state.renderer?.disposed ?? true,true);
    assert.equal(state.canvas?.removed ?? true,true);
    assert.equal(state.resize?.disconnected ?? true,true);
    assert.ok(state.resources.every(resource=>resource.disposed === 1));
    assert.ok([...document.listeners.values()].every(set=>set.size===0));
    assert.ok([...(state.canvas?.listeners.values() || [])].every(set=>set.size===0));
}
let engine = setup();
state.resize.fire(); assert.equal(state.draws,1); assert.equal(state.frames.size,0); assert.deepEqual(state.reported,[true]);
engine.setActive(true); assert.equal(state.frames.size,1);
engine.setVisible(false); const offscreenDraws=state.draws; assert.equal(state.frames.size,0);
state.resize.fire(); assert.equal(state.draws,offscreenDraws);
engine.setVisible(true); assert.equal(state.frames.size,1);
document.hidden=true; document.emit('visibilitychange'); assert.equal(state.frames.size,0);
document.hidden=false; document.emit('visibilitychange'); assert.equal(state.frames.size,1);
let prevented=false; state.canvas.emit('webglcontextlost',{preventDefault(){prevented=true;}});
assert.equal(prevented,true); assert.equal(state.frames.size,0); assert.equal(state.canvas.style.visibility,'hidden');
state.canvas.emit('webglcontextrestored'); assert.equal(state.frames.size,1); assert.deepEqual(state.reported,[true,false,true]);
engine.setActive(false); assert.equal(state.frames.size,0);
engine.dispose(); engine.dispose(); noLeaks();
console.log('PASS: static/reduced-motion draw, resume, offscreen resize, tab visibility, context loss/restore, idempotent cleanup');
for (const failure of ['constructor','setup','resize','shader','render']) {
    engine=setup(failure,true);
    state.resize?.fire();
    engine.dispose(); noLeaks();
    console.log(`PASS: ${failure} failure falls back without leaked listeners, canvas, frame or resources`);
}
engine=setup('',true); state.resize.fire(); state.fail='render';
const callback=state.frames.values().next().value; state.frames.clear(); callback(performance.now());
assert.deepEqual(state.reported,[true,false]); noLeaks();
console.log('PASS: later draw failure transitions from WebGL to CSS and releases everything');
