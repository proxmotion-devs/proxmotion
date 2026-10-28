import { describe, expect, it } from 'vite-plus/test';
import { linePath, nearestPoint } from '@/lib/charts';

type Point = { time: number; value: number | null };

const points: Point[] = [
    { time: 0, value: 1 },
    { time: 10, value: 2 },
    { time: 20, value: null },
    { time: 30, value: 3 },
    { time: 100, value: 4 },
];

describe('linePath', () => {
    it('breaks at missing values and long gaps', () => {
        const d = linePath(
            points,
            (point) => point.value,
            (time) => time,
            (value) => value * 10,
            15,
        );

        expect(d).toBe('M0.0,10.0L10.0,20.0M30.0,30.0M100.0,40.0');
    });
});

describe('nearestPoint', () => {
    it('finds the point closest in time', () => {
        expect(nearestPoint(points, 27, (point) => point.time)?.time).toBe(30);
        expect(nearestPoint([], 27, (point: Point) => point.time)).toBeNull();
    });
});
