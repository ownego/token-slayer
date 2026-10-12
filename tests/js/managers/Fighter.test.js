import { describe, expect, test, vi } from 'vitest';

// Fighter imports Boss (for clamp/validate ctx) which imports Phaser (via leaderboard → bus). Provide minimal stubs.
vi.mock('phaser', () => ({
  default: {
    Events: {
      EventEmitter: class {
        on() {}
        off() {}
        emit() {}
        once() {}
      },
    },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete', ANIMATION_REPEAT: 'animationrepeat' } },
  },
}));

vi.mock('@battlefield/fighter/flair-font.js', () => ({
  FLAIR_FONT_FAMILY: 'x', FLAIR_FONT_WEIGHT: 400, ensureFlairFont() {}, isFlairFontReady: () => true,
}));

vi.mock('@battlefield/fighter/custom-fighter-loader.js', () => ({ loadCustomFighter: vi.fn() }));

import { Fighter } from '@battlefield/fighter.js';
import { LAYOUTS } from '@battlefield/config.js';

describe('fighterRestScale', () => {
  test('returns 1.0 for base-size fighter with no damage scale', () => {
    const fighter = { displaySize: 48, baseSize: 48, damageScale: 1 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.0);
  });

  test('scales up when displaySize is larger than baseSize', () => {
    const fighter = { displaySize: 96, baseSize: 48, damageScale: 1 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(2.0);
  });

  test('applies damageScale as a multiplier', () => {
    const fighter = { displaySize: 48, baseSize: 48, damageScale: 1.5 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.5);
  });

  test('defaults damageScale to 1 when undefined', () => {
    const fighter = { displaySize: 48, baseSize: 48 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.0);
  });

  test('combines displaySize ratio and damageScale', () => {
    const fighter = { displaySize: 60, baseSize: 40, damageScale: 2 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(3.0);
  });
});

describe('handleFighterMoved', () => {
  // A melee attack (blade) tweens the sprite INTO the boss column and only
  // queues its return tween once the dash finishes. A FighterMoved landing
  // mid-dash kills that dash, so the move must not plan from the sprite's
  // transient in-boss position — planRoute can't leave a blocked origin and
  // the fighter used to stay parked on the boss until a full reload.
  const makeScene = () => {
    const added = [];
    return {
      added,
      scene: {
        layout: LAYOUTS.landscape,
        bossState: { number: 0 },
        currentUserId: 99,
        fighters: new Map(),
        charges: new Map(),
        tweens: { killTweensOf() {}, add(cfg) { added.push(cfg); return cfg; } },
      },
    };
  };

  test('walks out to the target when a move lands while the sprite is mid-dash inside the boss column', () => {
    const { scene, added } = makeScene();
    const entry = {
      id: 1,
      sprite: { x: 480, y: 200, active: true, scaleX: 1 },
      pos: { x: 300, y: 460 },
      displaySize: 48,
      damageScale: 1,
    };
    scene.fighters.set(1, entry);

    new Fighter(scene).handleFighterMoved({ user_id: 1, x: 700 / 960, y: 460 / 540 });

    const spriteTween = added.find(t => t.targets === entry.sprite);
    expect(spriteTween).toBeDefined();
    // Drain the route: each leg queues the next from its onComplete.
    let last = spriteTween;
    while (last) {
      entry.sprite.x = last.x;
      entry.sprite.y = last.y;
      const before = added.length;
      last.onComplete();
      last = added.slice(before).find(t => t.targets === entry.sprite);
    }
    expect(entry.pos.x).toBeCloseTo(700, 0);
    expect(entry.pos.y).toBeCloseTo(460, 0);
  });
});

describe('handleHit', () => {
  // A second HitDealt landing while the first hit's blade dash has the sprite
  // inside the boss column used to record that spot as the fighter's home;
  // the new attack then "returned" there and every later move planned from it,
  // parking the fighter on the boss until a reload.
  test('keeps the resting home when a hit lands while the sprite is mid-dash inside the boss column', () => {
    let dispatchedFrom = null;
    const entry = {
      id: 1,
      sprite: { x: 480, y: 200, active: true, scaleX: 1 },
      pos: { x: 300, y: 460 },
      displaySize: 48,
      damageScale: 1,
    };
    const scene = {
      layout: LAYOUTS.landscape,
      bossState: { number: 0, currentHp: 1000, maxHp: 1000 },
      fighters: new Map([[1, entry]]),
      charges: new Map(),
      time: { now: 0, delayedCall() {} },
      tweens: { killTweensOf() {}, add(cfg) { return cfg; } },
      attacks: { dispatch(_type, fighter) { dispatchedFrom = { ...fighter.pos }; } },
    };

    new Fighter(scene).handleHit({ user_id: 1, damage: 0, boss_hp_after: 990 });

    expect(entry.pos).toEqual({ x: 300, y: 460 });
    expect(dispatchedFrom).toEqual({ x: 300, y: 460 });
  });

  test('still picks up the live position when a hit interrupts an ordinary walk', () => {
    const entry = {
      id: 1,
      sprite: { x: 650, y: 460, active: true, scaleX: 1 },
      pos: { x: 300, y: 460 },
      displaySize: 48,
      damageScale: 1,
    };
    const scene = {
      layout: LAYOUTS.landscape,
      bossState: { number: 0, currentHp: 1000, maxHp: 1000 },
      fighters: new Map([[1, entry]]),
      charges: new Map(),
      time: { now: 0, delayedCall() {} },
      tweens: { killTweensOf() {}, add(cfg) { return cfg; } },
      attacks: { dispatch() {} },
    };

    new Fighter(scene).handleHit({ user_id: 1, damage: 0, boss_hp_after: 990 });

    expect(entry.pos).toEqual({ x: 650, y: 460 });
  });

  // The HUD board (hud/board-view.js, via rankBoard) reads scene.damageTotals
  // directly now that the Phaser leaderboard is gone — a hitter with no
  // fighter currently on the field (e.g. joined via hook mid-fight before
  // their sprite spawned) must still count, or the board silently disagrees
  // with the real damage dealt.
  test('a hit from someone without a fighter on the field still counts toward the boss totals', () => {
    const scene = {
      layout: LAYOUTS.landscape,
      bossState: { number: 0, currentHp: 1000, maxHp: 1000 },
      fighters: new Map(),
      charges: new Map(),
      damageTotals: new Map(),
      time: { now: 0, delayedCall() {} },
      tweens: { killTweensOf() {}, add(cfg) { return cfg; } },
    };

    new Fighter(scene).handleHit({ user_id: 99, damage: 500, boss_hp_after: 500 });

    expect(scene.damageTotals.get(99)).toBe(500);
  });
});

describe('updateCharacters', () => {
  // The fighter sheet's equip lands on the field the way the approved
  // mockup shows it: the old fighter dies, a summon circle opens, the new
  // one rises — not a silent texture swap.
  const makeSwapScene = ({ reducedMotion = false } = {}) => {
    const onceHandlers = [];
    const body = {
      play: vi.fn(),
      setTexture: vi.fn(),
      once: vi.fn((event, cb) => onceHandlers.push(cb)),
    };
    const entry = { id: 1, ftype: { key: 'soldier' }, pos: { x: 100, y: 200 }, body, animState: 'idle' };
    const scene = {
      fighters: new Map([[1, entry]]),
      anims: { exists: () => true, get: () => ({ frames: [1, 2, 3] }) },
      necromancer: { spawnSummonCircle: vi.fn() },
      reducedMotion,
      isShuttingDown: false,
    };

    return { scene, entry, body, finishAnim: () => onceHandlers.shift()?.() };
  };

  test('plays the old fighter\'s death, then a summon circle and the new fighter rising, then idle', () => {
    const { scene, entry, body, finishAnim } = makeSwapScene();
    const fighter = new Fighter(scene);

    fighter.updateCharacters([{ user_id: 1, character: 'wizard' }], { animate: true });
    expect(body.play).toHaveBeenLastCalledWith('soldier-death');
    expect(body.setTexture).not.toHaveBeenCalled();

    finishAnim();
    expect(scene.necromancer.spawnSummonCircle).toHaveBeenCalledWith(100, 200);
    expect(entry.ftype.key).toBe('wizard');
    expect(body.play).toHaveBeenLastCalledWith('wizard-summon');

    finishAnim();
    expect(body.play).toHaveBeenLastCalledWith('wizard-idle');
  });

  test('swaps at once under reduced motion', () => {
    const { scene, entry, body } = makeSwapScene({ reducedMotion: true });

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard' }], { animate: true });

    expect(entry.ftype.key).toBe('wizard');
    expect(body.play).toHaveBeenLastCalledWith('wizard-idle');
    expect(scene.necromancer.spawnSummonCircle).not.toHaveBeenCalled();
  });

  test('a boss change re-skins the whole field at once, without a death/summon per fighter', () => {
    const { scene, entry, body } = makeSwapScene();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard' }]);

    expect(entry.ftype.key).toBe('wizard');
    expect(body.play).toHaveBeenLastCalledWith('wizard-idle');
  });
});

describe('changing character while charging', () => {
  test('the charging ring moves with the avatar to the new fighter\'s head height', async () => {
    const { FIGHTER_TYPES } = await import('@battlefield/config.js');
    const settable = y => ({ y, setY(v) { this.y = v; return this; } });
    const ring = settable(-40);
    const entry = {
      id: 7,
      baseSize: 48,
      head: settable(-40),
      body: { setTexture() {}, play() {} },
    };
    const scene = { charges: new Map([[7, { ring }]]), anims: { get: () => null } };
    const fighter = new Fighter(scene);
    // two fighters whose heads sit at different heights
    const [a, b] = FIGHTER_TYPES.filter((t, i, all) => all.findIndex(o => o.key !== t.key) >= 0).slice(0, 2);

    fighter._applyCharacter(entry, a);
    fighter._applyCharacter(entry, b);

    expect(ring.y).toBe(entry.head.y);
  });
});

describe('custom fighters', () => {
  const WIRE = {
    version: 'abc',
    url_base: '/storage/custom-characters/1/abc',
    animations: {
      idle: { frames: 6, rate: 8 },
      walk: { frames: 8, rate: 10 },
      attack: { frames: 6, rate: 12 },
      death: { frames: 3, rate: 6 },
    },
  };

  const makeScene = ({ ftype = { key: 'soldier' }, reducedMotion = true } = {}) => {
    const onceHandlers = [];
    const body = {
      play: vi.fn(),
      setTexture: vi.fn(),
      setScale: vi.fn(function () { return this; }),
      setY: vi.fn(function () { return this; }),
      once: vi.fn((event, cb) => onceHandlers.push(cb)),
    };
    const entry = { id: 1, ftype, baseSize: 36, pos: { x: 100, y: 200 }, body, animState: 'idle', bodyFitted: Boolean(ftype.custom) };
    const scene = {
      fighters: new Map([[1, entry]]),
      textures: { getFrame: () => null },
      anims: { exists: () => true, get: () => ({ frames: [1, 2, 3] }) },
      necromancer: { spawnSummonCircle: vi.fn() },
      reducedMotion,
      isShuttingDown: false,
    };

    return { scene, entry, body, finishAnim: () => onceHandlers.shift()?.() };
  };

  /** Lets the loader's promise chain settle. */
  const settle = () => new Promise(resolve => setTimeout(resolve, 0));

  const loaded = async () => {
    const { customFighterType, normalizeCustomCharacter } = await import('@battlefield/fighter/custom-fighter.js');

    return customFighterType(1, normalizeCustomCharacter(WIRE));
  };

  test('loads the strips a payload names, then shows the fighter as that character', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    const ftype = await loaded();
    loadCustomFighter.mockResolvedValue(ftype);
    const { scene, entry, body } = makeScene();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(loadCustomFighter).toHaveBeenCalledWith(scene, 1, expect.objectContaining({ version: 'abc' }));
    expect(entry.ftype).toBe(ftype);
    expect(body.setTexture).toHaveBeenLastCalledWith('custom-1-abc', 'custom-1-abc-idle-0');
    expect(body.play).toHaveBeenLastCalledWith('custom-1-abc-idle');
  });

  test('stands the body as tall as a roster fighter, with its feet on the same line', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    const ftype = await loaded();
    loadCustomFighter.mockResolvedValue(ftype);
    const { scene, body } = makeScene();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    const scale = 36 / 18;
    expect(body.setScale).toHaveBeenLastCalledWith(scale * ftype.bodyScale);
    expect(body.setY).toHaveBeenLastCalledWith(ftype.bodyOffsetY * scale);
  });

  test('a fighter already showing that version is not loaded again', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockClear();
    const { scene } = makeScene({ ftype: await loaded() });

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(loadCustomFighter).not.toHaveBeenCalled();
  });

  test('the boot payload\'s camelCase shape loads the same fighter', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockClear();
    loadCustomFighter.mockResolvedValue(await loaded());
    const { scene } = makeScene();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: { version: 'abc', urlBase: WIRE.url_base, animations: WIRE.animations } }]);

    expect(loadCustomFighter).toHaveBeenCalledOnce();
  });

  test('a character equipped through the sheet does not displace the custom fighter', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    const { scene, entry } = makeScene({ ftype: await loaded() });
    loadCustomFighter.mockClear();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard', custom_character: WIRE }]);
    await settle();

    expect(entry.ftype.key).toBe('custom-1-abc');
  });

  test('removing the custom character puts the roster character back, body at its own size', async () => {
    const { scene, entry, body } = makeScene({ ftype: await loaded() });

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard', custom_character: null }]);

    expect(entry.ftype.key).toBe('wizard');
    expect(body.setScale).toHaveBeenLastCalledWith(36 / 18);
    expect(body.setY).toHaveBeenLastCalledWith(0);
    expect(body.setTexture).toHaveBeenLastCalledWith('fighters', 'wizard-idle-0');
  });

  test('a load that finishes after the character was removed does not bring it back', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    let finish;
    loadCustomFighter.mockReturnValue(new Promise(resolve => { finish = resolve; }));
    const { scene, entry } = makeScene();
    const fighter = new Fighter(scene);

    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    fighter.updateCharacters([{ user_id: 1, character: 'wizard', custom_character: null }]);
    finish(await loaded());
    await settle();

    expect(entry.ftype.key).toBe('wizard');
  });

  test('a strip that will not load leaves the fighter as its roster character', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockRejectedValue(new Error('404'));
    const { scene, entry } = makeScene();

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(entry.ftype.key).toBe('soldier');
  });

  test('a custom fighter\'s own death and summon play when it is swapped in with animation', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockResolvedValue(await loaded());
    const { scene, entry, body, finishAnim } = makeScene({ reducedMotion: false });

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }], { animate: true });
    await settle();
    expect(body.play).toHaveBeenLastCalledWith('soldier-death');

    finishAnim();
    expect(entry.ftype.key).toBe('custom-1-abc');
    expect(body.setTexture).toHaveBeenLastCalledWith('custom-1-abc', 'custom-1-abc-death-0');
    expect(body.play).toHaveBeenLastCalledWith('custom-1-abc-summon');
  });
});

describe('custom fighters across a rotate', () => {
  test('a fighter whose strips are still loading snapshots the custom character it is waiting for', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    const { snapshotState } = await import('@battlefield/snapshot.js');
    loadCustomFighter.mockReturnValue(new Promise(() => {}));
    const entry = { id: 1, ftype: { key: 'soldier' }, baseSize: 36, pos: { x: 1, y: 2 }, handleText: 'a', body: {} };
    const scene = { fighters: new Map([[1, entry]]), charges: new Map(), damageTotals: new Map(), layout: { logicalWidth: 960, logicalHeight: 540 } };
    const wire = { version: 'abc', url_base: '/storage/custom-characters/1/abc', animations: { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } } };

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: wire }]);

    expect(snapshotState({}, scene).fighters[0].customCharacter).toMatchObject({ version: 'abc', urlBase: wire.url_base });
  });

  test('a removed custom character is no longer snapshotted', async () => {
    const { snapshotState } = await import('@battlefield/snapshot.js');
    const entry = { id: 1, ftype: { key: 'soldier' }, baseSize: 36, pos: { x: 1, y: 2 }, handleText: 'a', body: { setTexture() {}, play() {}, setScale() { return this; }, setY() { return this; } }, wantedCustom: { version: 'abc' }, wantedCustomKey: 'custom-1-abc', bodyFitted: true };
    const scene = { fighters: new Map([[1, entry]]), charges: new Map(), damageTotals: new Map(), layout: { logicalWidth: 960, logicalHeight: 540 }, textures: { getFrame: () => null }, anims: { exists: () => true, get: () => ({ frames: [1] }) }, reducedMotion: true };

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard', custom_character: null }]);

    expect(snapshotState({}, scene).fighters[0].customCharacter).toBeNull();
  });
});

describe('custom fighters: retries and stale loads', () => {
  const WIRE = { version: 'abc', url_base: '/storage/custom-characters/1/abc', animations: { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } } };
  const settle = () => new Promise(resolve => setTimeout(resolve, 0));
  const body = () => ({ play: vi.fn(), setTexture: vi.fn(), setScale: vi.fn(function () { return this; }), setY: vi.fn(function () { return this; }), once: vi.fn() });
  const sceneFor = entry => ({ fighters: new Map([[1, entry]]), textures: { getFrame: () => null }, anims: { exists: () => true, get: () => ({ frames: [1] }) }, reducedMotion: true, isShuttingDown: false, time: { now: 0 } });
  const entryFor = () => ({ id: 1, ftype: { key: 'soldier' }, baseSize: 36, pos: { x: 1, y: 2 }, body: body(), sprite: { active: true } });
  const typeFor = async () => {
    const { customFighterType, normalizeCustomCharacter } = await import('@battlefield/fighter/custom-fighter.js');

    return customFighterType(1, normalizeCustomCharacter(WIRE));
  };

  test('a swap cut short by a hit is retried by the next update, not blocked for the session', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockReset();
    loadCustomFighter.mockResolvedValue(await typeFor());
    const entry = entryFor();
    const scene = sceneFor(entry);
    scene.reducedMotion = false; // animated swap: ftype is only set once the death animation completes
    const fighter = new Fighter(scene);

    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }], { animate: true });
    await settle(); // load done, waiting on the death animation that a hit then cancels
    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(entry.ftype.key).toBe('custom-1-abc');
  });

  test('a failed load is tried again by a later update', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockReset();
    loadCustomFighter.mockRejectedValueOnce(new Error('503')).mockResolvedValue(await typeFor());
    const entry = entryFor();
    const scene = sceneFor(entry);
    const fighter = new Fighter(scene);

    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();
    expect(entry.ftype.key).toBe('soldier');

    scene.time.now = 60_000; // past the retry backoff
    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(entry.ftype.key).toBe('custom-1-abc');
  });

  test('a failed load is not hammered: an update right behind it does not fetch again', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    loadCustomFighter.mockReset();
    loadCustomFighter.mockRejectedValue(new Error('503'));
    const scene = sceneFor(entryFor());
    const fighter = new Fighter(scene);

    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();
    fighter.updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    await settle();

    expect(loadCustomFighter).toHaveBeenCalledTimes(1);
  });

  test('a load that finishes after the fighter left does nothing', async () => {
    const { loadCustomFighter } = await import('@battlefield/fighter/custom-fighter-loader.js');
    let finish;
    loadCustomFighter.mockReset();
    loadCustomFighter.mockReturnValue(new Promise(resolve => { finish = resolve; }));
    const entry = entryFor();
    const scene = sceneFor(entry);

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'soldier', custom_character: WIRE }]);
    scene.fighters.delete(1);
    entry.sprite.active = false;
    finish(await typeFor());
    await settle();

    expect(entry.body.setTexture).not.toHaveBeenCalled();
    expect(entry.ftype.key).toBe('soldier');
  });

  test('a rotate snapshot prefers the newer request over the look it is still wearing', async () => {
    const { snapshotState } = await import('@battlefield/snapshot.js');
    const entry = { ...entryFor(), handleText: 'a', ftype: { key: 'custom-1-old', custom: true, spec: { version: 'old' } }, wantedCustom: { version: 'new' } };
    const scene = { ...sceneFor(entry), charges: new Map(), damageTotals: new Map(), layout: { logicalWidth: 960, logicalHeight: 540 } };

    expect(snapshotState({}, scene).fighters[0].customCharacter).toEqual({ version: 'new' });
  });

  test('a removed custom character is not snapshotted even while its old look is still on', async () => {
    const { snapshotState } = await import('@battlefield/snapshot.js');
    const entry = { ...entryFor(), handleText: 'a', ftype: { key: 'custom-1-old', custom: true, spec: { version: 'old' } }, wantedCustom: { version: 'old' }, wantedCustomKey: 'custom-1-old', bodyFitted: true };
    const scene = { ...sceneFor(entry), charges: new Map(), damageTotals: new Map(), layout: { logicalWidth: 960, logicalHeight: 540 } };

    new Fighter(scene).updateCharacters([{ user_id: 1, character: 'wizard', custom_character: null }]);

    expect(entry.ftype.key).toBe('wizard');
    expect(snapshotState({}, scene).fighters[0].customCharacter).toBeNull();
  });
});
