// Where a fighter's avatar sits above its body — per fighter, from its own
// measured body box, rather than one fixed line for every character.
import { BOX } from '../sheet/sheet-roster.js';

/**
 * The visible body's height inside the 100px frame, in sprite pixels.
 * @type {number}
 */
const BODY_PX = 18;

/**
 * A fighter's avatar centre, in container-local px (the sprite's frame is
 * centred on the container): above the fighter's own head top by 38/48 of a
 * body height — the approved mockup's proportion, the same the sheet's stage
 * uses — so a short fighter's avatar doesn't float and a tall one's doesn't
 * crowd its head. Unknown keys fall back to the generic head line.
 *
 * @param {string} key Fighter key.
 * @param {number} scale Sprite scale (display size / 18).
 * @return {number}
 */
export function avatarCenterY(key, scale) {
  const headTop = BOX[key]?.[1] ?? 38;

  return -(50 - headTop) * scale - BODY_PX * scale * (38 / 48);
}

/**
 * The URL a fighter's avatar loads from: the versioned proxy URL the server
 * sent (`User::avatarProxyUrl()`, cached a week, `?v=` changing with the
 * Slack image), else the bare proxy route. Never a per-load cache buster,
 * which re-downloaded every head on every reload.
 *
 * @param {{id?: number|string, avatarUrl?: ?string}} fighter
 * @return {?string}
 */
export function avatarSrc(fighter) {
  if (fighter.avatarUrl) {
    return fighter.avatarUrl;
  }

  return fighter.id ? `/avatars/${fighter.id}` : null;
}
