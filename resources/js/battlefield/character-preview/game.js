import Phaser from 'phaser';
import { CharacterPreviewScene } from './scene.js';

const PREVIEW_SIZE = 260;

/**
 * Creates the standalone preview Phaser.Game mounted into the given DOM
 * element. CANVAS renderer keeps this light and avoids competing with the
 * live battlefield's WebGL context.
 *
 * @param {HTMLElement} mountEl
 * @return {Phaser.Game}
 */
export function createPreviewGame(mountEl) {
  return new Phaser.Game({
    type: Phaser.CANVAS,
    parent: mountEl,
    width: PREVIEW_SIZE,
    height: PREVIEW_SIZE,
    transparent: true,
    scene: [CharacterPreviewScene],
  });
}

/**
 * @param {Phaser.Game|null|undefined} game
 * @return {void}
 */
export function destroyPreviewGame(game) {
  game?.destroy(true);
}
