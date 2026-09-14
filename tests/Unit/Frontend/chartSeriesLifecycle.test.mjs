import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import { ref, reactive, computed, watch, nextTick, effectScope } from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { cursorTarget } from '../../../resources/js/components/chart/marketLens.js';
import { deskHeaders } from '../../../resources/js/api.js';
const base = new URL('../../../', import.meta.url);
class Events {
    handlers=new Set(); subscribe(_owner,fn){this.handlers.add(fn);} unsubscribe(_owner,fn){this.handlers.delete(fn);} emit(...args){for(const fn of [...this.handlers])fn(...args);}
}
const requests=[];
const api={get(url){return new Promise((resolve,reject)=>requests.push({url,resolve,reject}));}};
let timers=new Map(),timerId=0;
globalThis.setInterval=(fn)=>{const id=++timerId;timers.set(id,fn);return id;};
globalThis.clearInterval=(id)=>timers.delete(id);
globalThis.document={hidden:false,listeners:new Set(),addEventListener(_n,fn){this.listeners.add(fn);},removeEventListener(_n,fn){this.listeners.delete(fn);},emit(){for(const fn of [...this.listeners])fn();}};
function deferredRequests(){return requests.splice(0);}
async function instance(file, initialProps, exposed) {
    const { descriptor } = parse(await fs.readFile(new URL(file, base), 'utf8'), { filename: file });
    const compiled = compileScript(descriptor, { id: file });
    let source = descriptor.scriptSetup.content;
    // Remove imports by their parsed spans, including multiline and side-effect imports.
    for (const node of compiled.scriptSetupAst.filter(node => node.type === 'ImportDeclaration').reverse()) {
        source = source.slice(0, node.start) + source.slice(node.end);
    }
    const mounted=[],unmounted=[],scope=effectScope(),props=reactive(initialProps);
    const create=new Function('ref','computed','watch','onMounted','onBeforeUnmount','onUnmounted','defineProps','useRouter','useRoute','api','cursorTarget','deskHeaders',`${source}\nreturn {${exposed}}`);
    const result=scope.run(()=>create(ref,computed,watch,(fn)=>mounted.push(fn),(fn)=>unmounted.push(fn),(fn)=>unmounted.push(fn),()=>props,()=>({replace(){}}),()=>({query:{}}),api,cursorTarget,deskHeaders));
    return {result,props,mounted,unmount(){for(const fn of unmounted)fn();scope.stop();}};
}
let widget,failConstructor=false;
class Widget {
    constructor(config){if(failConstructor)throw Error('Missing chart engine');this.config=config;this.studies=[];this.studyId=0;this.currentSymbol=config.symbol;this.currentInterval=config.interval;this.calls=[];this.pending=[];this.events={range:new Events(),data:new Events(),interval:new Events()};this.visible={from:300,to:600};widget=this;}
    onChartReady(fn){this.ready=fn;}
    chart(){return {symbol:()=>this.currentSymbol,resolution:()=>this.currentInterval,getVisibleRange:()=>this.visible,crossHairMoved:fn=>{this.crosshair=fn;},getPanes:()=>[{hasMainSeries:()=>true,getHeight:()=>400,getMainSourcePriceScale:()=>({getVisiblePriceRange:()=>({from:100,to:200}),isInverted:()=>false})}],onVisibleRangeChanged:()=>this.events.range,onDataLoaded:()=>this.events.data,onIntervalChanged:()=>this.events.interval,getAllStudies:()=>this.studies,removeEntity:(id)=>{this.studies=this.studies.filter(study=>study.id!==id);},createStudy:(name)=>{const id=++this.studyId;this.studies.push({id,name});return Promise.resolve(id);}};}
    setSymbol(symbol,interval,callback){this.calls.push({symbol,interval});this.pending.push({symbol,interval,callback});}
    finish(){const request=this.pending.shift();this.currentSymbol=request.symbol;this.currentInterval=request.interval;this.events.interval.emit(request.interval);request.callback();return request;}
    remove(){this.removed=true;}
}
globalThis.window={TradingView:{widget:Widget},Datafeeds:{UDFCompatibleDatafeed:class{getBars(...args){this.historyRequest=args;}}},fetch(){return new Promise(()=>{});}};
const chart=await instance('resources/js/pages/Chart.vue',{symbol:'BTC-USD'},'symbol,interval,range,products,error,guardian,effects');
const aims=[];chart.result.guardian.value={aim:target=>aims.push(target),leave(){}};
const mountedPromise=chart.mounted[0]();
chart.result.symbol.value='ETH-USD';chart.result.interval.value='15S';await nextTick();
assert.equal(widget.calls.length,0);widget.ready();assert.deepEqual(widget.calls,[{symbol:'ETH-USD',interval:'15S'}]);
chart.result.symbol.value='SOL-USD';chart.result.interval.value='5';await nextTick();
chart.result.symbol.value='BTC-USD';chart.result.interval.value='1';await nextTick();
assert.equal(widget.calls.length,1);widget.finish();
assert.equal(chart.result.interval.value,'1');assert.deepEqual(widget.calls[1],{symbol:'BTC-USD',interval:'1'});
widget.events.range.emit();assert.equal(chart.result.range.value,null);
widget.events.interval.emit('15S');assert.equal(chart.result.interval.value,'1');
widget.finish();assert.deepEqual(chart.result.range.value,{from:300,to:600});
widget.crosshair({time:450,price:125});assert.deepEqual(aims[0],{x:.5,y:.75,time:450,price:125,paneHeight:400});
chart.result.effects.value=false;widget.crosshair({time:460,price:140});assert.equal(aims.length,1);chart.result.effects.value=true;
assert.equal(widget.studies.length,1);await Promise.resolve();await Promise.resolve();await nextTick();
widget.events.interval.emit('15S');assert.equal(chart.result.interval.value,'1');
widget.currentInterval='5';widget.events.interval.emit('5');await nextTick();assert.equal(chart.result.interval.value,'5');assert.equal(widget.calls.length,2);
chart.result.interval.value='15S';await nextTick();assert.equal(widget.studies.length,0);assert.equal(widget.calls.length,3);widget.finish();assert.equal(widget.studies.length,0);
const feed=widget.config.datafeed;const errors=[];let barResult;
feed.getBars({},'15S',0,60,(bars)=>{barResult=bars;},(reason)=>errors.push(reason),false);
const bars=[{time:0,open:1,high:2,low:1,close:2,volume:137}];feed.historyRequest[4](bars,{});assert.equal(barResult[0].volume,137);
for(const reason of [Error('aborted study'),undefined,{errmsg:'unknown interval'}])feed.historyRequest[5](reason);
assert.deepEqual(errors,['aborted study','Market history is temporarily unavailable.','unknown interval']);
chart.result.interval.value='1';await nextTick();widget.finish();await Promise.resolve();await Promise.resolve();await nextTick();assert.equal(widget.studies.length,1);
chart.result.symbol.value='ETH-USD';await nextTick();assert.equal(widget.calls.length,5);
chart.unmount();widget.finish();assert.equal(widget.removed,true);
widget.crosshair({time:450,price:180});assert.equal(aims.length,1);
assert.ok(Object.values(widget.events).every(event=>event.handlers.size===0));
const request=deferredRequests().find(request=>request.url==='/products');request.resolve([{product_id:'LATE'}]);await mountedPromise;assert.deepEqual(chart.result.products.value,[]);
console.log('PASS: pre-ready selection, coalesced rapid symbol/timeframe changes, stale interval/range rejection, native interval synchronization, late completion and listener cleanup');
console.log('PASS: Volume removed before 15s, restored on minute views, raw bar volume retained, and UDF errors normalized to strings');
console.log('PASS: crosshair uses the main price scale and pane height; pause and unmount suppress tracking');
failConstructor=true;
const broken=await instance('resources/js/pages/Chart.vue',{symbol:'BTC-USD'},'error');broken.mounted[0]();assert.match(broken.result.error.value,/Missing chart engine/);broken.unmount();deferredRequests();
console.log('PASS: widget initialization error stays contained');

const smx=await instance('resources/js/components/SmxPanel.vue',{symbol:'BTC-USD',tf:'1m',range:null,height:170},'data,error');
smx.mounted[0]();let pending=deferredRequests();assert.equal(pending.length,1);
document.hidden=true;smx.props.symbol='ETH-USD';await nextTick();assert.equal(requests.length,0);
pending[0].resolve({t:[1],marker:'old BTC'});await Promise.resolve();await nextTick();assert.equal(smx.result.data.value,null);
document.hidden=false;document.emit();pending=deferredRequests();assert.match(pending[0].url,/ETH-USD.*1m/);
smx.props.tf='15s';await nextTick();const latest=deferredRequests()[0];assert.match(latest.url,/ETH-USD.*15s/);
latest.resolve({t:[1],marker:'new ETH 15s'});await Promise.resolve();
pending[0].resolve({t:[1],marker:'old ETH 1m'});await Promise.resolve();assert.equal(smx.result.data.value.marker,'new ETH 15s');
const poll=[...timers.values()][0];poll();poll();pending=deferredRequests();assert.equal(pending.length,1);
document.hidden=true;smx.props.symbol='SOL-USD';await nextTick();pending[0].reject(Error('old request failure'));await Promise.resolve();assert.equal(smx.result.error.value,'');assert.equal(smx.result.data.value,null);
document.hidden=false;document.emit();pending=deferredRequests();assert.match(pending[0].url,/SOL-USD/);smx.unmount();pending[0].resolve({t:[1],marker:'after unmount'});await Promise.resolve();
assert.equal(smx.result.data.value,null);assert.equal(document.listeners.size,0);assert.equal(timers.size,0);
console.log('PASS: hidden-series invalidation, immediate visibility refresh, out-of-order indicators, overlapping poll exclusion, stale failure rejection and unmount cleanup');
