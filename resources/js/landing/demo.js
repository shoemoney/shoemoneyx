// Explicit presentation mode only. Never substituted for unavailable trading data.
const markets = [
    ["BTC", 97284.16, 2.84, 3248.6, "long"],
    ["ETH", 3241.87, 3.62, 1826.44, "long"],
    ["SOL", 184.32, 5.17, 2147.92, "long"],
    ["XRP", 2.41, -1.28, 486.18, "short"],
    ["LINK", 23.68, 4.21, 724.36, "long"],
    ["DOGE", 0.3184, 6.48, 1128.54, "long"],
    ["ADA", 0.8942, -2.14, -184.22, "long"],
    ["AVAX", 38.72, 3.76, 892.16, "long"],
    ["SUI", 4.23, 7.83, 1548.72, "long"],
    ["LTC", 112.54, -0.86, 664.12, "short"],
    ["BNB", 684.22, 1.72, 428.19, "long"],
    ["BCH", 482.16, -2.43, 384.72, "short"],
    ["XLM", 0.4321, 4.86, 728.44, "long"],
    ["ETC", 28.64, 2.18, 216.38, "long"],
    ["XMR", 218.42, -1.39, -138.67, "long"],
    ["DASH", 42.86, 3.14, 318.26, "long"],
    ["EOS", 0.8624, -3.12, 284.15, "short"],
    ["ZEC", 58.21, 5.82, 572.48, "long"],
];
export function demoSnapshot(step = 0, count = 10) {
    const pairs = markets
        .slice(0, count)
        .map(([symbol, base, change, pnl, side], i) => {
            const noise = (n) => {
                const x = Math.sin(n * 12.9898 + i * 78.233) * 43758.5453;
                return (x - Math.floor(x)) * 2 - 1;
            };
            const movement = step
                ? Array.from({ length: 9 }, (_, k) => noise(step - k)).reduce(
                      (a, b) => a + b,
                      0,
                  ) / 3
                : 0;
            const startingNotional = 3500 + i * 1380;
            const markReturn = movement * 0.0003;
            const markPnl =
                startingNotional * markReturn * (side === "short" ? -1 : 1);
            return {
                id: `${symbol}-USD`,
                price: base * (1 + movement * 0.0003),
                change: change + movement * 0.03,
                pnl: pnl + markPnl,
                realised: pnl * 0.7,
                unrealised: pnl * 0.3 + markPnl,
                open: 1,
                side,
                notional: startingNotional * (1 + markReturn),
                live: true,
                quote_at: Date.now() / 1000,
                volume: 1364000000 / (1 + i * 0.8),
                history: Array.from(
                    { length: 42 },
                    (_, j) =>
                        base *
                        (1 +
                            ((j / 41 - 1) * change) / 100 +
                            (Math.sin(j * 0.9 + i) * 0.004 +
                                Math.cos(j * 2.1) * 0.002) *
                                (1 - j / 41)),
                ),
            };
        });
    return {
        at: new Date().toISOString(),
        mode: "paper",
        strategy: "mr",
        running: true,
        halted: null,
        feed_alive: true,
        heartbeats: { SCAN: 2, VET: 3, SIZE: 2, FILLS: 4, RISK: 1 },
        pairs,
        metrics: {
            pnl: pairs.reduce((s, p) => s + p.pnl, 0),
            realised: pairs.reduce((s, p) => s + p.realised, 0),
            unrealised: pairs.reduce((s, p) => s + p.unrealised, 0),
            open: pairs.length,
            trades: 1284 + Math.floor(step / 24),
            win_rate:
                ((935 + Math.floor(Math.floor(step / 24) * 0.728)) /
                    (1284 + Math.floor(step / 24))) *
                100,
            notional: pairs.reduce((s, p) => s + p.notional, 0),
        },
        events: [],
    };
}
export function demoEvent(index, count = 10) {
    const [coin] = markets[index % Math.min(count, markets.length)];
    const agentIndex =
        (index + Math.floor(index / Math.min(count, markets.length))) % 6;
    const agent = ["SCAN", "VET", "SIZE", "FILLS", "RISK", "AI"][agentIndex];
    const messages = [
        `${coin}-USD momentum signal detected · evaluating entry`,
        `${coin}-USD spread + liquidity checks passed`,
        `${coin}-USD allocation recalculated · within risk budget`,
        `${coin}-USD paper order filled · position updated`,
        `${coin}-USD trailing stop adjusted · exposure checked`,
        `${coin}-USD challenger scored · out-of-sample validation`,
    ];
    return {
        key: `demo-${index}`,
        at: new Date(Date.now() - (20 - index) * 550).toISOString(),
        agent,
        kind: agent === "AI" ? "optimizer" : "desk",
        message: messages[agentIndex],
        coin: `${coin}-USD`,
    };
}
