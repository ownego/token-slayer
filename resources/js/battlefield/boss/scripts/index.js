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

/**
 * The script to start for a boss, or null. A scripted boss whose state is
 * gone does not start: the engine clears bossState.script on a kill, so a
 * scene restarted from a snapshot during the kill ceremony (a rotate) does
 * not bring the dead boss's script back.
 *
 * @param {string|undefined} bossKey A BOSS_TYPES key.
 * @param {{script?: object}|null|undefined} bossState
 * @return {object|null}
 */
export function scriptToRun(bossKey, bossState) {
  const def = scriptFor(bossKey);
  if (!def || (def.readState && !bossState?.script)) {
    return null;
  }
  return def;
}

// Exposed so config.test.js can check every key is a real BOSS_TYPES key.
scriptFor.registry = registry;
