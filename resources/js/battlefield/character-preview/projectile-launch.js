/**
 * The traveling projectile launches partway through the attack swing,
 * matching the real game's "release point" (see fighter/index.js onEffect).
 *
 * @param {number} durationMs - the attack skill's full animation duration
 * @return {number} milliseconds after the swing starts to spawn the projectile
 */
export function getProjectileLaunchDelay(durationMs) {
  return Math.round(durationMs * 0.4);
}
