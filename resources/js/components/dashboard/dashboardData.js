export function finiteNumber(value) {
    if (typeof value !== "number" && typeof value !== "string") return null;
    if (typeof value === "string" && !value.trim()) return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

export function historyPoints(rows, field = "equity") {
    return (Array.isArray(rows) ? rows : [])
        .map((row) => [Date.parse(row.taken_at), finiteNumber(row[field])])
        .filter(([time]) => Number.isFinite(time))
        .sort((a, b) => a[0] - b[0]);
}

export function windowHistory(rows, hours, now = Date.now()) {
    const cutoff = now - hours * 3_600_000;
    return (Array.isArray(rows) ? rows : []).filter((row) => {
        const time = Date.parse(row.taken_at);
        return Number.isFinite(time) && time >= cutoff && time <= now;
    });
}

export function sumPositionPnl(positions, loaded = true) {
    if (!loaded || !Array.isArray(positions)) return null;
    const values = positions.map((position) =>
        finiteNumber(position.unrealised_pnl),
    );
    if (values.some((value) => value === null)) return null;
    return values.reduce((total, value) => total + value, 0);
}

export function equityChange(rows) {
    const points = historyPoints(rows);
    if (points.length < 2 || points[0][1] === null || points.at(-1)[1] === null)
        return null;
    return points.at(-1)[1] - points[0][1];
}
