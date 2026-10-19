import { normalizeCustomCharacter } from '@battlefield/fighter/custom-fighter.js';

/**
 * The attack frame a custom minion lands its hit on. `SpriteStripBuilder` holds
 * each attack pose for two frames, so the third pose starts at frame 4.
 * @type {number}
 */
export const CUSTOM_MINION_HIT_FRAME = 4;

/**
 * What a custom minion plays when it is hit: fall (the death strip), lie there,
 * get back up. Same shape as a default minion's `reaction` (config/companions.js).
 * @type {{anim: string, holdMs: number, getUp: boolean}}
 */
const CUSTOM_MINION_REACTION = { anim: 'death', holdMs: 2000, getUp: true };

/**
 * Tallest and shortest a minion's measured body may be, in px of its 100 px frame.
 * @type {{min: number, max: number}}
 */
const BODY_PX_RANGE = { min: 1, max: 100 };

/**
 * Reads a player's set of custom minions off the wire (`custom_minions`, snake_case)
 * or off a snapshot / the boot payload (`customMinions`, already camelCase). Anything
 * that is not an array is `null`: "no information", which callers must keep apart
 * from `[]`, "the player has none". Within an array, an item that is not a complete
 * custom character, or whose `body_px` is not an integer from 1 to 100, is dropped,
 * and a version listed twice counts once.
 *
 * @param {*} raw
 * @return {?Array<{version: string, urlBase: string, animations: object, bodyPx: number}>}
 */
export function normalizeCustomMinions(raw) {
  if (!Array.isArray(raw)) {
    return null;
  }
  const seen = new Set();
  const minions = [];
  for (const item of raw) {
    const spec = normalizeCustomCharacter(item);
    const bodyPx = item?.body_px ?? item?.bodyPx;
    if (!spec || !Number.isInteger(bodyPx) || bodyPx < BODY_PX_RANGE.min || bodyPx > BODY_PX_RANGE.max || seen.has(spec.version)) {
      continue;
    }
    seen.add(spec.version);
    minions.push({ ...spec, bodyPx });
  }

  return minions;
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
    attacks: [{ anim: 'attack', hitFrame: CUSTOM_MINION_HIT_FRAME }],
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
 * Picks the look of one spawn: any of the player's loaded minions, or a default type
 * when none is loaded.
 *
 * @param {Array<object>} loaded The player's custom types whose textures are in.
 * @param {Array<object>} defaults The default MINION_TYPES.
 * @param {number} random A number in [0, 1).
 * @return {object}
 */
export function pickMinionType(loaded, defaults, random) {
  const pool = loaded.length > 0 ? loaded : defaults;

  return pool[Math.min(pool.length - 1, Math.floor(random * pool.length))];
}

/**
 * Whether two sets hold the same minions (by version, in any order). `null` ("no
 * information" or none) and `[]` count as the same empty set.
 *
 * @param {?Array<{version: string}>} a
 * @param {?Array<{version: string}>} b
 * @return {boolean}
 */
export function sameMinionSet(a, b) {
  const left = new Set((a ?? []).map(minion => minion.version));
  const right = new Set((b ?? []).map(minion => minion.version));

  return left.size === right.size && [...left].every(version => right.has(version));
}

/**
 * What a spawn does about the player's set: use the custom looks, wait a little for
 * them to load, or give up and use a default.
 *
 * @param {{setSize: number, settled: boolean, waitedMs: number, waitMs: number}} input
 *   `settled` is true once every look of the set is in (or failed for good).
 * @return {('custom'|'wait'|'default')}
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
