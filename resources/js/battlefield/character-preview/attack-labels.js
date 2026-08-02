import { AttackType } from '@battlefield/constants.js';

/** Per-attackType button labels, indexed by attack slot (0-based). */
export const ATTACK_LABELS = {
  [AttackType.SLASH]:    ['⚔ Slash Combo', '⚔ Spinning Slash', '⚔ Slash Finisher'],
  [AttackType.BLADE]:    ['🗡️ Blade Strike', '🗡️ Blade Flurry', '🗡️ Blade Finisher'],
  [AttackType.SHURIKEN]: ['✴ Shuriken Toss', '✴ Shuriken Storm'],
  [AttackType.ARROW]:    ['🏹 Quick Shot', '🏹 Arrow Volley'],
  [AttackType.BLAST]:    ['🔥 Fireball', '🔥 Blast Wave', '🔥 Meteor'],
};

/**
 * Resolves the skill-row label for one attack slot of a given attackType,
 * falling back to a generic label for slots/types the table doesn't cover.
 *
 * @param {string} attackType - AttackType value
 * @param {number} slotIndex - 0-based index into that character's attacks[]
 * @return {string}
 */
export function getAttackLabel(attackType, slotIndex) {
  return ATTACK_LABELS[attackType]?.[slotIndex] ?? `Attack ${slotIndex + 1}`;
}
