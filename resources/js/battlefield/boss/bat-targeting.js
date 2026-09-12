/** HP-fraction thresholds at which bat 1..5 dies, in order. */
export const BAT_HP_THRESHOLDS = [0.80, 0.60, 0.40, 0.20, 0.10];

/**
 * Resolves which entity a single HitDealt visually lands on, purely from the
 * damage number and the boss HP fraction before/after this hit — no server
 * involvement (see the design spec's "Bat boss-minion mechanic" section).
 *
 * @param {{ damage: number, hpBeforePct: number, hpAfterPct: number, aliveBats: Array<boolean> }} args
 *   aliveBats is 1-indexed: aliveBats[1] is bat 1's alive flag ... aliveBats[5]
 *   is bat 5's. Index 0 is unused, kept so "bat 3" reads as index 3.
 * @return {{ targetBat: number|null, killedBats: Array<number> }}
 *   targetBat is null when the hit lands on the boss. killedBats lists every
 *   bat index whose threshold this single hit crossed, ascending; when
 *   non-empty, targetBat is always the last (lowest-HP) one killed,
 *   overriding whatever the damage tail digit would otherwise have picked.
 */
export function computeBatHitTarget({ damage, hpBeforePct, hpAfterPct, aliveBats }) {
  const killedBats = [];
  for (let i = 0; i < BAT_HP_THRESHOLDS.length; i++) {
    const batIndex = i + 1;
    const threshold = BAT_HP_THRESHOLDS[i];
    if (hpBeforePct > threshold && hpAfterPct <= threshold && aliveBats[batIndex]) {
      killedBats.push(batIndex);
    }
  }
  if (killedBats.length > 0) {
    return { targetBat: killedBats[killedBats.length - 1], killedBats };
  }
  const tail = damage % 10;
  if (tail >= 1 && tail <= 5 && aliveBats[tail]) {
    return { targetBat: tail, killedBats: [] };
  }
  return { targetBat: null, killedBats: [] };
}
