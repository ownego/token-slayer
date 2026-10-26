import { AttackType, TextureKey } from '../constants.js';

/**
 * Side of one frame in a custom fighter's strips, in px. Fighter frames stay
 * 100 px (battlefield.md invariant 3) — the server builds the strips that size.
 * @type {number}
 */
export const CUSTOM_FRAME = 100;

/**
 * The animations a custom fighter ships, in the row order the sheet stacks them.
 * @type {string[]}
 */
const STATES = ['idle', 'walk', 'attack', 'death'];

/**
 * How tall a custom fighter's body is inside its frame. The server pixelates the sheet to
 * `config('token_slayer.custom_character.sprite_height')`, enough pixels for the costume
 * to survive (at 22 px an AI sheet's armour, tunic and trim averaged into brown). 52 is about
 * the most the 100 px frame holds above the foot row (58); the field draws it at
 * `CUSTOM_STAND_PX`. `CustomCharacterConfigTest` keeps this equal to the server's number.
 * @type {number}
 */
const CUSTOM_BODY_PX = 52;

/**
 * How tall a custom fighter stands on the field, in the roster fighters' pixels (their body
 * is 18, a custom one a little taller because a fighter from a player's own sheet read as
 * small next to them).
 * @type {number}
 */
const CUSTOM_STAND_PX = 22;

/**
 * Row of a standing custom fighter's lowest pixel (the server's `FOOT_ROW`). A custom
 * minion's measured body is a height up from this row, so it is also the tallest `body_px`
 * the client accepts (`minion-custom.js`, `sheet/custom-roster.js`).
 * @type {number}
 */
export const CUSTOM_FOOT_ROW = 58;

/**
 * The row a fighter's frame is centred on; a sprite scales about it.
 * @type {number}
 */
const FRAME_CENTRE_ROW = CUSTOM_FRAME / 2;

/**
 * The most frames one strip may have. Ours are 3–8; the cap only keeps a malformed
 * payload from building an enormous texture.
 * @type {number}
 */
const MAX_FRAMES = 32;

/**
 * Reads a custom character off the wire. The broadcast and the page's boot payload
 * both carry `url_base`; a snapshot (which hands back a type's own `spec`) carries
 * the camelCase `urlBase`. All three come out as one shape. Anything incomplete is `null`, so a fighter with a half-sent
 * character simply plays its roster character.
 *
 * @param {?{version?: string, url_base?: string, urlBase?: string, animations?: object}} raw
 * @return {?{version: string, urlBase: string, animations: Object<string, {frames: number, rate: number}>}}
 */
export function normalizeCustomCharacter(raw) {
  if (!raw || typeof raw !== 'object') {
    return null;
  }
  const version = typeof raw.version === 'string' ? raw.version : '';
  const urlBase = raw.url_base ?? raw.urlBase;
  if (!version || typeof urlBase !== 'string' || !urlBase || !raw.animations) {
    return null;
  }
  const animations = {};
  for (const state of STATES) {
    const { frames, rate } = raw.animations[state] ?? {};
    if (!Number.isInteger(frames) || frames < 1 || frames > MAX_FRAMES || !Number.isFinite(rate) || rate <= 0) {
      return null;
    }
    animations[state] = { frames, rate };
  }

  return { version, urlBase, animations };
}

/**
 * The key a custom fighter goes by: its FIGHTER_TYPES-style `key`, the prefix of
 * its animation keys and the name of its texture. A new version is a new key, so
 * a rebuilt character loads fresh files instead of reusing the old texture.
 *
 * @param {number|string} userId
 * @param {string} version
 * @return {string}
 */
export function customFighterKey(userId, version) {
  return `custom-${userId}-${version}`;
}

/**
 * Where each of a custom fighter's strips is served from.
 *
 * @param {{urlBase: string}} spec A normalised custom character.
 * @return {Object<string, string>} Strip URL per animation.
 */
export function stripUrls(spec) {
  return Object.fromEntries(STATES.map(state => [state, `${spec.urlBase}/${state}.png`]));
}

/**
 * How the four strips stack into the one texture a custom fighter owns: a row
 * each, and a named frame per 100 px cell (`idle-0`, `walk-7`, …) — the same
 * names the roster atlas uses, so the fighter code reads both alike.
 *
 * @param {Object<string, {frames: number}>} animations
 * @return {{width: number, height: number, rows: Array<{name: string, y: number}>, frames: Array<{name: string, x: number, y: number}>}}
 */
export function sheetLayout(animations) {
  const rows = STATES.map((name, index) => ({ name, y: index * CUSTOM_FRAME }));
  const frames = rows.flatMap(({ name, y }) => Array.from(
    { length: animations[name].frames },
    (_, index) => ({ name: `${name}-${index}`, x: index * CUSTOM_FRAME, y }),
  ));
  const widest = Math.max(...STATES.map(state => animations[state].frames));

  return { width: widest * CUSTOM_FRAME, height: STATES.length * CUSTOM_FRAME, rows, frames };
}

/**
 * The texture a fighter's frames live in: its own for a custom fighter, the
 * shared atlas for every roster fighter. Every `setTexture`/`add.sprite` of a
 * fighter's body goes through this.
 *
 * @param {{texture?: string}} ftype A FIGHTER_TYPES entry or a custom fighter type.
 * @return {string}
 */
export function textureOf(ftype) {
  return ftype.texture ?? TextureKey.FIGHTERS;
}

/**
 * Takes a custom fighter's texture and animations out of the game, once nothing
 * shows it any more. Each version is its own texture and set of animations, so
 * without this a long session collects one canvas per rebuild.
 *
 * @param {{remove: function(string): *}} textures The game's texture manager (or a scene's).
 * @param {{remove: function(string): *, exists: function(string): boolean}} anims The game's animation manager.
 * @param {{key: string, texture: string}} ftype The custom type being released.
 * @return {void}
 */
export function releaseCustomFighter({ textures, anims }, ftype) {
  for (const state of [...STATES, 'summon']) {
    if (anims.exists(`${ftype.key}-${state}`)) {
      anims.remove(`${ftype.key}-${state}`);
    }
  }
  textures.remove(ftype.texture);
}

/**
 * A FIGHTER_TYPES-shaped entry for a user's custom fighter. Everything that
 * reads a roster type (animations, `attackType`, the heel, the anim keys) reads
 * this one the same way; `texture` says it lives in its own texture rather than
 * the shared atlas, and `bodyScale`/`bodyOffsetY` stand it as tall as a roster
 * fighter with its feet on the same line. It has no per-attack art, so its one
 * attack animation plays as a slash.
 *
 * @param {number|string} userId
 * @param {{version: string, animations: Object<string, {frames: number, rate: number}>}} spec A normalised custom character.
 * @return {{key: string, texture: string, custom: true, spec: object, attackType: string, animations: object, bodyScale: number, bodyPx: number, headTop: number, bodyOffsetY: number}}
 */
export function customFighterType(userId, spec) {
  const key = customFighterKey(userId, spec.version);
  const bodyScale = CUSTOM_STAND_PX / CUSTOM_BODY_PX;

  return {
    key,
    texture: key,
    custom: true,
    spec,
    attackType: AttackType.SLASH,
    animations: spec.animations,
    bodyScale,
    bodyPx: CUSTOM_STAND_PX,
    headTop: CUSTOM_FOOT_ROW - CUSTOM_STAND_PX,
    bodyOffsetY: (CUSTOM_FOOT_ROW - FRAME_CENTRE_ROW) * (1 - bodyScale),
  };
}
