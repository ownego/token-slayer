import { describe, expect, test, vi, beforeEach } from 'vitest';

// scene.js's import chain pulls in every manager module; only Phaser needs
// stubbing to satisfy preload()'s own direct API surface (Textures.FilterMode
// via the fighter-atlas filter line, which runs unconditionally).
vi.mock('phaser', () => ({
  default: {
    Scene: class {
      constructor() {}
    },
    Events: {
      EventEmitter: class {
        on() {}
        off() {}
        emit() {}
        once() {}
      },
    },
    Textures: { FilterMode: { NEAREST: 'nearest' } },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete', ANIMATION_REPEAT: 'animationrepeat' } },
  },
}));

// registerAllFighterAnimations is the boot-only work under test; replacing it
// with a spy lets the test assert call count without needing a real atlas.
vi.mock('@battlefield/fighter/animations.js', () => ({
  registerAllFighterAnimations: vi.fn(),
}));

import { BattlefieldScene } from '@battlefield/scene.js';
import { registerAllFighterAnimations } from '@battlefield/fighter/animations.js';

/**
 * Builds a minimal fake `load` plugin that models Phaser's real
 * EventEmitter contract for `on`/`once`: a `once` listener is invoked at
 * most one time across repeated `emit`s of the same event, matching the
 * documented Phaser LoaderPlugin/EventEmitter semantics this fix relies on
 * (the same contract already used by boss/index.js's `_awaitBossTypeReady`
 * for `filecomplete-spritesheet-<key>`).
 *
 * @return {object}
 */
function fakeLoad() {
  const listeners = { progress: [], complete: [] };
  return {
    atlas: vi.fn(),
    spritesheet: vi.fn(),
    isLoading: vi.fn(() => false),
    start: vi.fn(),
    on(event, cb) { listeners[event]?.push({ cb, once: false }); },
    once(event, cb) { listeners[event]?.push({ cb, once: true }); },
    emit(event) {
      const arr = listeners[event] || [];
      for (const entry of [...arr]) {
        entry.cb();
        if (entry.once) {
          const idx = arr.indexOf(entry);
          if (idx !== -1) arr.splice(idx, 1);
        }
      }
    },
  };
}

/**
 * Builds a fake `this` sufficient for `BattlefieldScene.prototype.preload`
 * to run without a real Phaser scene: textures never report as existing
 * (so every asset queues a load) and `game.registry` has no boot state
 * (boot boss number falls back to 0).
 *
 * @return {object}
 */
function fakeSceneForPreload() {
  return {
    textures: {
      exists: vi.fn(() => false),
      get: vi.fn(() => ({ setFilter: vi.fn() })),
    },
    load: fakeLoad(),
    game: { registry: { get: vi.fn(() => undefined) } },
  };
}

describe('BattlefieldScene#preload — boot-only complete handler', () => {
  beforeEach(() => {
    // node test environment has no DOM; preload() only ever calls
    // getElementById to find the loader bar (absent here is fine — it
    // just skips the progress-bar/`display:none` branches).
    globalThis.document = { getElementById: vi.fn(() => null) };
    registerAllFighterAnimations.mockClear();
  });

  test('runs boot-only registration exactly once even when the loader completes again later', () => {
    const scene = fakeSceneForPreload();
    BattlefieldScene.prototype.preload.call(scene);

    // First completion: the boot load (atlas + boot boss + companions + FX).
    scene.load.emit('complete');
    // A later completion: a background boss-type load queued by
    // Boss.preloadNextType()/_awaitBossTypeReady() on a kill. This must NOT
    // re-run the boot-only registration.
    scene.load.emit('complete');

    expect(registerAllFighterAnimations).toHaveBeenCalledTimes(1);
  });
});
