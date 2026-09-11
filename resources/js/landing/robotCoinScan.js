const TAU = Math.PI * 2;
export const COIN_SCAN_STEP_MS = 3400;
export const COIN_SCAN_TURN_MS = 1600;
const smooth = (value) => {
    const t = Math.max(0, Math.min(1, value));
    return t * t * (3 - 2 * t);
};

// World coordinates: positive Y is up. Both renderers share this orbit.
export function coinOrbit(index, count) {
    const angle = (index / Math.max(count, 1)) * TAU;
    return {
        x: Math.cos(angle),
        y: Math.sin(angle),
        z: Math.sin(angle * 3) * 0.5,
    };
}

export function clockwiseCoins(pairs) {
    return pairs
        .map((pair, index) => {
            const orbit = coinOrbit(index, pairs.length);
            return {
                id: pair.id,
                ...orbit,
                angle: (Math.atan2(orbit.x, orbit.y) + TAU) % TAU,
            };
        })
        .sort((a, b) => a.angle - b.angle);
}

// Decorative attention only: never changes market selection or trading state.
export function coinScanFrame(coins, elapsed) {
    if (!coins.length) return { x: 0, y: 0, id: null, light: 0 };
    const time = Math.max(0, elapsed);
    const step = Math.floor(time / COIN_SCAN_STEP_MS);
    const from = step === 0 ? { x: 0, y: 0 } : coins[(step - 1) % coins.length];
    const to = coins[step % coins.length];
    const phase = time % COIN_SCAN_STEP_MS;
    const progress = Math.min(1, phase / COIN_SCAN_TURN_MS);
    const ease = progress * progress * (3 - 2 * progress);
    return {
        x: from.x + (to.x - from.x) * ease,
        y: from.y + (to.y - from.y) * ease,
        id: progress >= 0.75 ? to.id : from.id || null,
        light:
            smooth((progress - 0.75) / 0.25) *
            (1 - smooth((phase - (COIN_SCAN_STEP_MS - 450)) / 450)),
    };
}
