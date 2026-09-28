import { expect, test, vi } from 'vitest';
import { onSceneReady } from '@battlefield/shared/scene-ready.js';

/** A fake Phaser game whose scene fires 'create' when told to. */
function fakeGame({ created = false } = {}) {
  const handlers = [];
  const scene = {
    sys: { isActive: () => created },
    events: { on: (name, fn) => handlers.push([name, fn]), off: vi.fn() },
  };

  return { game: { scene: { getScene: () => scene } }, scene, create: () => { created = true; handlers.filter(([n]) => n === 'create').forEach(([, fn]) => fn(scene)); } };
}

test('a board wired before Phaser finishes creating the scene still renders once it exists (the F5 empty-board bug)', () => {
  const { game, create } = fakeGame();
  const render = vi.fn();

  onSceneReady(game, 'battlefield', render);
  expect(render).not.toHaveBeenCalled();

  create();
  expect(render).toHaveBeenCalledTimes(1);
});

test('renders at once when the scene is already up, and again after every restart', () => {
  const { game, create } = fakeGame({ created: true });
  const render = vi.fn();

  onSceneReady(game, 'battlefield', render);
  expect(render).toHaveBeenCalledTimes(1);

  create(); // a rotate restarts the scene
  expect(render).toHaveBeenCalledTimes(2);
});

test('before the scene manager has the scene, it waits for the game to be ready instead of giving up', () => {
  const { scene, create } = fakeGame();
  let ready = null;
  let available = false;
  const game = { scene: { getScene: () => (available ? scene : null) }, events: { once: (n, fn) => { if (n === 'ready') { ready = fn; } } } };
  const render = vi.fn();

  onSceneReady(game, 'battlefield', render);
  available = true;
  ready();
  create();

  expect(render).toHaveBeenCalledTimes(1);
});
