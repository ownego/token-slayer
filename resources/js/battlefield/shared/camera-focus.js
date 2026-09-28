/**
 * How much closer the camera zooms in for a focus pan, relative to
 * whatever zoom it was already at — the approved mockup's own 2.2x push-in.
 * @type {number}
 */
const FOCUS_ZOOM_MULTIPLIER = 2.2;

/**
 * How long the focus pan/zoom takes, in ms — the mockup's push-in time.
 * @type {number}
 */
const FOCUS_DURATION_MS = 950;

/**
 * How long the camera holds on the fighter before easing back, in ms: the
 * sheet persists the new character once the push-in lands, and the field
 * then plays the old fighter's death and the new one's summon (~1.7s) plus
 * the mockup's 900ms beat on the result.
 * @type {number}
 */
const FOCUS_HOLD_MS = 2600;

/**
 * The camera pan/zoom target for focusing on a fighter after an equip, or
 * null when there's nothing to focus on (fighter not found — the equip
 * broadcast may not have arrived yet, or they've since left the field).
 *
 * @param {{pos: {x: number, y: number}}|undefined} fighter a scene.fighters entry
 * @param {{zoom: number}} camera the scene's current camera (only its zoom is read)
 * @return {{x: number, y: number, zoom: number, duration: number, hold: number}|null}
 */
export function focusPlan(fighter, camera) {
  if (!fighter) {
    return null;
  }

  return {
    x: fighter.pos.x,
    y: fighter.pos.y,
    zoom: camera.zoom * FOCUS_ZOOM_MULTIPLIER,
    duration: FOCUS_DURATION_MS,
    hold: FOCUS_HOLD_MS,
  };
}

/**
 * Where the camera pans back to once a focus transition ends — the
 * layout's own center point, which is the only place scene.js's create()
 * ever frames the camera at (`centerOn(logicalWidth/2, logicalHeight/2)`).
 * Nothing else in this codebase pans or scrolls the battlefield camera, so
 * "restore" always means "back to center," never a captured scroll
 * position — `Camera.pan(x, y)` targets a center point, not the top-left
 * corner `scrollX`/`scrollY` read, so panning back to a captured scroll
 * reading (the bug this replaced) leaves the camera off-center or off-world
 * at any zoom other than 1.
 *
 * @param {{logicalWidth: number, logicalHeight: number}} layout
 * @return {{x: number, y: number}}
 */
export function restoreCenter(layout) {
  return { x: layout.logicalWidth / 2, y: layout.logicalHeight / 2 };
}
