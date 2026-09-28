/**
 * Leaderboard ranks for the current boss, with each row's climb since the
 * last update and the ids it overtook (for the Overtake effect).
 * @param {Map<number, number>} prevRanks id → previous 0-based rank
 * @param {Map<number, number>} damageById
 * @param {number} [limit]
 * @return {{rows:object[], total:number, more:number, leader:number|null}}
 */
export function rankBoard(prevRanks, damageById, limit = 5) {
  const sorted = [...damageById.entries()].filter(([, d]) => d > 0).sort((a, b) => b[1] - a[1]);
  const total = sorted.reduce((s, [, d]) => s + d, 0);
  const rows = sorted.slice(0, limit).map(([id, damage], rank) => {
    const before = prevRanks.get(id);
    const climbed = before === undefined ? 0 : Math.max(0, before - rank);
    const passedIds = climbed ? [...prevRanks.entries()].filter(([o, r]) => o !== id && r >= rank && r < before).map(([o]) => o) : [];
    return { id, damage, rank, share: total ? damage / total : 0, climbed, passedIds };
  });
  return { rows, total, more: Math.max(0, sorted.length - limit), leader: sorted[0]?.[0] ?? null };
}
