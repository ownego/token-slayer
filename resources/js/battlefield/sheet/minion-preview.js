// Sizes for the sheet's field-size view: the equipped fighter beside a minion, drawn
// in the proportion the battlefield draws them. Pure and Phaser-free: the field's own
// constants come from config (not from minions.js, which imports Phaser).
import { MINION_SIZE_RATIO } from '@battlefield/config.js';
import { normalizeCustomMinions } from '@battlefield/minion-custom.js';
import { stripUrls } from '@battlefield/fighter/custom-fighter.js';

/**
 * A roster fighter's body height on the field, in sprite px at scale 1 (the
 * battlefield's `SPRITE_CHAR_HEIGHT`). The field draws every fighter so its body is
 * this many px times the field scale, whatever its sheet's own body height.
 * @type {number}
 */
const FIELD_BODY_PX = 18;

/**
 * The scale a minion's strip is drawn at beside a fighter drawn at `fighterScale`:
 * the minion stands `MINION_SIZE_RATIO` of the fighter's height, from its own measured body.
 *
 * @param {number} fighterScale The field scale the fighter is shown at.
 * @param {number} minionBodyPx The minion's measured body height in its 100 px frame (22 for a new build, 28 for an older fighter copied in).
 * @return {number}
 */
export function fieldSizeMinionScale(fighterScale, minionBodyPx) {
  return fighterScale * FIELD_BODY_PX * MINION_SIZE_RATIO / minionBodyPx;
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
  return fighterScale * FIELD_BODY_PX / boxHeight;
}

/**
 * What the Profile stage needs to draw each of the player's minions: its idle strip.
 * Reads both the sheet's own shape (`url_base`) and the camelCase one; anything that is
 * not a set (no information) is no looks, so the defaults stay.
 *
 * @param {*} raw A list of minions (`FighterSheet::$customMinions`, a `custom_minions` broadcast).
 * @return {Array<{url: string, frames: number, rate: number}>}
 */
export function minionLooks(raw) {
  return (normalizeCustomMinions(raw) ?? []).map(spec => ({
    url: stripUrls(spec).idle,
    frames: spec.animations.idle.frames,
    rate: spec.animations.idle.rate,
  }));
}
