/**
 * The animation a fighter plays while its player works (it is charging, not
 * moving). Fighters walk in place; a flying hero (Iron Man, Superman) stands on
 * its hover with its jets lit, and flies only when it actually moves.
 *
 * @param {{flies?: boolean}} ftype A FIGHTER_TYPES entry or a custom fighter type.
 * @return {string} The animation state: 'idle' or 'walk'.
 */
export function chargeAnim(ftype) {
  return ftype.flies ? 'idle' : 'walk';
}
