import { describe, expect, test } from 'vitest';
import { isNear, clusterAngles, computeZones, zoneFanOffset } from '@battlefield/minion-grouping.js';

describe('isNear', () => {
  test('two points within ratio * average size are near', () => {
    const a = { x: 0, y: 0, size: 40 };
    const b = { x: 200, y: 0, size: 40 };
    // avg size 40, distance 200 -> ratio 5, threshold ratio 7 -> near
    expect(isNear(a, b, 7)) .toBe(true);
  });

  test('two points beyond ratio * average size are not near', () => {
    const a = { x: 0, y: 0, size: 40 };
    const b = { x: 500, y: 0, size: 40 };
    expect(isNear(a, b, 7)).toBe(false);
  });

  test('a larger average size grows the near threshold', () => {
    const a = { x: 0, y: 0, size: 100 };
    const b = { x: 600, y: 0, size: 100 };
    // avg size 100 * ratio 7 = 700 threshold, distance 600 -> near
    expect(isNear(a, b, 7)).toBe(true);
  });
});

describe('clusterAngles', () => {
  test('a single angle forms its own cluster', () => {
    const clusters = clusterAngles([0], Math.PI / 2);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].indices).toEqual([0]);
    expect(clusters[0].meanAngle).toBeCloseTo(0);
  });

  test('two angles within the cone merge into one cluster with the circular mean direction', () => {
    const clusters = clusterAngles([-0.2, 0.2], Math.PI / 2);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].indices.sort()).toEqual([0, 1]);
    expect(clusters[0].meanAngle).toBeCloseTo(0);
  });

  test('two roughly opposite angles (a straight line) split into two separate clusters', () => {
    const clusters = clusterAngles([0, Math.PI], Math.PI / 2.4);
    expect(clusters).toHaveLength(2);
    const allIndices = clusters.flatMap(c => c.indices).sort();
    expect(allIndices).toEqual([0, 1]);
  });

  test('a reasonable triangle (neighbors ~90° apart) still merges into one cluster under a wide-enough cone', () => {
    const clusters = clusterAngles([-Math.PI / 4, Math.PI / 4], Math.PI / 1.9);
    expect(clusters).toHaveLength(1);
  });

  test('three angles chain-merge through a middle angle even when the outer two are far apart themselves', () => {
    // 0 and 0.6 are within the cone of each other (single-link chaining),
    // and 1.2 is within the cone of 0.6, so all three end up in one cluster
    // even though 0 and 1.2 alone would not be.
    const coneWidth = 0.7;
    const clusters = clusterAngles([0, 0.6, 1.2], coneWidth);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].indices.sort()).toEqual([0, 1, 2]);
  });

  test('an empty angle list produces no clusters', () => {
    expect(clusterAngles([], Math.PI / 2)).toEqual([]);
  });
});

describe('computeZones', () => {
  test('a fighter with no nearby neighbors gets no zones', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [{ id: 2, x: 1000, y: 0, size: 40 }];
    expect(computeZones(fighter, others)).toEqual([]);
  });

  test('a fighter with one nearby neighbor gets a single zone pointed at it', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [{ id: 2, x: 100, y: 0, size: 40 }];
    const zones = computeZones(fighter, others);
    expect(zones).toHaveLength(1);
    expect(zones[0].angle).toBeCloseTo(0);
    expect(zones[0].neighborIds).toEqual([2]);
    expect(zones[0].distance).toBeCloseTo(100);
  });

  test('a zone\'s distance is the closest of its merged neighbors, so minions pull toward whichever is nearest', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [
      { id: 2, x: 60, y: -10, size: 40 },
      { id: 3, x: 200, y: 10, size: 40 },
    ];
    const zones = computeZones(fighter, others);
    expect(zones).toHaveLength(1);
    expect(zones[0].neighborIds.sort()).toEqual([2, 3]);
    expect(zones[0].distance).toBeCloseTo(Math.hypot(60, -10));
  });

  test('two nearby neighbors in roughly the same direction merge into one zone', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [
      { id: 2, x: 100, y: -20, size: 40 },
      { id: 3, x: 100, y: 20, size: 40 },
    ];
    const zones = computeZones(fighter, others);
    expect(zones).toHaveLength(1);
    expect(zones[0].neighborIds.sort()).toEqual([2, 3]);
  });

  test('two nearby neighbors on opposite sides split into two zones (the straight-line case)', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [
      { id: 2, x: -100, y: 0, size: 40 },
      { id: 3, x: 100, y: 0, size: 40 },
    ];
    const zones = computeZones(fighter, others);
    expect(zones).toHaveLength(2);
    const allNeighborIds = zones.flatMap(z => z.neighborIds).sort();
    expect(allNeighborIds).toEqual([2, 3]);
  });

  test('a far-away fighter never contributes to a zone even if others are close', () => {
    const fighter = { id: 1, x: 0, y: 0, size: 40 };
    const others = [
      { id: 2, x: 100, y: 0, size: 40 },
      { id: 3, x: 5000, y: 0, size: 40 },
    ];
    const zones = computeZones(fighter, others);
    expect(zones).toHaveLength(1);
    expect(zones[0].neighborIds).toEqual([2]);
  });
});

describe('zoneFanOffset', () => {
  test('a single minion in a zone gets no offset (sits right on the zone direction)', () => {
    expect(zoneFanOffset(0, 1, Math.PI / 2)).toBeCloseTo(0);
  });

  test('two minions in a zone straddle the zone direction evenly', () => {
    const arc = Math.PI / 2;
    expect(zoneFanOffset(0, 2, arc)).toBeCloseTo(-arc / 2);
    expect(zoneFanOffset(1, 2, arc)).toBeCloseTo(arc / 2);
  });

  test('three minions in a zone spread with the middle one on the zone direction', () => {
    const arc = Math.PI / 2;
    expect(zoneFanOffset(0, 3, arc)).toBeCloseTo(-arc / 2);
    expect(zoneFanOffset(1, 3, arc)).toBeCloseTo(0);
    expect(zoneFanOffset(2, 3, arc)).toBeCloseTo(arc / 2);
  });
});
