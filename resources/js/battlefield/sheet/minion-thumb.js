// How a look of the Minions tab's pool is drawn in a cell and on the stage: pure and Phaser-free, so the
// sizes can be tested without a browser. Every look is scaled from its own measured body (`bodyPx`) to one
// body height, the way the field draws them all one height whatever their sheet, so a minion copied from a
// fighter is no longer a speck beside one built from an upload.
import { MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FRAME, stripUrls } from '@battlefield/fighter/custom-fighter.js';

/**
 * The row, as a share of its frame, a minion's feet stand on: a custom strip is built with the feet on
 * row 58 of 100 (`CUSTOM_FOOT_ROW`), and the default minions are drawn the same way to within a few px.
 *
 * @type {number}
 */
export const FOOT_RATIO = 0.58;

/**
 * What the stage and the cells need to draw one look: its idle strip, how that strip steps, and the frame
 * and body it was drawn with.
 *
 * @typedef {{url: string, frames: number, rate: number, frame: number, bodyPx: number}} PoolLook
 */

/**
 * The scale at which a frame is drawn so that a body `bodyPx` tall stands `targetPx` tall: the same
 * on-screen height for a 22 px minion and a 52 px fighter copied in. A body that is not a positive
 * number is drawn unscaled.
 *
 * @param {number} bodyPx The look's measured body height in its frame.
 * @param {number} targetPx The height to give the body on screen.
 * @return {number}
 */
export function thumbScale(bodyPx, targetPx) {
  if (!Number.isFinite(bodyPx) || bodyPx <= 0 || !Number.isFinite(targetPx) || targetPx <= 0) {
    return 1;
  }

  return targetPx / bodyPx;
}

/**
 * The look a cell or stage spec names. A default look is named by its key and found in `MINION_TYPES`
 * (the server does not hold the default strips); a custom one carries where its strips are served from,
 * its animation table and its body height (snake_case off the page, camelCase off a snapshot).
 *
 * @param {*} spec `{kind: 'default', key}` or `{kind: 'custom', url_base, animations: {idle: {frames, rate}}, body_px}`.
 * @return {?PoolLook} Null when the spec names nothing drawable.
 */
export function lookOf(spec) {
  if (spec === null || typeof spec !== 'object') {
    return null;
  }

  if (spec.kind === 'default') {
    const type = MINION_TYPES.find(candidate => candidate.key === spec.key);
    const idle = type?.animFiles.idle;

    return idle ? { url: idle.file, frames: idle.count, rate: idle.rate, frame: idle.frameWidth, bodyPx: type.charHeight } : null;
  }

  const urlBase = spec.url_base ?? spec.urlBase;
  const bodyPx = spec.body_px ?? spec.bodyPx;
  const idle = spec.animations?.idle;

  if (spec.kind !== 'custom' || typeof urlBase !== 'string' || !Number.isFinite(bodyPx) || !idle || !Number.isFinite(idle.frames) || !Number.isFinite(idle.rate)) {
    return null;
  }

  return { url: stripUrls({ urlBase }).idle, frames: idle.frames, rate: idle.rate, frame: CUSTOM_FRAME, bodyPx };
}

/**
 * The style properties that draw a look at a scale: the strip, how it steps, the box its frame is drawn
 * in and how far that box hangs below the feet. Set as properties, not a style string, so a URL can never
 * break out of its value.
 *
 * @param {PoolLook} look The look.
 * @param {number} scale How much to scale its frame by.
 * @param {{still?: boolean, footRatio?: number}} [options] `still` holds the look on its first frame (reduced motion); `footRatio` is the share of the frame above the feet.
 * @return {Object<string, string|number>}
 */
export function lookStyle(look, scale, { still = false, footRatio = FOOT_RATIO } = {}) {
  const size = look.frame * scale;

  return {
    '--strip': `url("${look.url}")`,
    '--frames': look.frames,
    '--rate': look.rate,
    '--size': `${size.toFixed(1)}px`,
    '--below': `${(size * (1 - footRatio)).toFixed(1)}px`,
    '--play': still ? 'paused' : 'running',
  };
}

/**
 * The style that draws a look with its body `targetPx` tall.
 *
 * @param {PoolLook} look The look.
 * @param {number} targetPx The height to give the body on screen.
 * @param {boolean} [still] Hold the look on its first frame.
 * @return {Object<string, string|number>}
 */
export function thumbStyle(look, targetPx, still = false) {
  return lookStyle(look, thumbScale(look.bodyPx, targetPx), { still });
}

/**
 * Alpine.data() component for one cell's thumbnail: the look's idle loop at a fixed body height. It plays
 * only while the cell is hovered or selected (the stylesheet decides; here it only draws).
 *
 * @param {*} spec The cell's `idle` spec (see `lookOf`).
 * @param {number} [targetPx] The body height to draw it at.
 * @return {object}
 */
export function minionThumb(spec, targetPx = 40) {
  return {
    style: {},
    init() {
      const look = lookOf(spec);
      this.style = look ? thumbStyle(look, targetPx) : {};
    },
  };
}
