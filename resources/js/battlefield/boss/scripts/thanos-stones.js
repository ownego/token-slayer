// Pure stone arithmetic for the thanos script. The server sends {stones, stoneSchedule}
// (see App\Support\StoneClock): the count plus every tick still to come, as UTC
// instants. The client only counts how many of those have passed, so it needs no
// calendar, timezone or tick-time knowledge of its own.

export const STONE_MAX = 6;

// Power, Space, Reality, Soul, Time, Mind — filled left to right in this order.
export const STONE_COLORS = [0x8b5cf6, 0x3b82f6, 0xef4444, 0xf97316, 0x22c55e, 0xeab308];

/**
 * Steps a stone state forward to `now`.
 *
 * @param {{stones?: number, stoneSchedule?: string|Array<number>}} state
 * @param {number} now Epoch milliseconds.
 * @param {number} [max=STONE_MAX]
 * @return {{stones: number, stoneSchedule: Array<number>}}
 */
export function advanceStones(state, now, max = STONE_MAX) {
  const schedule = toSchedule(state.stoneSchedule);
  const passed = schedule.filter((at) => at <= now).length;
  const stones = Math.min((state.stones ?? 0) + passed, max);

  return { stones, stoneSchedule: stones >= max ? [] : schedule.filter((at) => at > now) };
}

// A comma-joined ISO string arrives from the boot payload and broadcasts; an
// array of epoch ms from a snapshot.
function toSchedule(value) {
  if (Array.isArray(value)) return [...value];
  if (!value) return [];
  return value.split(',').map((iso) => Date.parse(iso));
}
