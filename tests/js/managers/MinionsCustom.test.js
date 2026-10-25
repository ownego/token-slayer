import { afterEach, beforeEach, describe, expect, it, test, vi } from 'vitest';

/**
 * The manager's idle wander and fidget timers draw their delay (1 s or more) from
 * `Phaser.Math.Between`. While `quiet` is set the fake returns a delay no test reaches, so
 * those timers cannot change what a test is about; the fidget test turns it off and gets the
 * real (minimum) delay.
 */
const phaserState = vi.hoisted(() => ({ quiet: true }));

vi.mock('phaser', () => ({
  default: {
    Math: {
      Between: (min) => (phaserState.quiet && min >= 1000 ? 1e9 : min),
      Clamp: (value, low, high) => Math.min(high, Math.max(low, value)),
      Distance: { Between: () => 999 },
    },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete' } },
    BlendModes: { ADD: 'ADD' },
  },
}));

// Each call of the stacked loader hands the test a promise it settles itself.
vi.mock('@battlefield/fighter/custom-fighter-loader.js', () => ({ loadStackedType: vi.fn() }));

import { loadStackedType } from '@battlefield/fighter/custom-fighter-loader.js';
import { CUSTOM_RETRY_MS } from '@battlefield/fighter/custom-fighter-requests.js';
import { MINION_TYPES, TIMINGS } from '@battlefield/config.js';
import { customMinionKey, normalizeMinionPool } from '@battlefield/minion-custom.js';
import { Minions } from '@battlefield/minions.js';
import { minionPoolPayloads } from '@battlefield/resync.js';

const DEFAULT_KEYS = MINION_TYPES.map(type => type.key);
const USER = 7;
const ANIMATIONS = {
  idle: { frames: 6, rate: 8 },
  walk: { frames: 8, rate: 16 },
  attack: { frames: 6, rate: 12 },
  death: { frames: 3, rate: 6 },
};

/** Long enough for a summon (poof, circle, rise-in) or a swap poof to finish. */
const MOTION_MS = 1000;

/**
 * One custom minion as the wire carries it.
 *
 * @param {string} version
 * @return {object}
 */
const wire = version => ({ version, url_base: `/storage/custom-minions/${USER}/${version}`, animations: ANIMATIONS, body_px: 22 });

/**
 * A minion pool as the wire carries it. Most tests keep no default look, so a pick out of
 * the pool is decided by its custom minions alone.
 *
 * @param {number} rev
 * @param {string[]} [versions] The custom minions in the pool.
 * @param {string[]} [defaults] The default looks the pool keeps.
 * @return {object}
 */
const poolWire = (rev, versions = [], defaults = []) => ({ rev, defaults, custom: versions.map(wire) });

const keyOf = version => customMinionKey(USER, version);

/**
 * Lets the promise callbacks the manager chained run.
 *
 * @return {Promise<void>}
 */
const flush = async () => {
  for (let i = 0; i < 6; i++) {
    await Promise.resolve();
  }
};

/**
 * A sprite that records what it was told to play, and lets the test fire its animation events.
 *
 * @param {number} x
 * @param {number} y
 * @param {string} [texture]
 * @return {object}
 */
function makeSprite(x = 0, y = 0, texture = '__DEFAULT') {
  const handlers = {};
  const sprite = {
    active: true,
    x,
    y,
    scale: 1,
    scaleX: 1,
    flipX: false,
    texture: { key: texture },
    plays: [],
    textures: [],
    setDepth: () => sprite,
    setAlpha: () => sprite,
    setBlendMode: () => sprite,
    setDisplaySize: () => sprite,
    setScale: (value) => { sprite.scale = value; return sprite; },
    setPosition: (px, py) => { sprite.x = px; sprite.y = py; return sprite; },
    setFlipX: (value) => { sprite.flipX = value; return sprite; },
    setTexture: (key, frame) => { sprite.texture.key = key; sprite.textures.push({ key, frame }); return sprite; },
    play: (key) => { sprite.plays.push(key); return sprite; },
    playReverse: (key) => { sprite.plays.push(key); return sprite; },
    once: (event, fn) => { (handlers[event] ??= []).push(fn); return sprite; },
    emit: (event) => {
      const due = handlers[event] ?? [];
      handlers[event] = [];
      due.forEach(fn => fn());
    },
    destroy: () => { sprite.active = false; },
  };

  return sprite;
}

/**
 * A scene fake with real (fake-timer) delays, and tweens that finish at once. It records
 * what the manager draws: `images` (the Clawd poof frames among them), `rings` (tool-use
 * rings) and `bursts` (the puffs that end a poof).
 *
 * @param {{reducedMotion?: boolean}} [options]
 * @return {object}
 */
function makeScene({ reducedMotion = true } = {}) {
  const scene = {
    reducedMotion,
    isShuttingDown: false,
    fighters: new Map(),
    images: [],
    rings: [],
    bursts: [],
    necromancer: { spawnSummonCircle: vi.fn(() => null) },
    time: {
      get now() { return Date.now(); },
      delayedCall: vi.fn((ms, fn) => {
        const id = setTimeout(fn, ms);

        return { remove: () => clearTimeout(id) };
      }),
    },
    tweens: { add: vi.fn((config) => { config.onComplete?.(); return config; }), killTweensOf: vi.fn() },
    textures: { exists: () => true, remove: vi.fn() },
    anims: { exists: () => true, remove: vi.fn() },
    add: {
      sprite: (x, y, texture) => makeSprite(x, y, texture),
      image: (x, y, texture) => {
        const image = makeSprite(x, y, texture);
        scene.images.push(image);

        return image;
      },
      graphics: (config) => {
        const ring = makeSprite(config.x, config.y);
        scene.rings.push(ring);

        return ring;
      },
      particles: (x, y) => {
        scene.bursts.push({ x, y });

        return { scene: {}, explode() {}, destroy() {}, setDepth() { return this; } };
      },
    },
    addSharpText: () => makeSprite(),
  };

  return scene;
}

/**
 * The Clawd poofs the manager played so far, one image each (a summon's and a swap's).
 *
 * @param {object} scene
 * @return {Array<object>}
 */
const poofs = scene => scene.images.filter(image => image.texture.key.startsWith('clawd-'));

/**
 * Puts a fighter on the field.
 *
 * @param {object} scene
 * @param {object} [pool] The minion pool the fighter holds, as the wire carries it.
 * @return {object} The fighter entry.
 */
function addFighter(scene, pool = poolWire(1, [], DEFAULT_KEYS)) {
  const entry = { baseSize: 48, legH: 6, sprite: { x: 100, y: 100, scaleX: 1, active: true }, minionPool: normalizeMinionPool(pool) };
  scene.fighters.set(USER, entry);

  return entry;
}

/**
 * The loads the manager asked the loader for, each with a way to settle it.
 *
 * @type {Array<{type: object, resolve: Function, reject: Function}>}
 */
let loads;

/**
 * Settles the newest load of a version and lets the manager react.
 *
 * @param {string} version
 * @param {'resolve'|'reject'} [how]
 * @return {Promise<void>}
 */
async function settle(version, how = 'resolve') {
  const load = [...loads].reverse().find(item => item.type.key === keyOf(version));
  load[how](load.type);
  await flush();
}

/**
 * Raises a fighter's minion count and lets every pending summon finish.
 *
 * @param {Minions} minions
 * @param {number} count
 * @return {Promise<Array<object>>} The fighter's minions.
 */
async function setCount(minions, count) {
  minions.handleAgentCountChanged({ user_id: USER, count });
  await flush();
  vi.advanceTimersByTime(MOTION_MS);

  return minions.byUser.get(USER);
}

/**
 * Puts a fighter with `pool` on the field, raises its swarm to `count`, lands every custom
 * minion of the pool and lets the summons finish.
 *
 * @param {object} scene
 * @param {Minions} minions
 * @param {object} pool The pool, as the wire carries it.
 * @param {number} count
 * @return {Promise<Array<object>>} The fighter's minions.
 */
async function minionsOut(scene, minions, pool, count) {
  addFighter(scene, pool);
  minions.handleAgentCountChanged({ user_id: USER, count });
  for (const custom of pool.custom) {
    await settle(custom.version);
  }
  vi.advanceTimersByTime(MOTION_MS);

  return minions.byUser.get(USER);
}

beforeEach(() => {
  vi.useFakeTimers();
  phaserState.quiet = true;
  loads = [];
  loadStackedType.mockReset();
  loadStackedType.mockImplementation((scene, type) => new Promise((resolve, reject) => {
    loads.push({ type, resolve, reject });
  }));
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('spawning from a pool', () => {
  test('spawns only custom looks once they are loaded, when the pool keeps no default look', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);

    const list = await minionsOut(scene, minions, poolWire(1, ['a', 'b']), 4);

    expect(list).toHaveLength(4);
    expect(loadStackedType).toHaveBeenCalledTimes(2);
    expect(list.every(minion => [keyOf('a'), keyOf('b')].includes(minion.typeKey))).toBe(true);
    expect(list.every(minion => minion.type.custom === true && minion.charHeight === 22)).toBe(true);
    expect(list[0].sprite.texture.key).toBe(list[0].typeKey);
  });

  test('waits for a loading custom minion and summons it', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const minion = minions.byUser.get(USER)[0];

    expect(minion.typeKey).toBeNull();
    vi.advanceTimersByTime(500);
    expect(scene.necromancer.spawnSummonCircle).not.toHaveBeenCalled();
    expect(minion.sprite.plays).toEqual([]);

    await settle('a');
    vi.advanceTimersByTime(500);

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(scene.necromancer.spawnSummonCircle).toHaveBeenCalledTimes(1);
    expect(minion.sprite.plays.every(key => key.startsWith('minion-7-'))).toBe(true);
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs);
    expect(minion.typeKey).toBe(keyOf('a'));
  });

  test('draws a pool that keeps some defaults from those and its custom minions, never from a look it unticked', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);

    const list = await minionsOut(scene, minions, poolWire(1, ['a'], ['demon-a', 'smw-bowser']), 10);

    expect(list).toHaveLength(10);
    expect(list.every(minion => ['demon-a', 'smw-bowser', keyOf('a')].includes(minion.typeKey))).toBe(true);
  });

  test('draws from every default look for a fighter whose pool the server never sent', async () => {
    const scene = makeScene();
    const entry = addFighter(scene);
    entry.minionPool = null;
    const minions = new Minions(scene);

    const list = await setCount(minions, 5);

    expect(list).toHaveLength(5);
    expect(loadStackedType).not.toHaveBeenCalled();
    expect(list.every(minion => DEFAULT_KEYS.includes(minion.typeKey))).toBe(true);
  });

  test('a pool of defaults only loads nothing and plays the default idle', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, [], ['demon-a']));
    const minions = new Minions(scene);

    const list = await setCount(minions, 3);

    expect(loadStackedType).not.toHaveBeenCalled();
    expect(list.map(minion => minion.typeKey)).toEqual(['demon-a', 'demon-a', 'demon-a']);
    expect(list[0].sprite.plays[0]).toBe('demon-a-idle');
  });

  test('falls back to a default after customMinionWaitMs and changes to the custom look when it lands', async () => {
    const scene = makeScene({ reducedMotion: false });
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const minion = minions.byUser.get(USER)[0];
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs - 1);
    expect(minion.typeKey).toBeNull();
    vi.advanceTimersByTime(1);

    expect(DEFAULT_KEYS).toContain(minion.typeKey);
    expect(poofs(scene)).toHaveLength(1);
    vi.advanceTimersByTime(MOTION_MS);

    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(poofs(scene)).toHaveLength(2);
    expect(minion.sprite.plays.at(-1)).toBe(`${keyOf('a')}-idle`);
  });

  test('a failed load stays on a default, and is not asked for again before CUSTOM_RETRY_MS', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');
    vi.advanceTimersByTime(500);
    const minion = minions.byUser.get(USER)[0];

    expect(DEFAULT_KEYS).toContain(minion.typeKey);

    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  it('retries a failed look without waiting for the next dispatch', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');
    vi.advanceTimersByTime(500);
    const minion = minions.byUser.get(USER)[0];
    expect(DEFAULT_KEYS).toContain(minion.typeKey);

    vi.advanceTimersByTime(CUSTOM_RETRY_MS);

    expect(loadStackedType).toHaveBeenCalledTimes(2);
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);
    expect(minion.typeKey).toBe(keyOf('a'));
  });

  test('does not retry a failed look for a player whose swarm is gone; the next spawn asks instead', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');
    minions.handleAgentCountChanged({ user_id: USER, count: 0 });

    vi.advanceTimersByTime(CUSTOM_RETRY_MS);
    expect(loadStackedType).toHaveBeenCalledTimes(1);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    expect(loadStackedType).toHaveBeenCalledTimes(2);
  });
});

describe('handleMinionsChanged', () => {
  test('ignores a pool that is not newer by rev', async () => {
    const scene = makeScene({ reducedMotion: false });
    const entry = addFighter(scene, poolWire(2, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);
    const poofsBefore = poofs(scene).length;

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['b']) });
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(1, ['b']) });
    await flush();

    expect(entry.minionPool.rev).toBe(2);
    expect(entry.minionPool.custom.map(minion => minion.version)).toEqual(['a']);
    expect(loadStackedType).toHaveBeenCalledTimes(1);
    expect(poofs(scene)).toHaveLength(poofsBefore);
  });

  it('ignores a pool older than the one it holds', async () => {
    const scene = makeScene();
    const entry = addFighter(scene, poolWire(3, ['a', 'b']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(MOTION_MS);
    // the player removes a: the broadcast (rev 5) arrives ...
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(5, ['b']) });
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(minions.byUser.get(USER).every(minion => minion.typeKey === keyOf('b'))).toBe(true);
    const loadCalls = loadStackedType.mock.calls.length;

    // ... and then the charge that read the pool before the removal (rev 4), and a stale resync
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(4, ['a', 'b']) });
    for (const change of minionPoolPayloads({ [USER]: poolWire(4, ['a', 'b']) })) {
      minions.handleMinionsChanged(change);
    }
    await flush();

    expect(entry.minionPool.rev).toBe(5);
    expect(entry.minionPool.custom.map(minion => minion.version)).toEqual(['b']);
    expect(loadStackedType).toHaveBeenCalledTimes(loadCalls);
    expect(minions.byUser.get(USER).every(minion => minion.typeKey === keyOf('b'))).toBe(true);
  });

  test('a resync pool that is newer replaces the one the fighter holds', () => {
    const scene = makeScene();
    const entry = addFighter(scene, poolWire(2, [], ['demon-a']));
    const minions = new Minions(scene);

    for (const change of minionPoolPayloads({ [USER]: poolWire(6, [], ['smw-bowser']) })) {
      minions.handleMinionsChanged(change);
    }

    expect(entry.minionPool.rev).toBe(6);
    expect(entry.minionPool.defaults).toEqual(['smw-bowser']);
  });

  test.each([
    ['a missing key', { user_id: USER }],
    ['undefined', { user_id: USER, minion_pool: undefined }],
    ['null', { user_id: USER, minion_pool: null }],
    ['the old list shape', { user_id: USER, minion_pool: [] }],
    ['a pool without a rev', { user_id: USER, minion_pool: { defaults: [], custom: [] } }],
  ])('ignores a payload with no readable pool (%s)', async (_, payload) => {
    const scene = makeScene({ reducedMotion: false });
    const entry = addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);
    const poofsBefore = poofs(scene).length;
    const held = entry.minionPool;

    minions.handleMinionsChanged(payload);
    await flush();

    expect(entry.minionPool).toBe(held);
    expect(minions.byUser.get(USER)[0].typeKey).toBe(keyOf('a'));
    expect(poofs(scene)).toHaveLength(poofsBefore);
    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  test('ignores a fighter that is not on the field', () => {
    const scene = makeScene();
    const minions = new Minions(scene);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(1, ['a']) });

    expect(loadStackedType).not.toHaveBeenCalled();
    expect(minions.looks.size).toBe(0);
  });

  test('stores the pool, and loads nothing while the player has no minions out', async () => {
    const scene = makeScene();
    const entry = addFighter(scene, poolWire(1, [], DEFAULT_KEYS));
    const minions = new Minions(scene);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['a'], DEFAULT_KEYS) });
    await flush();

    expect(entry.minionPool.rev).toBe(2);
    expect(entry.minionPool.custom.map(minion => minion.version)).toEqual(['a']);
    expect(loadStackedType).not.toHaveBeenCalled();
  });

  test('loads a custom minion added while minions are out, and keeps the swarm as it is', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, [], ['demon-a']));
    const minions = new Minions(scene);
    await setCount(minions, 2);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['a'], ['demon-a']) });
    expect(loadStackedType).toHaveBeenCalledTimes(1);
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);

    expect(minions.byUser.get(USER).map(minion => minion.typeKey)).toEqual(['demon-a', 'demon-a']);
  });
});

describe('swapping looks', () => {
  test('swaps a look that left the pool to another member once that one is in, and to a default when none is left', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);
    const list = await minionsOut(scene, minions, poolWire(1, ['a']), 3);
    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('a'), keyOf('a'), keyOf('a')]);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['b']) });

    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('a'), keyOf('a'), keyOf('a')]);
    await settle('b');

    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('b'), keyOf('b'), keyOf('b')]);
    expect(list[0].type.key).toBe(keyOf('b'));
    expect(list[0].sprite.textures.at(-1)).toEqual({ key: keyOf('b'), frame: `${keyOf('b')}-idle-0` });
    expect(list[0].sprite.plays.at(-1)).toBe(`${keyOf('b')}-idle`);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(3, [], ['demon-a']) });

    expect(list.map(minion => minion.typeKey)).toEqual(['demon-a', 'demon-a', 'demon-a']);
    expect(list[0].type).toBe(MINION_TYPES.find(type => type.key === 'demon-a'));
    expect(list[0].charHeight).toBe(list[0].type.charHeight);
    expect(list[0].sprite.textures.at(-1)).toEqual({ key: 'demon-a-idle', frame: undefined });
  });

  test('swaps the minions showing a default look the player unticked', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, [], ['demon-a']));
    const minions = new Minions(scene);
    const list = await setCount(minions, 2);
    expect(list.map(minion => minion.typeKey)).toEqual(['demon-a', 'demon-a']);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['smw-bowser']) });

    expect(list.map(minion => minion.typeKey)).toEqual(['smw-bowser', 'smw-bowser']);
  });

  test('leaves a minion alone when the new pool still has its look', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const list = await minionsOut(scene, minions, poolWire(1, ['a']), 2);
    const poofsBefore = poofs(scene).length;

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['a'], ['demon-a']) });
    vi.advanceTimersByTime(MOTION_MS);

    expect(poofs(scene)).toHaveLength(poofsBefore);
    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('a'), keyOf('a')]);
    expect(scene.textures.remove).not.toHaveBeenCalled();
  });

  test('plays the poof before a swap lands, and none under reduced motion', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);
    const poofsBefore = poofs(scene).length;

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });

    expect(poofs(scene)).toHaveLength(poofsBefore + 1);
    expect(minion.typeKey).toBe(keyOf('a'));
    vi.advanceTimersByTime(MOTION_MS);
    expect(minion.typeKey).toBe('demon-a');

    scene.reducedMotion = true;
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(3, [], ['smw-bowser']) });

    expect(poofs(scene)).toHaveLength(poofsBefore + 1);
    expect(minion.typeKey).toBe('smw-bowser');
  });

  it('plays the swap poof where the minion is', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });
    const poof = poofs(scene).at(-1);

    expect([poof.x, poof.y]).toEqual([minion.sprite.x, minion.sprite.y]);
    minion.sprite.x += 120; // the minion walks on while the poof plays
    minion.sprite.y += 30;
    vi.advanceTimersByTime(300);
    expect([poof.x, poof.y]).toEqual([minion.sprite.x, minion.sprite.y]);
    vi.advanceTimersByTime(MOTION_MS);

    expect(scene.bursts.at(-1)).toEqual({ x: minion.sprite.x, y: minion.sprite.y });
    expect(minion.typeKey).toBe('demon-a');
  });

  test('a minion mid-poof lands the newest look, with one poof', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);
    const poofsBefore = poofs(scene).length;

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(3, [], ['smw-bowser']) });
    vi.advanceTimersByTime(MOTION_MS);

    expect(poofs(scene)).toHaveLength(poofsBefore + 1);
    expect(minion.typeKey).toBe('smw-bowser');
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('parks a swap during the summon until it ends', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    const minion = minions.byUser.get(USER)[0];
    expect(minion.summoning).toBe(true);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });

    expect(minion.typeKey).toBe(keyOf('a'));
    vi.advanceTimersByTime(MOTION_MS);

    expect(minion.typeKey).toBe('demon-a');
  });

  test('applies a parked swap at the end of a fidget', async () => {
    phaserState.quiet = false; // this one wants the fidget timer to fire
    const scene = makeScene();
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);
    vi.advanceTimersByTime(3000);
    expect(minion.fidgeting).toBe(true);
    expect(minion.sprite.plays.at(-1)).toBe(`${keyOf('a')}-attack`);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });
    expect(minion.typeKey).toBe(keyOf('a'));
    minion.sprite.emit('animationcomplete');

    expect(minion.fidgeting).toBe(false);
    expect(minion.typeKey).toBe('demon-a');
    expect(minion.sprite.plays.at(-1)).toBe('demon-a-idle');
  });
});

describe('releasing looks', () => {
  it('releases a look that left the pool', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);
    await minionsOut(scene, minions, poolWire(1, ['a']), 2);
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, ['a', 'b']) });
    await settle('b');
    expect(scene.textures.remove).not.toHaveBeenCalled();

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(3, ['a']) });

    expect(scene.textures.remove).toHaveBeenCalledTimes(1);
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('b'));
    expect(scene.anims.remove).toHaveBeenCalledWith(`${keyOf('b')}-idle`);
    expect(scene.anims.remove).toHaveBeenCalledWith(`${keyOf('b')}-death`);
    expect(minions.looks.get(USER).has('b')).toBe(false);
    expect(minions.looks.get(USER).has('a')).toBe(true);
  });

  test('keeps a look that left the pool while a minion still shows it, and releases it when the swap lands', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(scene.textures.remove).not.toHaveBeenCalled();
    vi.advanceTimersByTime(MOTION_MS);

    expect(minion.typeKey).toBe('demon-a');
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  it("keeps a player's looks while the pool is unchanged and the swarm drops to zero", async () => {
    const scene = makeScene();
    const minions = new Minions(scene);
    await minionsOut(scene, minions, poolWire(1, ['a', 'b']), 2);

    minions.handleAgentCountChanged({ user_id: USER, count: 0 });

    expect(scene.textures.remove).not.toHaveBeenCalled();
    expect(scene.anims.remove).not.toHaveBeenCalled();

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const [first] = minions.byUser.get(USER);

    expect(loadStackedType).toHaveBeenCalledTimes(2);
    expect(first.typeKey).not.toBeNull(); // not hidden while a look loads again
    expect(first.waitingForLook).toBe(false);
    expect(scene.necromancer.spawnSummonCircle).toHaveBeenCalledTimes(3);
  });

  test('keeps the looks while some minions are still out', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);
    await minionsOut(scene, minions, poolWire(1, ['a']), 3);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });

    expect(scene.textures.remove).not.toHaveBeenCalled();
  });

  test('releases the looks when the fighter leaves (despawnAll)', async () => {
    const scene = makeScene();
    const minions = new Minions(scene);
    await minionsOut(scene, minions, poolWire(1, ['a']), 2);

    minions.despawnAll(USER);

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(minions.byUser.has(USER)).toBe(false);
    expect(minions.looks.has(USER)).toBe(false);
  });

  test('releases a look that left the pool while it was loading, as soon as it lands', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });
    expect(scene.textures.remove).not.toHaveBeenCalled();
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(minions.byUser.get(USER)[0].typeKey).toBe('demon-a');
  });

  test('stops retrying a failed look that left the pool', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');

    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });
    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 2);

    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });
});

describe('tool rings', () => {
  it('does not attach a tool ring to a minion still waiting for its look', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const minion = minions.byUser.get(USER)[0];
    expect(minion.typeKey).toBeNull();

    minions.handleAgentToolUsed({ user_id: USER, agent_id: 'x', busy: true });

    expect(scene.rings).toHaveLength(0);

    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);
    minions.handleAgentToolUsed({ user_id: USER, agent_id: 'x', busy: true });

    expect(scene.rings).toHaveLength(1);
    expect([scene.rings[0].x, scene.rings[0].y]).toEqual([minion.sprite.x, minion.sprite.y]);
  });
});

describe('scene shutdown', () => {
  it('drops a load that lands after a scene restart', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    const [first] = minions.byUser.get(USER);

    minions.destroy();
    scene.isShuttingDown = false; // scene.create() re-arms the flag on a restart
    await settle('a');
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs * 2);

    expect(first.typeKey).toBeNull();
    expect(first.sprite.plays).toEqual([]);
    expect(first.sprite.textures).toEqual([]);
    expect(scene.necromancer.spawnSummonCircle).not.toHaveBeenCalled();
    expect(loadStackedType).toHaveBeenCalledTimes(1);
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a')); // the canvas it added is not left behind
  });

  test('keeps the texture a load added when the scene\'s new manager asked for the same look', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const old = new Minions(scene);
    scene.minions = old;
    old.handleAgentCountChanged({ user_id: USER, count: 1 });

    old.destroy();
    const fresh = new Minions(scene);
    scene.minions = fresh;
    fresh.handleAgentCountChanged({ user_id: USER, count: 1 });
    loads[0].resolve(loads[0].type);
    await flush();

    expect(scene.textures.remove).not.toHaveBeenCalled();

    loads[1].resolve(loads[1].type);
    await flush();
    vi.advanceTimersByTime(MOTION_MS);

    expect(fresh.byUser.get(USER)[0].typeKey).toBe(keyOf('a'));
  });

  test('a failed look does not retry after destroy()', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');

    minions.destroy();
    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 2);

    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  test('destroy() keeps the textures, and a restarted manager asks for them again', async () => {
    const scene = makeScene();
    const old = new Minions(scene);
    await minionsOut(scene, old, poolWire(1, ['a']), 1);

    old.destroy();

    expect(scene.textures.remove).not.toHaveBeenCalled();
    expect(scene.anims.remove).not.toHaveBeenCalled();

    const fresh = new Minions(scene);
    fresh.handleAgentCountChanged({ user_id: USER, count: 1 });
    expect(loadStackedType).toHaveBeenCalledTimes(2);
    await settle('a');
    vi.advanceTimersByTime(MOTION_MS);

    expect(fresh.byUser.get(USER)[0].typeKey).toBe(keyOf('a'));
  });

  test('destroy() lets go of the looks of a player whose swarm is at 0, since no later manager could', async () => {
    const scene = makeScene();
    const old = new Minions(scene);
    await minionsOut(scene, old, poolWire(1, ['a']), 1);
    old.handleAgentCountChanged({ user_id: USER, count: 0 });
    expect(scene.textures.remove).not.toHaveBeenCalled();

    old.destroy();

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('a failed look retries even when its timer fires a few ms before the scene clock reaches retryAt', async () => {
    const scene = makeScene();
    let skew = 0;
    Object.defineProperty(scene.time, 'now', { get: () => Date.now() - skew });
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');
    skew = 25;

    vi.advanceTimersByTime(CUSTOM_RETRY_MS);

    expect(loadStackedType).toHaveBeenCalledTimes(2);
  });

  test('a look that fails again changes no default minion, only a look that came in does', async () => {
    const scene = makeScene({ reducedMotion: false });
    let roll = 0;
    const random = vi.spyOn(Math, 'random').mockImplementation(() => roll);
    try {
      addFighter(scene, poolWire(1, ['a', 'b'], ['demon-a']));
      const minions = new Minions(scene);
      minions.handleAgentCountChanged({ user_id: USER, count: 1 });
      await settle('b');
      vi.advanceTimersByTime(TIMINGS.customMinionWaitMs + MOTION_MS);
      const minion = minions.byUser.get(USER)[0];
      expect(minion.typeKey).toBe('demon-a');
      const poofsBefore = poofs(scene).length;

      roll = 0.99;
      await settle('a', 'reject');
      vi.advanceTimersByTime(MOTION_MS);

      expect(minion.typeKey).toBe('demon-a');
      expect(poofs(scene)).toHaveLength(poofsBefore);
    } finally {
      random.mockRestore();
    }
  });

  test('a load that lands while the scene is shutting down changes no minion, and drops its texture', async () => {
    const scene = makeScene();
    addFighter(scene, poolWire(1, ['a']));
    const minions = new Minions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });

    scene.isShuttingDown = true;
    await settle('a');

    expect(minions.byUser.get(USER)[0].typeKey).toBeNull();
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('a swap poof that ends after the sprite is gone changes nothing', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = new Minions(scene);
    const [minion] = await minionsOut(scene, minions, poolWire(1, ['a']), 1);
    minions.handleMinionsChanged({ user_id: USER, minion_pool: poolWire(2, [], ['demon-a']) });

    minion.sprite.destroy();
    vi.advanceTimersByTime(MOTION_MS);

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(minion.sprite.textures).toEqual([{ key: keyOf('a'), frame: `${keyOf('a')}-idle-0` }]);
  });
});
