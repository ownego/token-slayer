// Sizes and looks for the sheet's minions: the equipped fighter beside a minion, drawn in the
// proportion the battlefield draws them, and the idle strip of each look in the player's pool.
// Pure and Phaser-free: the field's own constants come from config (not from minions.js, which
// imports Phaser).
import { MINION_SIZE_RATIO, MINION_TYPES, SPRITE_CHAR_HEIGHT } from '@battlefield/config.js';
import { normalizeMinionPool } from '@battlefield/minion-custom.js';
import { CUSTOM_FRAME, stripUrls } from '@battlefield/fighter/custom-fighter.js';

/**
 * The scale a minion's strip is drawn at beside a fighter drawn at `fighterScale`:
 * the minion stands `MINION_SIZE_RATIO` of the fighter's height, from its own measured body.
 *
 * @param {number} fighterScale The field scale the fighter is shown at.
 * @param {number} minionBodyPx The minion's measured body height in its 100 px frame (22 for a new build, 28 for an older fighter copied in).
 * @return {number}
 */
export function fieldSizeMinionScale(fighterScale, minionBodyPx) {
  return fighterScale * SPRITE_CHAR_HEIGHT * MINION_SIZE_RATIO / minionBodyPx;
}

/**
 * The scale a fighter's strip is drawn at to stand as tall as the field draws a fighter at
 * `fighterScale`.
 *
 * @param {number} fighterScale The field scale to show it at.
 * @param {number} boxHeight The fighter's measured body height in its 100 px frame (the roster's `BOX`).
 * @return {number}
 */
export function fieldSizeFighterScale(fighterScale, boxHeight) {
  return fighterScale * SPRITE_CHAR_HEIGHT / boxHeight;
}

/**
 * What the Profile stage needs to draw one look of the pool: its idle strip, how that strip steps, and
 * the frame and body it was drawn with (the stage scales every look to one body height from them).
 *
 * @typedef {{url: string, frames: number, rate: number, frame: number, bodyPx: number}} MinionLook
 */

/**
 * The look of one of the five default minions, off its `MINION_TYPES` entry.
 *
 * @param {{charHeight: number, animFiles: {idle: {file: string, frameWidth: number, count: number, rate: number}}}} type A default minion type.
 * @return {MinionLook}
 */
function defaultLook(type) {
  const idle = type.animFiles.idle;

  return { url: idle.file, frames: idle.count, rate: idle.rate, frame: idle.frameWidth, bodyPx: type.charHeight };
}

/**
 * The look of one of the player's own minions, off its normalised pool entry.
 *
 * @param {{urlBase: string, animations: {idle: {frames: number, rate: number}}, bodyPx: number}} spec A custom minion of the pool.
 * @return {MinionLook}
 */
function customLook(spec) {
  return { url: stripUrls(spec).idle, frames: spec.animations.idle.frames, rate: spec.animations.idle.rate, frame: CUSTOM_FRAME, bodyPx: spec.bodyPx };
}

/**
 * The looks of the minions a player's pool summons: the player's own first (they are the ones just
 * added or replaced, so the Profile stage shows them before the defaults), then the ticked defaults.
 * Reads the pool the way the battlefield does (`normalizeMinionPool`: snake_case off the wire or the
 * page, camelCase off a snapshot). A pool that is not one (no information) or that names nothing is
 * the pool of a player who never touched a tick: the five defaults, never none.
 *
 * @param {*} raw A pool (`FighterSheet::$minionPool`, the `pool` of `custom-minions-changed`, the `minion_pool` of `FighterMinionsChanged`).
 * @return {Array<MinionLook>}
 */
export function minionLooks(raw) {
  const pool = normalizeMinionPool(raw);
  const defaults = pool
    ? pool.defaults.map(key => MINION_TYPES.find(type => type.key === key)).filter(Boolean).map(defaultLook)
    : [];
  const looks = [...(pool?.custom ?? []).map(customLook), ...defaults];

  return looks.length ? looks : MINION_TYPES.map(defaultLook);
}
