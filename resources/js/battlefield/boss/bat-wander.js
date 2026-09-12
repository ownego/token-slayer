/**
 * Returns a uniformly-random point inside an elliptical wander zone.
 * Shared by BatSwarm (boss/bats.js) and Necromancer (necromancer.js) so both
 * kinds of ambient hover-wander move the same way.
 *
 * @param {{ centerX: number, centerY: number, radiusX: number, radiusY: number }} zone
 * @param {() => number} [rng=Math.random] injectable for deterministic tests
 * @return {{x: number, y: number}}
 */
export function randomWanderPoint(zone, rng = Math.random) {
  const angle = rng() * Math.PI * 2;
  const r = Math.sqrt(rng()); // sqrt so points spread uniformly over the AREA, not bunched at center
  return {
    x: zone.centerX + Math.cos(angle) * zone.radiusX * r,
    y: zone.centerY + Math.sin(angle) * zone.radiusY * r,
  };
}
