/**
 * Draws a single named frame from the 'fighters' texture atlas onto a 2D
 * canvas by reusing the atlas already loaded by the running Phaser game —
 * no separate image request, and no re-generated sprite asset.
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

  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(
    frame.source.image,
    frame.cutX, frame.cutY, frame.cutWidth, frame.cutHeight,
    0, 0, canvas.width, canvas.height,
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
