/**
 * Returns the local offset (relative to the fighter's foot anchor) of one
 * minion's own idle "home slot" — evenly dividing the circle among the
 * fighter's current minion count so they spread out around it instead of
 * stacking on the same point. Recomputed fresh every frame from the
 * minion's own array index and the current count (see minions.js), so it
 * self-rebalances for free whenever a minion spawns or despawns.
 *
 * @param {number} index this minion's 0-indexed position in its fighter's list
 * @param {number} count how many minions that fighter currently has
 * @param {number} radius distance from the foot anchor to the slot
 * @return {{x: number, y: number}}
 */
export function homeSlotOffset(index, count, radius) {
  const angle = homeSlotAngle(index, count);

  return { x: radius * Math.cos(angle), y: radius * Math.sin(angle) };
}

/**
 * The angle (radians) of one minion's home slot around the circle — the
 * same division homeSlotOffset derives its point from, extracted so
 * minions.js can smoothly tween a minion's own *rendered* angle toward this
 * target when the slot count changes (spawn/despawn), instead of every
 * remaining minion snapping straight to its new slot the instant the
 * fighter's count changes (see shortestAngleDelta for the tween step).
 *
 * @param {number} index this minion's 0-indexed position in its fighter's list
 * @param {number} count how many minions that fighter currently has
 * @return {number}
 */
export function homeSlotAngle(index, count) {
  return (2 * Math.PI * index) / count;
}

/**
 * The shortest signed angular distance (radians, in (-π, π]) from `from` to
 * `to` — adding this delta to `from` reaches `to` by the short way around
 * the circle, so a minion re-tweening toward a new home slot never spins
 * the long way round when the slot count wraps past 0/2π.
 *
 * @param {number} from
 * @param {number} to
 * @return {number}
 */
export function shortestAngleDelta(from, to) {
  let delta = (to - from) % (2 * Math.PI);
  if (delta > Math.PI) {
    delta -= 2 * Math.PI;
  } else if (delta <= -Math.PI) {
    delta += 2 * Math.PI;
  }

  return delta;
}

/**
 * Whether a minion at `pointY` should draw in front of its fighter (true)
 * or tucked behind it (false) — the same near/far Y-sort already used for
 * the fighter's own flair-ring orbit (fighter/index.js's `back = sinA < 0`):
 * a minion at or below the foot anchor stands on the near/ground side and
 * draws in front; one above it (up around leg/torso height, since minions
 * orbit the foot in a full circle, not just its southern half) draws behind,
 * as if occluded by the body.
 *
 * @param {number} pointY the minion's current world-space Y
 * @param {number} anchorY the fighter's own foot-anchor Y
 * @return {boolean}
 */
export function isInFrontOfFighter(pointY, anchorY) {
  return pointY >= anchorY;
}
