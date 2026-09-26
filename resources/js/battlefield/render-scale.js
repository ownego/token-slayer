/**
 * Returns the canvas pixel size and render scale for a layout.
 *
 * The scene is authored in a fixed logical coordinate space (960x540, see
 * LAYOUTS) and Scale.FIT stretches that canvas to fill whatever space the
 * page gives it. On a wide display that stretch is close to 2x -- measured
 * live at 1863 CSS px for a 960px canvas -- so every pixel the game drew was
 * being blown up nearly double, which is what made avatars (and everything
 * else) look soft and blocky next to crisp pixel-art sprites.
 *
 * Rendering the canvas at `logical * scale` and zooming the camera by the
 * same factor keeps every coordinate in the codebase logical while giving
 * the renderer enough real pixels to land roughly 1:1 on screen. Derived
 * from the space actually available (times devicePixelRatio) rather than
 * fixed, so small screens don't pay for pixels they can't show; capped
 * because past ~2.5x the cost stops buying visible sharpness.
 *
 * Boot and the orientation-flip resize must both size through here: the
 * camera zoom is read from the same renderScale, and a canvas sized any
 * other way renders the world zoomed in around the boss.
 *
 * @param {number} availableCssWidth
 * @param {number} devicePixelRatio
 * @param {{logicalWidth: number, logicalHeight: number}} layout
 * @return {{width: number, height: number, renderScale: number}}
 */
export function canvasSizeFor(availableCssWidth, devicePixelRatio, layout) {
  const available = availableCssWidth * (devicePixelRatio || 1);
  const renderScale = Math.min(2.5, Math.max(1, available / layout.logicalWidth));
  return {
    width: Math.round(layout.logicalWidth * renderScale),
    height: Math.round(layout.logicalHeight * renderScale),
    renderScale,
  };
}
