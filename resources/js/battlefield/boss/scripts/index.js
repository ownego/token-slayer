// Registry of per-boss scripts, keyed by BOSS_TYPES key. The boss engine
// (boss/index.js) never branches on a boss key itself: it resolves a script
// here and calls its optional hooks — readState / create / destroy — at the
// matching points of the boss lifecycle (a script may run its own ticker). A boss with no entry runs
// the plain engine behaviour. Adding a boss with its own behaviour is one
// file in this directory plus one line below.
import { thanosScript } from './thanos.js';

const registry = {
  'boss-thanos': thanosScript,
};

/**
 * Resolves the script for a boss key.
 *
 * @param {string|undefined} bossKey A BOSS_TYPES key.
 * @return {object|null} The script, or null when the boss has none.
 */
export function scriptFor(bossKey) {
  return (bossKey && registry[bossKey]) || null;
}

// Exposed so config.test.js can check every key is a real BOSS_TYPES key.
scriptFor.registry = registry;
