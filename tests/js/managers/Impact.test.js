import { describe, expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({
  default: {
    Events: { EventEmitter: class { on() {} off() {} emit() {} once() {} } },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete', ANIMATION_REPEAT: 'animationrepeat' } },
  },
}));

import { Impact } from '@battlefield/impact.js';
import { LAYOUTS } from '@battlefield/config.js';

const makeScene = () => {
  const added = [];
  const chain = () => {
    const o = { setScale: () => o, play: () => o, once: () => o, destroy() {} };
    return o;
  };
  const boss = {
    scaleX: 4,
    scaleY: 4,
    setTint() {},
    clearTint() {},
  };
  const scene = {
    layout: LAYOUTS.landscape,
    bossSprite: boss,
    bossState: { currentHp: 1000, maxHp: 1000 },
    hpBarFill: { setFillStyle() {}, width: 0 },
    hpText: { setText() {} },
    anims: { exists: () => true, create() {} },
    add: { sprite: chain },
    addSharpText: chain,
    time: { delayedCall() {} },
    cameras: { main: { shake() {} } },
    tweens: {
      add(cfg) {
        const tween = { ...cfg, stopped: false, stop() { this.stopped = true; } };
        added.push(tween);
        return tween;
      },
    },
  };
  return { scene, boss, added };
};

describe('Impact boss flinch', () => {
  // Each flinch used to take the boss's CURRENT scale as its baseline. A hit
  // landing while the previous flinch was still mid-yoyo baked that squashed
  // scale in as the new baseline, and a burst of hits ratcheted the boss
  // wider/flatter until a reload.
  test('a hit landing mid-flinch still flinches around the boss rest scale', () => {
    const { scene, boss, added } = makeScene();
    const impact = new Impact(scene);

    impact.apply(990);
    const first = added.find(t => t.targets === boss);

    // Mid-yoyo: halfway squashed.
    boss.scaleX = 4 * 1.05;
    boss.scaleY = 4 * 0.95;
    impact.apply(980);

    const flinches = added.filter(t => t.targets === boss);
    const second = flinches[flinches.length - 1];
    expect(first.stopped).toBe(true);
    expect(boss.scaleX).toBeCloseTo(4);
    expect(boss.scaleY).toBeCloseTo(4);
    expect(second.scaleX).toBeCloseTo(4 * 1.1);
    expect(second.scaleY).toBeCloseTo(4 * 0.9);
  });

  test('a flinch on a freshly spawned boss uses that new boss scale, not the old one', () => {
    const { scene, boss, added } = makeScene();
    const impact = new Impact(scene);
    impact.apply(990);

    const nextBoss = { scaleX: 3, scaleY: 3, setTint() {}, clearTint() {} };
    scene.bossSprite = nextBoss;
    impact.apply(980);

    const flinch = added.filter(t => t.targets === nextBoss).at(-1);
    expect(flinch.scaleX).toBeCloseTo(3 * 1.1);
    expect(flinch.scaleY).toBeCloseTo(3 * 0.9);
    expect(boss.scaleX).toBe(4);
  });
});
