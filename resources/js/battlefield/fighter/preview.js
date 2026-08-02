/**
 * Finds the tight bounding box of non-transparent pixels in an RGBA pixel
 * buffer (e.g. ImageData.data). Pure and DOM-free so it's directly
 * unit-testable, unlike the canvas/image calls around it.
 *
 * @param {Uint8ClampedArray|number[]} data RGBA pixel buffer, 4 bytes/pixel
 * @param {number} width
 * @param {number} height
 * @return {{x: number, y: number, width: number, height: number}}
 */
export function findOpaqueBounds(data, width, height) {
  let minX = width;
  let minY = height;
  let maxX = -1;
  let maxY = -1;
  for (let py = 0; py < height; py++) {
    for (let px = 0; px < width; px++) {
      if (data[(py * width + px) * 4 + 3] > 8) {
        if (px < minX) { minX = px; }
        if (px > maxX) { maxX = px; }
        if (py < minY) { minY = py; }
        if (py > maxY) { maxY = py; }
      }
    }
  }
  if (maxX < minX || maxY < minY) {
    return { x: 0, y: 0, width, height };
  }
  return { x: minX, y: minY, width: maxX - minX + 1, height: maxY - minY + 1 };
}

/**
 * Draws a single named frame from the 'fighters' texture atlas onto a 2D
 * canvas by reusing the atlas already loaded by the running Phaser game —
 * no separate image request, and no re-generated sprite asset.
 *
 * pack-sprites.js packs every atlas frame into a fixed 100x100 slot with
 * trimmed:false — a raw frame's cutWidth/cutHeight is the WHOLE slot, not
 * the character's actual silhouette (measured as small as ~15x18px for
 * soldier's idle frame). Stretching the untrimmed slot to fill a thumbnail
 * canvas left the character looking tiny inside a mostly-empty circle, so
 * this probes the frame's real bounds (via findOpaqueBounds) and draws
 * only that region. The destination canvas itself is reused as scratch
 * space for the probe — document.createElement is unavailable under this
 * project's Vitest setup (no jsdom/canvas polyfill, a deliberate,
 * documented convention), so a second canvas element isn't an option here.
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

  canvas.width = cutWidth;
  canvas.height = cutHeight;
  ctx.clearRect(0, 0, cutWidth, cutHeight);
  ctx.drawImage(source.image, cutX, cutY, cutWidth, cutHeight, 0, 0, cutWidth, cutHeight);
  const bounds = findOpaqueBounds(ctx.getImageData(0, 0, cutWidth, cutHeight).data, cutWidth, cutHeight);

  canvas.width = destWidth;
  canvas.height = destHeight;
  ctx.clearRect(0, 0, destWidth, destHeight);
  ctx.drawImage(
    source.image,
    cutX + bounds.x, cutY + bounds.y, bounds.width, bounds.height,
    0, 0, destWidth, destHeight,
  );

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
