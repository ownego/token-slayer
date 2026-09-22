// Pure stone arithmetic for the thanos script. The server sends {stones, nextStoneAt}
// (see App\Support\StoneClock); the client only has to notice when nextStoneAt has
// passed and step forward in whole days — Vietnam has no DST, so +24h is exact.

export const STONE_MAX = 6;

// Power, Space, Reality, Soul, Time, Mind — filled left to right in this order.
export const STONE_COLORS = [0x8b5cf6, 0x3b82f6, 0xef4444, 0xf97316, 0x22c55e, 0xeab308];

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Steps a stone state forward to `now`.
 *
 * @param {{stones?: number, nextStoneAt?: number|string|null}} state
 * @param {number} now Epoch milliseconds.
 * @param {number} [max=STONE_MAX]
 * @return {{stones: number, nextStoneAt: number|null}}
 */
export function advanceStones(state, now, max = STONE_MAX) {
  let stones = state.stones ?? 0;
  let next = toMs(state.nextStoneAt);

  while (next != null && now >= next && stones < max) {
    stones++;
    next += DAY_MS;
  }
  if (stones >= max) next = null;

  return { stones, nextStoneAt: next };
}

// ISO strings arrive from the boot payload and broadcasts; numbers from a snapshot.
function toMs(value) {
  if (value == null) return null;
  return typeof value === 'string' ? Date.parse(value) : value;
}
