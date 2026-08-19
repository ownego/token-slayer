import { PREVIEW_SPRITE_SCALE } from '@battlefield/constants.js';

/**
 * Computes a centered destination rect for drawing a sourceWidth x
 * sourceHeight region into a destWidth x destHeight canvas at a fixed
 * scale — never stretched/warped, and never forced to fill the
 * destination. Whichever axis exceeds the destination is simply clipped
 * by the canvas's own bounds when drawn. Pure and DOM-free so it's
 * directly unit-testable.
 *
 * @param {number} sourceWidth
 * @param {number} sourceHeight
 * @param {number} scale
 * @param {number} destWidth
 * @param {number} destHeight
 * @return {{x: number, y: number, width: number, height: number}}
 */
export function centeredScaleFit(sourceWidth, sourceHeight, scale, destWidth, destHeight) {
  const width = sourceWidth * scale;
  const height = sourceHeight * scale;
  return { x: (destWidth - width) / 2, y: (destHeight - height) / 2, width, height };
}

/**
 * Draws a single named frame from the 'fighters' texture atlas onto a 2D
 * canvas by reusing the atlas already loaded by the running Phaser game —
 * no separate image request, and no re-generated sprite asset.
 *
 * Draws the frame's whole cutWidth x cutHeight slot (never trimmed to its
 * opaque silhouette) at PREVIEW_SPRITE_SCALE, centered — deliberately
 * mirroring how the live Phaser preview tiles render: a Phaser sprite's
 * default origin (0.5, 0.5) sits at the middle of its *whole* frame, not
 * the middle of its visible pixels, and that origin is placed at the
 * tile's canvas center. An earlier version of this function detected the
 * frame's real opaque bounding box (via a since-removed findOpaqueBounds)
 * and centered *that* instead — correct in isolation, but wrong next to a
 * live tile, since a character whose silhouette isn't perfectly centered
 * in its own 100x100 slot would then render at a different position than
 * the live tile showing the same character, causing a visible jump the
 * moment a tile switched from this static draw to the live one. Centering
 * on the untrimmed frame, exactly as Phaser does, keeps both in lockstep.
 *
 * @param {Phaser.Game|null} game the booted battlefield game, or null if not ready yet
 * @param {HTMLCanvasElement} canvas destination canvas; drawn at its current width/height
 * @param {string} frameName atlas frame name, e.g. 'soldier-attack1-0'
 * @return {boolean} true if the frame existed and was drawn
 */
export function drawFighterFrame(game, canvas, frameName) {
  const frame = game?.textures?.getFrame('fighters', frameName);
  if (!frame) {
    return false;
  }

  const { cutX, cutY, cutWidth, cutHeight, source } = frame;
  const destWidth = canvas.width;
  const destHeight = canvas.height;
  const ctx = canvas.getContext('2d');
  // Scaling a small source region up onto the destination canvas would
  // otherwise get bilinear-smoothed by the canvas API itself — CSS's
  // image-rendering:pixelated on the element only governs CSS-level
  // scaling, not drawImage's own resampling — and came out visibly blurry.
  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, destWidth, destHeight);

  const dest = centeredScaleFit(cutWidth, cutHeight, PREVIEW_SPRITE_SCALE, destWidth, destHeight);
  ctx.drawImage(source.image, cutX, cutY, cutWidth, cutHeight, dest.x, dest.y, dest.width, dest.height);

  return true;
}

/**
 * Draws frame 0 of a fighter character's idle animation onto a 2D canvas.
 *
 * @param {Phaser.Game|null} game the booted battlefield game, or null if not ready yet
 * @param {HTMLCanvasElement} canvas destination canvas; drawn at its current width/height
 * @param {string} characterKey a FighterCharacter enum value, e.g. 'werebear'
 * @return {boolean} true if the frame existed and was drawn
 */
export function drawFighterPreview(game, canvas, characterKey) {
  return drawFighterFrame(game, canvas, `${characterKey}-idle-0`);
}
