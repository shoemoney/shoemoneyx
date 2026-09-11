// Browser-local presentation events. No socket, credentials, service or scheduler.
export function createDemoEcho({ clock = globalThis, isHidden = () => globalThis.document?.hidden } = {}) {
    const channels = new Map();
    const coins = ['BTC', 'ETH', 'SOL', 'XRP', 'LINK', 'DOGE', 'ADA', 'AVAX', 'SUI', 'LTC', 'ZEC', 'BNB', 'ETC', 'BCH', 'XMR', 'XLM', 'DASH', 'EOS'];
    let timer = null, step = 0;
    function tick() {
        if (isHidden()) return;
        step++;
        const listeners = channels.get('optimizer');
        if (!listeners) return;
        const send = (event, data) => listeners.get(event)?.forEach(fn => fn(data));
        for (let index = 0; index < 3; index++) {
            const coin = coins[(step * 3 + index) % coins.length] + '-USD';
            for (const side of ['long', 'short']) {
                const cand = step * 6 + index * 2 + Number(side === 'short');
                const row = { demo: true, id: cand, coin, side, cand, batch: 'demo-session', status: 'done', at: new Date().toISOString(), trades: 80 + cand % 50, pf: 1.7, dd: 3.2, params: { tf: ['15s','1m','5m'][cand % 3], x1: 8, x2: 21, base: 90, tp: 1.5, rungs: 4, lev: 3, qty: 1, ma: 'EMA' } };
                send('.backtest.scored', { ...row, window: 'train', ret: 6 + Math.sin(cand) * 7 });
                send('.backtest.scored', { ...row, window: 'test', ret: 3 + Math.sin(cand) * 5 });
            }
        }
    }
    function stop() { if (timer !== null) clock.clearInterval(timer); timer = null; }
    return {
        channel(name) {
            if (!channels.has(name)) channels.set(name, new Map());
            if (timer === null) timer = clock.setInterval(tick, 1000);
            const channel = { listen(event, callback) { const handlers = channels.get(name); if (!handlers.has(event)) handlers.set(event, new Set()); handlers.get(event).add(callback); return channel; } };
            return channel;
        },
        leave(name) { channels.delete(name); if (!channels.size) stop(); },
        disconnect() { channels.clear(); stop(); },
    };
}
