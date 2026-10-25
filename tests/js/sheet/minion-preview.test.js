import { describe, expect, test } from 'vitest';
import { MINION_SIZE_RATIO, MINION_TYPES, SPRITE_CHAR_HEIGHT } from '@battlefield/config.js';
import { fieldSizeFighterScale, fieldSizeMinionScale, minionLooks } from '@battlefield/sheet/minion-preview.js';

describe('fieldSizeMinionScale', () => {
  test('a 22 px minion beside a fighter at field scale 2 is drawn a little under life size', () => {
    expect(fieldSizeMinionScale(2, 22)).toBeCloseTo(SPRITE_CHAR_HEIGHT / 22, 3);
  });

  test('an older fighter copied in is 28 px tall and drawn larger, so it still stands half its fighter\'s height', () => {
    expect(fieldSizeMinionScale(4, 28)).toBeCloseTo(4 * SPRITE_CHAR_HEIGHT * MINION_SIZE_RATIO / 28, 3);
  });

  test('follows the field\'s own ratio of minion height to fighter height', () => {
    expect(MINION_SIZE_RATIO).toBe(0.5);
    expect(fieldSizeMinionScale(2, SPRITE_CHAR_HEIGHT)).toBeCloseTo(2 * MINION_SIZE_RATIO, 5);
  });
});

describe('fieldSizeFighterScale', () => {
  test('puts the fighter\'s measured body at the height the field draws it', () => {
    expect(fieldSizeFighterScale(2, 2 * SPRITE_CHAR_HEIGHT)).toBeCloseTo(1, 5);
    expect(fieldSizeFighterScale(2, SPRITE_CHAR_HEIGHT)).toBeCloseTo(2, 5);
  });
});

describe('minionLooks', () => {
  const animations = { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 16 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } };
  const mine = (version, bodyPx = 22, idle = animations.idle) => ({
    version,
    url_base: `/storage/custom-minions/7/${version}`,
    body_px: bodyPx,
    animations: { ...animations, idle },
  });
  const look = key => {
    const type = MINION_TYPES.find(candidate => candidate.key === key);
    const idle = type.animFiles.idle;

    return { url: idle.file, frames: idle.count, rate: idle.rate, frame: idle.frameWidth, bodyPx: type.charHeight };
  };

  test('is the player\'s own minions first, then the defaults still ticked, in the pool\'s order', () => {
    const pool = { rev: 4, defaults: ['smw-goomba', 'demon-a'], custom: [mine('aaaaaaaaaaaa'), mine('bbbbbbbbbbbb', 28, { frames: 4, rate: 8 })] };

    expect(minionLooks(pool)).toEqual([
      { url: '/storage/custom-minions/7/aaaaaaaaaaaa/idle.png', frames: 6, rate: 8, frame: 100, bodyPx: 22 },
      { url: '/storage/custom-minions/7/bbbbbbbbbbbb/idle.png', frames: 4, rate: 8, frame: 100, bodyPx: 28 },
      look('smw-goomba'),
      look('demon-a'),
    ]);
  });

  test('a default look carries its own frame size and body, so the stage can draw it as tall as the rest', () => {
    const [bowser] = minionLooks({ rev: 1, defaults: ['smw-bowser'], custom: [] });

    expect(bowser.frame).toBeGreaterThan(100);
    expect(bowser.bodyPx).toBe(MINION_TYPES.find(type => type.key === 'smw-bowser').charHeight);
  });

  test('reads the camelCase shape the battlefield snapshot uses too', () => {
    const snapshot = { rev: 2, defaults: [], custom: [{ version: 'v1', urlBase: '/m/v1', bodyPx: 22, animations }] };

    expect(minionLooks(snapshot)).toEqual([{ url: '/m/v1/idle.png', frames: 6, rate: 8, frame: 100, bodyPx: 22 }]);
  });

  test('a default the pool names but the game does not have is left out', () => {
    expect(minionLooks({ rev: 1, defaults: ['demon-a', 'not-a-minion'], custom: [] })).toEqual([look('demon-a')]);
  });

  test('a custom minion whose body is out of range is not drawn', () => {
    const looks = minionLooks({ rev: 1, defaults: ['demon-a'], custom: [mine('aaaaaaaaaaaa', 0), mine('bbbbbbbbbbbb', 59), mine('cccccccccccc', 58)] });

    expect(looks.map(entry => entry.url)).toEqual(['/storage/custom-minions/7/cccccccccccc/idle.png', look('demon-a').url]);
  });

  test.each([undefined, null, {}, 'pool', []])('no information (%s) is the pool of a player who never touched a tick: the five defaults', raw => {
    expect(minionLooks(raw)).toEqual(MINION_TYPES.map(type => look(type.key)));
  });

  test('a pool that names nothing is the five defaults too, never an empty stage', () => {
    expect(minionLooks({ rev: 3, defaults: [], custom: [] })).toHaveLength(MINION_TYPES.length);
  });
});
