/**
 * The HUD overlay's box: exactly the canvas Scale.FIT drew inside the mount.
 *
 * @param {{left:number, top:number, width:number, height:number}} canvasRect
 * @param {{left:number, top:number}} mountRect
 * @return {{left:number, top:number, width:number, height:number}}
 */
export function hudBox(canvasRect, mountRect) {
  return { left: canvasRect.left - mountRect.left, top: canvasRect.top - mountRect.top, width: canvasRect.width, height: canvasRect.height };
}

/**
 * Keeps #bf-hud on the canvas: on every scale resize and mode restart.
 *
 * @param {Phaser.Game} game
 * @param {HTMLElement} mount
 * @param {HTMLElement} hud
 * @return {function(): void} unsubscribe
 */
export function keepHudOnCanvas(game, mount, hud) {
  const sync = () => {
    const box = hudBox(game.canvas.getBoundingClientRect(), mount.getBoundingClientRect());
    Object.assign(hud.style, { left: box.left + 'px', top: box.top + 'px', width: box.width + 'px', height: box.height + 'px' });
  };
  const onResize = () => requestAnimationFrame(sync);
  game.scale.on('resize', onResize);
  const ro = new ResizeObserver(onResize);
  ro.observe(mount);
  sync();
  return () => { game.scale.off('resize', onResize); ro.disconnect(); };
}

/**
 * Returns how far the HUD's top-band panels (nav, boss plate, team) reach
 * down the canvas, in logical px — what a portrait layout must keep the boss
 * below (config/layouts.js's layoutFor).
 *
 * @param {Array<{bottom: number}>} panelRects The top-band panels' client rects.
 * @param {{top: number, width: number}} hudRect The HUD's own client rect.
 * @param {number} logicalWidth The world width the HUD's width maps to.
 * @return {number}
 */
export function hudBand(panelRects, hudRect, logicalWidth) {
  if (!panelRects.length || !hudRect.width) {
    return 0;
  }
  const lowest = Math.max(...panelRects.map(r => r.bottom)) - hudRect.top;

  return Math.max(0, Math.round(lowest * logicalWidth / hudRect.width));
}

/**
 * Measures the HUD band for a canvas box: lays the HUD over that box first
 * (where keepHudOnCanvas will put it once the canvas exists at that size),
 * reads the given top-band panels — only those in the top half, so a sheet
 * docked at the bottom never counts — then puts the HUD's box back.
 *
 * @param {HTMLElement|null} hud
 * @param {{width: number, height: number}} box The canvas's CSS size.
 * @param {number} logicalWidth The world width that box maps to.
 * @param {string} selectors The top-band panels to measure.
 * @return {number}
 */
export function measureHudBand(hud, box, logicalWidth, selectors) {
  if (!hud) {
    return 0;
  }
  const saved = { left: hud.style.left, top: hud.style.top, width: hud.style.width, height: hud.style.height };
  Object.assign(hud.style, { width: box.width + 'px', height: box.height + 'px' });
  const hudRect = hud.getBoundingClientRect();
  const panels = [...hud.querySelectorAll(selectors)]
    .map(el => el.getBoundingClientRect())
    .filter(r => r.height > 0 && r.top - hudRect.top < hudRect.height / 2);
  const band = hudBand(panels, hudRect, logicalWidth);
  Object.assign(hud.style, saved);

  return band;
}
