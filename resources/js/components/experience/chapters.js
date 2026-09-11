// Editorial identities, not operational status or generated financial measurements.
const chapters = {
    command: { key: "command", chapter: "01 / COMMAND", description: "Your capital. Your positions. One living view of the entire desk.", signature: "Every signal, connected.", icon: "fa-wave-pulse", objects: ["BTC", "ETH", "SOL"], tilt: 0.28, speed: 0.10 },
    positions: { key: "positions", chapter: "02 / EXPOSURE", description: "Follow every position from first fill to final exit. See where your capital is working.", signature: "Know your exposure.", icon: "fa-layer-group", objects: ["BTC", "ETH", "SOL"], tilt: -0.28, speed: 0.08 },
    desk: { key: "desk", chapter: "03 / EXECUTION", description: "Trace the decision. Follow the evidence. See how each cycle becomes a trade.", signature: "Inside the decision.", icon: "fa-brain-circuit", objects: ["fa-crosshairs", "fa-circle-nodes", "fa-bolt"], tilt: 0.65, speed: 0.12 },
    chart: { key: "chart", chapter: "04 / MARKET", description: "Explore market structure, study price action, and bring every move into focus.", signature: "Read the field.", icon: "fa-chart-candlestick", objects: ["BTC", "ETH", "fa-chart-candlestick"], tilt: -0.12, speed: 0.065 },
    backtests: { key: "backtests", chapter: "05 / RESEARCH", description: "Turn a trading hypothesis into evidence. Replay the market and inspect what happened.", signature: "Question. Test. Learn.", icon: "fa-clock-rotate-left", objects: ["fa-clock-rotate-left", "fa-chart-scatter", "fa-crosshairs"], tilt: 0.45, speed: -0.09 },
    arena: { key: "arena", chapter: "06 / ARENA", description: "Put strategies side by side. Compare the contenders and examine the evidence behind every result.", signature: "Let the evidence compete.", icon: "fa-swords", objects: ["fa-swords", "fa-shield-halved", "fa-trophy"], tilt: -0.6, speed: 0.14 },
    optimizer: { key: "optimizer", chapter: "07 / EVOLUTION", description: "Follow research as it evolves. Explore candidate parameters, champions, and each promotion.", signature: "An edge is earned.", icon: "fa-microchip-ai", objects: ["fa-microchip-ai", "fa-circle-nodes", "fa-chart-scatter"], tilt: 0.9, speed: 0.11 },
    settings: { key: "settings", chapter: "08 / CONTROL", description: "Set the boundaries of your desk. Shape the strategy, execution rules, and risk controls.", signature: "Your rules. Your control.", icon: "fa-sliders", objects: ["fa-sliders", "fa-shield-halved", "fa-microchip-ai"], tilt: 0.08, speed: 0.045 },
};
export function chapterFor(title = "") {
    const key = String(title).trim().toLowerCase();
    if (key === "command center" || key === "dashboard") return chapters.command;
    return chapters[key] || chapters.command;
}
