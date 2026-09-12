/**
 * Above this many fighters already waiting behind the one the Necromancer is
 * currently summoning, a newly-joined fighter skips the cast flourish
 * entirely (reveals immediately) instead of growing the wait further — a
 * burst of joins (e.g. a whole team starting sessions at once) would
 * otherwise queue everyone behind a full teleport/cast/return cycle each.
 */
export const SUMMON_QUEUE_BURST_LIMIT = 2;

/**
 * Decides whether a newly-requested summon should skip the Necromancer's
 * visit and reveal immediately, given how many summons are already waiting.
 *
 * @param {number} queueLength - summons already queued (not counting the one in flight)
 * @param {number} [limit] - defaults to SUMMON_QUEUE_BURST_LIMIT
 * @return {boolean}
 */
export function shouldSkipSummonFlourish(queueLength, limit = SUMMON_QUEUE_BURST_LIMIT) {
  return queueLength >= limit;
}
