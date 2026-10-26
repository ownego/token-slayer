import { CUSTOM_FOOT_ROW, CUSTOM_FRAME } from '@battlefield/fighter/custom-fighter.js';

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

/** How far the tool ring sits outside the badge edge, as a fraction of the badge's own display size — mirrors Charge.createChargingRing's `avR + max(4, displaySize*0.08)` margin shape, scaled down since a minion's badge is far smaller in absolute px than a fighter's avatar. */
export const TOOL_RING_MARGIN_RATIO = 0.15;
/** The least the tool ring sits outside the badge edge (world px), so a tiny badge still gets a visible gap. */
export const TOOL_RING_MIN_MARGIN_PX = 2;
/** Line width (world px) the tool ring is stroked with. */
export const TOOL_RING_STROKE_PX = 2;

/**
 * Returns the radius of a minion's tool ring around a badge: the badge radius plus the larger of
 * the minimum margin and the margin ratio. The one place the ring's size is decided, shared by
 * `_positionToolRing` (drawing) and `toolRingClearance` (how far the badge is lifted).
 *
 * @param {number} badgePx the badge's display size
 * @return {number}
 */
export function toolRingRadius(badgePx) {
  return badgePx / 2 + Math.max(TOOL_RING_MIN_MARGIN_PX, badgePx * TOOL_RING_MARGIN_RATIO);
}

/**
 * Returns how far the tool ring's outer edge reaches beyond the badge edge at scale 1: the margin
 * plus half the stroke. A custom minion's badge is lifted by this much so the ring never reaches
 * the head.
 *
 * @param {number} badgePx the badge's display size
 * @return {number}
 */
export function toolRingClearance(badgePx) {
  return toolRingRadius(badgePx) - badgePx / 2 + TOOL_RING_STROKE_PX / 2;
}

/**
 * World Y of the centre of a minion's avatar badge (and its tool ring).
 *
 * A default type keeps the old rule, a fixed fraction of the minion's visual height above the
 * sprite centre: its ink sits roughly around the frame centre, so that clears the head.
 * A custom type stands with its feet on `CUSTOM_FOOT_ROW` (below the frame centre) and is
 * `charHeight` (= `bodyPx`) rows tall from there, so its real head top is
 * `CUSTOM_FOOT_ROW - charHeight` rows into the frame. The badge sits above that row with a gap
 * of `toolRingClearance`, always (with or without the ring on, so it never jumps): the ring's
 * outer bottom edge at scale 1 then lands on the head top. The ring's pulse swells it to 1.18x
 * while it fades, so it may brush the hair at the peak; that is accepted.
 *
 * @param {{pointY: number, scale: number, type: ?{custom?: boolean}, charHeight: number, sizeRatio: number, offsetRatio: number}} params
 *   `pointY` the sprite centre's world Y; `scale` its sprite scale; `type` the minion's current look (null while it has none);
 *   `charHeight` the look's ink height; `sizeRatio` / `offsetRatio` the badge size and default-type lift, as fractions of the visual height.
 * @return {number}
 */
export function minionBadgeCenterY({ pointY, scale, type, charHeight, sizeRatio, offsetRatio }) {
  const visualHeight = scale * charHeight;
  if (!type?.custom) {
    return pointY - visualHeight * offsetRatio;
  }
  const badgePx = visualHeight * sizeRatio;
  const headTopY = pointY + (CUSTOM_FOOT_ROW - charHeight - CUSTOM_FRAME / 2) * scale;

  return headTopY - toolRingClearance(badgePx) - badgePx / 2;
}
