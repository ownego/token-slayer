const ACCENTS = {
  soldier: '#ffbb00', knight: '#ffbb00', swordsman: '#ffbb00', axeman: '#ffbb00',
  orc: '#88ee44', 'armored-orc': '#88ee44', 'elite-orc': '#88ee44',
  skeleton: '#aaddff', 'armored-skeleton': '#aaddff', 'greatsword-skeleton': '#aaddff',
  'skeleton-archer': '#aaddff',
  slime: '#ddff44', archer: '#ffee44', werewolf: '#cc88ff', werebear: '#cc88ff',
  'orc-rider': '#88ee44',
  'knight-templar': '#ffbb00', lancer: '#ffbb00',
  wizard: '#cc88ff', priest: '#cc88ff',
};

/**
 * The accent color for a fighter type's own roster/preview chrome, or the
 * shared amber fallback for anything not in the table.
 *
 * @param {string} key
 * @return {string}
 */
export function accentFor(key) {
  return ACCENTS[key] ?? '#fbbf24';
}

/**
 * The roster entries matching a filter chip's attack type, or the full
 * roster for the "All" chip (empty string).
 *
 * @param {Array<{key: string, attackType: string}>} roster
 * @param {string} attackType '' for all, or one of the AttackType values
 * @return {Array<{key: string, attackType: string}>}
 */
export function filterRoster(roster, attackType) {
  return attackType === '' ? roster : roster.filter(entry => entry.attackType === attackType);
}
