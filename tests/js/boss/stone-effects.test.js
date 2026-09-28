import { describe, expect, test } from 'vitest';
import { STONE_EFFECTS, playStoneEffect } from '@battlefield/boss/scripts/stone-effects.js';
import { STONE_COLORS, STONE_MAX, STONE_NAMES } from '@battlefield/boss/scripts/thanos-stones.js';

// A just-enough Phaser scene: every game object records its own destroy(),
// tweens run to completion synchronously, so an effect's promise settles and
// we can check it cleaned up after itself.
function fakeScene() {
  const created = [];
  const obj = () => {
    const o = {
      destroyed: false,
      x: 0, y: 0, active: true,
      destroy() { this.destroyed = true; },
    };
    for (const m of ['setDepth', 'setAlpha', 'setScale', 'setStrokeStyle', 'setOrigin', 'setBlendMode', 'setAngle',
      'clear', 'lineStyle', 'fillStyle', 'beginPath', 'arc', 'strokePath', 'fillCircle', 'lineBetween', 'strokeCircle',
      'setTint', 'clearTint', 'setPosition', 'moveTo', 'closePath', 'fillPath']) {
      o[m] = () => o;
    }
    created.push(o);
    return o;
  };
  const runTween = (cfg) => {
    const targets = [].concat(cfg.targets ?? []);
    for (const t of targets) {
      for (const [k, v] of Object.entries(cfg)) {
        if (typeof v === 'number' && k in t) t[k] = v;
      }
    }
    cfg.onUpdate?.({ getValue: () => cfg.to ?? 1 });
    cfg.onComplete?.();
  };
  return {
    created,
    reducedMotion: false,
    add: { circle: obj, rectangle: obj, graphics: obj, star: obj },
    addSharpText: obj,
    tweens: { add: runTween, addCounter: runTween, killTweensOf() {} },
    cameras: { main: { shake() {} } },
  };
}

describe('stone effects', () => {
  test('every stone has its own effect', () => {
    expect(Object.keys(STONE_EFFECTS)).toEqual(STONE_NAMES);
    expect(new Set(Object.values(STONE_EFFECTS)).size).toBe(STONE_MAX);
  });

  test.each(STONE_NAMES.map((name, i) => [name, i + 1]))('the %s Stone effect finishes and removes everything it drew', async (_name, ordinal) => {
    const scene = fakeScene();
    await playStoneEffect(scene, ordinal, { x: 100, y: 100 });

    expect(scene.created.length).toBeGreaterThan(0);
    expect(scene.created.every((o) => o.destroyed)).toBe(true);
  });

  test('an aborted effect settles at once and removes what it drew, even though its tweens never complete', async () => {
    const scene = fakeScene();
    scene.tweens.add = () => {};
    scene.tweens.addCounter = () => {};
    const controller = new AbortController();
    const done = playStoneEffect(scene, 1, { x: 0, y: 0 }, new Set(), controller.signal);
    controller.abort();
    await done;

    expect(scene.created.every((o) => o.destroyed)).toBe(true);
  });

  test('an ordinal past the roster plays nothing', async () => {
    const scene = fakeScene();
    await playStoneEffect(scene, STONE_MAX + 1, { x: 0, y: 0 });

    expect(scene.created).toEqual([]);
  });

  test('stone names and colours line up one to one', () => {
    expect(STONE_NAMES).toHaveLength(STONE_COLORS.length);
  });
});
