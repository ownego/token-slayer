import { beforeEach, describe, expect, test, vi } from 'vitest';

// leaderboard.js imports Phaser, which touches `navigator` at module init.
// We only exercise pure state methods, so a bare stub keeps the import safe in node.
vi.mock('phaser', () => ({ default: {} }));
vi.mock('../../resources/js/battlefield/bus.js', () => ({ bus: { emit: () => {} } }));

const { makeMethods } = await import('../../resources/js/battlefield/leaderboard.js');

function setup() {
  const fighters = new Map();
  const render = vi.fn();
  const methods = makeMethods(fighters, /* scene */ {}, render);
  return { fighters, render, methods };
}

describe('makeMethods', () => {
  describe('damageFor', () => {
    test('returns 0 for an unknown user', () => {
      const { methods } = setup();
      expect(methods.damageFor(999)).toBe(0);
    });

    test('returns the accumulated damage for a fighter', () => {
      const { methods } = setup();
      methods.onHit(7, 100, 'alice');
      methods.onHit(7, 50, 'alice');
      expect(methods.damageFor(7)).toBe(150);
    });
  });

  describe('rankOf', () => {
    test('returns null for an unknown user', () => {
      const { methods } = setup();
      methods.onHit(1, 100, 'alice');
      expect(methods.rankOf(999)).toBeNull();
    });

    test('returns 1 for the top damage dealer', () => {
      const { methods } = setup();
      methods.onHit(1, 100, 'alice');
      methods.onHit(2, 300, 'bob');
      methods.onHit(3, 200, 'carol');
      expect(methods.rankOf(2)).toBe(1);
    });

    test('ranks lower damage further down the leaderboard', () => {
      const { methods } = setup();
      methods.onHit(1, 100, 'alice');
      methods.onHit(2, 300, 'bob');
      methods.onHit(3, 200, 'carol');
      expect(methods.rankOf(3)).toBe(2);
      expect(methods.rankOf(1)).toBe(3);
    });

    test('updates rank when accumulated damage shifts the order', () => {
      const { methods } = setup();
      methods.onHit(1, 100, 'alice');
      methods.onHit(2, 200, 'bob');
      expect(methods.rankOf(1)).toBe(2);
      methods.onHit(1, 500, 'alice');
      expect(methods.rankOf(1)).toBe(1);
      expect(methods.rankOf(2)).toBe(2);
    });
  });

  test('onHit ignores non-positive damage', () => {
    const { methods } = setup();
    methods.onHit(1, 0, 'alice');
    methods.onHit(2, -10, 'bob');
    expect(methods.damageFor(1)).toBe(0);
    expect(methods.rankOf(1)).toBeNull();
    expect(methods.rankOf(2)).toBeNull();
  });

  test('reset clears all damage and ranks', () => {
    const { methods } = setup();
    methods.onHit(1, 100, 'alice');
    methods.reset();
    expect(methods.damageFor(1)).toBe(0);
    expect(methods.rankOf(1)).toBeNull();
  });

  test('seed populates damage and rank from entries', () => {
    const { methods } = setup();
    methods.seed([
      { userId: 1, damage: 100, handle: 'alice' },
      { userId: 2, damage: 300, handle: 'bob' },
    ]);
    expect(methods.damageFor(2)).toBe(300);
    expect(methods.rankOf(2)).toBe(1);
    expect(methods.rankOf(1)).toBe(2);
  });
});

beforeEach(() => {
  vi.clearAllMocks();
});
