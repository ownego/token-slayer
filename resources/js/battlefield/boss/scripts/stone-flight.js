// The last beat of a stone's arrival: the gem leaves the boss on the canvas
// and swoops up into its socket on the DOM boss plate, while the screen edge
// pulses in the stone's colour. Canvas → page coordinates go through the
// camera's world view, so zoom, the render scale and FIT letterboxing all hold.

/**
 * Converts a world point to page (viewport) pixels.
 *
 * @param {{x: number, y: number}} point World coordinates.
 * @param {{x: number, y: number, width: number, height: number}} worldView The camera's visible world rect.
 * @param {{left: number, top: number, width: number, height: number}} canvasRect The canvas's getBoundingClientRect().
 * @return {{x: number, y: number}}
 */
export function worldToScreen(point, worldView, canvasRect) {
  return {
    x: canvasRect.left + ((point.x - worldView.x) / worldView.width) * canvasRect.width,
    y: canvasRect.top + ((point.y - worldView.y) / worldView.height) * canvasRect.height,
  };
}

/**
 * Points along a quadratic curve from `from` to `to`, bowed `lift` px above
 * the straight line, so the gem swoops up rather than sliding across.
 *
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {number} lift
 * @param {number} steps Segments; the result has steps + 1 points.
 * @return {Array<{x: number, y: number}>}
 */
export function arcPoints(from, to, lift, steps) {
  const control = { x: (from.x + to.x) / 2, y: Math.min(from.y, to.y) - lift };
  const points = [];
  for (let i = 0; i <= steps; i++) {
    const t = i / steps;
    const u = 1 - t;
    points.push({
      x: u * u * from.x + 2 * u * t * control.x + t * t * to.x,
      y: u * u * from.y + 2 * u * t * control.y + t * t * to.y,
    });
  }
  return points;
}

/**
 * Flies a glowing gem (plus two fading ghosts behind it) from `from` to `to`
 * in page pixels, resolving when the gem lands.
 *
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {string} color CSS colour.
 * @param {number} [duration]
 * @return {Promise<void>}
 */
export function flyGem(from, to, color, duration = 700) {
  const points = arcPoints(from, to, 90, 12);
  const pieces = [0, 70, 140].map((delay, i) => {
    const el = document.createElement('span');
    el.className = 'bf-stone-flight';
    el.style.setProperty('--stone', color);
    el.style.opacity = String(1 - i * 0.35);
    document.body.appendChild(el);
    const frames = points.map((p, k) => ({
      transform: `translate(${p.x}px, ${p.y}px) rotate(${45 + k * 30}deg) scale(${1.9 - (k / points.length) * 0.9 - i * 0.25})`,
    }));
    const anim = el.animate(frames, { duration, delay, easing: 'cubic-bezier(.5,0,.75,.2)', fill: 'forwards' });
    return { el, anim };
  });
  return pieces[0].anim.finished
    .catch(() => {})
    .finally(() => pieces.forEach(({ el }) => el.remove()));
}

/**
 * A brief glow around the screen edge in the stone's colour.
 *
 * @param {HTMLElement|null} host The HUD overlay (#bf-hud), sized to the canvas.
 * @param {string} color CSS colour.
 * @return {void}
 */
export function pulseEdges(host, color) {
  if (!host) {
    return;
  }
  const el = document.createElement('div');
  el.className = 'bf-stone-pulse';
  el.style.setProperty('--stone', color);
  host.appendChild(el);
  el.animate([{ opacity: 0 }, { opacity: 1, offset: 0.25 }, { opacity: 0 }], { duration: 900, easing: 'ease-out' })
    .finished.catch(() => {}).finally(() => el.remove());
}
