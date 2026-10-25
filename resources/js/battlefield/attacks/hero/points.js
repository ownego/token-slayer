/** The centre of a fighter's 100 px frame, where its body sprite's origin sits. */
const HALF_FRAME = 50;

/**
 * Maps a point drawn on a hero's preview cell (a hand, the eyes, where the
 * shield leaves the arm) to world space, through the hero's strip pad, its
 * body scale and offset, its facing and its container.
 *
 * @param {{x: number, y: number}} p The point in the preview cell, facing right.
 * @param {{x: number, y: number, scale: number, rowShift: number}} cell The hero's HERO_CELL entry.
 * @param {{x: number, y: number, scaleX: number, scaleY: number, bodyScale: number, bodyY: number, flip: boolean}} frame
 *   The container's position and scale, the body's scale and y offset, and whether it faces left.
 * @return {{x: number, y: number}}
 */
export function cellToWorld(p, cell, frame) {
  const cx = p.x * cell.scale + cell.x;
  const cy = (p.y - cell.rowShift) * cell.scale + cell.y;
  const lx = (cx - HALF_FRAME) * frame.bodyScale * (frame.flip ? -1 : 1);
  const ly = (cy - HALF_FRAME) * frame.bodyScale + frame.bodyY;
  return { x: frame.x + lx * frame.scaleX, y: frame.y + ly * frame.scaleY };
}

/**
 * The frame `cellToWorld` needs, read off a live fighter entry.
 *
 * @param {{sprite: object, body: object}} fighter A scene.fighters entry.
 * @return {{x: number, y: number, scaleX: number, scaleY: number, bodyScale: number, bodyY: number, flip: boolean}}
 */
export function fighterFrame(fighter) {
  return {
    x: fighter.sprite.x,
    y: fighter.sprite.y,
    scaleX: fighter.sprite.scaleX,
    scaleY: fighter.sprite.scaleY,
    bodyScale: fighter.body.scaleX,
    bodyY: fighter.body.y,
    flip: Boolean(fighter.body.flipX),
  };
}
