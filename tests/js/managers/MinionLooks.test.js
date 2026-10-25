import { afterEach, beforeEach, describe, expect, it, test, vi } from 'vitest';

// Each call of the stacked loader hands the test a promise it settles itself.
vi.mock('@battlefield/fighter/custom-fighter-loader.js', () => ({ loadStackedType: vi.fn() }));

import { loadStackedType } from '@battlefield/fighter/custom-fighter-loader.js';
import { CUSTOM_RETRY_MS } from '@battlefield/fighter/custom-fighter-requests.js';
import { MINION_TYPES, TIMINGS } from '@battlefield/config.js';
import { customMinionKey, normalizeMinionPool } from '@battlefield/minion-custom.js';
import { MinionLooks } from '@battlefield/minion-looks.js';

const DEFAULT_KEYS = MINION_TYPES.map(type => type.key);
const USER = 7;
const ANIMATIONS = {
  idle: { frames: 6, rate: 8 },
  walk: { frames: 8, rate: 16 },
  attack: { frames: 6, rate: 12 },
  death: { frames: 3, rate: 6 },
};

const wire = version => ({ version, url_base: `/storage/custom-minions/${USER}/${version}`, animations: ANIMATIONS, body_px: 22 });
const pool = (rev, versions = [], defaults = []) => normalizeMinionPool({ rev, defaults, custom: versions.map(wire) });
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
 * A scene fake: real (fake-timer) delays, a clock, the texture and animation managers the
 * release goes through.
 *
 * @param {{reducedMotion?: boolean}} [options]
 * @return {object}
 */
function makeScene({ reducedMotion = true } = {}) {
  return {
    reducedMotion,
    isShuttingDown: false,
    fighters: new Map(),
    time: {
      get now() { return Date.now(); },
      delayedCall: (ms, fn) => {
        const id = setTimeout(fn, ms);

        return { remove: () => clearTimeout(id) };
      },
    },
    textures: { remove: vi.fn() },
    anims: { exists: () => true, remove: vi.fn() },
  };
}

/**
 * A minion as far as the looks manager sees it: out on the field showing the default
 * `demon-a`, unless `extra` says otherwise.
 *
 * @param {object} [extra]
 * @return {object}
 */
function makeMinion(extra = {}) {
  const sprite = {
    active: true,
    textures: [],
    plays: [],
    setTexture(key, frame) { sprite.textures.push({ key, frame }); return sprite; },
    play(key) { sprite.plays.push(key); return sprite; },
  };

  return {
    userId: USER, sprite, typeKey: 'demon-a', type: MINION_TYPES.find(type => type.key === 'demon-a'), charHeight: 20, animState: 'idle', awaitingCustom: false, pendingLook: null,
    waitingForLook: false, waitStartedAt: 0, waitTimer: null, swapping: false, summoning: false, fidgeting: false, fighting: false,
    ...extra,
  };
}

const defaultType = key => MINION_TYPES.find(type => type.key === key);

/**
 * The swarm side of the manager: a list of minions per player and a poof that ends after
 * a delay, as the real one does.
 *
 * @param {object} scene
 * @return {object}
 */
function makeHost(scene) {
  const swarm = new Map();

  return {
    swarm,
    poofs: [],
    summons: [],
    minionsOf: userId => swarm.get(userId) ?? [],
    beginSummon: vi.fn(function beginSummon(userId, entry, minion) { this.summons.push(minion); }),
    playSwapPoof(minion, entry, onDone) {
      this.poofs.push(minion);
      scene.time.delayedCall(300, onDone);
    },
  };
}

/**
 * Wires a manager to a scene and a host (the host's own methods bound to it).
 *
 * @param {{reducedMotion?: boolean}} [options]
 * @return {{scene: object, host: object, looks: MinionLooks}}
 */
function setup(options) {
  const scene = makeScene(options);
  const host = makeHost(scene);
  host.beginSummon = host.beginSummon.bind(host);
  host.playSwapPoof = host.playSwapPoof.bind(host);
  const looks = new MinionLooks(scene, host);

  return { scene, host, looks };
}

/**
 * Puts a fighter with `minionPool` on the field.
 *
 * @param {object} scene
 * @param {?object} minionPool A normalised pool.
 * @return {object} The fighter entry.
 */
function addFighter(scene, minionPool) {
  const entry = { minionPool };
  scene.fighters.set(USER, entry);

  return entry;
}

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

beforeEach(() => {
  vi.useFakeTimers();
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

describe('loadPool', () => {
  test('asks the loader once per custom minion of the pool, and again for none while they are on their way or in', async () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a', 'b']));

    looks.loadPool(USER, entry);
    looks.loadPool(USER, entry);
    expect(loadStackedType).toHaveBeenCalledTimes(2);

    await settle('a');
    looks.loadPool(USER, entry);

    expect(loadStackedType).toHaveBeenCalledTimes(2);
    expect(looks.holds(USER, 'a')).toBe(true);
    expect(looks.holds(USER, 'b')).toBe(true);
  });

  test('a pool with no custom minion asks for nothing and holds nothing', () => {
    const { scene, looks } = setup();

    looks.loadPool(USER, addFighter(scene, pool(1, [], DEFAULT_KEYS)));

    expect(loadStackedType).not.toHaveBeenCalled();
    expect(looks.holdsAny(USER)).toBe(false);
  });

  test('a failed look is not asked for before CUSTOM_RETRY_MS, and then is', async () => {
    const { scene, host, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a']));
    host.swarm.set(USER, [makeMinion()]);
    looks.loadPool(USER, entry);
    await settle('a', 'reject');

    looks.loadPool(USER, entry);
    expect(loadStackedType).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(CUSTOM_RETRY_MS);
    expect(loadStackedType).toHaveBeenCalledTimes(2);
  });

  it('does not retry on its own for a player with no minion out', async () => {
    const { scene, looks } = setup();
    looks.loadPool(USER, addFighter(scene, pool(1, ['a'])));
    await settle('a', 'reject');

    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 2);

    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });
});

describe('drawing a look for a spawn', () => {
  test('waits while a custom minion is loading, and gives up after customMinionWaitMs', () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a']));
    looks.loadPool(USER, entry);

    expect(looks.spawnDecision(USER, entry, 0)).toBe('wait');
    expect(looks.spawnDecision(USER, entry, TIMINGS.customMinionWaitMs)).toBe('default');
  });

  test('draws with the custom minions once they are in, and from every default for a player with no pool', async () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a']));
    looks.loadPool(USER, entry);
    await settle('a');

    expect(looks.spawnDecision(USER, entry, 0)).toBe('custom');
    expect(looks.pickSpawnType(USER, entry).key).toBe(keyOf('a'));

    entry.minionPool = null;
    const picks = [...Array(30)].map(() => looks.pickSpawnType(USER, entry).key);
    expect(picks.every(key => DEFAULT_KEYS.includes(key))).toBe(true);
  });

  test('never draws a custom minion that is not in, nor a default the pool dropped', async () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a', 'b'], ['demon-a']));
    looks.loadPool(USER, entry);
    await settle('a');

    const picks = new Set([...Array(60)].map(() => looks.pickSpawnType(USER, entry).key));

    expect([...picks].every(key => ['demon-a', keyOf('a')].includes(key))).toBe(true);
  });

  test('a pool that keeps no default and has nothing in yet draws from every default', () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a']));
    looks.loadPool(USER, entry);

    expect(DEFAULT_KEYS).toContain(looks.pickSpawnType(USER, entry).key);
  });

  test('a default look is awaiting a custom minion while one of the pool is not in', async () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a'], ['demon-a']));
    looks.loadPool(USER, entry);

    expect(looks.isAwaitingCustom(defaultType('demon-a'), USER, entry)).toBe(true);
    await settle('a');
    expect(looks.isAwaitingCustom(defaultType('demon-a'), USER, entry)).toBe(false);
  });

  test('a custom look is never awaiting anything', async () => {
    const { scene, looks } = setup();
    const entry = addFighter(scene, pool(1, ['a', 'b']));
    looks.loadPool(USER, entry);
    await settle('a');

    expect(looks.isAwaitingCustom({ key: keyOf('a'), custom: true }, USER, entry)).toBe(false);
  });
});

describe('a minion waiting for its first look', () => {
  /**
   * Holds one minion for a pool whose custom minion `a` is loading.
   *
   * @return {object}
   */
  function holdOne() {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    const minion = makeMinion({ typeKey: null, type: null, waitingForLook: true, waitStartedAt: env.scene.time.now });
    env.host.swarm.set(USER, [minion]);
    env.looks.loadPool(USER, entry);
    env.looks.holdForLook(USER, minion);

    return { ...env, entry, minion };
  }

  test('takes a look and starts its summon when the custom minion lands', async () => {
    const { host, minion } = holdOne();

    await settle('a');

    expect(minion.waitingForLook).toBe(false);
    expect(minion.typeKey).toBe(keyOf('a'));
    expect(minion.sprite.plays).toEqual([`${keyOf('a')}-idle`]);
    expect(host.summons).toEqual([minion]);
  });

  test('takes a default look when customMinionWaitMs runs out, flagged as awaiting the custom one', () => {
    const { host, minion } = holdOne();

    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs);

    expect(minion.waitingForLook).toBe(false);
    expect(DEFAULT_KEYS).toContain(minion.typeKey);
    expect(minion.awaitingCustom).toBe(true);
    expect(host.summons).toEqual([minion]);
  });

  test('stays hidden and waiting while the pool changes under it, and is never swapped', () => {
    const { scene, host, looks, entry, minion } = holdOne();
    entry.minionPool = pool(2, ['a', 'b']);

    looks.poolChanged(USER, entry);

    expect(minion.waitingForLook).toBe(true);
    expect(minion.typeKey).toBeNull();
    expect(minion.pendingLook).toBeNull();
    expect(host.poofs).toEqual([]);
    expect(scene.fighters.get(USER)).toBe(entry);
  });

  test('does nothing when its timer fires after the manager was destroyed', () => {
    const { host, looks, minion } = holdOne();

    looks.destroy();
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs * 2);

    expect(minion.typeKey).toBeNull();
    expect(host.summons).toEqual([]);
  });
});

describe('poolChanged', () => {
  /**
   * Puts `count` minions showing `typeKey` on the field for a fighter holding `minionPool`.
   *
   * @param {object} env
   * @param {object} minionPool
   * @param {string} typeKey A default look.
   * @param {number} count
   * @return {Array<object>}
   */
  function minionsShowing(env, minionPool, typeKey, count) {
    addFighter(env.scene, minionPool);
    const list = [...Array(count)].map(() => makeMinion({ typeKey, type: defaultType(typeKey) }));
    env.host.swarm.set(USER, list);

    return list;
  }

  test('puts every minion on the only look the new pool keeps', () => {
    const env = setup();
    const list = minionsShowing(env, pool(1, [], DEFAULT_KEYS), 'demon-a', 4);
    list[1].type = defaultType('smw-bowser');
    list[1].typeKey = 'smw-bowser';
    env.scene.fighters.get(USER).minionPool = pool(2, [], ['smw-bowser']);

    env.looks.poolChanged(USER, env.scene.fighters.get(USER));

    expect(list.map(minion => minion.typeKey)).toEqual(Array(4).fill('smw-bowser'));
    expect(env.host.poofs).toHaveLength(0); // reduced motion: no poof
  });

  test('swaps with a poof, once per minion that changes, and none for one that keeps its look', () => {
    const env = setup({ reducedMotion: false });
    const list = minionsShowing(env, pool(1, [], DEFAULT_KEYS), 'demon-a', 3);
    list[2].type = defaultType('smw-bowser');
    list[2].typeKey = 'smw-bowser';
    const entry = env.scene.fighters.get(USER);
    entry.minionPool = pool(2, [], ['smw-bowser']);

    env.looks.poolChanged(USER, entry);
    vi.advanceTimersByTime(1000);

    expect(env.host.poofs).toEqual([list[0], list[1]]);
    expect(list.map(minion => minion.typeKey)).toEqual(Array(3).fill('smw-bowser'));
    expect(list[2].pendingLook).toBeNull();
  });

  test('loads the custom minions of the new pool only for a player with minions out', () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, [], DEFAULT_KEYS));

    entry.minionPool = pool(2, ['a'], DEFAULT_KEYS);
    env.looks.poolChanged(USER, entry);
    expect(loadStackedType).not.toHaveBeenCalled();

    env.host.swarm.set(USER, [makeMinion({ typeKey: 'demon-a', type: defaultType('demon-a') })]);
    env.looks.poolChanged(USER, entry);
    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  test('a minion keeps what it shows while the pool offers nothing to change to, and moves when a look lands', async () => {
    const env = setup();
    const list = minionsShowing(env, pool(1, [], ['demon-a']), 'demon-a', 2);
    const entry = env.scene.fighters.get(USER);
    entry.minionPool = pool(2, ['a']);

    env.looks.poolChanged(USER, entry);
    expect(list.map(minion => minion.typeKey)).toEqual(['demon-a', 'demon-a']);

    await settle('a');
    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('a'), keyOf('a')]);
  });

  test('releases the look that left the pool once no minion shows it', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    const minion = makeMinion();
    env.host.swarm.set(USER, [minion]);
    env.looks.loadPool(USER, entry);
    await settle('a');
    expect(minion.typeKey).toBe(keyOf('a'));

    entry.minionPool = pool(2, [], ['demon-a']);
    env.looks.poolChanged(USER, entry);

    expect(minion.typeKey).toBe('demon-a');
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(env.looks.holds(USER, 'a')).toBe(false);
  });
});

describe('poolChanged with a custom look still loading', () => {
  const SWARM = 40; // enough that a whole swarm missing a look drawn evenly from three is out of the question

  /**
   * A swarm of `SWARM` minions out for a player whose pool holds the loaded custom look
   * `a` (and the default `demon-a` when `withDefault`), half of them showing each when
   * there are both, with the poofs of getting there already played.
   *
   * @param {boolean} withDefault
   * @return {{env: object, entry: object, list: Array<object>}}
   */
  async function swarmOnLoadedA(withDefault) {
    const env = setup({ reducedMotion: false });
    const entry = addFighter(env.scene, pool(1, ['a'], withDefault ? ['demon-a'] : []));
    const list = [...Array(SWARM)].map(() => makeMinion());
    env.host.swarm.set(USER, list);
    env.looks.loadPool(USER, entry);
    await settle('a');
    vi.advanceTimersByTime(1000);
    if (withDefault) {
      const typeA = env.looks.records.get(USER).get('a').type;
      list.forEach((minion, index) => {
        minion.type = index % 2 === 0 ? defaultType('demon-a') : typeA;
        minion.typeKey = minion.type.key;
      });
    }
    env.host.poofs.length = 0;

    return { env, entry, list };
  }

  const flags = list => list.map(minion => minion.awaitingCustom);
  const showing = (list, version) => list.filter(minion => minion.typeKey === keyOf(version));

  test('a swarm on look a draws again when a look b added by a newer pool lands, and poofs only the minions that change', async () => {
    const { env, entry, list } = await swarmOnLoadedA(false);
    entry.minionPool = pool(2, ['a', 'b']);

    env.looks.poolChanged(USER, entry);
    vi.advanceTimersByTime(1000);

    expect(env.host.poofs).toHaveLength(0);
    expect(list.every(minion => minion.typeKey === keyOf('a'))).toBe(true);
    expect(flags(list)).toEqual(Array(SWARM).fill(true));

    await settle('b');
    vi.advanceTimersByTime(1000);

    expect(showing(list, 'b').length).toBeGreaterThan(0);
    expect(showing(list, 'a').length).toBeGreaterThan(0);
    expect(env.host.poofs).toHaveLength(showing(list, 'b').length);
    expect(flags(list)).toEqual(Array(SWARM).fill(false));
  });

  test('minions on the default and on the loaded look both draw again at the newer pool and once more when the new look lands', async () => {
    const { env, entry, list } = await swarmOnLoadedA(true);
    entry.minionPool = pool(2, ['a', 'b'], ['demon-a']);

    env.looks.poolChanged(USER, entry);
    vi.advanceTimersByTime(1000);
    expect(flags(list)).toEqual(Array(SWARM).fill(true));
    expect(showing(list, 'b')).toHaveLength(0);

    await settle('b');
    vi.advanceTimersByTime(1000);

    expect(showing(list, 'b').length).toBeGreaterThan(0);
    expect(showing(list.filter((_, index) => index % 2 === 0), 'b').length).toBeGreaterThan(0); // the ones on the default too
    expect(showing(list.filter((_, index) => index % 2 === 1), 'b').length).toBeGreaterThan(0); // and the ones on a
    expect(flags(list)).toEqual(Array(SWARM).fill(false));
  });

  test('draws again at each look that lands, and the flag drops only once the whole pool is in', async () => {
    const { env, entry, list } = await swarmOnLoadedA(false);
    entry.minionPool = pool(2, ['a', 'b', 'c']);
    env.looks.poolChanged(USER, entry);

    await settle('b');
    vi.advanceTimersByTime(1000);
    expect(showing(list, 'b').length).toBeGreaterThan(0);
    expect(flags(list)).toEqual(Array(SWARM).fill(true));

    await settle('c');
    vi.advanceTimersByTime(1000);
    expect(showing(list, 'c').length).toBeGreaterThan(0);
    expect(flags(list)).toEqual(Array(SWARM).fill(false));
  });

  test('a look that fails to load changes nothing, not even at each retry, and the retry that lands draws once', async () => {
    const { env, entry, list } = await swarmOnLoadedA(false);
    entry.minionPool = pool(2, ['a', 'b']);
    env.looks.poolChanged(USER, entry);

    await settle('b', 'reject');
    vi.advanceTimersByTime(CUSTOM_RETRY_MS);
    await settle('b', 'reject');
    vi.advanceTimersByTime(CUSTOM_RETRY_MS);

    expect(env.host.poofs).toHaveLength(0);
    expect(list.every(minion => minion.typeKey === keyOf('a'))).toBe(true);
    expect(flags(list)).toEqual(Array(SWARM).fill(true));

    await settle('b');
    vi.advanceTimersByTime(1000);
    const changed = showing(list, 'b').length;
    expect(changed).toBeGreaterThan(0);
    expect(env.host.poofs).toHaveLength(changed);

    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 3);
    expect(env.host.poofs).toHaveLength(changed);
  });

  test('a look that lands after a newer pool dropped it changes nothing', async () => {
    const { env, entry, list } = await swarmOnLoadedA(false);
    entry.minionPool = pool(2, ['a', 'b']);
    env.looks.poolChanged(USER, entry);
    entry.minionPool = pool(3, ['a']);
    env.looks.poolChanged(USER, entry);
    expect(flags(list)).toEqual(Array(SWARM).fill(false));

    await settle('b');
    vi.advanceTimersByTime(1000);

    expect(env.host.poofs).toHaveLength(0);
    expect(list.every(minion => minion.typeKey === keyOf('a'))).toBe(true);
  });

  test('a minion waiting for its first look is not flagged or forced by the newer pool', async () => {
    const { env, entry, list } = await swarmOnLoadedA(false);
    list[0].waitingForLook = true;
    list[0].awaitingCustom = false;
    entry.minionPool = pool(2, ['a', 'b']);

    env.looks.poolChanged(USER, entry);

    expect(list[0].awaitingCustom).toBe(false);
    expect(list[0].pendingLook).toBeNull();
  });
});

describe('flushParked', () => {
  /**
   * A minion showing a default with a swap to `smw-bowser` parked on it.
   *
   * @param {object} env
   * @param {object} [extra]
   * @return {object}
   */
  function parked(env, extra = {}) {
    addFighter(env.scene, pool(1, [], ['smw-bowser']));
    const minion = makeMinion({ typeKey: 'demon-a', type: defaultType('demon-a'), pendingLook: defaultType('smw-bowser'), ...extra });
    env.host.swarm.set(USER, [minion]);

    return minion;
  }

  test.each(['summoning', 'fidgeting', 'fighting', 'swapping', 'waitingForLook'])('keeps the swap parked while the minion is %s', (busy) => {
    const env = setup();
    const minion = parked(env, { [busy]: true });

    env.looks.flushParked(minion);

    expect(minion.typeKey).toBe('demon-a');
    expect(minion.pendingLook).not.toBeNull();
    expect(env.host.poofs).toEqual([]);
  });

  test('applies the swap at once under reduced motion', () => {
    const env = setup();
    const minion = parked(env);

    env.looks.flushParked(minion);

    expect(minion.typeKey).toBe('smw-bowser');
    expect(minion.pendingLook).toBeNull();
  });

  test('plays the swap poof first, and the look lands when it ends', () => {
    const env = setup({ reducedMotion: false });
    const minion = parked(env);

    env.looks.flushParked(minion);
    expect(minion.swapping).toBe(true);
    expect(minion.typeKey).toBe('demon-a');
    vi.advanceTimersByTime(300);

    expect(minion.swapping).toBe(false);
    expect(minion.typeKey).toBe('smw-bowser');
  });

  test('a poof that ends after destroy() changes nothing', () => {
    const env = setup({ reducedMotion: false });
    const minion = parked(env);
    env.looks.flushParked(minion);

    env.looks.destroy();
    vi.advanceTimersByTime(300);

    expect(minion.typeKey).toBe('demon-a');
  });

  test('a poof that ends after the sprite is gone drops the look', () => {
    const env = setup({ reducedMotion: false });
    const minion = parked(env);
    env.looks.flushParked(minion);

    minion.sprite.active = false;
    vi.advanceTimersByTime(300);

    expect(minion.typeKey).toBe('demon-a');
    expect(minion.pendingLook).toBeNull();
  });
});

describe('releaseUnused', () => {
  test('keeps a look in the pool with no minion out, and a look a minion still shows or has parked', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a', 'b']));
    env.looks.loadPool(USER, entry);
    await settle('a');
    await settle('b');

    env.looks.releaseUnused(USER);
    expect(env.scene.textures.remove).not.toHaveBeenCalled();

    entry.minionPool = pool(2, [], ['demon-a']);
    env.host.swarm.set(USER, [makeMinion({ type: { key: keyOf('a') } }), makeMinion({ pendingLook: { key: keyOf('b') } })]);
    env.looks.releaseUnused(USER);

    expect(env.scene.textures.remove).not.toHaveBeenCalled();
    expect(env.looks.holds(USER, 'a')).toBe(true);
  });

  test('releases a look that left the pool, and every look when the fighter left', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a', 'b']));
    env.looks.loadPool(USER, entry);
    await settle('a');
    await settle('b');

    entry.minionPool = pool(2, ['a']);
    env.looks.releaseUnused(USER);
    expect(env.scene.textures.remove).toHaveBeenCalledTimes(1);
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('b'));

    env.looks.releaseUnused(USER, true);
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(env.looks.holdsAny(USER)).toBe(false);
  });

  test('leaves a look that is still loading alone, and releases it when it lands', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.host.swarm.set(USER, [makeMinion({ typeKey: 'demon-a', type: defaultType('demon-a') })]);
    env.looks.loadPool(USER, entry);

    entry.minionPool = pool(2, [], ['demon-a']);
    env.looks.releaseUnused(USER);
    expect(env.scene.textures.remove).not.toHaveBeenCalled();

    await settle('a');
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('stops retrying a failed look that left the pool', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.host.swarm.set(USER, [makeMinion({ typeKey: 'demon-a', type: defaultType('demon-a') })]);
    env.looks.loadPool(USER, entry);
    await settle('a', 'reject');

    entry.minionPool = pool(2, [], ['demon-a']);
    env.looks.releaseUnused(USER);
    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 2);

    expect(loadStackedType).toHaveBeenCalledTimes(1);
    expect(env.looks.holdsAny(USER)).toBe(false);
  });
});

describe('destroy and a restarted scene', () => {
  test('lets go of the looks of a player with no minion out, and keeps those of a player whose swarm is out', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.looks.loadPool(USER, entry);
    await settle('a');

    env.looks.destroy();
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));

    const other = setup();
    const otherEntry = addFighter(other.scene, pool(1, ['a']));
    other.host.swarm.set(USER, [makeMinion()]);
    other.looks.loadPool(USER, otherEntry);
    await settle('a');

    other.looks.destroy();

    expect(other.scene.textures.remove).not.toHaveBeenCalled();
    expect(other.looks.holdsAny(USER)).toBe(false);
  });

  test('a load that lands after destroy() touches no state and drops its texture', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    const minion = makeMinion({ typeKey: null, type: null, waitingForLook: true });
    env.host.swarm.set(USER, [minion]);
    env.looks.loadPool(USER, entry);

    env.looks.destroy();
    await settle('a');

    expect(minion.typeKey).toBeNull();
    expect(env.host.summons).toEqual([]);
    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('a load that lands while the scene is shutting down drops its texture', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.looks.loadPool(USER, entry);

    env.scene.isShuttingDown = true;
    await settle('a');

    expect(env.scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('keeps the texture a load added when the scene\'s new manager already asked for the same look', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.host.swarm.set(USER, [makeMinion()]);
    env.looks.loadPool(USER, entry);
    env.looks.destroy();

    const fresh = new MinionLooks(env.scene, env.host);
    env.scene.minions = { looks: fresh };
    fresh.loadPool(USER, entry);
    loads[0].resolve(loads[0].type);
    await flush();

    expect(env.scene.textures.remove).not.toHaveBeenCalled();
  });

  test('drops the texture when the scene\'s manager is this one, or has not asked for the look', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.looks.loadPool(USER, entry);
    env.scene.minions = { looks: env.looks };
    env.looks.destroy();
    await settle('a');

    expect(env.scene.textures.remove).toHaveBeenCalledTimes(1);

    const bare = setup();
    const bareEntry = addFighter(bare.scene, pool(1, ['a']));
    bare.looks.loadPool(USER, bareEntry);
    bare.scene.minions = { looks: new MinionLooks(bare.scene, bare.host) };
    bare.looks.destroy();
    await settle('a');

    expect(bare.scene.textures.remove).toHaveBeenCalledTimes(1);
  });

  test('a failed look does not retry after destroy()', async () => {
    const env = setup();
    const entry = addFighter(env.scene, pool(1, ['a']));
    env.host.swarm.set(USER, [makeMinion()]);
    env.looks.loadPool(USER, entry);
    await settle('a', 'reject');

    env.looks.destroy();
    vi.advanceTimersByTime(CUSTOM_RETRY_MS * 2);

    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });
});
