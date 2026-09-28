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
 * Rear heel of a fighter from one 100×100 frame's alpha, searched only in
 * the rear half of its body box (fighters face right, so the back foot is
 * on the left): the lowest row with at least two inked pixels there is the
 * sole, and its rear-most inked pixel the heel. A weapon touching the ground
 * — a staff, a bow's lower tip, a blade — sits on the front side or is one
 * pixel wide, so it never counts.
 *
 * @param {Uint8ClampedArray} rgba 100×100 RGBA pixels
 * @param {{x0:number, x1:number}} [box] body box in frame px
 * @return {{x:number, y:number}} heel in frame px (y = just below the sole)
 */
export function findHeel(rgba, box = { x0: 30, x1: 70 }) {
  const x0 = Math.max(0, Math.floor(box.x0) - 1);
  const x1 = Math.min(FRAME - 1, Math.floor((box.x0 + box.x1) / 2));
  const inked = (x, y) => rgba[(y * FRAME + x) * 4 + 3] > OPAQUE;
  for (let y = FRAME - 1; y >= 0; y--) {
    let count = 0;
    let rear = -1;
    for (let x = x0; x <= x1; x++) {
      if (inked(x, y)) {
        count++;
        rear = rear < 0 ? x : rear;
      }
    }
    if (count >= 2) {
      return { x: rear, y: y + 1 };
    }
  }

  return { x: Math.round((box.x0 + box.x1) / 2) - 3, y: 57 };
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

/**
 * A fighter's heel offset from its container, in world px: the body's own
 * scale times the container's damage growth, mirrored when the body faces
 * left.
 *
 * @param {{x:number, y:number}} heel frame px
 * @param {{sprite: {scaleX: number}, body: {scaleX: number, flipX: boolean}}} fighter
 * @return {{dx:number, dy:number}}
 */
export function heelWorldOffset(heel, fighter) {
  const scale = (fighter.sprite?.scaleX ?? 1) * (fighter.body?.scaleX ?? 1);

  return heelToLocal(heel, scale, fighter.body?.flipX ? -1 : 1);
}
