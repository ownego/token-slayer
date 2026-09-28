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
