/**
 * Picks which of a fighter's attacks a hit plays. Each attack counts by its
 * `weight` (1 when it has none), so the roster's unweighted attacks stay an
 * even split and a hero's rarer big moves come up less often.
 *
 * @param {Array<{weight?: number}>|null} attacks The fighter type's `attacks`.
 * @param {number} roll A random number in [0, 1).
 * @return {number} The attack's index, or -1 when there is none.
 */
export function pickAttackIndex(attacks, roll) {
  if (!attacks?.length) return -1;
  const weights = attacks.map(a => a.weight ?? 1);
  const total = weights.reduce((sum, w) => sum + w, 0);
  let edge = 0;
  for (let i = 0; i < weights.length; i++) {
    edge += weights[i] / total;
    if (roll < edge) return i;
  }
  return weights.length - 1;
}
