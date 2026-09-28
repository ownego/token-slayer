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
