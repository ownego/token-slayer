/**
 * Returns the damage HUD's top offset in px, relative to its offset parent.
 *
 * The HUD mirrors the in-canvas panel's y, but the nav stack occupies the same
 * top-left corner in every orientation, so it clears the nav whenever the
 * canvas starts high enough to collide. Measured rather than hard-coded: the
 * stack grows by a pill each time a nav link is added.
 *
 * @param {object} geometry
 * @param {number|null} geometry.navBottom viewport y of the nav's bottom edge, or null when no nav is rendered (IDE embed)
 * @param {number} geometry.canvasTop viewport y of the canvas top edge
 * @param {number} geometry.parentTop viewport y of the HUD's offset parent
 * @param {number} geometry.scale canvas FIT scale factor
 * @param {number} [geometry.gap=8] px kept between the nav and the HUD
 * @param {number} [geometry.panelTop=5] logical y of the in-canvas panel (leaderboard PANEL_TOP)
 * @return {number}
 */
export function computeHudTop({ navBottom, canvasTop, parentTop, scale, gap = 8, panelTop = 5 }) {
  const alignedWithCanvas = canvasTop - parentTop + panelTop * scale;

  if (typeof navBottom !== 'number') {
    return alignedWithCanvas;
  }

  return Math.max(navBottom - parentTop + gap, alignedWithCanvas);
}
