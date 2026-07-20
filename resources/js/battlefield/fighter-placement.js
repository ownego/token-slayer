import { snapToValidTarget } from '@battlefield/move-geometry.js';

/**
 * Resolves where a fighter should be placed when it enters the scene, either
 * at boot (data-battlefield-state) or on a FighterJoined echo.
 *
 * A saved position is normalized [0..1] against the layout it was made in, so
 * it is denormalized against the current one. When it no longer passes the
 * move-geometry rules — a bigger sprite widened the margins, or the boss /
 * leaderboard zone shifted — it is snapped to the nearest valid point rather
 * than discarded, so a player never gets thrown back to the default grid row.
 *
 * @param {{x: number, y: number}|null|undefined} saved Normalized saved position.
 * @param {{x: number, y: number}} gridPos Default grid slot for this fighter.
 * @param {{layout: object, bossType: object, fsize: number}} ctx
 * @return {{pos: {x: number, y: number}, isCustom: boolean}}
 */
export function resolveFighterPlacement(saved, gridPos, ctx) {
  if (!saved || !Number.isFinite(saved.x) || !Number.isFinite(saved.y)) {
    return { pos: gridPos, isCustom: false };
  }

  const snapped = snapToValidTarget(
    saved.x * ctx.layout.logicalWidth,
    saved.y * ctx.layout.logicalHeight,
    ctx,
  );

  return snapped ? { pos: snapped, isCustom: true } : { pos: gridPos, isCustom: false };
}
