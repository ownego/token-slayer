// The draft preview's pause / play / step state. Pure: the stage applies it to the sprite.

/**
 * The starting state: playing from the first frame.
 *
 * @return {{playing: boolean, frame: number, stepped: boolean}}
 */
export function inspectorStart() {
  return { playing: true, frame: 0, stepped: false };
}

/**
 * Pauses a playing strip, or plays a paused one. A strip stepped by hand has to restart (its
 * animation was replaced by a fixed frame); a plain pause just resumes.
 *
 * @param {{playing: boolean, frame: number, stepped: boolean}} state The current state.
 * @return {{state: {playing: boolean, frame: number, stepped: boolean}, restart: boolean}}
 */
export function inspectorToggle(state) {
  if (state.playing) {
    return { state: { ...state, playing: false }, restart: false };
  }

  return { state: { playing: true, frame: 0, stepped: false }, restart: state.stepped };
}

/**
 * Pauses on the next or the previous frame, wrapping at both ends.
 *
 * @param {{playing: boolean, frame: number, stepped: boolean}} state The current state.
 * @param {number} direction 1 for the next frame, -1 for the one before.
 * @param {number} frames How many frames the strip has.
 * @return {{playing: boolean, frame: number, stepped: boolean}}
 */
export function inspectorStep(state, direction, frames) {
  return { playing: false, stepped: true, frame: (state.frame + direction + frames) % frames };
}

/**
 * The line shown beside the controls.
 *
 * @param {{playing: boolean, frame: number}} state The current state.
 * @return {string}
 */
export function inspectorLabel(state) {
  return `Frame ${state.frame + 1} · ${state.playing ? 'playing' : 'paused'}`;
}

/**
 * The background offset of a frame in a strip of 100 px frames.
 *
 * @param {number} frame The frame index.
 * @return {number}
 */
export function frameOffset(frame) {
  return frame === 0 ? 0 : -frame * 100;
}
