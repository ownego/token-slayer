import { describe, expect, test } from 'vitest';
import {
  CUSTOM_MINION_HIT_FRAME,
  customHitFrame,
  customMinionKey,
  customMinionType,
  customSpecsOf,
  defaultTypesOf,
  isLookInPool,
  isNewerPool,
  minionSpriteFrame,
  normalizeMinionPool,
  pickMinionType,
  pickSwapType,
  spawnLookDecision,
} from '@battlefield/minion-custom.js';
import { MINION_FALL_HOLD_MS, MINION_POOL_CUSTOM_CAP, MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FOOT_ROW } from '@battlefield/fighter/custom-fighter.js';

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

const DEFAULT_KEYS = MINION_TYPES.map(type => type.key);

/**
 * A pool as the wire carries it.
 *
 * @param {object} [extra] Keys that replace the defaults.
 * @return {object}
 */
const wirePool = (extra = {}) => ({ rev: 3, defaults: DEFAULT_KEYS, custom: [wire()], ...extra });

describe('normalizeMinionPool', () => {
  test.each([
    ['undefined', undefined],
    ['null', null],
    ['a string', 'abc'],
    ['an array', []],
    ['an empty object', {}],
    ['a pool without rev', { defaults: DEFAULT_KEYS, custom: [] }],
    ['a pool without defaults', { rev: 1, custom: [] }],
    ['a pool without custom', { rev: 1, defaults: DEFAULT_KEYS }],
    ['a pool whose custom is not a list', { rev: 1, defaults: DEFAULT_KEYS, custom: {} }],
    ['a pool whose rev is a string', { rev: '1', defaults: DEFAULT_KEYS, custom: [] }],
  ])('%s gives null (no information)', (_, raw) => {
    expect(normalizeMinionPool(raw)).toBeNull();
  });

  test('a pool that names nothing is a pool, not "no information"', () => {
    expect(normalizeMinionPool({ rev: 0, defaults: [], custom: [] })).toEqual({ rev: 0, defaults: [], custom: [] });
  });

  test('reads the wire shape and the camelCase shape the same', () => {
    const expected = {
      rev: 3,
      defaults: DEFAULT_KEYS,
      custom: [{ version: 'abc', urlBase: '/storage/custom-minions/7/abc', animations: ANIMATIONS, bodyPx: 22 }],
    };

    expect(normalizeMinionPool(wirePool())).toEqual(expected);
    expect(normalizeMinionPool(expected)).toEqual(expected);
  });

  test('drops a default look the game does not know, and a repeated one', () => {
    const pool = normalizeMinionPool(wirePool({ defaults: ['demon-a', 'evil', 'demon-a', 'smw-bowser'], custom: [] }));

    expect(pool.defaults).toEqual(['demon-a', 'smw-bowser']);
  });

  test.each([
    [0, false], [1, true], [22, true], [CUSTOM_FOOT_ROW, true], [CUSTOM_FOOT_ROW + 1, false],
    ['22', false], [21.5, false], [null, false], [undefined, false],
  ])('a custom minion whose body_px is %s is kept: %s', (bodyPx, kept) => {
    const { custom } = normalizeMinionPool(wirePool({ custom: [wire('abc', { body_px: bodyPx })] }));

    expect(custom).toHaveLength(kept ? 1 : 0);
  });

  test('drops an item that is not a complete custom character', () => {
    const { custom } = normalizeMinionPool(wirePool({ custom: [wire('abc', { animations: {} }), wire('def'), 'x', null] }));

    expect(custom.map(minion => minion.version)).toEqual(['def']);
  });

  test('drops a duplicate version', () => {
    const { custom } = normalizeMinionPool(wirePool({ custom: [wire('abc'), wire('def'), wire('abc')] }));

    expect(custom.map(minion => minion.version)).toEqual(['abc', 'def']);
  });

  test('cuts the custom minions at the cap', () => {
    const versions = Array.from({ length: MINION_POOL_CUSTOM_CAP + 2 }, (_, index) => `v${index}`);
    const { custom } = normalizeMinionPool(wirePool({ custom: versions.map(version => wire(version)) }));

    expect(custom.map(minion => minion.version)).toEqual(versions.slice(0, MINION_POOL_CUSTOM_CAP));
  });
});

describe('isNewerPool', () => {
  test('a pool with a higher rev is newer', () => {
    expect(isNewerPool({ rev: 4 }, { rev: 5 })).toBe(true);
  });

  test('a pool with the same rev is the same pool again', () => {
    expect(isNewerPool({ rev: 5 }, { rev: 5 })).toBe(false);
  });

  test('a pool with a lower rev is older', () => {
    expect(isNewerPool({ rev: 5 }, { rev: 4 })).toBe(false);
  });

  test.each([[null], [undefined]])('anything beats a fighter that holds no pool (%s)', current => {
    expect(isNewerPool(current, { rev: 0 })).toBe(true);
  });
});

describe('customMinionKey', () => {
  test('is namespaced apart from a custom fighter\'s key', () => {
    expect(customMinionKey(7, 'abc')).toBe('minion-7-abc');
  });
});

describe('customMinionType', () => {
  const spec = normalizeMinionPool(wirePool()).custom[0];

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

  test('falls down and gets back up when hit, holding as long as the default minions that fall do', () => {
    expect(customMinionType(7, spec).reaction).toEqual({ anim: 'death', holdMs: MINION_FALL_HOLD_MS, getUp: true });
    expect(MINION_TYPES.filter(type => type.reaction.getUp).every(type => type.reaction.holdMs === MINION_FALL_HOLD_MS)).toBe(true);
  });

  test.each([
    [1, 0], [3, 2], [4, 3], [5, 4], [6, 4], [12, 4],
  ])('a strip of %s attack frames lands its hit on frame %s', (frames, hitFrame) => {
    const short = { ...spec, animations: { ...ANIMATIONS, attack: { frames, rate: 12 } } };

    expect(customHitFrame(frames)).toBe(hitFrame);
    expect(customMinionType(7, short).attacks).toEqual([{ anim: 'attack', hitFrame }]);
  });
});

describe('minionSpriteFrame', () => {
  test('a custom minion is a named frame of its own stacked texture', () => {
    const type = customMinionType(7, normalizeMinionPool(wirePool()).custom[0]);

    expect(minionSpriteFrame(type)).toEqual({ texture: 'minion-7-abc', frame: 'minion-7-abc-idle-0' });
  });

  test('a default minion is its own idle sheet, with no frame', () => {
    expect(minionSpriteFrame({ key: 'demon-a' })).toEqual({ texture: 'demon-a-idle', frame: undefined });
  });
});

describe('pickMinionType', () => {
  const loaded = ['a', 'b'];
  const defaults = ['x', 'y', 'z'];

  test.each([
    [0, 'x'], [0.19, 'x'], [0.5, 'z'], [0.6, 'a'], [0.999, 'b'],
  ])('random %s picks %s, evenly across the defaults and the loaded custom minions', (random, expected) => {
    expect(pickMinionType(loaded, defaults, random)).toBe(expected);
  });

  test('only ever picks from the pool', () => {
    const picks = [0, 0.1, 0.25, 0.5, 0.75, 0.9, 0.999].map(random => pickMinionType(loaded, defaults, random));

    expect(picks.every(pick => [...loaded, ...defaults].includes(pick))).toBe(true);
  });

  test('draws from the defaults alone while no custom minion is loaded', () => {
    expect(pickMinionType([], defaults, 0)).toBe('x');
    expect(pickMinionType([], defaults, 0.999)).toBe('z');
  });

  test('draws from the custom minions alone when the pool keeps no default', () => {
    expect(pickMinionType(loaded, [], 0)).toBe('a');
    expect(pickMinionType(loaded, [], 0.999)).toBe('b');
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

describe('customSpecsOf, defaultTypesOf', () => {
  test('a fighter with no pool has no custom minion and every default look', () => {
    expect(customSpecsOf(null)).toEqual([]);
    expect(defaultTypesOf(null)).toBe(MINION_TYPES);
  });

  test('a pool lists its custom minions and keeps the default looks it names, in MINION_TYPES order', () => {
    const pool = normalizeMinionPool(wirePool({ defaults: [DEFAULT_KEYS[2], DEFAULT_KEYS[0]] }));

    expect(customSpecsOf(pool).map(spec => spec.version)).toEqual(['abc']);
    expect(defaultTypesOf(pool).map(type => type.key)).toEqual([DEFAULT_KEYS[0], DEFAULT_KEYS[2]]);
  });

  test('a pool that keeps no default look has none', () => {
    expect(defaultTypesOf(normalizeMinionPool(wirePool({ defaults: [] })))).toEqual([]);
  });
});

describe('isLookInPool', () => {
  const pool = normalizeMinionPool(wirePool({ defaults: [DEFAULT_KEYS[0]] }));
  const defaultType = key => MINION_TYPES.find(type => type.key === key);

  test('a default look is in the pool when the pool keeps it', () => {
    expect(isLookInPool(defaultType(DEFAULT_KEYS[0]), pool)).toBe(true);
    expect(isLookInPool(defaultType(DEFAULT_KEYS[1]), pool)).toBe(false);
  });

  test('a custom look is in the pool by its version', () => {
    expect(isLookInPool(customMinionType(7, pool.custom[0]), pool)).toBe(true);
    expect(isLookInPool(customMinionType(7, { ...pool.custom[0], version: 'other' }), pool)).toBe(false);
  });

  test('every default look is in the pool of a fighter with none, and no custom look', () => {
    expect(DEFAULT_KEYS.every(key => isLookInPool(defaultType(key), null))).toBe(true);
    expect(isLookInPool(customMinionType(7, pool.custom[0]), null)).toBe(false);
  });
});

describe('pickSwapType', () => {
  const defaults = [{ key: 'x' }, { key: 'y' }];
  const loaded = [{ key: 'a' }];

  test('offers nothing to change to when the pool has no default and no custom minion in', () => {
    expect(pickSwapType({ loaded: [], defaults: [], shownKey: 'x', random: 0.3 })).toBeNull();
  });

  test('draws evenly from the defaults and the loaded custom minions, like a spawn', () => {
    expect(pickSwapType({ loaded, defaults, shownKey: 'q', random: 0 }).type).toBe(defaults[0]);
    expect(pickSwapType({ loaded, defaults, shownKey: 'q', random: 0.999 }).type).toBe(loaded[0]);
  });

  test('says whether the draw is another look than the one shown', () => {
    expect(pickSwapType({ loaded, defaults, shownKey: 'q', random: 0 }).change).toBe(true);
    expect(pickSwapType({ loaded, defaults, shownKey: 'x', random: 0 }).change).toBe(false);
  });

  test('a pool with a single look draws it whatever the roll', () => {
    const picks = [0, 0.4, 0.999].map(random => pickSwapType({ loaded: [], defaults: [defaults[1]], shownKey: 'x', random }));

    expect(picks.every(pick => pick.type === defaults[1] && pick.change)).toBe(true);
  });
});
