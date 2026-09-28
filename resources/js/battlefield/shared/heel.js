/**
 * Frame side length in px, shared by every fighter sprite sheet.
 * @type {number}
 */
const FRAME = 100;

/**
 * Alpha threshold above which a pixel counts as inked.
 * @type {number}
 */
const OPAQUE = 40;

/**
 * Rear heel of a fighter from one 100×100 frame's alpha: the lowest inked row
 * inside the body box, then the first inked pixel from the back within the
 * three rows above it (so a weapon tip or a mount's tail doesn't count).
 *
 * @param {Uint8ClampedArray} rgba 100×100 RGBA pixels
 * @param {{x0:number, x1:number}} [box] body box in frame px
 * @return {{x:number, y:number}} heel in frame px (y = just below the sole)
 */
export function findHeel(rgba, box = { x0: 30, x1: 70 }) {
  const x0 = Math.max(0, Math.floor(box.x0) - 1);
  const x1 = Math.min(FRAME - 1, Math.ceil(box.x1));
  const inked = (x, y) => rgba[(y * FRAME + x) * 4 + 3] > OPAQUE;
  let foot = -1;
  for (let y = FRAME - 1; y >= 0 && foot < 0; y--) {
    for (let x = x0; x <= x1; x++) { if (inked(x, y)) { foot = y; break; } }
  }
  if (foot < 0) {
    return { x: Math.round((box.x0 + box.x1) / 2) - 3, y: 57 };
  }
  for (let y = foot; y >= foot - 3; y--) {
    for (let x = x0; x <= x1; x++) { if (inked(x, y)) { return { x, y: y + 1 }; } }
  }
  return { x: Math.round((box.x0 + box.x1) / 2) - 3, y: foot + 1 };
}

/**
 * Heel offset from the sprite's frame centre, in world px.
 *
 * @param {{x:number, y:number}} heel frame px
 * @param {number} scale sprite scale
 * @param {number} facing +1 facing right, -1 facing left
 * @return {{dx:number, dy:number}}
 */
export function heelToLocal(heel, scale, facing) {
  return { dx: (heel.x - FRAME / 2) * scale * facing, dy: (heel.y - FRAME / 2) * scale };
}
