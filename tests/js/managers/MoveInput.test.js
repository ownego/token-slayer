import { afterEach, beforeEach, expect, test, vi } from 'vitest';

// MoveInput imports Boss, which reaches Phaser through the bus. Provide minimal stubs.
vi.mock('phaser', () => ({
  default: {
    Events: { EventEmitter: class { on() {} off() {} emit() {} once() {} } },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete', ANIMATION_REPEAT: 'animationrepeat' } },
  },
}));

vi.mock('@battlefield/fighter/flair-font.js', () => ({
  FLAIR_FONT_FAMILY: 'x', FLAIR_FONT_WEIGHT: 400, ensureFlairFont() {}, isFlairFontReady: () => true,
}));

vi.mock('@battlefield/move-geometry.js', async importOriginal => ({
  ...(await importOriginal()),
  moveOrigin: (sprite, pos) => pos,
}));

import { MoveInput } from '@battlefield/move-input.js';

/**
 * A scene with the viewer's own fighter on the field and a pointer handler
 * table, enough for MoveInput.setup() to register and run its click handler.
 *
 * @return {{scene: object, handlers: Map<string, Function>, entry: object}}
 */
function fakeScene() {
  const handlers = new Map();
  const entry = { id: 7, sprite: {}, pos: { x: 100, y: 400 }, waypointMoving: false };
  const scene = {
    currentUserId: 7,
    fighters: new Map([[7, entry]]),
    layout: { logicalWidth: 960, logicalHeight: 540 },
    input: { on: (event, fn) => handlers.set(event, fn) },
    tweens: { killTweensOf() {} },
    game: { canvas: { style: {} } },
  };

  return { scene, handlers, entry };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('window', { Livewire: { dispatch: vi.fn() } });
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

test('a straight one-hop click animates the viewer\'s fighter at once, not after the Reverb echo that may never come', () => {
  const { scene, handlers, entry } = fakeScene();
  const input = new MoveInput(scene);
  input._spawnClickRipple = () => {};
  input._planRoute = () => [{ x: 300, y: 420 }];
  input._animateRoute = vi.fn();
  input.setup();

  handlers.get('pointerdown')({ worldX: 300, worldY: 420 });
  vi.runAllTimers();

  expect(input._animateRoute).toHaveBeenCalledWith(entry, [{ x: 300, y: 420 }]);
  expect(window.Livewire.dispatch).toHaveBeenCalledWith('fighter-move', { x: 0.3125, y: 0.7778 });
});

test('a detour still animates locally too', () => {
  const { scene, handlers, entry } = fakeScene();
  const input = new MoveInput(scene);
  const route = [{ x: 200, y: 480 }, { x: 300, y: 420 }];
  input._spawnClickRipple = () => {};
  input._planRoute = () => route;
  input._animateRoute = vi.fn();
  input.setup();

  handlers.get('pointerdown')({ worldX: 300, worldY: 420 });
  vi.runAllTimers();

  expect(input._animateRoute).toHaveBeenCalledWith(entry, route);
});
