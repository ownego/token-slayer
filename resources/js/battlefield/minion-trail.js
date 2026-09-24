/**
 * Returns the position a leader's own recorded trail was at a given moment,
 * so a follower can replay that exact path with a fixed time delay instead
 * of steering toward the leader's live position (which would cut corners on
 * a bent route rather than actually retrace it). Shared by minions.js: each
 * minion in the chain samples the same trail at a different, larger delay,
 * so together they read as one train following the leader's real path.
 *
 * @param {Array<{x: number, y: number, t: number}>} history samples sorted ascending by t
 * @param {number} atTime the timestamp to sample at (same clock as history[].t)
 * @return {?{x: number, y: number}} interpolated position, or null for an empty history
 */
export function sampleTrail(history, atTime) {
  if (history.length === 0) {
    return null;
  }
  if (history.length === 1 || atTime <= history[0].t) {
    return { x: history[0].x, y: history[0].y };
  }
  const last = history[history.length - 1];
  if (atTime >= last.t) {
    return { x: last.x, y: last.y };
  }
  for (let i = 1; i < history.length; i++) {
    const b = history[i];
    if (atTime > b.t) {
      continue;
    }
    const a = history[i - 1];
    const span = b.t - a.t;
    const progress = span === 0 ? 0 : (atTime - a.t) / span;
    return {
      x: a.x + (b.x - a.x) * progress,
      y: a.y + (b.y - a.y) * progress,
    };
  }
  return { x: last.x, y: last.y };
}

/**
 * Appends a new sample to a leader's trail history and drops samples too old
 * to ever be sampled again, without mutating the input array.
 *
 * @param {Array<{x: number, y: number, t: number}>} history samples sorted ascending by t
 * @param {{x: number, y: number, t: number}} sample the new sample to append
 * @param {number} maxAgeMs samples older than `sample.t - maxAgeMs` are dropped
 * @return {Array<{x: number, y: number, t: number}>} the new history array
 */
export function pushTrailSample(history, sample, maxAgeMs) {
  const cutoff = sample.t - maxAgeMs;

  return [...history.filter((s) => s.t >= cutoff), sample];
}
