import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({
  default: {
    Math: {
      Between: (min) => min,
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
import { customMinionKey } from '@battlefield/minion-custom.js';
import { Minions } from '@battlefield/minions.js';

const DEFAULT_KEYS = MINION_TYPES.map(type => type.key);
const USER = 7;
const ANIMATIONS = {
  idle: { frames: 6, rate: 8 },
  walk: { frames: 8, rate: 16 },
  attack: { frames: 6, rate: 12 },
  death: { frames: 3, rate: 6 },
};

/**
 * One minion as the wire carries it.
 *
 * @param {string} version
 * @return {object}
 */
const wire = version => ({ version, url_base: `/storage/custom-minions/${USER}/${version}`, animations: ANIMATIONS, body_px: 22 });

/**
 * The same minion as a fighter entry stores it.
 *
 * @param {string} version
 * @return {object}
 */
const stored = version => ({ version, urlBase: `/storage/custom-minions/${USER}/${version}`, animations: ANIMATIONS, bodyPx: 22 });

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
    reversed: [],
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
    playReverse: (key) => { sprite.reversed.push(key); return sprite; },
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
 * A scene fake with real (fake-timer) delays, and tweens that finish at once.
 *
 * @param {{reducedMotion?: boolean}} [options]
 * @return {object}
 */
function makeScene({ reducedMotion = true } = {}) {
  return {
    reducedMotion,
    isShuttingDown: false,
    fighters: new Map(),
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
      image: (x, y, texture) => makeSprite(x, y, texture),
      particles: () => ({ scene: {}, explode() {}, destroy() {}, setDepth() { return this; } }),
    },
    addSharpText: () => makeSprite(),
  };
}

/**
 * Puts a fighter on the field.
 *
 * @param {object} scene
 * @param {string[]} versions The versions of its custom minion set.
 * @return {object} The fighter entry.
 */
function addFighter(scene, versions = []) {
  const entry = { baseSize: 48, legH: 6, sprite: { x: 100, y: 100, scaleX: 1, active: true }, customMinions: versions.map(stored) };
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
 * Builds a manager with its fidget and idle wander timers off (these tests are about looks).
 *
 * @param {object} scene
 * @param {boolean} [quiet]
 * @return {Minions}
 */
function makeMinions(scene, quiet = true) {
  const minions = new Minions(scene);
  if (quiet) {
    vi.spyOn(minions, '_scheduleFidget').mockImplementation(() => {});
    vi.spyOn(minions, '_scheduleIdleWander').mockImplementation(() => {});
  }

  return minions;
}

/**
 * Makes the Clawd poof finish at once, and records where it played.
 *
 * @param {Minions} minions
 * @return {import('vitest').MockInstance}
 */
function spyOnPoof(minions) {
  return vi.spyOn(minions, '_playClawdPoof').mockImplementation((x, y, scale, onDone) => onDone());
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
  vi.advanceTimersByTime(500);

  return minions.byUser.get(USER);
}

beforeEach(() => {
  vi.useFakeTimers();
  loads = [];
  loadStackedType.mockReset();
  loadStackedType.mockImplementation((scene, type) => new Promise((resolve, reject) => {
    loads.push({ type, resolve, reject });
  }));
  vi.spyOn(Math, 'random').mockReturnValue(0);
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('spawning with a custom set', () => {
  test('spawns only custom looks once the set is loaded', async () => {
    const scene = makeScene();
    addFighter(scene, ['a', 'b']);
    const minions = makeMinions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    await settle('b');
    const list = await setCount(minions, 4);

    expect(list).toHaveLength(4);
    expect(loadStackedType).toHaveBeenCalledTimes(2);
    expect(list.every(minion => minion.typeKey.startsWith('minion-7-'))).toBe(true);
    expect(list.every(minion => minion.type.custom === true && minion.charHeight === 22)).toBe(true);
    expect(list[0].sprite.texture.key).toBe(keyOf('a'));
  });

  test('waits for a loading set and summons the custom look', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const minion = minions.byUser.get(USER)[0];

    expect(minion.typeKey).toBeNull();
    expect(minion.summoning).toBe(true);
    vi.advanceTimersByTime(500);
    expect(scene.necromancer.spawnSummonCircle).not.toHaveBeenCalled();
    expect(minion.sprite.plays).toEqual([]);

    await settle('a');
    vi.advanceTimersByTime(500);

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(scene.necromancer.spawnSummonCircle).toHaveBeenCalledTimes(1);
    expect(minion.summoning).toBe(false);
    expect(minion.sprite.plays.every(key => key.startsWith('minion-7-'))).toBe(true);
    expect(minion.sprite.plays.some(key => DEFAULT_KEYS.some(def => key.startsWith(def)))).toBe(false);
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs);
    expect(minion.typeKey).toBe(keyOf('a'));
  });

  test('falls back to a default after customMinionWaitMs and swaps when the look lands', async () => {
    const scene = makeScene({ reducedMotion: false });
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    const minion = minions.byUser.get(USER)[0];
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs - 1);
    expect(minion.typeKey).toBeNull();
    vi.advanceTimersByTime(1);

    expect(DEFAULT_KEYS).toContain(minion.typeKey);
    expect(minion.awaitingCustom).toBe(true);
    expect(poof).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(500);
    expect(minion.summoning).toBe(false);

    await settle('a');

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(minion.awaitingCustom).toBe(false);
    expect(poof).toHaveBeenCalledTimes(2);
    expect(minion.sprite.plays.at(-1)).toBe(`${keyOf('a')}-idle`);
  });

  test('keeps random default types for a player without a set', async () => {
    const scene = makeScene();
    addFighter(scene, []);
    const minions = makeMinions(scene);

    const list = await setCount(minions, 3);

    expect(list).toHaveLength(3);
    expect(loadStackedType).not.toHaveBeenCalled();
    expect(list.every(minion => DEFAULT_KEYS.includes(minion.typeKey))).toBe(true);
    expect(list.every(minion => minion.awaitingCustom === false)).toBe(true);
    expect(list[0].sprite.plays[0]).toBe(`${list[0].typeKey}-idle`);
  });

  test('a failed load stays on defaults and retries after CUSTOM_RETRY_MS', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a', 'reject');
    vi.advanceTimersByTime(500);
    const minion = minions.byUser.get(USER)[0];

    expect(DEFAULT_KEYS).toContain(minion.typeKey);
    expect(minion.awaitingCustom).toBe(true);

    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    expect(loadStackedType).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(CUSTOM_RETRY_MS);
    minions.handleAgentCountChanged({ user_id: USER, count: 3 });
    expect(loadStackedType).toHaveBeenCalledTimes(2);

    await settle('a');
    vi.advanceTimersByTime(500);

    expect(minions.byUser.get(USER).every(item => item.typeKey === keyOf('a'))).toBe(true);
  });
});

describe('handleMinionsChanged', () => {
  test('ignores an unchanged set', async () => {
    const scene = makeScene({ reducedMotion: false });
    const entry = addFighter(scene, ['a', 'b']);
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(500);
    const loadCalls = loadStackedType.mock.calls.length;
    const storedSet = entry.customMinions;
    poof.mockClear();

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b'), wire('a')] });
    await flush();

    expect(loadStackedType).toHaveBeenCalledTimes(loadCalls);
    expect(poof).not.toHaveBeenCalled();
    expect(entry.customMinions).toBe(storedSet);
  });

  test.each([
    ['a missing key', { user_id: USER }],
    ['undefined', { user_id: USER, custom_minions: undefined }],
    ['null', { user_id: USER, custom_minions: null }],
    ['an object', { user_id: USER, custom_minions: {} }],
  ])('ignores a missing custom_minions (%s)', async (_, payload) => {
    const scene = makeScene({ reducedMotion: false });
    const entry = addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    vi.advanceTimersByTime(500);
    poof.mockClear();
    const storedSet = entry.customMinions;

    minions.handleMinionsChanged(payload);
    await flush();

    expect(entry.customMinions).toBe(storedSet);
    expect(minions.byUser.get(USER)[0].typeKey).toBe(keyOf('a'));
    expect(poof).not.toHaveBeenCalled();
    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  test('ignores a fighter that is not on the field', () => {
    const scene = makeScene();
    const minions = makeMinions(scene);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('a')] });

    expect(loadStackedType).not.toHaveBeenCalled();
    expect(minions.looks.size).toBe(0);
  });

  test('stores the set, and loads nothing while the player has no minions out', async () => {
    const scene = makeScene();
    const entry = addFighter(scene, []);
    const minions = makeMinions(scene);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('a')] });
    await flush();

    expect(entry.customMinions).toEqual([stored('a')]);
    expect(loadStackedType).not.toHaveBeenCalled();
  });

  test('loads a look added while minions are out', async () => {
    const scene = makeScene();
    addFighter(scene, []);
    const minions = makeMinions(scene);
    await setCount(minions, 2);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('a')] });
    expect(loadStackedType).toHaveBeenCalledTimes(1);
    await settle('a');
    vi.advanceTimersByTime(500);

    const list = minions.byUser.get(USER);
    expect(list).toHaveLength(2);
    expect(list.every(minion => DEFAULT_KEYS.includes(minion.typeKey))).toBe(true);
    await setCount(minions, 3);
    expect(minions.byUser.get(USER)[2].typeKey).toBe(keyOf('a'));
  });
});

describe('swapping looks', () => {
  /**
   * A fighter with two custom looks loaded and `count` minions out, all showing `a`.
   *
   * @param {object} scene
   * @param {Minions} minions
   * @param {number} count
   * @return {Promise<Array<object>>}
   */
  async function outWithTwoLooks(scene, minions, count) {
    addFighter(scene, ['a', 'b']);
    minions.handleAgentCountChanged({ user_id: USER, count });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(500);

    return minions.byUser.get(USER);
  }

  test('swaps a removed look to another member, or to a default when the set is empty', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene);
    const list = await outWithTwoLooks(scene, minions, 3);
    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('a'), keyOf('a'), keyOf('a')]);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    expect(list.map(minion => minion.typeKey)).toEqual([keyOf('b'), keyOf('b'), keyOf('b')]);
    expect(list[0].type.key).toBe(keyOf('b'));
    expect(list[0].sprite.textures.at(-1)).toEqual({ key: keyOf('b'), frame: `${keyOf('b')}-idle-0` });
    expect(list[0].sprite.plays.at(-1)).toBe(`${keyOf('b')}-idle`);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [] });

    expect(list.every(minion => DEFAULT_KEYS.includes(minion.typeKey))).toBe(true);
    expect(list[0].type).toBe(MINION_TYPES.find(type => type.key === list[0].typeKey));
    expect(list[0].charHeight).toBe(list[0].type.charHeight);
    expect(list[0].sprite.textures.at(-1)).toEqual({ key: `${list[0].typeKey}-idle`, frame: undefined });
    expect(list[0].awaitingCustom).toBe(false);
  });

  test('plays the poof before a swap lands, and none under reduced motion', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);
    const list = await outWithTwoLooks(scene, minions, 1);
    poof.mockClear();
    let finishPoof;
    poof.mockImplementation((x, y, scale, onDone) => { finishPoof = onDone; });

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    expect(poof).toHaveBeenCalledTimes(1);
    expect(list[0].typeKey).toBe(keyOf('a'));
    finishPoof();
    expect(list[0].typeKey).toBe(keyOf('b'));

    scene.reducedMotion = true;
    poof.mockClear();
    minions.handleMinionsChanged({ user_id: USER, custom_minions: [] });

    expect(poof).not.toHaveBeenCalled();
    expect(DEFAULT_KEYS).toContain(list[0].typeKey);
  });

  test('a minion mid-poof takes no fight, and lands the newest look', async () => {
    const scene = makeScene({ reducedMotion: false });
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);
    const [minion] = await outWithTwoLooks(scene, minions, 1);
    let finishPoof;
    poof.mockClear();
    poof.mockImplementation((x, y, scale, onDone) => { finishPoof = onDone; });

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });
    expect(minion.swapping).toBe(true);
    expect(minions._isFightEligible(minion, Infinity)).toBe(false);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [] });
    expect(poof).toHaveBeenCalledTimes(1);
    finishPoof();

    expect(minion.swapping).toBe(false);
    expect(DEFAULT_KEYS).toContain(minion.typeKey);
    expect(minions._isFightEligible(minion, Infinity)).toBe(true);
  });

  test('parks a swap during a fight until _endFightRole', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene);
    const [attacker, victim] = await outWithTwoLooks(scene, minions, 2);
    minions._performAttack(attacker, victim);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    expect(attacker.typeKey).toBe(keyOf('a'));
    expect(attacker.pendingLook.key).toBe(keyOf('b'));
    expect(scene.textures.remove).not.toHaveBeenCalled();

    minions._endFightRole(attacker);

    expect(attacker.typeKey).toBe(keyOf('b'));
    expect(attacker.pendingLook).toBeNull();
    expect(victim.typeKey).toBe(keyOf('a'));
    expect(scene.textures.remove).not.toHaveBeenCalled();

    minions._endFightRole(victim);

    expect(victim.typeKey).toBe(keyOf('b'));
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });

  test('parks a swap during the summon until it ends', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene);
    addFighter(scene, ['a', 'b']);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    await settle('b');
    const minion = minions.byUser.get(USER)[0];
    expect(minion.summoning).toBe(true);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    expect(minion.typeKey).toBe(keyOf('a'));
    expect(minion.pendingLook.key).toBe(keyOf('b'));
    vi.advanceTimersByTime(500);

    expect(minion.typeKey).toBe(keyOf('b'));
    expect(minion.pendingLook).toBeNull();
  });

  test('applies a parked swap at the end of a fidget', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene, false);
    vi.spyOn(minions, '_scheduleIdleWander').mockImplementation(() => {});
    const [minion] = await outWithTwoLooks(scene, minions, 1);
    vi.advanceTimersByTime(3000);
    expect(minion.fidgeting).toBe(true);
    expect(minion.sprite.plays.at(-1)).toBe(`${keyOf('a')}-attack`);

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });
    expect(minion.typeKey).toBe(keyOf('a'));
    minion.sprite.emit('animationcomplete');

    expect(minion.fidgeting).toBe(false);
    expect(minion.typeKey).toBe(keyOf('b'));
    expect(minion.sprite.plays.at(-1)).toBe(`${keyOf('b')}-idle`);
  });

  test('re-picks a parked look that left the set too', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene);
    const [attacker, victim] = await outWithTwoLooks(scene, minions, 2);
    minions._performAttack(attacker, victim);
    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });
    expect(attacker.pendingLook.key).toBe(keyOf('b'));

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [] });

    expect(DEFAULT_KEYS).toContain(attacker.pendingLook.key);
    minions._endFightRole(attacker);
    expect(DEFAULT_KEYS).toContain(attacker.typeKey);
  });
});

describe('releasing looks', () => {
  test('releases a removed look once no minion shows it', async () => {
    const scene = makeScene();
    const minions = makeMinions(scene);
    addFighter(scene, ['a', 'b']);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(500);
    const [first, second] = minions.byUser.get(USER);
    second.fighting = true;

    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    expect(first.typeKey).toBe(keyOf('b'));
    expect(scene.textures.remove).not.toHaveBeenCalled();

    minions._endFightRole(second);

    expect(scene.textures.remove).toHaveBeenCalledTimes(1);
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(scene.anims.remove).toHaveBeenCalledWith(`${keyOf('a')}-idle`);
    expect(scene.anims.remove).toHaveBeenCalledWith(`${keyOf('a')}-death`);
    expect(minions.looks.get(USER).has('a')).toBe(false);
    expect(minions.looks.get(USER).has('b')).toBe(true);
  });

  test("releases the player's looks when the count drops to 0", async () => {
    const scene = makeScene();
    const entry = addFighter(scene, ['a', 'b']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(500);

    minions.handleAgentCountChanged({ user_id: USER, count: 0 });

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('b'));
    expect(entry.customMinions).toHaveLength(2);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    expect(loadStackedType).toHaveBeenCalledTimes(4);
  });

  test('keeps the looks while some minions are still out', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 3 });
    await settle('a');
    vi.advanceTimersByTime(500);

    minions.handleAgentCountChanged({ user_id: USER, count: 1 });

    expect(scene.textures.remove).not.toHaveBeenCalled();
  });

  test('releases the looks when the fighter leaves (despawnAll)', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    await settle('a');
    vi.advanceTimersByTime(500);

    minions.despawnAll(USER);

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
    expect(minions.byUser.has(USER)).toBe(false);
  });

  test('a look that lands after nothing wants it is released at once', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    minions.handleAgentCountChanged({ user_id: USER, count: 0 });
    expect(scene.textures.remove).not.toHaveBeenCalled();

    await settle('a');

    expect(scene.textures.remove).toHaveBeenCalledWith(keyOf('a'));
  });
});

describe('scene shutdown', () => {
  test('a load that lands after destroy() touches nothing', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 2 });
    const [first] = minions.byUser.get(USER);
    const release = vi.spyOn(minions, '_releaseUnusedLooks');
    const resolveWaiting = vi.spyOn(minions, '_resolveWaiting');

    minions.destroy();
    scene.isShuttingDown = false; // scene.create() re-arms the flag on a restart
    await settle('a');
    vi.advanceTimersByTime(TIMINGS.customMinionWaitMs * 2);

    expect(release).not.toHaveBeenCalled();
    expect(resolveWaiting).not.toHaveBeenCalled();
    expect(first.typeKey).toBeNull();
    expect(first.sprite.plays).toEqual([]);
    expect(first.sprite.textures).toEqual([]);
    expect(scene.necromancer.spawnSummonCircle).not.toHaveBeenCalled();
    expect(scene.textures.remove).not.toHaveBeenCalled();
    expect(loadStackedType).toHaveBeenCalledTimes(1);
  });

  test('destroy() keeps the textures, and a restarted manager asks for them again', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const old = makeMinions(scene);
    old.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    vi.advanceTimersByTime(500);

    old.destroy();

    expect(scene.textures.remove).not.toHaveBeenCalled();
    expect(scene.anims.remove).not.toHaveBeenCalled();

    const fresh = makeMinions(scene);
    fresh.handleAgentCountChanged({ user_id: USER, count: 1 });
    expect(loadStackedType).toHaveBeenCalledTimes(2);
    await settle('a');
    vi.advanceTimersByTime(500);

    expect(fresh.byUser.get(USER)[0].typeKey).toBe(keyOf('a'));
  });

  test('a load that lands while the scene is shutting down touches nothing', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });

    scene.isShuttingDown = true;
    await settle('a');

    expect(minions.byUser.get(USER)[0].typeKey).toBeNull();
    expect(scene.textures.remove).not.toHaveBeenCalled();
  });

  test('a swap poof that ends after the sprite is gone changes nothing', async () => {
    const scene = makeScene({ reducedMotion: false });
    addFighter(scene, ['a', 'b']);
    const minions = makeMinions(scene);
    const poof = spyOnPoof(minions);
    minions.handleAgentCountChanged({ user_id: USER, count: 1 });
    await settle('a');
    await settle('b');
    vi.advanceTimersByTime(500);
    const [minion] = minions.byUser.get(USER);
    let finishPoof;
    poof.mockImplementation((x, y, scale, onDone) => { finishPoof = onDone; });
    minions.handleMinionsChanged({ user_id: USER, custom_minions: [wire('b')] });

    minion.sprite.destroy();
    finishPoof();

    expect(minion.typeKey).toBe(keyOf('a'));
  });
});

describe('fighting as a custom minion', () => {
  test('a custom minion attacks with hit frame 4 and falls with the death strip held 2 s', async () => {
    const scene = makeScene();
    addFighter(scene, ['a']);
    const minions = makeMinions(scene);
    minions.handleAgentCountChanged({ user_id: USER, count: 3 });
    await settle('a');
    vi.advanceTimersByTime(500);
    const [attacker, target, faller] = minions.byUser.get(USER);
    scene.time.delayedCall.mockClear();

    minions._performAttack(attacker, target);

    expect(attacker.sprite.plays.at(-1)).toBe(`${keyOf('a')}-attack`);
    const delays = scene.time.delayedCall.mock.calls.map(([ms]) => ms);
    expect(delays).toContain(4 * (1000 / 12));
    expect(delays).toContain(6 * (1000 / 12) + 250);

    faller.fighting = true;
    scene.time.delayedCall.mockClear();
    minions._playReaction(faller);

    expect(faller.sprite.plays.at(-1)).toBe(`${keyOf('a')}-death`);
    faller.sprite.emit('animationcomplete');
    expect(scene.time.delayedCall.mock.calls.map(([ms]) => ms)).toEqual([2000]);
    expect(faller.sprite.reversed).toEqual([]);
    vi.advanceTimersByTime(2000);
    expect(faller.sprite.reversed).toEqual([`${keyOf('a')}-death`]);
    faller.sprite.emit('animationcomplete');
    expect(faller.fighting).toBe(false);
  });
});
