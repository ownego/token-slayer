/**
 * Decides which flourish Priest's Blast attack plays for this hit, purely
 * from the damage's parity — even lands the normal elemental burst on the
 * boss, odd instead sends a heal orb to the Necromancer (which then bolts
 * the boss itself). Cosmetic only; the real damage/HP still lands via the
 * normal beam+projectile regardless of which branch fires.
 *
 * @param {number} damage
 * @return {boolean}
 */
export function isHealRoll(damage) {
  return damage % 2 !== 0;
}
