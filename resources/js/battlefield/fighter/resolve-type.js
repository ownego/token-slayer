import { FIGHTER_TYPES } from '@battlefield/config.js';
import { TextureKey } from '@battlefield/constants.js';

/** The roster fighters a missing hero may be drawn as: every type not on the hero atlas. */
const ROSTER_TYPES = FIGHTER_TYPES.filter(ft => ft.texture !== TextureKey.HEROES);

/** How many roster fighters a stand-in is picked from. */
export const ROSTER_COUNT = ROSTER_TYPES.length;

/**
 * The fighter type a client draws for a character key. A hero needs its own
 * atlas, so a client whose hero atlas did not load (or a key this bundle does
 * not know, from a newer server) draws a roster fighter picked by user id —
 * never a hero, never a missing-frame box.
 *
 * @param {string|null} key The character key the server sent.
 * @param {number|string} userId The fighter's user id.
 * @param {{heroesLoaded: boolean}} options Whether the hero atlas is loaded.
 * @return {object} A FIGHTER_TYPES entry.
 */
export function resolveFighterType(key, userId, { heroesLoaded }) {
  const ftype = key ? FIGHTER_TYPES.find(ft => ft.key === key) : null;
  if (ftype && (ftype.texture !== TextureKey.HEROES || heroesLoaded)) {
    return ftype;
  }
  return ROSTER_TYPES[Math.abs(Number(userId) || 0) % ROSTER_COUNT];
}

/**
 * The character key a fighter's snapshot keeps: the server's, even when this
 * client drew a stand-in for it, so a rotate that loads the hero atlas draws
 * the hero.
 *
 * @param {string|null} key The character key the server sent.
 * @param {object} ftype The type this client drew.
 * @return {string}
 */
export function snapshotCharacter(key, ftype) {
  return key ?? ftype.key;
}
