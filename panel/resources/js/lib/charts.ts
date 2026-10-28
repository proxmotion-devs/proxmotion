/**
 * Helpers shared by the SVG charts of the server page: the live sparklines
 * (docs/adr/0036) and the usage history (docs/adr/0050).
 */

/**
 * An SVG path through the points' values, oldest first. The line breaks at
 * a missing value and where two points are further apart than `gapMs`
 * (Proxmox had no figures in between).
 */
export function linePath<P extends { time: number }>(
    points: P[],
    value: (point: P) => number | null | undefined,
    x: (time: number) => number,
    y: (value: number) => number,
    gapMs: number,
): string {
    let d = '';
    let previous: number | null = null;

    for (const point of points) {
        const current = value(point);

        if (current === null || current === undefined) {
            previous = null;
            continue;
        }

        const joined = previous !== null && point.time - previous <= gapMs;
        d += `${joined ? 'L' : 'M'}${x(point.time).toFixed(1)},${y(current).toFixed(1)}`;
        previous = point.time;
    }

    return d;
}

/**
 * The point nearest to `time` by `timeOf` (its time, or the middle of a
 * bar), or null without points.
 */
export function nearestPoint<P>(
    points: P[],
    time: number,
    timeOf: (point: P) => number,
): P | null {
    let nearest: P | null = null;

    for (const point of points) {
        if (
            nearest === null ||
            Math.abs(timeOf(point) - time) < Math.abs(timeOf(nearest) - time)
        ) {
            nearest = point;
        }
    }

    return nearest;
}
