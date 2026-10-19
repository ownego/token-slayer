import { describe, expect, test } from 'vitest';
import {
  CUSTOM_MINION_HIT_FRAME,
  customMinionKey,
  customMinionType,
  minionSpriteFrame,
  normalizeCustomMinions,
  pickMinionType,
  sameMinionSet,
  spawnLookDecision,
} from '@battlefield/minion-custom.js';

const ANIMATIONS = {
  idle: { frames: 6, rate: 8 },
  walk: { frames: 8, rate: 16 },
  attack: { frames: 6, rate: 12 },
  death: { frames: 3, rate: 6 },
};

const wire = (version = 'abc', extra = {}) => ({
  version,
  url_base: `/storage/custom-minions/7/${version}`,
  animations: ANIMATIONS,
  body_px: 22,
  ...extra,
});

describe('normalizeCustomMinions', () => {
  test.each([
    ['undefined', undefined],
    ['an object', {}],
    ['null', null],
    ['a string', 'abc'],
  ])('%s gives null (no information)', (_, raw) => {
    expect(normalizeCustomMinions(raw)).toBeNull();
  });

  test('an empty array gives an empty set', () => {
    expect(normalizeCustomMinions([])).toEqual([]);
  });

  test('reads the wire shape and the camelCase shape the same', () => {
    const expected = [{ version: 'abc', urlBase: '/storage/custom-minions/7/abc', animations: ANIMATIONS, bodyPx: 22 }];

    expect(normalizeCustomMinions([wire()])).toEqual(expected);
    expect(normalizeCustomMinions(expected)).toEqual(expected);
  });

  test.each([0, 101, '22', 21.5, null, undefined])('drops a minion whose body_px is %s', bodyPx => {
    expect(normalizeCustomMinions([wire('abc', { body_px: bodyPx })])).toEqual([]);
  });

  test('drops an item that is not a complete custom character', () => {
    expect(normalizeCustomMinions([wire('abc', { animations: {} }), wire('def'), 'x', null])).toHaveLength(1);
  });

  test('drops a duplicate version', () => {
    expect(normalizeCustomMinions([wire('abc'), wire('def'), wire('abc')]).map(m => m.version)).toEqual(['abc', 'def']);
  });
});

describe('customMinionKey', () => {
  test('is namespaced apart from a custom fighter\'s key', () => {
    expect(customMinionKey(7, 'abc')).toBe('minion-7-abc');
  });
});

describe('customMinionType', () => {
  const spec = normalizeCustomMinions([wire()])[0];

  test('is keyed and sized from the minion', () => {
    const type = customMinionType(7, spec);

    expect(type).toMatchObject({ key: 'minion-7-abc', texture: 'minion-7-abc', custom: true, spec, animations: spec.animations, charHeight: 22 });
  });

  test('describes its strips for the fight code', () => {
    const { animFiles } = customMinionType(7, spec);

    expect(animFiles.attack.count).toBe(6);
    expect(animFiles.attack.rate).toBe(12);
    expect(animFiles.idle).toEqual({ count: 6, rate: 8, loop: true });
    expect(animFiles.walk).toEqual({ count: 8, rate: 16, loop: true });
    expect(animFiles.death).toEqual({ count: 3, rate: 6 });
  });

  test('lands its hit on the third pose of the attack', () => {
    expect(CUSTOM_MINION_HIT_FRAME).toBe(4);
    expect(customMinionType(7, spec).attacks).toEqual([{ anim: 'attack', hitFrame: 4 }]);
  });

  test('falls down and gets back up when hit', () => {
    expect(customMinionType(7, spec).reaction).toEqual({ anim: 'death', holdMs: 2000, getUp: true });
  });
});

describe('minionSpriteFrame', () => {
  test('a custom minion is a named frame of its own stacked texture', () => {
    const type = customMinionType(7, normalizeCustomMinions([wire()])[0]);

    expect(minionSpriteFrame(type)).toEqual({ texture: 'minion-7-abc', frame: 'minion-7-abc-idle-0' });
  });

  test('a default minion is its own idle sheet, with no frame', () => {
    expect(minionSpriteFrame({ key: 'demon-a' })).toEqual({ texture: 'demon-a-idle', frame: undefined });
  });
});

describe('pickMinionType', () => {
  const loaded = ['a', 'b', 'c'];
  const defaults = ['x', 'y'];

  test('picks from the loaded set by the random number', () => {
    expect(pickMinionType(loaded, defaults, 0)).toBe('a');
    expect(pickMinionType(loaded, defaults, 0.5)).toBe('b');
    expect(pickMinionType(loaded, defaults, 0.99)).toBe('c');
  });

  test('falls back to the defaults when nothing is loaded', () => {
    expect(pickMinionType([], defaults, 0.99)).toBe('y');
  });
});

describe('sameMinionSet', () => {
  const a = { version: 'a' };
  const b = { version: 'b' };

  test('ignores order', () => {
    expect(sameMinionSet([a, b], [b, a])).toBe(true);
  });

  test('null and an empty set are the same', () => {
    expect(sameMinionSet(null, [])).toBe(true);
    expect(sameMinionSet(undefined, null)).toBe(true);
  });

  test('different versions or sizes differ', () => {
    expect(sameMinionSet([a], [b])).toBe(false);
    expect(sameMinionSet([a], [a, b])).toBe(false);
    expect(sameMinionSet([a], null)).toBe(false);
  });
});

describe('spawnLookDecision', () => {
  test.each([
    ['no set', { setSize: 0, settled: false, waitedMs: 0, waitMs: 2000 }, 'default'],
    ['no set, even when settled', { setSize: 0, settled: true, waitedMs: 0, waitMs: 2000 }, 'default'],
    ['a settled set', { setSize: 2, settled: true, waitedMs: 0, waitMs: 2000 }, 'custom'],
    ['an unsettled set, still within the wait', { setSize: 2, settled: false, waitedMs: 1999, waitMs: 2000 }, 'wait'],
    ['an unsettled set, wait over', { setSize: 2, settled: false, waitedMs: 2000, waitMs: 2000 }, 'default'],
  ])('%s gives %s', (_, input, expected) => {
    expect(spawnLookDecision(input)).toBe(expected);
  });
});
