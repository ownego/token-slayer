/**
 * Draws frame 0 of a fighter character's idle animation onto a 2D canvas by
 * reusing the 'fighters' texture atlas already loaded by the running Phaser
 * game — no separate image request, and no re-generated sprite asset.
 *
 * @param {Phaser.Game|null} game the booted battlefield game, or null if not ready yet
 * @param {HTMLCanvasElement} canvas destination canvas; drawn at its current width/height
 * @param {string} characterKey a FighterCharacter enum value, e.g. 'werebear'
 * @return {boolean} true if the frame existed and was drawn
 */
export function drawFighterPreview(game, canvas, characterKey) {
  const frame = game?.textures?.getFrame('fighters', `${characterKey}-idle-0`);
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
