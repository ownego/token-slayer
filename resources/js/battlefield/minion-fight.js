/**
 * Counts each clashing side's own minions standing inside a fight's area —
 * what decides who is likelier to land the blow (see pickAttackerSide). A
 * third fighter's minion wandering through the same spot counts for
 * neither side.
 *
 * @param {Array<{side: *, x: number, y: number}>} points every currently-positioned minion
 * @param {{x: number, y: number}} center the fight's midpoint
 * @param {number} radius how far from the midpoint still counts as "in the area"
 * @param {*} sideA
 * @param {*} sideB
 * @return {{a: number, b: number}}
 */
export function countSidesNear(points, center, radius, sideA, sideB) {
  const counts = { a: 0, b: 0 };
  for (const p of points) {
    if (Math.hypot(p.x - center.x, p.y - center.y) > radius) {
      continue;
    }
    if (p.side === sideA) {
      counts.a++;
    } else if (p.side === sideB) {
      counts.b++;
    }
  }
  return counts;
}

/**
 * Picks which side attacks, weighted by how many minions each side has in
 * the area: 3 vs 7 gives side a a 30% chance and side b 70%.
 *
 * @param {number} countA
 * @param {number} countB
 * @param {number} roll a uniform random number in [0, 1)
 * @return {'a'|'b'}
 */
export function pickAttackerSide(countA, countB, roll) {
  const total = countA + countB;
  const shareA = total > 0 ? countA / total : 0.5;
  return roll < shareA ? 'a' : 'b';
}

/**
 * Where a travelling attack (a leap or a dash) lands: on the straight line
 * from the attacker to its target, stopping `stopShort` before it so the two
 * sprites end up side by side instead of on top of each other. An attacker
 * already that close stays where it is.
 *
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {number} stopShort
 * @return {{x: number, y: number}}
 */
export function travelLanding(from, to, stopShort) {
  const dx = to.x - from.x;
  const dy = to.y - from.y;
  const dist = Math.hypot(dx, dy);
  if (dist <= stopShort) {
    return { x: from.x, y: from.y };
  }
  const t = (dist - stopShort) / dist;
  return { x: from.x + dx * t, y: from.y + dy * t };
}

/**
 * Maps a uniform [0, 1) roll onto one entry of a non-empty list.
 *
 * @param {Array<*>} list
 * @param {number} roll
 * @return {*}
 */
export function pickRandom(list, roll) {
  return list[Math.min(list.length - 1, Math.floor(roll * list.length))];
}

/**
 * The attacks a settled minion may play as its idle fidget: everything
 * except the ones flagged `fidget: false` (a dash has no reason to happen
 * without a target to run at).
 *
 * @param {Array<{anim: string, fidget?: boolean}>} attacks
 * @return {Array<{anim: string, fidget?: boolean}>}
 */
export function fidgetAttacks(attacks) {
  return attacks.filter((a) => a.fidget !== false);
}

/**
 * The flipX that makes a minion at `fromX` face something at `toX`. Every
 * minion strip is drawn facing right, so only a target on the left flips it;
 * a target straight above/below keeps whatever facing it already has.
 *
 * @param {number} fromX
 * @param {number} toX
 * @param {boolean} current the sprite's current flipX
 * @return {boolean}
 */
export function flipToFace(fromX, toX, current = false) {
  if (Math.abs(toX - fromX) < 0.5) {
    return current;
  }
  return toX < fromX;
}

/**
 * The flipX that makes a settled minion face along a gathering zone's
 * direction (radians, 0 = right) — i.e. toward the other fighter's swarm, so
 * an idle fidget reads as squaring up to them instead of whatever way it
 * last happened to walk. A zone pointing (nearly) straight up/down keeps the
 * current facing.
 *
 * @param {number} angle
 * @param {boolean} current the sprite's current flipX
 * @return {boolean}
 */
export function flipToFaceAngle(angle, current = false) {
  const c = Math.cos(angle);
  if (Math.abs(c) < 0.2) {
    return current;
  }
  return c < 0;
}
