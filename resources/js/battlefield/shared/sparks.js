/**
 * Grinding sparks: shared emitter settings (one emitter per scene).
 * @type {{lifespan: {min: number, max: number}, gravityY: number, scaleX: {start: number, end: number}, scaleY: {start: number, end: number}, alpha: {start: number, end: number}, blendMode: string, emitting: boolean}}
 */
export const SPARK_EMITTER = Object.freeze({
  lifespan: { min: 180, max: 380 },
  gravityY: 1100,
  scaleX: { start: 1.4, end: 0.35 },
  scaleY: { start: 1.2, end: 0.6 },
  alpha: { start: 1, end: 0 },
  blendMode: 'ADD',
  emitting: false,
});

/**
 * Launch angle for one spark: behind the heel and slightly up.
 * @param {number} facing +1 right, -1 left
 * @param {function(): number} rnd 0..1
 * @return {number} degrees
 */
export function sparkAngle(facing, rnd) {
  return facing > 0 ? 192 + rnd() * 36 : 312 + rnd() * 36;
}

/**
 * Crackle timing: bursts of a few sparks at uneven intervals, denser while moving.
 * @param {{wait:number, moving:boolean}} state
 * @param {number} dt seconds
 * @param {function(): number} rnd
 * @return {{wait:number, count:number}}
 */
export function nextBurst(state, dt, rnd) {
  const wait = state.wait - dt;
  if (wait > 0) {
    return { wait, count: 0 };
  }
  const count = state.moving ? 2 + Math.floor(rnd() * 5) : 2 + Math.floor(rnd() * 3);
  const next = state.moving ? 0.03 + rnd() * 0.05 : 0.05 + rnd() * 0.11;
  return { wait: next, count };
}
