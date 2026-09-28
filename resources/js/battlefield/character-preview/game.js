import Phaser from 'phaser';
import { CharacterPreviewScene } from './scene.js';

/**
 * Fallback size used only if the mount element reports a 0×0 box at boot
 * (e.g. its tab is hidden) — `Scale.FIT` + the ResizeObserver below
 * immediately re-fit once it's actually visible.
 *
 * @type {number}
 */
const FALLBACK_SIZE = 260;

/**
 * Creates the standalone preview Phaser.Game mounted into the given DOM
 * element, sized from the element's own box (`Scale.FIT`) rather than a
 * fixed pixel size, so the mini stage fits a phone-width Character tab as
 * well as a wide desktop one. CANVAS renderer keeps this light and avoids
 * competing with the live battlefield's WebGL context.
 *
 * @param {HTMLElement} mountEl
 * @return {Phaser.Game}
 */
export function createPreviewGame(mountEl) {
  const width = mountEl.clientWidth || FALLBACK_SIZE;
  const height = mountEl.clientHeight || FALLBACK_SIZE;

  const game = new Phaser.Game({
    type: Phaser.CANVAS,
    parent: mountEl,
    width,
    height,
    transparent: true,
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    scene: [CharacterPreviewScene],
  });

  // Sizing while the tab is hidden reads a 0×0 box — the scene's own
  // _reframe() already skips that case, so a resize that briefly reports
  // 0 is harmless here too, and the next real resize corrects it.
  const observer = new ResizeObserver(entries => {
    const { width: w, height: h } = entries[0].contentRect;
    if (w > 0 && h > 0) {
      game.scale.resize(w, h);
    }
  });
  observer.observe(mountEl);
  game.events.once(Phaser.Core.Events.DESTROY, () => observer.disconnect());

  return game;
}

/**
 * @param {Phaser.Game|null|undefined} game
 * @return {void}
 */
export function destroyPreviewGame(game) {
  game?.destroy(true);
}
