// Rasterised PNGs, not the SVGs: three.js's TextureLoader needs a decoded image with known
// dimensions up front, and cryptocurrency-icons' SVGs have no intrinsic width/height — that
// produced a 0x0 upload (texSubImage2D "bad image data") and a black chest plate.
const pngUrls = import.meta.glob('../../node_modules/cryptocurrency-icons/128/color/*.png', { eager: true, query: '?url', import: 'default' });

// Original local artwork for currencies absent from the bundled icon set.
// Asset provenance and checksums are recorded in public/coin-icons/README.md.
const localPngUrls = new Map([
    ['sui', '/coin-icons/sui.png'],
    ['hype', '/coin-icons/hype.png'],
    ['near', '/coin-icons/near.png'],
    ['ena', '/coin-icons/ena.png'],
    ['hbar', '/coin-icons/hbar.png'],
    ['ondo', '/coin-icons/ondo.png'],
]);

function tickerFor(coin) {
    return (coin || '').split('-')[0].toLowerCase();
}

export function pngUrlFor(coin) {
    const ticker = tickerFor(coin);
    if (localPngUrls.has(ticker)) return localPngUrls.get(ticker);
    const needle = `/${ticker}.png`;
    for (const path in pngUrls) {
        if (path.endsWith(needle)) return pngUrls[path];
    }
    return null;
}

/** A generated badge (ticker letters on the accent colour) for coins cryptocurrency-icons doesn't ship. */
function badgeCanvas(coin, accent = '#f59e0b') {
    const ticker = (coin || '').split('-')[0].toUpperCase().slice(0, 5);
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#18181b';
    ctx.beginPath();
    ctx.arc(64, 64, 60, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = accent;
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.arc(64, 64, 57, 0, Math.PI * 2);
    ctx.stroke();
    ctx.fillStyle = accent;
    ctx.font = `bold ${ticker.length > 3 ? 24 : 32}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(ticker, 64, 68);
    return canvas;
}

/** { type: 'url', value } for a bundled PNG, or { type: 'canvas', value: <canvas> } for the generated fallback. */
export function coinTextureSource(coin) {
    const url = pngUrlFor(coin);

    return url ? { type: 'url', value: url } : { type: 'canvas', value: badgeCanvas(coin) };
}
