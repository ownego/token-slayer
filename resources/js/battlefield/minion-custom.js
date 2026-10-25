import { MINION_FALL_HOLD_MS, MINION_POOL_CUSTOM_CAP, MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FOOT_ROW, normalizeCustomCharacter } from '@battlefield/fighter/custom-fighter.js';

/**
 * The attack frame a custom minion lands its hit on. `SpriteStripBuilder` holds
 * each attack pose for two frames, so the third pose starts at frame 4. A strip with
 * fewer frames lands on its last one (`customHitFrame`).
 * @type {number}
 */
export const CUSTOM_MINION_HIT_FRAME = 4;

/**
 * What a custom minion plays when it is hit: fall (the death strip), lie there,
 * get back up. Same shape as a default minion's `reaction` (config/companions.js).
 * @type {{anim: string, holdMs: number, getUp: boolean}}
 */
const CUSTOM_MINION_REACTION = { anim: 'death', holdMs: MINION_FALL_HOLD_MS, getUp: true };

/**
 * Tallest and shortest a minion's measured body may be, in px up from the foot row of its
 * 100 px frame. The same range the server measures into (`MeasureStripBodyHeight`) and
 * the sheet draws a minion's box in.
 * @type {{min: number, max: number}}
 */
const BODY_PX_RANGE = { min: 1, max: CUSTOM_FOOT_ROW };

/**
 * The keys of the default minion looks, which is all a pool may name.
 * @type {Set<string>}
 */
const DEFAULT_LOOK_KEYS = new Set(MINION_TYPES.map(type => type.key));

/**
 * Reads a player's minion pool off the wire (`minion_pool`, snake_case), off the boot
 * payload (`minionPool`, whose inner keys are snake_case too) or off a snapshot (already
 * camelCase). Anything that is not an object with a whole-number `rev`, a `defaults` array
 * and a `custom` array is `null`: "no information", which callers must keep apart from a
 * pool that names nothing. Within it, a default key the game does not know is dropped (a
 * look removed since the server last heard of it), so is a custom minion that is not a
 * complete custom character or whose `body_px` is not a whole number from 1 to
 * `CUSTOM_FOOT_ROW`; a version listed twice counts once; and `custom` is cut at
 * `MINION_POOL_CUSTOM_CAP`.
 *
 * @param {*} raw
 * @return {?{rev: number, defaults: Array<string>, custom: Array<{version: string, urlBase: string, animations: object, bodyPx: number}>}}
 */
export function normalizeMinionPool(raw) {
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  if (!Number.isInteger(raw.rev) || raw.rev < 0 || !Array.isArray(raw.defaults) || !Array.isArray(raw.custom)) {
    return null;
  }

  const defaults = [...new Set(raw.defaults.filter(key => DEFAULT_LOOK_KEYS.has(key)))];
  const seen = new Set();
  const custom = [];
  for (const item of raw.custom) {
    const spec = normalizeCustomCharacter(item);
    const bodyPx = item?.body_px ?? item?.bodyPx;
    if (!spec || !Number.isInteger(bodyPx) || bodyPx < BODY_PX_RANGE.min || bodyPx > BODY_PX_RANGE.max || seen.has(spec.version)) {
      continue;
    }
    seen.add(spec.version);
    custom.push({ ...spec, bodyPx });
  }

  return { rev: raw.rev, defaults, custom: custom.slice(0, MINION_POOL_CUSTOM_CAP) };
}

/**
 * Whether a pool that just arrived is newer than the one a fighter holds. Pools reach the
 * client by three routes (a broadcast, a charge or join that read the pool a moment
 * earlier, a resync), and Reverb orders none of them, so each carries the owner's `rev`
 * and only a higher one wins. An equal `rev` is the same pool again.
 *
 * @param {?{rev: number}} current The pool the fighter holds, `null` when it holds none.
 * @param {{rev: number}} incoming A normalised pool.
 * @return {boolean}
 */
export function isNewerPool(current, incoming) {
  return current == null || incoming.rev > current.rev;
}

/**
 * The attack frame a custom minion's blow lands on: `CUSTOM_MINION_HIT_FRAME`, or the last
 * frame of a strip too short to have one.
 *
 * @param {number} attackFrames How many frames the minion's attack strip has.
 * @return {number}
 */
export function customHitFrame(attackFrames) {
  return Math.max(0, Math.min(CUSTOM_MINION_HIT_FRAME, attackFrames - 1));
}

/**
 * The key a custom minion goes by: its type `key`, the prefix of its animation keys
 * and the name of its texture. Namespaced apart from `customFighterKey` so a fighter
 * changing version can never release a texture a minion still draws.
 *
 * @param {number|string} userId
 * @param {string} version
 * @return {string}
 */
export function customMinionKey(userId, version) {
  return `minion-${userId}-${version}`;
}

/**
 * A MINION_TYPES-shaped entry for one of a player's custom minions. The fight code
 * reads it like a default type (`animFiles` counts and rates, `attacks`, `reaction`,
 * `charHeight`); `texture` and `custom` say it lives in its own stacked texture.
 *
 * @param {number|string} userId
 * @param {{version: string, animations: Object<string, {frames: number, rate: number}>, bodyPx: number}} spec A normalised custom minion.
 * @return {{key: string, texture: string, custom: true, spec: object, animations: object, charHeight: number, animFiles: object, attacks: Array<{anim: string, hitFrame: number}>, reaction: object}}
 */
export function customMinionType(userId, spec) {
  const key = customMinionKey(userId, spec.version);
  const { idle, walk, attack, death } = spec.animations;

  return {
    key,
    texture: key,
    custom: true,
    spec,
    animations: spec.animations,
    charHeight: spec.bodyPx,
    animFiles: {
      idle: { count: idle.frames, rate: idle.rate, loop: true },
      walk: { count: walk.frames, rate: walk.rate, loop: true },
      attack: { count: attack.frames, rate: attack.rate },
      death: { count: death.frames, rate: death.rate },
    },
    attacks: [{ anim: 'attack', hitFrame: customHitFrame(attack.frames) }],
    reaction: { ...CUSTOM_MINION_REACTION },
  };
}

/**
 * The texture and frame a minion's sprite is made from: a named frame of its own
 * stacked texture for a custom type, the whole idle sheet for a default one.
 *
 * @param {{key: string, texture?: string, custom?: boolean}} type A custom or a default minion type.
 * @return {{texture: string, frame: (string|undefined)}}
 */
export function minionSpriteFrame(type) {
  if (type.custom) {
    return { texture: type.texture, frame: `${type.key}-idle-0` };
  }

  return { texture: `${type.key}-idle`, frame: undefined };
}

/**
 * Picks the look of one spawn, evenly from the player's pool: the default types its pool
 * keeps and the custom minions whose textures are in (a custom one still loading is not a
 * candidate yet). `random` is passed in so the choice is a pure function of its inputs.
 *
 * @param {Array<object>} loadedCustom The player's custom types whose textures are in.
 * @param {Array<object>} defaultTypes The MINION_TYPES entries the pool's `defaults` keep.
 * @param {number} random A number in [0, 1).
 * @return {object}
 */
export function pickMinionType(loadedCustom, defaultTypes, random) {
  const candidates = [...defaultTypes, ...loadedCustom];

  return candidates[Math.min(candidates.length - 1, Math.floor(random * candidates.length))];
}

/**
 * What a spawn does about the player's custom minions: draw from the pool with them in,
 * wait a little for them to load, or give up and draw without them.
 *
 * @param {{setSize: number, settled: boolean, waitedMs: number, waitMs: number}} input
 *   `setSize` is how many custom minions the pool has; `settled` is true once every one of
 *   them is in (or failed).
 * @return {('custom'|'wait'|'default')} `custom`: draw from the pool with the loaded custom minions; `default`: draw without any that is not in.
 */
export function spawnLookDecision({ setSize, settled, waitedMs, waitMs }) {
  if (setSize === 0) {
    return 'default';
  }
  if (settled) {
    return 'custom';
  }

  return waitedMs >= waitMs ? 'default' : 'wait';
}
