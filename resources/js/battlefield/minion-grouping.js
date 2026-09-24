import { shortestAngleDelta } from './minion-layout.js';

/**
 * Whether two fighters are close enough to trigger the "gather toward each
 * other" minion behavior — distance no more than `ratio` times their
 * average current size. Deliberately size-relative rather than a fixed
 * pixel threshold, matching every other distance minions.js already
 * computes (IDLE_HOME_RADIUS_RATIO etc.): a fixed px value would read wrong
 * once `fighterDisplayConfig` shrinks fighters for a crowded roster, or
 * grows them from damage.
 *
 * @param {{x: number, y: number, size: number}} a
 * @param {{x: number, y: number, size: number}} b
 * @param {number} ratio
 * @return {boolean}
 */
export function isNear(a, b, ratio) {
  const avgSize = (a.size + b.size) / 2;
  const dist = Math.hypot(a.x - b.x, a.y - b.y);
  return dist <= avgSize * ratio;
}

/**
 * Greedy single-link clustering of angles (radians): two angles within
 * `coneWidthRad` of each other join the same cluster, and clusters can
 * chain-merge through an intermediate angle (a joins b, b joins c => a, b, c
 * all end up together, even if a and c alone would not have merged) — the
 * same behavior that makes a reasonable triangle's two neighbor directions
 * merge into one zone, while two directly-opposite neighbors (a straight
 * line) split into separate zones, from one rule with no special-casing per
 * shape. Each cluster's direction is its members' circular mean
 * (atan2(mean(sin), mean(cos)), not a plain arithmetic mean, which breaks
 * across the -π/π wraparound).
 *
 * @param {Array<number>} angles
 * @param {number} coneWidthRad
 * @return {Array<{indices: Array<number>, meanAngle: number}>}
 */
export function clusterAngles(angles, coneWidthRad) {
  const remaining = angles.map((angle, index) => ({ angle, index }));
  const clusters = [];

  while (remaining.length > 0) {
    const group = [remaining.shift()];
    let changed = true;
    while (changed) {
      changed = false;
      for (let i = remaining.length - 1; i >= 0; i--) {
        const candidate = remaining[i];
        const joinsGroup = group.some(member => Math.abs(shortestAngleDelta(member.angle, candidate.angle)) <= coneWidthRad);
        if (joinsGroup) {
          group.push(candidate);
          remaining.splice(i, 1);
          changed = true;
        }
      }
    }
    clusters.push({
      indices: group.map(member => member.index),
      meanAngle: circularMean(group.map(member => member.angle)),
    });
  }

  return clusters;
}

/**
 * @param {Array<number>} angles
 * @return {number}
 */
function circularMean(angles) {
  const sumSin = angles.reduce((sum, angle) => sum + Math.sin(angle), 0);
  const sumCos = angles.reduce((sum, angle) => sum + Math.cos(angle), 0);
  return Math.atan2(sumSin, sumCos);
}

/**
 * A fighter's current "gather zones" — one per cluster of nearby-neighbor
 * directions (see clusterAngles), each carrying the neighbor ids that
 * contributed to it (a stable identity for that zone across frames, so
 * minions.js can keep a minion's zone assignment stable as long as the same
 * neighbor combination is still the reason that zone exists — see
 * minions.js's own zone-assignment docblock). Empty when nothing is near,
 * so the caller falls back to the fighter's normal all-around idle spread.
 *
 * `distance` is the closest of the zone's merged neighbors (not their
 * average) — minions.js uses it to pull the zone's own radius toward the
 * midpoint with whichever neighbor is actually nearest, since a merged
 * cluster's other, farther neighbors would otherwise dilute the pull and
 * leave minions short of everyone's actual meeting point.
 *
 * @param {{id: number|string, x: number, y: number, size: number}} fighter
 * @param {Array<{id: number|string, x: number, y: number, size: number}>} others
 * @param {{nearRatio?: number, coneWidthRad?: number}} opts
 * @return {Array<{angle: number, neighborIds: Array<number|string>, distance: number}>}
 */
export function computeZones(fighter, others, opts = {}) {
  const nearRatio = opts.nearRatio ?? 7;
  const coneWidthRad = opts.coneWidthRad ?? Math.PI / 2.4;

  const near = others.filter(other => isNear(fighter, other, nearRatio));
  if (near.length === 0) {
    return [];
  }

  const angles = near.map(other => Math.atan2(other.y - fighter.y, other.x - fighter.x));
  const distances = near.map(other => Math.hypot(other.x - fighter.x, other.y - fighter.y));
  const clusters = clusterAngles(angles, coneWidthRad);

  return clusters.map(cluster => ({
    angle: cluster.meanAngle,
    distance: Math.min(...cluster.indices.map(index => distances[index])),
    neighborIds: cluster.indices.map(index => near[index].id),
  }));
}

/**
 * A minion's angular offset from its zone's own direction, evenly fanning
 * every minion sharing that zone across a `arcWidthRad`-wide wedge centered
 * on it — so several minions assigned the same zone spread out instead of
 * stacking on one point. A lone minion in a zone sits exactly on the zone's
 * own direction (offset 0).
 *
 * @param {number} indexInZone this minion's 0-indexed position among the others sharing its zone
 * @param {number} countInZone how many minions share this zone
 * @param {number} arcWidthRad
 * @return {number}
 */
export function zoneFanOffset(indexInZone, countInZone, arcWidthRad) {
  if (countInZone <= 1) {
    return 0;
  }
  return -arcWidthRad / 2 + (indexInZone / (countInZone - 1)) * arcWidthRad;
}
