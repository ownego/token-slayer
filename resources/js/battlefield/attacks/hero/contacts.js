/**
 * When a hero move's contacts land. Exactly one is the main contact: the only
 * one that calls `onImpact` (the HP drop and the damage number). The others are
 * visual only, so they are the first to go when the field is busy (`capped`),
 * under reduced motion, or after the main contact of a kill shot, when the
 * kill ceremony is about to start.
 *
 * @param {Array<{atMs: number, main?: boolean}>} contacts The move's contacts, in order (other fields, such as an id, are kept).
 * @param {{capped: boolean, reduced: boolean, killShot: boolean, maxMs: number}} options
 * @return {Array<{atMs: number, main: boolean}>}
 */
export function planContacts(contacts, { capped, reduced, killShot, maxMs }) {
  const marked = contacts.findIndex(c => c.main);
  const mainIndex = marked >= 0 ? marked : contacts.length - 1;
  const mainAt = contacts[mainIndex].atMs;
  const main = { ...contacts[mainIndex], atMs: Math.min(mainAt, maxMs), main: true };
  if (capped || reduced) {
    return [main];
  }
  return contacts.flatMap((c, i) => {
    if (i === mainIndex) return [main];
    if (killShot && c.atMs > mainAt) return [];
    return [{ ...c, atMs: Math.min(c.atMs, maxMs), main: false }];
  });
}

/**
 * Whether a contact survived planContacts (the field was not too busy for it).
 *
 * @param {Array<{id: string}>} contacts The planned contacts.
 * @param {string} id
 * @return {boolean}
 */
export function has(contacts, id) {
  return contacts.some(c => c.id === id);
}
