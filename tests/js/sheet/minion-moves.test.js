import { describe, expect, test } from 'vitest';
import { MINION_FALL_HOLD_MS, MINION_TYPES } from '@battlefield/config.js';
import { MOVE_LIST, movePlan, moveSet, playbackStyle, stripMs } from '@battlefield/sheet/minion-moves.js';

const animations = { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } };
const custom = (table = animations) => ({ kind: 'custom', version: 'abc123def456', url_base: '/storage/custom-minions/7/abc123def456', animations: table, body_px: 22 });
const typeOf = key => MINION_TYPES.find(type => type.key === key);
const ids = set => set.moves.map(move => move.id);

describe('what a look can play', () => {
  test('every default look has the four moves in hotbar order, from its own MINION_TYPES strips', () => {
    for (const type of MINION_TYPES) {
      const set = moveSet({ kind: 'default', key: type.key });

      expect(ids(set), type.key).toEqual(['idle', 'walk', 'attack', 'fall']);
      expect(set.moves.map(move => move.label)).toEqual(['Idle', 'Walk', 'Attack', 'Fall']);
      expect(set.strips.idle.url).toBe(type.animFiles.idle.file);
      expect(set.strips.walk.url).toBe(type.animFiles.walk.file);
      expect(set.strips.attack.url).toBe(type.animFiles[type.attacks[0].anim].file);
      expect(set.strips.fall.url).toBe(type.animFiles[type.reaction.anim].file);
    }
  });

  test('a default strip keeps the frame, count and rate of its animFiles entry and the look\'s body', () => {
    const type = typeOf('smw-bowser');
    const set = moveSet({ kind: 'default', key: 'smw-bowser' });
    const attack = type.animFiles[type.attacks[0].anim];

    expect(set.strips.attack).toEqual({ url: attack.file, frames: attack.count, rate: attack.rate, frame: attack.frameWidth, bodyPx: type.charHeight });
  });

  test('a custom look has the four moves, from its animation table served under its url_base', () => {
    const set = moveSet(custom());

    expect(ids(set)).toEqual(['idle', 'walk', 'attack', 'fall']);
    expect(set.strips.idle).toEqual({ url: '/storage/custom-minions/7/abc123def456/idle.png', frames: 6, rate: 8, frame: 100, bodyPx: 22 });
    expect(set.strips.walk.url).toBe('/storage/custom-minions/7/abc123def456/walk.png');
    expect(set.strips.attack).toMatchObject({ url: '/storage/custom-minions/7/abc123def456/attack.png', frames: 6, rate: 12 });
    expect(set.strips.fall).toMatchObject({ url: '/storage/custom-minions/7/abc123def456/death.png', frames: 3, rate: 6 });
  });

  test('reads a snapshot\'s camelCase spec as well', () => {
    const set = moveSet({ ...custom(), url_base: undefined, urlBase: '/storage/custom-minions/7/x', body_px: undefined, bodyPx: 22 });

    expect(set.strips.walk.url).toBe('/storage/custom-minions/7/x/walk.png');
  });

  test('offers only the moves the look has a strip for', () => {
    const set = moveSet(custom({ idle: animations.idle, walk: animations.walk }));

    expect(ids(set)).toEqual(['idle', 'walk']);
    expect(moveSet(custom({ idle: animations.idle, death: animations.death })).moves.map(move => move.id)).toEqual(['idle', 'fall']);
  });

  test('skips a move whose table entry cannot be played', () => {
    const set = moveSet(custom({ ...animations, walk: { frames: 0, rate: 10 }, attack: { frames: 6, rate: 'fast' }, death: null }));

    expect(ids(set)).toEqual(['idle']);
  });

  test('names nothing for a spec that is not a look', () => {
    expect(moveSet(null)).toBeNull();
    expect(moveSet({ kind: 'default', key: 'evil' })).toBeNull();
    expect(moveSet({ kind: 'custom', url_base: '/x' })).toBeNull();
  });

  test('exports the hotbar order', () => {
    expect(MOVE_LIST.map(move => move.id)).toEqual(['idle', 'walk', 'attack', 'fall']);
  });
});

describe('how a move is played', () => {
  test('Idle and Walk loop until another move is played', () => {
    const set = moveSet(custom());

    expect(movePlan(set, 'idle')).toEqual([{ phase: 'loop', strip: 'idle', ms: null }]);
    expect(movePlan(set, 'walk')).toEqual([{ phase: 'loop', strip: 'walk', ms: null }]);
  });

  test('Attack plays its strip once, for the time the game plays it', () => {
    const type = typeOf('demon-a');
    const attack = type.animFiles[type.attacks[0].anim];

    expect(movePlan(moveSet({ kind: 'default', key: 'demon-a' }), 'attack')).toEqual([
      { phase: 'once', strip: 'attack', ms: Math.round(attack.count / attack.rate * 1000) },
    ]);
  });

  test('Fall of a default that gets up lies on the death strip for the hold, then plays it backwards', () => {
    const type = typeOf('demon-a');
    const death = type.animFiles.death;
    const down = Math.round(death.count / death.rate * 1000);

    expect(type.reaction.getUp).toBe(true);
    expect(movePlan(moveSet({ kind: 'default', key: 'demon-a' }), 'fall')).toEqual([
      { phase: 'once', strip: 'fall', ms: down + MINION_FALL_HOLD_MS },
      { phase: 'back', strip: 'fall', ms: down },
    ]);
  });

  test('Fall of a custom look holds MINION_FALL_HOLD_MS after the strip, then plays it backwards', () => {
    expect(movePlan(moveSet(custom()), 'fall')).toEqual([
      { phase: 'once', strip: 'fall', ms: 500 + MINION_FALL_HOLD_MS },
      { phase: 'back', strip: 'fall', ms: 500 },
    ]);
  });

  test('Fall of a default whose reaction is a plain hurt plays that strip once and does not get up, as on the field', () => {
    const type = typeOf('smw-goomba');
    const hurt = type.animFiles.hurt;

    expect(type.reaction).toEqual({ anim: 'hurt' });
    expect(movePlan(moveSet({ kind: 'default', key: 'smw-goomba' }), 'fall')).toEqual([
      { phase: 'once', strip: 'fall', ms: Math.round(hurt.count / hurt.rate * 1000) },
    ]);
  });

  test('has no beats for a move the look does not have, or for no look', () => {
    expect(movePlan(moveSet(custom({ idle: animations.idle })), 'attack')).toEqual([]);
    expect(movePlan(moveSet(custom()), 'dance')).toEqual([]);
    expect(movePlan(null, 'idle')).toEqual([]);
  });

  test('a strip plays through in frames over rate', () => {
    expect(stripMs({ frames: 4, rate: 8 })).toBe(500);
    expect(stripMs({ frames: 7, rate: 12 })).toBe(583);
  });
});

describe('the variables that play a strip', () => {
  const strip = { frames: 6 };

  test('a loop repeats forever, stepping its frames', () => {
    expect(playbackStyle('loop', strip)).toEqual({ '--ms-anim': 'fs-pool-play', '--ms-ease': 'steps(6)', '--ms-iter': 'infinite' });
  });

  test('a one-shot runs once forwards and lands on the real last frame', () => {
    expect(playbackStyle('once', strip)).toEqual({ '--ms-anim': 'fs-ms-once', '--ms-ease': 'steps(6, jump-none)', '--ms-iter': '1' });
  });

  test('getting up runs once backwards under a different animation, so it restarts the one before', () => {
    const back = playbackStyle('back', strip);

    expect(back['--ms-anim']).toBe('fs-ms-back');
    expect(back['--ms-anim']).not.toBe(playbackStyle('once', strip)['--ms-anim']);
    expect(back['--ms-iter']).toBe('1');
  });

  test('every one-shot has a twin with the same variables, so two in a row restart', () => {
    expect(playbackStyle('once', strip, true)).toEqual({ ...playbackStyle('once', strip), '--ms-anim': 'fs-ms-once-b' });
    expect(playbackStyle('back', strip, true)).toEqual({ ...playbackStyle('back', strip), '--ms-anim': 'fs-ms-back-b' });
    expect(playbackStyle('loop', strip, true)['--ms-anim']).toBe('fs-pool-play');
  });

  test('a one-frame strip still has the two steps jump-none needs', () => {
    expect(playbackStyle('once', { frames: 1 })['--ms-ease']).toBe('steps(2, jump-none)');
  });
});
