import { describe, expect, test } from 'vitest';
import { countSidesNear, pickAttackerSide, travelLanding, pickRandom, fidgetAttacks, flipToFace, flipToFaceAngle } from '@battlefield/minion-fight.js';

describe('countSidesNear', () => {
  const points = [
    { side: 'a', x: 0, y: 0 },
    { side: 'a', x: 10, y: 0 },
    { side: 'a', x: 500, y: 0 },   // same side but far away: not part of this fight's area
    { side: 'b', x: 5, y: 5 },
    { side: 'c', x: 1, y: 1 },     // a third fighter's minion standing in the area: counts for neither side
  ];

  test('counts only each side\'s own minions inside the radius', () => {
    expect(countSidesNear(points, { x: 0, y: 0 }, 20, 'a', 'b')).toEqual({ a: 2, b: 1 });
  });

  test('a point exactly on the radius is inside the area', () => {
    expect(countSidesNear([{ side: 'b', x: 20, y: 0 }], { x: 0, y: 0 }, 20, 'a', 'b')).toEqual({ a: 0, b: 1 });
  });
});

describe('pickAttackerSide', () => {
  test('3 vs 7 gives side a a 30% share: rolls below 0.3 pick a, the rest pick b', () => {
    expect(pickAttackerSide(3, 7, 0)).toBe('a');
    expect(pickAttackerSide(3, 7, 0.29)).toBe('a');
    expect(pickAttackerSide(3, 7, 0.3)).toBe('b');
    expect(pickAttackerSide(3, 7, 0.99)).toBe('b');
  });

  test('an even area is a coin flip', () => {
    expect(pickAttackerSide(4, 4, 0.49)).toBe('a');
    expect(pickAttackerSide(4, 4, 0.5)).toBe('b');
  });

  test('never divides by zero when neither side was counted', () => {
    expect(['a', 'b']).toContain(pickAttackerSide(0, 0, 0.7));
  });
});

describe('travelLanding', () => {
  test('stops short of the target along the straight line to it', () => {
    const p = travelLanding({ x: 0, y: 0 }, { x: 100, y: 0 }, 20);
    expect(p.x).toBeCloseTo(80);
    expect(p.y).toBeCloseTo(0);
  });

  test('works in any direction, e.g. leaping left and up', () => {
    const p = travelLanding({ x: 30, y: 40 }, { x: 0, y: 0 }, 10);
    expect(Math.hypot(p.x, p.y)).toBeCloseTo(10);
    expect(p.x).toBeGreaterThan(0);
  });

  test('never overshoots backwards when already closer than the stop distance', () => {
    expect(travelLanding({ x: 0, y: 0 }, { x: 5, y: 0 }, 20)).toEqual({ x: 0, y: 0 });
  });
});

describe('pickRandom', () => {
  test('maps a [0,1) roll onto the list without ever running off the end', () => {
    expect(pickRandom(['x', 'y', 'z'], 0)).toBe('x');
    expect(pickRandom(['x', 'y', 'z'], 0.5)).toBe('y');
    expect(pickRandom(['x', 'y', 'z'], 0.9999)).toBe('z');
  });
});

describe('fidgetAttacks', () => {
  test('drops attacks that only make sense with a target to travel to', () => {
    const attacks = [{ anim: 'punch' }, { anim: 'dash', travel: { from: 3, to: 5 }, fidget: false }, { anim: 'slam', travel: { from: 2, to: 5 } }];
    expect(fidgetAttacks(attacks).map((a) => a.anim)).toEqual(['punch', 'slam']);
  });
});

describe('flipToFace', () => {
  test('every strip faces right, so only a target on the left needs a flip', () => {
    expect(flipToFace(100, 40)).toBe(true);
    expect(flipToFace(100, 160)).toBe(false);
  });

  test('a target straight above/below keeps the current facing', () => {
    expect(flipToFace(100, 100, true)).toBe(true);
    expect(flipToFace(100, 100, false)).toBe(false);
  });
});

describe('flipToFaceAngle', () => {
  test('a gathering zone pointing left (toward the other side) flips; pointing right does not', () => {
    expect(flipToFaceAngle(Math.PI)).toBe(true);
    expect(flipToFaceAngle(0)).toBe(false);
    expect(flipToFaceAngle(-Math.PI * 0.75)).toBe(true);
  });

  test('a zone pointing straight up/down keeps the current facing', () => {
    expect(flipToFaceAngle(Math.PI / 2, true)).toBe(true);
    expect(flipToFaceAngle(-Math.PI / 2, false)).toBe(false);
  });
});
